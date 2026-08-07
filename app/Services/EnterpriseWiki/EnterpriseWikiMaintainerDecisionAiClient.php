<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Exceptions\EnterpriseWikiAiOutputCapacityExceededException;
use RuntimeException;

/**
 * AI client for the Karpathy-style maintainer decision step.
 *
 * Receives a source document (metadata + extracted text) and the existing
 * wiki page index, then asks the AI what pages to create or update.
 *
 * Returns a validated decision array (see EnterpriseWikiMaintainerDecisionPrompt).
 * Does NOT generate page content and is NOT wired into the ingest pipeline yet.
 *
 * Output-token budgeting is delegated to EnterpriseWikiAiCapacityPlanner instead of a fixed
 * local constant (see the Wiki run-583 incident: a fixed 3000-token budget did not grow when
 * commit 353aa98 added the concept_candidates field, truncating a content-rich document's
 * response). Both decide() and repair() get at most one bounded "capacity retry" — a second
 * attempt at a higher, still-capped budget — ONLY when the first attempt's response was
 * status=incomplete/reason=max_output_tokens. This is deliberately separate from
 * EnterpriseWikiMaintainerDecisionConsistencyValidator's repair pass: a capacity retry reacts to
 * an INCOMPLETE API response (never parses or reuses the partial JSON); consistency repair reacts
 * to a COMPLETE but logically inconsistent decision. The two must never be conflated.
 */
class EnterpriseWikiMaintainerDecisionAiClient
{
    private const MODEL = 'gpt-5';

    private const REASONING_EFFORT = 'low';

    // Keeps the source text well within token limits while leaving room for
    // the system prompt, index context, and the schema output.
    private const MAX_SOURCE_TEXT_CHARS = 12000;

    /** Selects this operation's profile in config('ai_capacity.operations'). */
    private const CAPACITY_OPERATION_TYPE = 'enterprise_wiki_maintainer_decision';

    // source_article + source_summary are always attempted; concept/entity page count is not
    // known before the AI responds, so it is captured by the capacity planner's input-size-driven
    // term instead of a guessed object count here.
    private const EXPECTED_RESULT_OBJECTS = 2;

    public function __construct(
        private readonly EnterpriseWikiAiCapacityPlanner $capacityPlanner,
        private readonly EnterpriseWikiAiCapacityRetryExecutor $capacityRetryExecutor,
        private readonly EnterpriseWikiMaintainerDecisionSplitCoordinator $splitCoordinator,
        private readonly EnterpriseWikiAiRequestTimeoutPolicy $timeoutPolicy,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * Ask the AI maintainer to decide what wiki pages to create or update.
     *
     * @param  array{title: string, filename: string}  $sourceMeta  Cleaned title + original filename.
     * @param  string  $sourceText  Extracted text from the document.
     * @param  array<int, array<string, mixed>>  $indexContext  From EnterpriseWikiIndexContextService::buildForCustomer().
     * @param  string  $languageCode  'no' | 'en'
     * @param  list<array<string, mixed>>  $figureCandidates  Showable image source elements
     *                                                        (EnterpriseWikiDocumentSourceElementService::inspect(), filtered to
     *                                                        source_element_type=image — decorative/logo images are never included, since
     *                                                        isShowable() already excluded them upstream) — Wiki run-587: figures were extracted
     *                                                        and classified correctly but never once considered by this planning step.
     * @return array<string, mixed> Validated maintainer decision.
     *
     * @throws RuntimeException when AI is disabled, the API fails, the response is empty or invalid.
     * @throws EnterpriseWikiAiOutputCapacityExceededException when the response is still
     *                                                         incomplete/max_output_tokens after one capacity retry.
     */
    public function decide(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        string $languageCode,
        array $figureCandidates = [],
        ?AiCallContext $context = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: wiki AI is not enabled.'
            );
        }

        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);

        // The split-vs-single-call DECISION must reflect the document's genuine size, not the
        // prompt text actually sent to OpenAI — userPrompt() truncates source text at
        // MAX_SOURCE_TEXT_CHARS before embedding it (an input-token concern, unrelated to output
        // budgeting), so sizing the decision off the already-truncated prompt would cap
        // rawSourceSizeChars at ~12000 regardless of how large the real document is, making
        // split_required effectively unreachable no matter how content-rich the document truly is.
        $rawSourceSizeChars = mb_strlen(trim($sourceText));
        $plan = $this->planCapacity($rawSourceSizeChars, retryAttempt: 0);

        // A capacity retry always clamps to the exact same ceiling as the first attempt (see
        // EnterpriseWikiAiCapacityPlanner), so whenever the first attempt's own estimate already
        // exceeds that ceiling, a retry is mathematically guaranteed to help — route through the
        // split flow instead of attempting (and predictably truncating) a single oversized call.
        if ($plan->strategy === AiCapacityPlan::STRATEGY_SPLIT_REQUIRED) {
            $decoded = $this->splitCoordinator->decide($sourceMeta, $sourceText, $indexContext, $languageCode, $figureCandidates, $context);
        } else {
            $userPromptText = $this->userPrompt($sourceMeta, $sourceText, $indexContext, $figureCandidates);

            $decoded = $this->capacityRetryExecutor->execute(
                'EnterpriseWikiMaintainerDecisionAiClient',
                $rawSourceSizeChars,
                fn (int $retryAttempt): AiCapacityPlan => $this->planCapacity($rawSourceSizeChars, $retryAttempt),
                fn (int $maxOutputTokens): array => $this->buildPayload($languageName, $userPromptText, $maxOutputTokens),
                fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolve(new AiTimeoutRequest(
                    operationType: self::CAPACITY_OPERATION_TYPE,
                    inputSizeChars: $rawSourceSizeChars,
                    chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                    remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
                )),
                $context,
            );
        }

        try {
            return EnterpriseWikiMaintainerDecisionPrompt::parse($decoded);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: decision failed schema validation: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function requiresSplit(string $sourceText): bool
    {
        return $this->planCapacity(mb_strlen(trim($sourceText)), retryAttempt: 0)->strategy === AiCapacityPlan::STRATEGY_SPLIT_REQUIRED;
    }

    /** @return array{global_plan: array<string,mixed>, batches: list<array<string,mixed>>} */
    public function preparePersistedCandidateBatches(array $sourceMeta, string $sourceText, array $indexContext, string $languageCode, array $figureCandidates = [], ?AiCallContext $context = null): array
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('EnterpriseWikiMaintainerDecisionAiClient: wiki AI is not enabled.');
        }

        return $this->splitCoordinator->preparePersistedCandidateBatches($sourceMeta, $sourceText, $indexContext, $languageCode, $figureCandidates, $context);
    }

    /** @param list<array<string,mixed>> $batchResults @return array<string,mixed> */
    public function mergePersistedBatchResults(array $globalPlan, array $batchResults): array
    {
        return $this->splitCoordinator->mergePersistedBatchResults($globalPlan, $batchResults);
    }

    /**
     * Ask the AI to correct a decision that EnterpriseWikiMaintainerDecisionConsistencyValidator
     * found logically inconsistent — a single bounded pass, not a general re-decision. Preferred
     * over deterministically inventing a fix in PHP because resolving an issue (e.g. deciding
     * whether a missing concept genuinely needs its own page) requires reading the source text
     * again, which only the model can do; preferred over silently rejecting the decision because a
     * self-healing correction keeps the ingest run moving instead of failing outright for a
     * mistake this same model can usually fix when the specific problem is pointed out.
     *
     * This is a consistency repair, not a capacity retry: it can itself still hit
     * status=incomplete/max_output_tokens (it echoes the full previous decision back to the
     * model, so its prompt is larger than decide()'s), and gets its own independent capacity-retry
     * budget for that — entirely separate from the consistency-repair semantics described above.
     *
     * @param  array{title: string, filename: string}  $sourceMeta
     * @param  array<int, array<string, mixed>>  $indexContext
     * @param  array<string, mixed>  $decision  The previous, inconsistent decision.
     * @param  string[]  $issues  Human-readable issues from the consistency validator.
     * @param  list<array<string, mixed>>  $figureCandidates  Same shape as decide()'s parameter.
     * @return array<string, mixed> Validated, corrected maintainer decision.
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid.
     * @throws EnterpriseWikiAiOutputCapacityExceededException when the response is still
     *                                                         incomplete/max_output_tokens after one capacity retry.
     */
    public function repair(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        string $languageCode,
        array $decision,
        array $issues,
        array $figureCandidates = [],
        ?AiCallContext $context = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: wiki AI is not enabled.'
            );
        }

        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);
        $repairPromptText = $this->repairUserPrompt($sourceMeta, $sourceText, $indexContext, $decision, $issues, $figureCandidates);
        $inputSizeChars = mb_strlen($repairPromptText);

        $decoded = $this->capacityRetryExecutor->execute(
            'EnterpriseWikiMaintainerDecisionAiClient:repair',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->planCapacity($inputSizeChars, $retryAttempt),
            fn (int $maxOutputTokens): array => $this->buildRepairPayload($languageName, $repairPromptText, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolve(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
        );

        try {
            return EnterpriseWikiMaintainerDecisionPrompt::parse($decoded);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: repaired decision failed schema validation: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function planCapacity(int $inputSizeChars, int $retryAttempt): AiCapacityPlan
    {
        return $this->capacityPlanner->plan(new AiCapacityRequest(
            operationType: self::CAPACITY_OPERATION_TYPE,
            model: self::MODEL,
            inputSizeChars: $inputSizeChars,
            expectedResultObjects: self::EXPECTED_RESULT_OBJECTS,
            retryAttempt: $retryAttempt,
        ));
    }

    private function buildRepairPayload(string $languageName, string $repairPromptText, int $maxOutputTokens): array
    {
        $schemaBlock = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema();

        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->repairDeveloperPrompt($languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $repairPromptText,
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => array_merge(
                    ['type' => $schemaBlock['type']],
                    $schemaBlock['json_schema'],
                ),
            ],
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function repairDeveloperPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer correcting a previous planning decision. Output language: {$languageName}.",
            'You already produced a decision for this source document. A deterministic check found',
            'specific logical inconsistencies in it, listed below as ISSUES TO FIX.',
            '',
            'Fix ONLY the listed issues. Do not change anything else in the decision that the issues do',
            'not require you to change — keep the same pages, actions, slugs, and wording otherwise.',
            '',
            'For an issue about a missing owning page (a page_title referenced in',
            'related_page_guidance, or a concept_candidates entry marked necessary_for_article, that',
            'matches no existing or planned page): resolve it either by adding a concept_pages entry',
            'that creates that page (action "create", page_id null, with its own short owned_topics),',
            'or, if the topic genuinely does not need its own page after reconsidering the source text,',
            'by revising the reference — remove the dangling related_page_guidance entry, or change the',
            'concept candidate decision to "exclude" with a justification for why the article does not',
            'need to point the reader there after all.',
            '',
            'Keep every free-text field short and decision-oriented (see CONCEPT CANDIDATES rules) —',
            'do not turn a fix into an opportunity to lengthen unrelated fields.',
            '',
            'For an issue about a planned figure with no matching FIGURE CANDIDATES entry: remove that',
            'planned_figures entry, or correct its source_element_key to a real candidate if the intent',
            'is clear. Do not invent a new source_element_key.',
            '',
            'For an issue about a concept candidate lacking separate substantial source evidence or',
            'independent reuse value (an overfragmentation issue — e.g. several short practices under',
            'one framework each got their own page, or several candidates came from the same short',
            'passage/bullet list): change that candidate\'s decision to "reference_only" (or "exclude" if',
            'it should not be mentioned at all) and name the correct owning page — usually the',
            'overarching framework/parent page, an already-planned page, or the article — in',
            'owning_page_title. Remove its now-unwarranted concept_pages entry. Fold the content it would',
            'have covered into the owning page\'s owned_topics instead of dropping it. Only keep "create"',
            'for a candidate that genuinely does have its own separate substantial evidence and reuse',
            'value after reconsidering — do not just flip the boolean flags to make the issue disappear',
            'without changing the underlying decision.',
            '',
            'For an issue about two candidates being near-duplicate or heavily overlapping concepts:',
            'keep "create" for at most one of them (the more general/central one) and change the other(s)',
            'to "reuse" (if the kept one already exists in the index) or "reference_only" naming the kept',
            'one as owning_page_title. Never leave two separate concept_pages entries for the same',
            'underlying concept.',
            '',
            self::figurePlanningRules(),
            '',
            'Return the complete corrected decision as JSON only, conforming to the same schema as',
            'before. Do not include any text outside the JSON.',
        ]);
    }

    private function repairUserPrompt(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        array $decision,
        array $issues,
        array $figureCandidates = [],
    ): string {
        $title = (string) ($sourceMeta['title'] ?? '');
        $text = trim($sourceText);

        if (mb_strlen($text) > self::MAX_SOURCE_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_SOURCE_TEXT_CHARS)."\n[... text truncated ...]";
        }

        $indexJson = $indexContext !== []
            ? (string) json_encode($indexContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'No pages yet.';

        $decisionJson = (string) json_encode($decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $issuesText = implode("\n", array_map(static fn (string $issue): string => "- {$issue}", $issues));

        return implode("\n", [
            'SOURCE METADATA:',
            "Title: {$title}",
            '',
            'SOURCE TEXT:',
            $text !== '' ? $text : '(empty)',
            '',
            'EXISTING WIKI INDEX ('.count($indexContext).' pages):',
            $indexJson,
            '',
            self::figureCandidatesBlock($figureCandidates),
            '',
            'PREVIOUS DECISION:',
            $decisionJson,
            '',
            'ISSUES TO FIX:',
            $issuesText,
        ]);
    }

    private function buildPayload(string $languageName, string $userPromptText, int $maxOutputTokens): array
    {
        $schemaBlock = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema();

        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->developerPrompt($languageName),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $userPromptText,
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => array_merge(
                    ['type' => $schemaBlock['type']],
                    $schemaBlock['json_schema'],
                ),
            ],
            // Low effort is enough for a bounded planning decision and reduces token pressure.
            'reasoning' => [
                'effort' => self::REASONING_EFFORT,
            ],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer making a planning decision. Output language: {$languageName}.",
            'You are NOT generating article content — you decide what pages to create or update.',
            '',
            'DECISION RULES:',
            'source_article — one article page per source document:',
            '  action "create": no matching article for this source exists in the wiki index.',
            '  action "update": an article covering this source already exists in the index.',
            '',
            'source_summary — one summary page per source document:',
            '  Same create/update logic as source_article.',
            '',
            'concept_pages — shared concept pages (recurring topics, methodologies, frameworks):',
            '  Zero or more. action "create" + page_id null: concept does not exist yet.',
            '  action "update" + integer page_id: concept page exists; use its ID from the index.',
            '',
            'entity_pages — shared entity pages (organisations, clients, suppliers):',
            '  Zero or more. Same create/update logic as concept_pages.',
            '',
            'no_action_reason: set if the source is empty, a duplicate, or should not produce a page.',
            'warnings: non-blocking concerns (empty text, language mismatch, ambiguous title, etc.).',
            '',
            'SLUG AND TITLE RULES:',
            '  proposed_slug: lowercase, hyphens only. No dots, spaces, or file extensions.',
            '  title: must not be a raw filename. Never include .pdf, .docx, etc.',
            '  source_article and source_summary slugs: append a short unique suffix (e.g. "tittel-ab1c2d").',
            '  concept/entity slugs: stable, no suffix — same page matched across sources.',
            '',
            'PAGE RESPONSIBILITY (owned_topics / reference_only_topics / excluded_topics / related_page_guidance):',
            '  You see every page planned for this source document at once — this is the one point',
            '  where cross-page repetition AND unbounded page breadth can both be prevented before any',
            '  page content is written. Three tiers, not a single list:',
            '  owned_topics: what THIS page, and only this page, explains in depth. This defines the',
            '  page\'s FULL scope — nothing beyond it. Keep it SHORT and proportional to what the source',
            '  document itself actually supports or requires to be understood — typically 1-3 items for',
            '  an article/summary, 1-3 for a concept/entity page. A page is not required, and is not',
            '  expected, to become a complete textbook treatment of its subject just because the subject',
            '  is broad — it should cover only what this specific source material needs.',
            '  reference_only_topics: topics this page may mention in passing (at most one short',
            '  sentence plus a link to the page that owns them) but must never explain.',
            '  excluded_topics: topics this page must NEVER mention at all, in any depth. Use this',
            '  deliberately for wider domain areas the source document does not itself require —',
            '  detailed catalogs (e.g. a full KPI list), adjacent process areas, or organisation-specific',
            '  roles/practices not evidenced by the source. When in doubt whether a related topic',
            '  belongs in owned_topics or excluded_topics, prefer excluded_topics: a thin source document',
            '  justifies a thin page, not an opportunity to write everything the model knows about the',
            '  general subject.',
            '  related_page_guidance: for each topic in reference_only_topics or excluded_topics that',
            '  another planned (or existing, from the wiki index) page already owns or should own, name',
            '  that page\'s title and a short instruction for how this page should refer to it (e.g. "Link',
            '  here for the detailed workflow — do not repeat it").',
            '  A related_page_guidance target may also be this run\'s source_article or source_summary',
            '  when the relationship is source-level detail/orientation; in that case copy the exact',
            '  source_article/source_summary title. Never use source_article/source_summary as a',
            '  concept_candidates.owning_page_title or as a substitute for a needed concept/entity page.',
            '  A concept/entity page that is itself the natural owner of a topic (e.g. an overarching',
            '  framework page) should still keep owned_topics short and specific — do not use "this page',
            '  owns the topic" as a reason to also own every adjacent sub-topic; assign those to',
            '  reference_only_topics or excluded_topics instead, even if this is the only page about the',
            '  broader subject so far.',
            '  The source_article and source_summary are about the same source document — give summary',
            '  a narrow owned_topics (a short orientation and the main points) and instruct it, via',
            '  related_page_guidance, to point to the article for detail rather than repeating it.',
            '',
            'CONCEPT CANDIDATES (identify before finalizing concept_pages):',
            '  Before deciding concept_pages, list every central concept candidate this source document',
            '  points to: an independent subject-matter term, process, practice, methodology, or',
            '  framework — not a mere mention of a random word, and not the document\'s own filename, an',
            '  illustration title, or a purely local label used only inside this document.',
            '  Each concept_candidates entry has: name, concept_type, independent_reason,',
            '  mentioned_context, existing_page_title (nullable), decision, justification,',
            '  owning_page_title (nullable), necessary_for_article (boolean),',
            '  has_separate_source_evidence (boolean), has_reuse_value (boolean).',
            '  KEEP FREE-TEXT FIELDS SHORT — this is a planning decision, not a report:',
            '    independent_reason: ONE short sentence (roughly 15 words or fewer).',
            '    mentioned_context: a short phrase naming WHERE it is mentioned (e.g. "document title",',
            '    "section 2") — never a quote or a paraphrase of what the document says.',
            '    justification: ONE short sentence. Do not repeat independent_reason or',
            '    mentioned_context in different words — each field states something the others do not.',
            '  decision is exactly one of:',
            '    "create": a NEW concept page is needed. Add a matching entry to concept_pages.',
            '    "reuse": an existing page in the wiki index already covers it. Set existing_page_title',
            '    to that page\'s title and reference it (page_id + action "update") in concept_pages.',
            '    "reference_only": the article/summary may mention it briefly and link onward, but it',
            '    does not need its own page yet — usually because it is not central enough, or because',
            '    another already-existing or already-planned page owns it (name that page in',
            '    owning_page_title).',
            '    "exclude": it must not be mentioned at all — usually a document name, filename,',
            '    illustration title, local label, or a topic explicitly out of scope for every planned',
            '    page.',
            '',
            '  STANDALONE PAGE vs SECTION — a new page is a bigger commitment than a section of an',
            '  existing page, and most candidates are NOT worth that commitment. Prefer sections over',
            '  new pages when the source material does not justify a standalone reusable concept.',
            '  A standalone page (concept OR entity) is normally RIGHT for:',
            '    - a genuinely independent subject-matter concept',
            '    - a clearly bounded, named process',
            '    - a durable entity (organisation, client, supplier, system)',
            '    - a topic with its own substantial source material',
            '    - a topic that would naturally be reused/linked from several OTHER wiki pages',
            '  A standalone page is normally WRONG for:',
            '    - a subsection or heading',
            '    - a bullet-list item',
            '    - a role mentioned briefly',
            '    - an activity, or a single step in a process',
            '    - a practice mentioned briefly under an overarching framework — this belongs as a',
            '      section/owned_topic of the framework\'s own page, not its own page',
            '    - a sub-topic without its own substantial source evidence',
            '    - a sibling concept that closely overlaps another candidate and would naturally be',
            '      merged with it',
            '  Decide "create" only when ALL of the following hold:',
            '    1. The concept is explicitly named in the source document or in the planned',
            '       article/summary.',
            '    2. It is an independent subject-matter term, process, practice, or framework — not an',
            '       arbitrary word or a passing reference mentioned once.',
            '    3. It is necessary to understand the article\'s professional context.',
            '    4. The article or summary otherwise needs to point the reader onward to it.',
            '    5. No existing page in the wiki index already covers it (check by meaning, not exact',
            '       string — e.g. "ITIL Incident Management", "Incident Management", and "Incident',
            '       management (ITIL)" are the same concept, not three different ones).',
            '    6. It is not a topic this decision itself excludes.',
            '    7. has_separate_source_evidence is true: the source gives THIS candidate its own',
            '       substantial passage or section — not just a brief mention alongside several',
            '       siblings (e.g. one line in a shared bullet list is NOT separate substantial',
            '       evidence, even if the line names a real practice).',
            '    8. has_reuse_value is true: the concept would plausibly be linked to from other,',
            '       unrelated wiki pages later — not just a detail specific to this one document\'s',
            '       narrative.',
            '  Do NOT create a concept page when: it is mentioned only once in passing; it is not an',
            '  independent concept; a suitable page already exists; it only belongs inside another page',
            '  as a reference_only topic; it is excluded; a separate page would only duplicate the',
            '  article without adding independent value; there is not enough information to give it a',
            '  clear scope; it is only a document name, filename, illustration title, or local label; or',
            '  either has_separate_source_evidence or has_reuse_value would be false — put that content',
            '  as a section/owned_topic under whichever page (an overarching framework, the article,',
            '  another concept) already is or should be its natural owner instead.',
            '  When several candidates all come from the same short passage or bullet list (e.g. a list',
            '  of practices under one framework), do not turn each one into its own page merely because',
            '  each has a name — set has_separate_source_evidence to false for each one unless the',
            '  source genuinely elaborates on it separately, and name the framework/parent page as',
            '  owning_page_title instead.',
            '  When two candidates are near-duplicates or heavily overlapping (naming variants of ONE',
            '  underlying concept, not two), decide "create" for at most one of them and "reuse" or',
            '  "reference_only" (naming the other as owning_page_title) for the rest — never create',
            '  separate pages for both.',
            '  Consistency requirement: if a concept candidate is "reference_only" or "exclude" AND',
            '  necessary_for_article is true, you MUST name a page in owning_page_title that either',
            '  already exists in the wiki index or is itself being created in this same decision. It is',
            '  never correct to point the reader onward to a concept in related_page_guidance while also',
            '  deciding not to create or reuse that same page — if no owning page can be named,',
            '  reconsider and create it instead.',
            '  Every concept_candidates entry with decision "reference_only" or "exclude" must justify,',
            '  in justification, specifically why no page is warranted — not just repeat that it is out',
            '  of scope.',
            '',
            self::figurePlanningRules(),
            '',
            'Return JSON only. No text outside JSON.',
        ]);
    }

    /**
     * Shared figure-planning instructions for the single-call developer prompt, the repair
     * developer prompt, and (via EnterpriseWikiMaintainerDecisionSplitCoordinator) the split-flow
     * global-plan/candidate-batch developer prompts.
     */
    public static function figurePlanningRules(): string
    {
        return implode("\n", [
            'PLANNED FIGURES (planned_figures on every page — source_article, source_summary,',
            'concept_pages, entity_pages):',
            '  You are given FIGURE CANDIDATES — every figure the document extraction already found,',
            '  classified, and made citable. For EACH figure candidate, explicitly decide one of:',
            '    use_on_page: the figure is professionally significant and belongs on a specific',
            '    planned or existing page — add one planned_figures entry to that page.',
            '    reference_only: the figure exists but is not central enough to place on any page —',
            '    do not add a planned_figures entry for it anywhere.',
            '    exclude: the figure is decorative/branding despite having reached this list, or is',
            '    otherwise irrelevant — do not add a planned_figures entry for it anywhere.',
            '  Weigh this deliberately for governance/process models, organisation charts, and formal',
            '  meeting/decision structures — these are exactly the figure types most likely to be',
            '  professionally significant and most likely to be silently dropped if not planned',
            '  explicitly. Do not plan a decorative image, a cover photo, or a figure with no clear',
            '  professional content.',
            '  Each planned_figures entry has: source_element_key (copy EXACTLY from the FIGURE',
            '  CANDIDATES list — never invent one), classification (copy from the candidate),',
            '  section_placement (the heading text of the owned_topics section this figure belongs',
            '  under, or null to place it right after the page introduction), purpose (ONE short',
            '  sentence — why this figure belongs on this page), required (true only when the page',
            '  would be materially incomplete without it; false when it would help but is not',
            '  essential), caption_hint (a short suggested caption, or null to use the figure\'s own',
            '  existing caption/description).',
            '  A figure belongs to the source document, not to any single page — plan it onto every',
            '  page (source_article, source_summary, concept_pages, entity_pages) where it is',
            '  professionally relevant enough to show, however many that is. The same figure MAY be',
            '  planned onto more than one page — two different concept pages, a concept page and an',
            '  entity page, source_summary without source_article, or any other combination — this is',
            '  expected and normal, never a duplicate to avoid. Only plan it onto pages where it',
            '  genuinely helps that specific page; do not add it everywhere out of caution.',
            '  Never plan a figure that is not in the FIGURE CANDIDATES list, and never alter its',
            '  source_element_key or classification.',
        ]);
    }

    private function userPrompt(array $sourceMeta, string $sourceText, array $indexContext, array $figureCandidates = []): string
    {
        $title = (string) ($sourceMeta['title'] ?? '');
        $filename = (string) ($sourceMeta['filename'] ?? '');
        $text = trim($sourceText);

        if (mb_strlen($text) > self::MAX_SOURCE_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_SOURCE_TEXT_CHARS)."\n[... text truncated ...]";
        }

        $indexJson = $indexContext !== []
            ? (string) json_encode($indexContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'No pages yet.';

        return implode("\n", [
            'SOURCE METADATA:',
            "Title: {$title}",
            "Original file: {$filename}",
            '',
            'SOURCE TEXT:',
            $text !== '' ? $text : '(empty)',
            '',
            'EXISTING WIKI INDEX ('.count($indexContext).' pages):',
            $indexJson,
            '',
            self::figureCandidatesBlock($figureCandidates),
        ]);
    }

    /**
     * Renders the "FIGURE CANDIDATES" prompt block shared by the single-call, repair, and (via
     * EnterpriseWikiMaintainerDecisionSplitCoordinator) split-flow prompts — every figure the
     * document extraction pipeline already found, classified, and made citable
     * (EnterpriseWikiDocumentSourceElementService — decorative/logo images are never in this list,
     * isShowable() already excluded them upstream of this class).
     *
     * @param  list<array<string, mixed>>  $figureCandidates
     */
    public static function figureCandidatesBlock(array $figureCandidates): string
    {
        if ($figureCandidates === []) {
            return "FIGURE CANDIDATES (0 figures):\nNo figures were extracted from this document.";
        }

        $parts = [
            'FIGURE CANDIDATES ('.count($figureCandidates).' figures):',
            'Each of these was already extracted from the source document and classified as a genuine,',
            'showable figure (never a logo/decorative element) — decide for each one whether it is',
            'professionally significant enough to plan onto a page.',
            '',
        ];

        foreach ($figureCandidates as $figure) {
            $parts[] = (string) json_encode($figure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $parts);
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
