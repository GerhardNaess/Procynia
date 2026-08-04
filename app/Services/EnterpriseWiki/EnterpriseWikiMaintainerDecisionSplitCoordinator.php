<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Exceptions\EnterpriseWikiMaintainerDecisionBatchFailedException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the split flow for an oversized Enterprise Wiki maintainer decision — only entered by
 * EnterpriseWikiMaintainerDecisionAiClient::decide() when EnterpriseWikiAiCapacityPlanner reports
 * strategy=split_required (i.e. even a capacity retry could not give the single call enough
 * headroom). Never entered from repair(): the consistency-repair pass always targets a small,
 * specifically-named set of issues, which stays well within a single call's capacity.
 *
 * Three phases, matching the design in the "Split oversized Wiki maintainer decisions" work item:
 *
 *  A. Global plan — one compact call deciding source_article/source_summary/entity_pages plus a
 *     MINIMAL concept_candidate_mentions identification (name/type/where-mentioned only, no
 *     disposition) — see EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema().
 *  B. Candidate batches — the mentions are split into capacity-sized batches
 *     (EnterpriseWikiAiCapacityPlanner::planBatchCount()); each batch independently decides
 *     create/reuse/reference_only/exclude (+ concept_pages for its own "create" candidates) for
 *     its own subset — see EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema(). Each
 *     batch call gets its own bounded capacity retry, exactly like a normal single call.
 *  C. Merge — EnterpriseWikiMaintainerDecisionMerger deterministically combines the global plan
 *     and every batch result into one complete decision, in the exact shape
 *     EnterpriseWikiMaintainerDecisionPrompt::parse() already expects. The existing consistency
 *     validator and its repair pass run on this merged result exactly as they would on a plain
 *     single-call decision — this class has no awareness of them, and they have none of it.
 *
 * A batch call that ultimately fails (capacity exhausted after its own retry, schema violation, or
 * any other error) aborts the WHOLE decision immediately via
 * EnterpriseWikiMaintainerDecisionBatchFailedException — the flow never proceeds with only some
 * batches decided, and no partial result is ever returned for persistence.
 */
class EnterpriseWikiMaintainerDecisionSplitCoordinator
{
    private const MODEL = 'gpt-5';

    private const REASONING_EFFORT = 'low';

    private const MAX_SOURCE_TEXT_CHARS = 12000;

    private const CAPACITY_OPERATION_TYPE = 'enterprise_wiki_maintainer_decision';

    // source_article + source_summary are always attempted; entity_pages count is unknown ahead
    // of time, same reasoning as EnterpriseWikiMaintainerDecisionAiClient's own constant.
    private const GLOBAL_PLAN_EXPECTED_RESULT_OBJECTS = 2;

    public function __construct(
        private readonly EnterpriseWikiAiCapacityPlanner $capacityPlanner,
        private readonly EnterpriseWikiAiCapacityRetryExecutor $capacityRetryExecutor,
        private readonly EnterpriseWikiMaintainerDecisionMerger $merger,
        private readonly EnterpriseWikiAiRequestTimeoutPolicy $timeoutPolicy,
    ) {}

    /**
     * @param  array{title: string, filename: string}  $sourceMeta
     * @param  array<int, array<string, mixed>>  $indexContext
     * @param  list<array<string, mixed>>  $figureCandidates  Same shape as
     *                                                        EnterpriseWikiMaintainerDecisionAiClient::decide()'s parameter — offered identically
     *                                                        to the global plan AND every candidate batch (all batches see the same truncated
     *                                                        source text, so no batch is any more or less entitled to plan a given figure; the
     *                                                        merger is what catches two batches planning the same figure onto different pages).
     * @return array<string, mixed> A raw (not yet EnterpriseWikiMaintainerDecisionPrompt::parse()d)
     *                              decision, in the exact same shape a single decide() call would produce.
     *
     * @throws EnterpriseWikiMaintainerDecisionBatchFailedException
     */
    public function decide(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        string $languageCode,
        array $figureCandidates = [],
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);

        $globalPlanRaw = $this->decideGlobalPlan($sourceMeta, $sourceText, $indexContext, $languageName, $figureCandidates, $context);
        $globalPlan = EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan($globalPlanRaw);

        $mentions = $globalPlan['concept_candidate_mentions'];

        Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_SPLIT] Global plan produced.', [
            'entity_pages' => count($globalPlan['entity_pages']),
            'concept_candidate_mentions' => count($mentions),
        ]);

        if ($mentions === []) {
            return $this->merger->merge($globalPlan, []);
        }

        $batchSizes = $this->capacityPlanner->planBatchCount(self::CAPACITY_OPERATION_TYPE, self::MODEL, count($mentions));

        Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_SPLIT] Batch plan computed.', [
            'total_candidates' => count($mentions),
            'batch_count' => count($batchSizes),
            'batch_sizes' => $batchSizes,
        ]);

        $batchResults = [];
        $offset = 0;

        foreach ($batchSizes as $batchIndex => $size) {
            $batchMentions = array_slice($mentions, $offset, $size);
            $offset += $size;

            try {
                $batchRaw = $this->decideCandidateBatch(
                    $sourceMeta,
                    $sourceText,
                    $indexContext,
                    $languageName,
                    $globalPlan,
                    $batchMentions,
                    $batchIndex,
                    $figureCandidates,
                    $context,
                );
                $batchResults[] = EnterpriseWikiMaintainerDecisionPrompt::parseCandidateBatch($batchRaw);
            } catch (Throwable $e) {
                throw new EnterpriseWikiMaintainerDecisionBatchFailedException(
                    $batchIndex + 1,
                    count($batchSizes),
                    count($batchMentions),
                    $e,
                );
            }
        }

        $merged = $this->merger->merge($globalPlan, $batchResults);

        Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_SPLIT] Merge completed.', [
            'batch_count' => count($batchResults),
            'concept_candidates' => count($merged['concept_candidates']),
            'concept_pages' => count($merged['concept_pages']),
        ]);

        return $merged;
    }

    /** @return array<string, mixed> */
    private function decideGlobalPlan(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        string $languageName,
        array $figureCandidates = [],
        ?AiCallContext $context = null,
    ): array {
        $userPromptText = $this->globalPlanUserPrompt($sourceMeta, $sourceText, $indexContext, $figureCandidates);
        $inputSizeChars = mb_strlen($userPromptText);

        return $this->capacityRetryExecutor->execute(
            'EnterpriseWikiMaintainerDecisionAiClient:global_plan',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->capacityPlanner->planGlobalPlanCall(
                self::CAPACITY_OPERATION_TYPE,
                self::MODEL,
                self::GLOBAL_PLAN_EXPECTED_RESULT_OBJECTS,
                $inputSizeChars,
                $retryAttempt,
            ),
            fn (int $maxOutputTokens): array => $this->buildGlobalPlanPayload($languageName, $userPromptText, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolveForGlobalPlan(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
        );
    }

    /**
     * @param  array<string, mixed>  $globalPlan
     * @param  list<array<string, mixed>>  $batchMentions
     * @return array<string, mixed>
     */
    private function decideCandidateBatch(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        string $languageName,
        array $globalPlan,
        array $batchMentions,
        int $batchIndex,
        array $figureCandidates = [],
        ?AiCallContext $context = null,
    ): array {
        $userPromptText = $this->candidateBatchUserPrompt($sourceMeta, $sourceText, $indexContext, $globalPlan, $batchMentions, $figureCandidates);
        $inputSizeChars = mb_strlen($userPromptText);
        $candidateCount = count($batchMentions);

        return $this->capacityRetryExecutor->execute(
            "EnterpriseWikiMaintainerDecisionAiClient:batch:{$batchIndex}",
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->capacityPlanner->planBatchCall(
                self::CAPACITY_OPERATION_TYPE,
                self::MODEL,
                $candidateCount,
                $inputSizeChars,
                $retryAttempt,
            ),
            fn (int $maxOutputTokens): array => $this->buildCandidateBatchPayload($languageName, $userPromptText, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolveForBatch(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            ), $candidateCount),
            $context,
        );
    }

    /** @return array<string,mixed> */
    public function decidePersistedCandidateBatch(array $sourceMeta, string $sourceText, array $indexContext, string $languageCode, array $globalPlan, array $mentions, int $batchNumber, array $figureCandidates = [], ?AiCallContext $context = null): array
    {
        return $this->decideCandidateBatch($sourceMeta, $sourceText, $indexContext, $this->languageName($languageCode), $globalPlan, $mentions, $batchNumber - 1, $figureCandidates, $context);
    }

    private function buildGlobalPlanPayload(string $languageName, string $userPromptText, int $maxOutputTokens): array
    {
        $schemaBlock = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema();

        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [['type' => 'input_text', 'text' => $this->globalPlanDeveloperPrompt($languageName)]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $userPromptText]],
                ],
            ],
            'text' => [
                'format' => array_merge(['type' => $schemaBlock['type']], $schemaBlock['json_schema']),
            ],
            'reasoning' => ['effort' => self::REASONING_EFFORT],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function buildCandidateBatchPayload(string $languageName, string $userPromptText, int $maxOutputTokens): array
    {
        $schemaBlock = EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema();

        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [['type' => 'input_text', 'text' => $this->candidateBatchDeveloperPrompt($languageName)]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $userPromptText]],
                ],
            ],
            'text' => [
                'format' => array_merge(['type' => $schemaBlock['type']], $schemaBlock['json_schema']),
            ],
            'reasoning' => ['effort' => self::REASONING_EFFORT],
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    private function globalPlanDeveloperPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer making a planning decision. Output language: {$languageName}.",
            'This source document is large enough that the decision is split into two phases. This is',
            'PHASE 1: decide source_article, source_summary, and entity_pages, and IDENTIFY (but do not',
            'yet fully evaluate) concept candidates. A second phase will decide each candidate\'s full',
            'disposition in smaller batches — do not try to do that work here.',
            '',
            'DECISION RULES:',
            'source_article — one article page per source document:',
            '  action "create": no matching article for this source exists in the wiki index.',
            '  action "update": an article covering this source already exists in the index.',
            '',
            'source_summary — one summary page per source document:',
            '  Same create/update logic as source_article.',
            '',
            'entity_pages — shared entity pages (organisations, clients, suppliers):',
            '  Zero or more. action "create" + page_id null: entity does not exist yet.',
            '  action "update" + integer page_id: entity page exists; use its ID from the index.',
            '',
            'no_action_reason: set if the source is empty, a duplicate, or should not produce a page.',
            'warnings: non-blocking concerns (empty text, language mismatch, ambiguous title, etc.).',
            '',
            'SLUG AND TITLE RULES:',
            '  proposed_slug: lowercase, hyphens only. No dots, spaces, or file extensions.',
            '  title: must not be a raw filename. Never include .pdf, .docx, etc.',
            '  source_article and source_summary slugs: append a short unique suffix (e.g. "tittel-ab1c2d").',
            '  entity slugs: stable, no suffix — same page matched across sources.',
            '',
            ...$this->pageResponsibilityInstructionLines(),
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figurePlanningRules(),
            '',
            'CONCEPT CANDIDATE MENTIONS (identification only — do not decide anything about them yet):',
            '  List every central concept candidate this source document points to: an independent',
            '  subject-matter term, process, practice, methodology, or framework — not a mere mention of',
            '  a random word, and not the document\'s own filename, an illustration title, or a purely',
            '  local label used only inside this document.',
            '  Each concept_candidate_mentions entry has ONLY: name, concept_type, mentioned_context (a',
            '  short phrase naming WHERE it is mentioned, e.g. "document title", "section 2" — never a',
            '  quote or paraphrase of what the document says).',
            '  Do NOT decide create/reuse/reference_only/exclude here, and do NOT write',
            '  independent_reason, justification, existing_page_title, owning_page_title, or',
            '  necessary_for_article — those belong to the next phase. List a candidate whenever it is',
            '  plausibly central; the next phase filters out any that turn out not to warrant a page.',
            '',
            'Return JSON only. No text outside JSON.',
        ]);
    }

    private function candidateBatchDeveloperPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer making a planning decision. Output language: {$languageName}.",
            'This source document is large enough that the decision is split into two phases. Phase 1',
            'already decided source_article, source_summary, and entity_pages, and identified concept',
            'candidates. This is PHASE 2: decide the full disposition for ONLY the candidates listed below',
            'under CANDIDATES TO DECIDE IN THIS BATCH — do not invent additional candidates, and do not',
            'redecide source_article/source_summary/entity_pages.',
            '',
            ...$this->conceptCandidateDecisionCriteriaLines(),
            '',
            'For each candidate decided "create", add a matching entry to concept_pages (action "create",',
            'page_id null, proposed_slug, reason, and a short owned_topics/reference_only_topics/',
            'excluded_topics/related_page_guidance — same rules as any other concept page).',
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figurePlanningRules(),
            '',
            'SLUG AND TITLE RULES:',
            '  proposed_slug: lowercase, hyphens only. No dots, spaces, or file extensions.',
            '  title: must not be a raw filename. Never include .pdf, .docx, etc. Stable, no suffix.',
            '',
            'Return JSON only. No text outside JSON.',
        ]);
    }

    /** @return string[] */
    private function pageResponsibilityInstructionLines(): array
    {
        // Kept identical in spirit to EnterpriseWikiMaintainerDecisionAiClient::developerPrompt()'s
        // PAGE RESPONSIBILITY section — update both together if this policy ever changes.
        return [
            'PAGE RESPONSIBILITY (owned_topics / reference_only_topics / excluded_topics / related_page_guidance):',
            '  Three tiers, not a single list:',
            '  owned_topics: what THIS page, and only this page, explains in depth. Keep it SHORT and',
            '  proportional to what the source document itself actually supports — typically 1-3 items.',
            '  reference_only_topics: topics this page may mention in passing (at most one short',
            '  sentence plus a link to the page that owns them) but must never explain.',
            '  excluded_topics: topics this page must NEVER mention at all, in any depth. Prefer',
            '  excluded_topics over owned_topics when in doubt — a thin source document justifies a thin',
            '  page, not an opportunity to write everything the model knows about the general subject.',
            '  related_page_guidance: for each topic in reference_only_topics or excluded_topics that',
            '  another planned (or existing, from the wiki index) page already owns or should own, name',
            '  that page\'s title and a short instruction for how this page should refer to it.',
            '  The source_article and source_summary are about the same source document — give summary a',
            '  narrow owned_topics and instruct it, via related_page_guidance, to point to the article for',
            '  detail rather than repeating it.',
        ];
    }

    /** @return string[] */
    private function conceptCandidateDecisionCriteriaLines(): array
    {
        // Kept identical in spirit to EnterpriseWikiMaintainerDecisionAiClient::developerPrompt()'s
        // CONCEPT CANDIDATES section — update both together if this policy ever changes.
        return [
            'For each candidate, decide is exactly one of:',
            '  "create": a NEW concept page is needed.',
            '  "reuse": an existing page in the wiki index already covers it. Set existing_page_title to',
            '  that page\'s title and reference it (page_id + action "update") in concept_pages.',
            '  "reference_only": the article/summary may mention it briefly and link onward, but it does',
            '  not need its own page yet — name the owning page in owning_page_title.',
            '  "exclude": it must not be mentioned at all.',
            'Decide "create" only when ALL of the following hold:',
            '  1. The concept is explicitly named in the source document or planned article/summary.',
            '  2. It is an independent subject-matter term, process, practice, or framework — not an',
            '     arbitrary word or a passing reference mentioned once.',
            '  3. It is necessary to understand the article\'s professional context.',
            '  4. The article or summary otherwise needs to point the reader onward to it.',
            '  5. No existing page in the wiki index already covers it (check by meaning, not exact',
            '     string — e.g. "ITIL Incident Management" and "Incident Management" are the same',
            '     concept, not two different ones).',
            '  6. It is not a topic this decision itself excludes.',
            '  7. There is enough basis in the source to write a scoped page — not just a bare mention.',
            'Do NOT create a concept page when: it is mentioned only once in passing; it is not an',
            'independent concept; a suitable page already exists; it only belongs inside another page as',
            'a reference_only topic; it is excluded; a separate page would only duplicate the article',
            'without adding independent value; there is not enough information to give it a clear scope;',
            'or it is only a document name, filename, illustration title, or local label.',
            'Consistency requirement: if a candidate is "reference_only" or "exclude" AND',
            'necessary_for_article is true, you MUST name a page in owning_page_title that either already',
            'exists in the wiki index, is one of the ALREADY PLANNED PAGES FROM PHASE 1 below, or is',
            'itself created by another candidate in THIS batch — if no such page can be named, reconsider',
            'and decide "create" instead.',
            'KEEP FREE-TEXT FIELDS SHORT — this is a planning decision, not a report:',
            '  independent_reason: ONE short sentence (roughly 15 words or fewer).',
            '  mentioned_context: a short phrase naming WHERE it is mentioned — never a quote.',
            '  justification: ONE short sentence — do not repeat independent_reason or mentioned_context.',
        ];
    }

    private function globalPlanUserPrompt(array $sourceMeta, string $sourceText, array $indexContext, array $figureCandidates = []): string
    {
        $title = (string) ($sourceMeta['title'] ?? '');
        $filename = (string) ($sourceMeta['filename'] ?? '');

        return implode("\n", [
            'SOURCE METADATA:',
            "Title: {$title}",
            "Original file: {$filename}",
            '',
            'SOURCE TEXT:',
            $this->truncatedSourceText($sourceText),
            '',
            'EXISTING WIKI INDEX ('.count($indexContext).' pages):',
            $this->indexContextJson($indexContext),
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figureCandidatesBlock($figureCandidates),
        ]);
    }

    /**
     * @param  array<string, mixed>  $globalPlan
     * @param  list<array<string, mixed>>  $batchMentions
     * @param  list<array<string, mixed>>  $figureCandidates
     */
    private function candidateBatchUserPrompt(
        array $sourceMeta,
        string $sourceText,
        array $indexContext,
        array $globalPlan,
        array $batchMentions,
        array $figureCandidates = [],
    ): string {
        $title = (string) ($sourceMeta['title'] ?? '');

        $plannedPages = [
            'source_article' => $globalPlan['source_article']['title'] ?? null,
            'source_summary' => $globalPlan['source_summary']['title'] ?? null,
            'entity_pages' => array_values(array_map(
                fn (array $entry): ?string => $entry['title'] ?? null,
                $globalPlan['entity_pages'] ?? [],
            )),
        ];

        return implode("\n", [
            'SOURCE METADATA:',
            "Title: {$title}",
            '',
            'SOURCE TEXT:',
            $this->truncatedSourceText($sourceText),
            '',
            'EXISTING WIKI INDEX ('.count($indexContext).' pages):',
            $this->indexContextJson($indexContext),
            '',
            'ALREADY PLANNED PAGES FROM PHASE 1 (do not redecide these; they are valid owning pages):',
            (string) json_encode($plannedPages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            'CANDIDATES TO DECIDE IN THIS BATCH ('.count($batchMentions).'):',
            (string) json_encode($batchMentions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figureCandidatesBlock($figureCandidates),
        ]);
    }

    private function truncatedSourceText(string $sourceText): string
    {
        $text = trim($sourceText);

        if (mb_strlen($text) > self::MAX_SOURCE_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_SOURCE_TEXT_CHARS)."\n[... text truncated ...]";
        }

        return $text !== '' ? $text : '(empty)';
    }

    private function indexContextJson(array $indexContext): string
    {
        return $indexContext !== []
            ? (string) json_encode($indexContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'No pages yet.';
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
