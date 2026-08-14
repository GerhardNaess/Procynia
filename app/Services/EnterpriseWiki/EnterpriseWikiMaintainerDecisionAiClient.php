<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Exceptions\EnterpriseWikiAiOutputCapacityExceededException;
use App\Models\EnterpriseWikiSourceReference;
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
 * response). Both decide() and repairGroup() get at most one bounded "capacity retry" — a second
 * attempt at a higher, still-capped budget — ONLY when the first attempt's response was
 * status=incomplete/reason=max_output_tokens, and only when that retry would genuinely raise the
 * budget (see EnterpriseWikiAiCapacityRetryExecutor). This is deliberately separate from
 * EnterpriseWikiMaintainerDecisionConsistencyValidator's repair pass: a capacity retry reacts to
 * an INCOMPLETE API response (never parses or reuses the partial JSON); consistency repair reacts
 * to a COMPLETE but logically inconsistent decision. The two must never be conflated.
 *
 * Consistency repair is repairGroup(): a BOUNDED DELTA over one attributed group of objects, not a
 * regeneration of the decision. See EnterpriseWikiMaintainerDecisionDeltaPrompt for why the
 * whole-decision repair it replaced could not work for any decision large enough to be split.
 */
class EnterpriseWikiMaintainerDecisionAiClient
{
    private const MODEL = 'gpt-5';

    private const REASONING_EFFORT = 'low';

    // Keeps the FLAT source text (non-DOCX, no addressable elements) well within token limits while
    // leaving room for the system prompt, index context, and the schema output.
    private const MAX_SOURCE_TEXT_CHARS = 12000;

    /**
     * Budget for the ADDRESSABLE element catalog, which is a different problem from flat text: the
     * maintainer must now name the source elements each owned topic rests on, so an element it
     * cannot see is an element it cannot cite. Under the old 12 000-char budget a 77 586-character
     * document showed 112 of its 515 elements — 22 % — while the planner went on planning sections
     * for pages about the whole document. That is exactly how run 53 produced five concept pages
     * whose planned sections no element supported.
     *
     * Complete coverage instead, bounded two ways: a per-element snippet cap (enough to know what
     * each element is about; generation reads the full text later) and a total ceiling that still
     * truncates on whole-element boundaries for a pathological document. Deliberately large: input
     * tokens here are far cheaper than the page-generation calls this prevents, and it changes
     * neither output budgeting nor split routing (both are capped independently — see
     * MAX_CAPACITY_CONTEXT_CHARS).
     */
    private const MAX_CATALOG_CHARS = 90_000;

    /**
     * Per-element snippet budget for a call that READS the elements it was given — a routed phase-2
     * batch, or a bounded repair. Those calls see a few sections and have to judge their actual
     * substance, so they get real text.
     */
    private const MAX_CATALOG_ELEMENT_CHARS = 240;

    /**
     * Per-element snippet budget for the ORIENTATION view — the one call that sees the whole document
     * at once (phase 1). It has to place every candidate in a section and cite element keys for the
     * two document pages; it does not have to read every paragraph in full, because the routed
     * phase-2 batch that judges a section's substance still receives that section at the full
     * MAX_CATALOG_ELEMENT_CHARS. The reference document's median element is 76 characters, so more
     * than half are still shown complete at this budget, and every one of its 515 keys stays
     * addressable.
     */
    private const MAX_ORIENTATION_ELEMENT_CHARS = 90;

    /** Selects this operation's profile in config('ai_capacity.operations'). */
    private const CAPACITY_OPERATION_TYPE = 'enterprise_wiki_maintainer_decision';

    /**
     * The bounded delta-repair profile. Deliberately its own operation rather than a reuse of the
     * decision profile: a repair's output is a handful of corrected objects, so sizing it from the
     * source document's length (as the decision profile does) would reproduce the exact mistake
     * that made the old whole-decision repair unrunnable.
     */
    private const DELTA_REPAIR_CAPACITY_OPERATION_TYPE = 'enterprise_wiki_maintainer_decision_repair';

    // source_article + source_summary are always attempted. Existing page candidates are known
    // before the call and may each require a patch-target decision, so they add bounded output
    // shape pressure on top of this base. New concept/entity count remains input-driven.
    private const EXPECTED_RESULT_OBJECTS = 2;

    /**
     * The decision prompt may contain a bounded amount of current-Wiki context in addition to the
     * source document. Use that real, rendered context when budgeting output, but never let a
     * large current Wiki turn a short source document into the split flow: splitting remains a
     * decision about the source document itself. The ceiling also prevents context growth from
     * turning every call into the operation's absolute output ceiling.
     */
    private const MAX_CAPACITY_CONTEXT_CHARS = 20_000;

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
        EnterpriseWikiPlanningContext $planning,
        string $languageCode,
        ?AiCallContext $context = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: wiki AI is not enabled.'
            );
        }

        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);
        $sourceText = $planning->sourceText;

        // The split-vs-single-call DECISION must reflect the document's genuine size, not the
        // prompt text actually sent to OpenAI — userPrompt() truncates source text at
        // MAX_SOURCE_TEXT_CHARS before embedding it (an input-token concern, unrelated to output
        // budgeting), so sizing the decision off the already-truncated prompt would cap
        // rawSourceSizeChars at ~12000 regardless of how large the real document is, making
        // split_required effectively unreachable no matter how content-rich the document truly is.
        $rawSourceSizeChars = mb_strlen(trim($sourceText));
        $sourcePlan = $this->planCapacity($rawSourceSizeChars, self::EXPECTED_RESULT_OBJECTS, retryAttempt: 0);

        // A capacity retry always clamps to the exact same ceiling as the first attempt (see
        // EnterpriseWikiAiCapacityPlanner), so whenever the first attempt's own estimate already
        // exceeds that ceiling, a retry is mathematically guaranteed to help — route through the
        // split flow instead of attempting (and predictably truncating) a single oversized call.
        if ($sourcePlan->strategy === AiCapacityPlan::STRATEGY_SPLIT_REQUIRED) {
            $decoded = $this->splitCoordinator->decide($planning, $languageCode, $context);
        } else {
            // The single-call path plans the whole document in one go, so it gets the complete
            // addressable catalog and the existing pages it may need to patch.
            $existingPageCandidates = $planning->existingPageCandidates();
            $userPromptText = $this->userPrompt(
                $planning->sourceMeta,
                $sourceText,
                $planning->wikiIndex,
                $planning->figureCandidates,
                $planning->catalogElements,
                $existingPageCandidates,
            );
            $capacityContext = $this->capacityContextForDecision($rawSourceSizeChars, $userPromptText, $existingPageCandidates);

            // This emits only lengths/counts, never source or Wiki content. It makes a slow run
            // distinguishable as prompt/decision pressure, a capacity retry, or an upstream call.
            logger()->info('[PROCYNIA][WIKI_MAINTAINER_DECISION_CAPACITY] Decision capacity context prepared.', $capacityContext);

            $decoded = $this->capacityRetryExecutor->execute(
                'EnterpriseWikiMaintainerDecisionAiClient',
                $capacityContext['capacity_input_size_chars'],
                fn (int $retryAttempt): AiCapacityPlan => $this->planCapacity(
                    $capacityContext['capacity_input_size_chars'],
                    $capacityContext['expected_result_objects'],
                    $retryAttempt,
                ),
                fn (int $maxOutputTokens): array => $this->buildPayload($languageName, $userPromptText, $maxOutputTokens),
                fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolve(new AiTimeoutRequest(
                    operationType: self::CAPACITY_OPERATION_TYPE,
                    inputSizeChars: $capacityContext['capacity_input_size_chars'],
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
        return $this->planCapacity(mb_strlen(trim($sourceText)), self::EXPECTED_RESULT_OBJECTS, retryAttempt: 0)->strategy === AiCapacityPlan::STRATEGY_SPLIT_REQUIRED;
    }

    /** @return array{global_plan: array<string,mixed>, batches: list<array<string,mixed>>} */
    public function preparePersistedCandidateBatches(EnterpriseWikiPlanningContext $planning, string $languageCode, ?AiCallContext $context = null): array
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('EnterpriseWikiMaintainerDecisionAiClient: wiki AI is not enabled.');
        }

        return $this->splitCoordinator->preparePersistedCandidateBatches($planning, $languageCode, $context);
    }

    /** @param list<array<string,mixed>> $batchResults @return array<string,mixed> */
    public function mergePersistedBatchResults(array $globalPlan, array $batchResults): array
    {
        return $this->splitCoordinator->mergePersistedBatchResults($globalPlan, $batchResults);
    }

    /**
     * Ask the AI to correct ONE bounded group of objects the deterministic validators rejected —
     * a delta, never a re-decision.
     *
     * This replaces the previous whole-decision repair pass. That pass echoed the full previous
     * decision back to the model and required the complete corrected decision in return, so its
     * output size was the size of the DECISION rather than the size of the FAULT: run 51's 31 599-
     * character decision needed ~9 500 output tokens against a 9 000 ceiling and could not be
     * repaired at all, no matter how small its actual faults were (21 issues, all of them local to
     * individual concept candidates). A delta scales with the faults, which is what keeps
     * max_output_tokens a safety bound instead of a ceiling on document complexity.
     *
     * Preferred over deterministically inventing a fix in PHP for the same reason as before:
     * resolving an issue (e.g. whether a concept genuinely needs its own page) requires reading the
     * source text again, which only the model can do. What is NOT left to the model is which
     * objects may change — see EnterpriseWikiMaintainerDecisionDeltaMerger.
     *
     * @param  array{title: string, filename: string}  $sourceMeta
     * @param  array<int, array<string, mixed>>  $indexContext
     * @param  array<string, mixed>  $decision  The pre-repair decision snapshot.
     * @param  array{object_ids: list<string>, issues: list<string>}  $group  One attributed repair group
     *                                                                        (EnterpriseWikiMaintainerDecisionIssueAttributor).
     * @param  list<array<string, mixed>>  $figureCandidates  Same shape as decide()'s parameter.
     * @return array{operations: list<array<string, mixed>>, notes: ?string} Structurally valid delta.
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid.
     * @throws EnterpriseWikiAiOutputCapacityExceededException when the response is still
     *                                                         incomplete/max_output_tokens after one capacity retry.
     */
    public function repairGroup(
        EnterpriseWikiPlanningContext $planning,
        string $languageCode,
        array $decision,
        array $group,
        ?AiCallContext $context = null,
    ): array {
        if (! self::isAvailable()) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: wiki AI is not enabled.'
            );
        }

        $context ??= AiCallContext::none();
        $languageName = $this->languageName($languageCode);
        $repairPromptText = EnterpriseWikiMaintainerDecisionDeltaPrompt::userPrompt(
            $planning->sourceMeta,
            // Same context routing as a phase-2 batch: a bounded repair reads the sections its own
            // objects already cite (their evidence keys, patch-target keys and figure keys), plus
            // every section-less element, plus the overview of everything else. An object that
            // cites nothing yet — which is exactly the "this topic has no evidence" fault — routes
            // to nothing, and the whole catalog is sent instead, because that repair has to be able
            // to go looking.
            self::sourceContentBlock(
                $planning->sourceText,
                $planning->catalogElements,
                self::MAX_SOURCE_TEXT_CHARS,
                self::repairSectionKeys($decision, $group, $planning->catalogElements),
            ),
            $planning->wikiIndex,
            $decision,
            $group,
            $planning->figureCandidates,
        );
        $inputSizeChars = mb_strlen($repairPromptText);
        // The delta's output is driven by how many objects this group repairs, not by the size of
        // the decision they came from — that is the whole point of the bounded contract.
        $repairedObjects = max(1, count($group['object_ids']));

        $decoded = $this->capacityRetryExecutor->execute(
            'EnterpriseWikiMaintainerDecisionAiClient:repair_delta',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->capacityPlanner->planBatchCall(
                self::DELTA_REPAIR_CAPACITY_OPERATION_TYPE,
                self::MODEL,
                $repairedObjects,
                $inputSizeChars,
                $retryAttempt,
            ),
            fn (int $maxOutputTokens): array => $this->buildRepairPayload($languageName, $repairPromptText, $maxOutputTokens),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolveForBatch(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            ), $repairedObjects),
            $context,
        );

        try {
            return EnterpriseWikiMaintainerDecisionDeltaPrompt::parse($decoded);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException(
                'EnterpriseWikiMaintainerDecisionAiClient: repair delta failed schema validation: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Whether a repair group is small enough to be answered inside this operation's output ceiling.
     * A group that is not must fail closed rather than burn a call that cannot fit its own answer —
     * the same principle EnterpriseWikiAiCapacityRetryExecutor applies to a pointless retry.
     *
     * @param  array{object_ids: list<string>, issues: list<string>}  $group
     */
    public function repairGroupFitsOneCall(array $group): bool
    {
        return $this->capacityPlanner->planBatchCall(
            self::DELTA_REPAIR_CAPACITY_OPERATION_TYPE,
            self::MODEL,
            max(1, count($group['object_ids'])),
            0,
        )->strategy !== AiCapacityPlan::STRATEGY_SPLIT_REQUIRED;
    }

    /** How many objects one bounded repair call may take on. */
    public function maxObjectsPerRepairCall(): int
    {
        return $this->capacityPlanner->maxItemsPerBatch(self::DELTA_REPAIR_CAPACITY_OPERATION_TYPE, self::MODEL);
    }

    private function planCapacity(int $inputSizeChars, int $expectedResultObjects, int $retryAttempt): AiCapacityPlan
    {
        return $this->capacityPlanner->plan(new AiCapacityRequest(
            operationType: self::CAPACITY_OPERATION_TYPE,
            model: self::MODEL,
            inputSizeChars: $inputSizeChars,
            expectedResultObjects: $expectedResultObjects,
            retryAttempt: $retryAttempt,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $existingPageCandidates
     * @return array{raw_source_size_chars: int, rendered_prompt_size_chars: int, capacity_input_size_chars: int, capacity_context_was_capped: bool, expected_result_objects: int, existing_page_candidates: int}
     */
    private function capacityContextForDecision(int $rawSourceSizeChars, string $userPromptText, array $existingPageCandidates): array
    {
        $renderedPromptSizeChars = mb_strlen($userPromptText);
        $boundedPromptSizeChars = min($renderedPromptSizeChars, self::MAX_CAPACITY_CONTEXT_CHARS);
        $candidateCount = min(count($existingPageCandidates), EnterpriseWikiPatchCandidateService::MAX_CANDIDATES);

        return [
            'raw_source_size_chars' => $rawSourceSizeChars,
            'rendered_prompt_size_chars' => $renderedPromptSizeChars,
            'capacity_input_size_chars' => max($rawSourceSizeChars, $boundedPromptSizeChars),
            'capacity_context_was_capped' => $renderedPromptSizeChars > self::MAX_CAPACITY_CONTEXT_CHARS,
            'expected_result_objects' => self::EXPECTED_RESULT_OBJECTS + $candidateCount,
            'existing_page_candidates' => $candidateCount,
        ];
    }

    private function buildRepairPayload(string $languageName, string $repairPromptText, int $maxOutputTokens): array
    {
        $schemaBlock = EnterpriseWikiMaintainerDecisionDeltaPrompt::jsonSchema();

        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => EnterpriseWikiMaintainerDecisionDeltaPrompt::developerPrompt($languageName),
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

    /**
     * How to resolve each class of validation issue — the domain half of the repair instructions,
     * unchanged in substance from the whole-decision repair pass it outlives. Kept here, next to
     * the decision rules these corrections have to stay consistent with, and consumed by
     * EnterpriseWikiMaintainerDecisionDeltaPrompt::developerPrompt(), which adds the delta-output
     * mechanics on top.
     *
     * @return string[]
     */
    public static function repairResolutionRules(): array
    {
        return [
            'For an issue about a missing owning page (a page_title referenced in',
            'related_page_guidance, or a concept candidate decided "reference_only"/"exclude", that',
            'matches no existing or planned page): "reference_only" means the article mentions the topic',
            'and links ONWARD to the page that owns it, so it is only valid when such a page actually',
            'exists or is planned in this same decision. Never leave a "reference_only" candidate',
            'without a target. Resolve it in exactly one of these ways, choosing by what the candidate',
            'genuinely is:',
            '  a) Name an owning page that already exists in the wiki index in owning_page_title.',
            '  b) Name another page already planned in THIS decision in owning_page_title.',
            '  c) Give the candidate its own page and decide "create": add it to concept_pages',
            '     (action "create", page_id null, with its own short owned_topics) when it is a',
            '     subject-matter concept, or to entity_pages when it is an entity — an organisation,',
            '     person, product or named system. Follow the ordinary CONCEPT CANDIDATES and entity',
            '     rules when deciding this; a named platform or system belongs in entity_pages, never',
            '     in concept_pages.',
            '  d) Decide "exclude", with a justification for why the article does not need to point the',
            '     reader anywhere for it. This is the right answer for a concrete local fact,',
            '     threshold, figure or parameter (e.g. an availability percentage, a response time, a',
            '     report cadence): those belong in the article\'s own text, not on a page of their own.',
            'For a dangling related_page_guidance entry specifically, removing that entry is also a',
            'valid resolution.',
            'necessary_for_article being true does NOT mean the candidate needs its own page — it only',
            'means the article needs the topic. Never create a page just to satisfy that flag: pick',
            'whichever of (a)-(d) is genuinely right, and clear necessary_for_article if the topic turns',
            'out not to be needed after all.',
            '',
            'Keep every free-text field short and decision-oriented (see CONCEPT CANDIDATES rules) —',
            'do not turn a fix into an opportunity to lengthen unrelated fields.',
            '',
            'For an issue about a planned figure with no matching FIGURE CANDIDATES entry: remove that',
            'planned_figures entry, or correct its source_element_key to a real candidate if the intent',
            'is clear. Do not invent a new source_element_key.',
            '',
            'For an issue about a concept candidate lacking BOTH separate substantial source evidence',
            'and independent reuse value (an overfragmentation issue — e.g. several short practices under',
            'one framework each got their own page, or several candidates came from the same short',
            'passage/bullet list): change that candidate\'s decision to "reference_only" (or "exclude" if',
            'it should not be mentioned at all) and name the correct owning page — usually the',
            'overarching framework/parent page, an already-planned page, or the article — in',
            'owning_page_title. Remove its now-unwarranted concept_pages entry. Fold the content it would',
            'have covered into the owning page\'s owned_topics instead of dropping it. Keep "create" for a',
            'candidate that, on reconsidering, genuinely does have its own substantial evidence OR real',
            'reuse potential — either one is enough — but do not just flip the boolean flags to make the',
            'issue disappear without changing the underlying decision.',
            '',
            'For an issue about two candidates being near-duplicate or heavily overlapping concepts:',
            'keep "create" for at most one of them (the more general/central one) and change the other(s)',
            'to "reuse" (if the kept one already exists in the index) or "reference_only" naming the kept',
            'one as owning_page_title. Never leave two separate concept_pages entries for the same',
            'underlying concept.',
            '',
            'For an issue about the create-gate, canonical ownership or page granularity (a candidate',
            'decided "create" while classified as anything other than "independent_new_topic"; a page',
            'created under a title an existing page already carries; a relationship asserting an',
            'existing owner without naming existing_owner_page_id): do NOT resolve it by relabelling',
            'the relationship to "independent_new_topic". Resolve it by moving the substance to the',
            'existing owner — change the candidate to "reference_only" naming that page, remove the',
            'unwarranted concept_pages/entity_pages entry, and add a patch target for the owner with',
            'operation "replace" (changed substance) or "amend" (extended or specialised topic).',
            'Only keep "create" if, on reconsidering, no existing page covers this ground at all.',
            '',
            'For an issue saying superseded_substance is NOT PRESENT VERBATIM in the target area: the issue',
            'quotes that area\'s CURRENT TEXT under "The relevant target area currently states". Copy your new',
            'superseded_substance out of THAT quoted text, character for character, punctuation included.',
            'It does not have to be a whole sentence — copying a shorter exact fragment is usually the safest',
            'correction, as long as it occurs exactly once in that text and still identifies the substance',
            'being replaced. A requirement written as "... within 30 minutes, the manager shall ..." is one',
            'continuing clause, not a sentence ending after "minutes": either include what follows, or copy',
            'a shorter fragment. Do not paraphrase, and do not change replacement_substance to match a',
            'shortened quote.',
            'Each issue quotes ITS OWN target area. Use only the text quoted in that specific issue for that',
            'specific target — never wording from another issue, another page, or another target, even when',
            'they mention the same value. Two pages can state the same requirement in different words.',
            'Never resolve this by deleting the patch target, moving it to warnings, changing the',
            'relationship, or turning the replace into a create — the change still has to happen.',
            '',
            'For an issue about a patch target (missing superseded_substance or replacement_substance,',
            'missing or unknown source_element_keys, a target that is not a real page of this customer,',
            'a page_type or heading that does not match the stored page, conflicting operations on one',
            'topic, or a substance change identified without a target): correct the target itself. Fill',
            'in the substance fields concretely, use only real ids/keys from the wiki index, EXISTING',
            'PAGE CANDIDATES and SOURCE ELEMENTS, and add the missing target. Never resolve such an',
            'issue by deleting the patch target and moving the finding into warnings.',
            '',
            'For an issue about an owned topic with no source element behind it, or citing a key that is',
            'not in this document\'s catalog: either bind the topic to the real SOURCE ELEMENTS keys that',
            'carry it, or stop owning it — move it to reference_only_topics/excluded_topics, or drop it.',
            'Do NOT cite a loosely related key to make the issue disappear: the section will be written',
            'from exactly those elements, so an unrelated key produces an ungrounded section. A page with',
            'fewer, genuinely supported topics is the correct outcome.',
            '',
            ...self::ownedTopicEvidenceRules(),
            '',
            ...self::existingPageSlotRules(),
            '',
            ...self::patchTargetRules(),
            '',
            self::figurePlanningRules(),
        ];
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
            ...self::existingPageSlotRules(),
            '',
            ...self::patchTargetRules(),
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
            ...self::ownedTopicEvidenceRules(),
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
            '  has_separate_source_evidence (boolean), has_reuse_value (boolean), relationship,',
            '  existing_owner_page_id (nullable) — the last two are the granularity classification',
            '  described under CANONICAL OWNERSHIP AND PAGE GRANULARITY above, and they decide whether',
            '  "create" is permitted at all.',
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
            '  STANDALONE PAGE vs SECTION — decide this on reusability, not on caution. A term with',
            '  independent, reusable professional meaning belongs on its own page; a fragment that only',
            '  makes sense inside this document belongs as a section of the page that owns it.',
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
            '    - a sub-topic the source only names, without saying enough about it to describe the',
            '      concept itself',
            '    - a sibling concept that closely overlaps another candidate and would naturally be',
            '      merged with it',
            '  THE MAIN QUESTION for "create" is reusability: could this term carry independent,',
            '  reusable meaning across wiki pages — now or later? A subject-matter concept that other',
            '  pages would plausibly link to earns its own page the FIRST time a source document',
            '  describes it. Decide "create" when all of these hold:',
            '    1. The concept is explicitly named in the source document or in the planned',
            '       article/summary.',
            '    2. It is an independent subject-matter term, process, practice, methodology or',
            '       framework — not an arbitrary word or a passing reference mentioned once.',
            '    3. The source gives enough actual content to write a meaningful page about the',
            '       concept itself — a defining description, its purpose, how it works, or what it',
            '       governs. A short but real section is enough; a bare name in a list is not.',
            '    4. No existing page in the wiki index already covers it (check by meaning, not exact',
            '       string — e.g. "ITIL Incident Management", "Incident Management", and "Incident',
            '       management (ITIL)" are the same concept, not three different ones).',
            '    5. It is not a topic this decision itself excludes, and it is a CONCEPT rather than',
            '       an entity (an organisation, client, supplier, person or named system belongs in',
            '       entity_pages, never in concept_pages).',
            '  Set has_separate_source_evidence and has_reuse_value honestly, but treat them as',
            '  SIGNALS, not requirements:',
            '    has_separate_source_evidence: true when the source devotes its own passage or section',
            '    to this candidate. It STRENGTHENS the case for a page, but a concept described in one',
            '    solid paragraph can still deserve one — a single source document is the normal',
            '    starting point for a wiki concept, not a reason to wait.',
            '    has_reuse_value: judge POTENTIAL reuse, not documented existing reuse. A standard',
            '    professional term that future documents and pages would naturally link to has reuse',
            '    value even though this is the first document to mention it. Only a detail that is',
            '    meaningless outside this one document\'s narrative lacks it.',
            '  Being covered by the article is NOT a reason to exclude a concept. The article describes',
            '  how the concept is applied HERE — locally, with this customer\'s own thresholds, roles',
            '  and procedures — while the concept page describes the concept ITSELF, reusable across',
            '  documents. Both are wanted; they are different pages, not duplicates. Likewise, a',
            '  concept does not have to be strictly necessary for understanding the article to deserve',
            '  a page.',
            '  NORMALLY CREATE (when check 3 above is satisfied): named professional processes,',
            '  practices, disciplines, methodologies and frameworks — for example incident handling,',
            '  change management, problem management, service-level management/SLA as a practice,',
            '  configuration management, capacity management. These are illustrations of the KIND of',
            '  term that qualifies, not a fixed list to match against: apply the same judgment to',
            '  whatever discipline this document actually deals with.',
            '  NORMALLY DO NOT CREATE a concept page for: a concrete threshold, figure or measurement',
            '  ("99.5 % availability", "4 hours response time", "monthly report") — those are facts',
            '  belonging to the article; an organisation, person, product or named system — those are',
            '  entities; a single local fact or a procedure step that only exists inside this document;',
            '  a heading or bullet with no independent professional meaning; a role mentioned in',
            '  passing; a naming variant of another candidate; or a term a suitable page already',
            '  covers.',
            '  Do NOT create a concept page when: it is mentioned only once in passing with nothing',
            '  said about it; it is not an independent concept; a suitable page already exists; it is',
            '  excluded; or there is not enough information to give it a clear scope — put that content',
            '  as a section/owned_topic under whichever page (an overarching framework, the article,',
            '  another concept) already is or should be its natural owner instead.',
            '  When several candidates all come from the same short bullet list and the source says',
            '  little beyond naming each one (e.g. a list of practices under one framework), do not',
            '  turn each into its own page merely because each has a name — set',
            '  has_separate_source_evidence to false for those, and name the framework/parent page as',
            '  owning_page_title. This targets bare list items only: a candidate the source genuinely',
            '  describes in its own section is not part of such a cluster, even when neighbouring',
            '  sections describe sibling concepts.',
            '  There is no quota and no minimum: zero concept_pages remains the correct outcome for a',
            '  document that genuinely contains no reusable concepts. Never invent or pad candidates to',
            '  reach a number.',
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
    /**
     * Fase 8K-2 — the patch contract and the two rules that govern when a NEW canonical page is
     * allowed at all. Shared verbatim by the single-call developer prompt, the repair prompt, and
     * (via EnterpriseWikiMaintainerDecisionSplitCoordinator) both split-flow prompts, so the policy
     * cannot drift between them.
     *
     * Deliberately domain-free: no field names, thresholds, page titles or subject terms from any
     * one document. Everything below is stated in terms of existing/new substance and relationships.
     *
     * @return string[]
     */
    public static function patchTargetRules(): array
    {
        return [
            'patch_targets — EXISTING pages this document changes:',
            '  Zero or more. This is how you say "an existing page already states something this',
            '  document changes". It addresses a page by its numeric id, so it can target ANY existing',
            '  page — article, summary, concept or entity alike. It never changes a page\'s type,',
            '  title or slug.',
            '  Use the EXISTING PAGE CANDIDATES block for this: judge each candidate\'s actual current',
            '  content against the new source document.',
            '  Each entry has: target_page_id, target_page_title, target_page_type, target_topic,',
            '  target_heading (nullable), relationship, operation, superseded_substance (nullable),',
            '  replacement_substance (nullable), source_element_keys, preserve_topics, reason.',
            '  target_page_id: the id shown for that page in the wiki index or in EXISTING PAGE',
            '  CANDIDATES. Never invent one, and never use a page belonging to any other customer.',
            '  target_page_type: state the type as shown. It is verified against the stored page and',
            '  is never used to change it.',
            '  target_topic: a short descriptor of WHICH topic on that page is affected — not the',
            '  whole page. target_heading: the exact existing heading that topic sits under when there',
            '  is one, otherwise null. A heading you name must already exist on that page.',
            '  The EXISTING PAGE CANDIDATES block also shows page_has_subsections and',
            '  valid_target_headings for the CURRENT version of each candidate. If page_has_subsections',
            '  is true, target_heading MUST be exactly one of valid_target_headings. If the page has',
            '  no sub-sections, valid_target_headings is empty and target_heading MUST be null.',
            '  operation is exactly one of:',
            '    "replace": the existing substance is expressly superseded by this document. Requires',
            '    superseded_substance (what the page states today) AND replacement_substance (what',
            '    takes its place). Requires relationship "substance_changed".',
            '    "amend": the existing topic stands but is extended or made more precise. Requires',
            '    replacement_substance; leave superseded_substance null. Requires relationship',
            '    "topic_extended" or "topic_specialized".',
            '    "preserve": you examined this page and it must be left UNTOUCHED. Carries no',
            '    substance and no source_element_keys. Requires relationship "reference_only". Use it',
            '    to record that leaving a candidate alone was a decision, not an oversight.',
            '  superseded_substance: COPY AN EXACT SUBSTRING out of that page\'s current text as shown in',
            '  EXISTING PAGE CANDIDATES — character for character, including its punctuation. Do NOT',
            '  paraphrase it, summarise it, or tidy it into a sentence.',
            '  It does not have to be a whole sentence — copy only as much as is needed to identify the',
            '  substance being replaced, and make sure that text occurs exactly once in the section you',
            '  are targeting.',
            '  A very common mistake: the page states a CLAUSE that continues ("... within 30 minutes,',
            '  the manager shall ..."), and it gets quoted as though the sentence ended at the value',
            '  ("... within 30 minutes."). That is not a substring of the page and will be rejected —',
            '  either copy the clause including what follows, or copy a shorter exact fragment.',
            '  replacement_substance: state the new substance plainly and concretely. Never leave a',
            '  change described only inside reason.',
            '  source_element_keys: for every "replace" and "amend", name the source elements that',
            '  AUTHORISE the change, using keys from the SOURCE ELEMENTS catalog. At least one; name',
            '  several when several carry it. Never invent a key.',
            '  preserve_topics: TARGET-LOCAL. Name the substance inside THIS target\'s own section/topic',
            '  area that must survive the edit — the neighbouring statements the change sits next to and',
            '  must not take with it. Do NOT list unrelated sections elsewhere on the page: everything',
            '  outside this target\'s area is preserved by default, and leaving something out of this',
            '  list is never permission to delete it. Keep the list short and local.',
            '  MULTIPLE TARGETS ARE EXPECTED: when the same existing substance is stated on several',
            '  pages, return one target per page. Do not pick a single page and leave the others',
            '  outdated. The same page may also appear more than once with a different target_topic,',
            '  AND the same target_topic may appear more than once on one page when that page states it',
            '  under DIFFERENT headings — a page can repeat the same requirement in two sections, and',
            '  each occurrence needs its own target with its own target_heading, or the ones you leave',
            '  out stay outdated. Only a repeat of the same topic under the SAME heading is a duplicate.',
            '  NEVER put an actionable change into warnings. If you notice that an existing page is',
            '  outdated, that belongs here as a patch target — warnings is only for concerns nobody',
            '  can act on structurally.',
            '',
            'CANONICAL OWNERSHIP AND PAGE GRANULARITY (decide this BEFORE deciding any create):',
            '  A new page is NOT justified by the topic being new. Two separate rules apply.',
            '  RULE 1 — no duplicate canonical ownership: if substance already has an owning page,',
            '  never create a second page for it. Target the owner instead.',
            '  RULE 2 — granularity: before creating anything, ask whether the topic naturally belongs',
            '  on an existing page. Classify every concept candidate with relationship, exactly one of:',
            '    "substance_changed": it changes substance an existing page states. Name that page in',
            '    existing_owner_page_id AND add a patch target with operation "replace" for it.',
            '    "topic_extended": it adds substance within the same topic an existing page owns. Name',
            '    that page in existing_owner_page_id and add a patch target with operation "amend".',
            '    "topic_specialized": it is a variant, sub-topic or specialisation of a topic an',
            '    existing page owns. Name that page and amend it — a sub-topic belongs on the page',
            '    that owns the broader topic, even when the source gives it its own name and detail.',
            '    "reference_only": it is mentioned but owns no new substance here.',
            '    "independent_new_topic": it is new AND semantically self-standing — it would carry',
            '    reusable meaning on its own page, independent of any existing page.',
            '  decision "create" is ALLOWED ONLY with relationship "independent_new_topic". For every',
            '  other relationship, create is wrong: patch or amend the existing owner instead.',
            '  New terminology is not independence. A term can be new to the wiki, have its own name',
            '  and its own detail in this document, and still belong as a section of an existing page.',
            '  Ask: does an existing page already cover this ground? If yes, extend that page.',
            '  existing_owner_page_id: the numeric id of the existing page that owns the topic, or',
            '  null when the topic genuinely has no existing owner.',
            '  When the owner is a page THIS decision is creating rather than an existing one, leave',
            '  existing_owner_page_id null and name that page in owning_page_title. That is the correct',
            '  answer for a variant or sub-topic of a concept this same document introduces — do NOT',
            '  relabel it "independent_new_topic" to get around the missing id, which would create a',
            '  second canonical page for substance the new page already owns. "substance_changed" is the',
            '  exception: superseding substance requires an existing page, and a structured patch target',
            '  for it.',
            '',
            'DOCUMENT PAGES ARE NOT CANONICAL OWNERS:',
            '  source_article and source_summary represent THE DOCUMENT — its identity, date, decisions',
            '  and wording. Creating them stays correct even when every factual change the document',
            '  carries belongs to an existing canonical page. That is not duplicate ownership.',
            '  But they must not become the owner of that faglige substance: keep their owned_topics on',
            '  what the document itself is and decides, put the substance change on the existing owner',
            '  as a patch target, and link between them.',
        ];
    }

    /**
     * The owned-topic evidence contract. Shared verbatim by the single-call developer prompt, the
     * split-flow batch prompt and the bounded delta-repair prompt, so a planner can never be told
     * one thing while EnterpriseWikiPlannedTopicEvidenceValidator enforces another — the failure
     * mode that produced run 51's unrepairable owning-page issues.
     *
     * @return string[]
     */
    public static function ownedTopicEvidenceRules(): array
    {
        return [
            '  EVERY owned topic is EVIDENCE-BOUND. Each owned_topics entry is an object with:',
            '    topic: the short topic name, which becomes this page\'s section heading verbatim.',
            '    source_element_keys: the keys from the SOURCE ELEMENTS catalog this page will explain',
            '    the topic FROM. At least one, copied exactly, never invented — the same keys and the',
            '    same catalog patch_targets and planned_figures already cite.',
            '  A topic you cannot bind to a real source element is a topic this document does not',
            '  support: do NOT own it. Put it in reference_only_topics or excluded_topics instead, or',
            '  leave it out. Never name a plausible-sounding section (a "pros and cons", "roles and',
            '  responsibilities" or "approval" section) that the source itself does not cover, and never',
            '  cite an unrelated key just to satisfy the requirement — the page will be generated from',
            '  exactly the evidence you name here and nothing else.',
            '  HARD LIMIT: at most '.EnterpriseWikiMaintainerDecisionPrompt::MAX_OWNED_TOPIC_EVIDENCE_KEYS
                .' keys per topic, in the order they should be read. The',
            '  section is built from exactly those; every later key is discarded and never reaches the',
            '  page. Name the few elements that DEFINE the topic, not everything near it — a topic that',
            '  truly needs more is two topics: split it.',
            '  The same element MAY support topics on more than one page when it genuinely covers both;',
            '  within one page, keep each topic on its own evidence so the sections do not repeat.',
            '  A page you CREATE must own at least one evidence-bound topic. Owning nothing is not a way',
            '  out of this rule: if no topic on this page can be bound to real source elements, the page',
            '  itself is not warranted — decide "reference_only" or "exclude" for the candidate instead.',
        ];
    }

    /**
     * The slot rule for EXISTING pages, shared by the single-call prompt, both split-flow prompts
     * and the bounded repair prompt.
     *
     * A typed slot is a claim about identity, and identity of an existing page belongs to the
     * database: EnterpriseWikiMaintainerDecisionApplyService will not retype a page named by id,
     * and EnterpriseWikiPlannedPageSlotValidator now says so at decision time. Run 55 is what it
     * costs when the prompt leaves this implicit — an existing entity page was named through
     * concept_pages, and the run died in the middle of applying.
     *
     * @return string[]
     */
    public static function existingPageSlotRules(): array
    {
        return [
            'NAMING AN EXISTING PAGE (page_id + action "update"):',
            '  The wiki index gives every existing page its own page_type. Name an existing page ONLY',
            '  through the slot for the type it already has: a "concept" page in concept_pages, an',
            '  "entity" page in entity_pages. A page never changes type because a decision put it in a',
            '  different slot — such a decision is rejected, not applied.',
            '  Name the same existing page in ONE slot only, once. If you also need to change what that',
            '  page states, that is a patch target, not a second slot entry.',
            '  An existing page of any other type (article, summary) is never named through these slots',
            '  at all — address it with a patch target.',
        ];
    }

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

    private function userPrompt(array $sourceMeta, string $sourceText, array $indexContext, array $figureCandidates = [], array $sourceElements = [], array $existingPageCandidates = []): string
    {
        $title = (string) ($sourceMeta['title'] ?? '');
        $filename = (string) ($sourceMeta['filename'] ?? '');

        // Compact JSON: same facts, ~19 % fewer characters than pretty-printed. Representation
        // only — no field is added, removed or reinterpreted.
        $indexJson = $indexContext !== []
            ? (string) json_encode($indexContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'No pages yet.';

        return implode("\n", [
            'SOURCE METADATA:',
            "Title: {$title}",
            "Original file: {$filename}",
            '',
            self::sourceContentBlock($sourceText, $sourceElements, self::MAX_SOURCE_TEXT_CHARS),
            '',
            'EXISTING WIKI INDEX ('.count($indexContext).' pages):',
            $indexJson,
            '',
            self::figureCandidatesBlock($figureCandidates),
            ...($existingPageCandidates !== []
                ? ['', self::existingPageCandidatesBlock($existingPageCandidates)]
                : []),
        ]);
    }

    /**
     * Element types carried by the SOURCE ELEMENTS catalog (Fase 8J-1B). Images are deliberately
     * excluded: FIGURE CANDIDATES is already their own correct, separate contract, and forcing an
     * image description into a prose catalog would send the same thing twice in two shapes.
     */
    private const SOURCE_CATALOG_ELEMENT_TYPES = [
        EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_PARAGRAPH,
        EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_LIST_ITEM,
        EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
    ];

    /**
     * Narrows an EnterpriseWikiDocumentSourceElementService::inspect() element list to the ones
     * the SOURCE ELEMENTS catalog carries, preserving the service's own deterministic order.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public static function sourceCatalogElements(array $elements): array
    {
        return array_values(array_filter(
            $elements,
            static fn (array $element): bool => in_array(
                $element['source_element_type'] ?? null,
                self::SOURCE_CATALOG_ELEMENT_TYPES,
                true,
            ),
        ));
    }

    /**
     * Renders the "SOURCE ELEMENTS" prompt block (Fase 8J-1B) shared by the single-call, repair and
     * split-flow prompts — the same addressable source elements page generation already receives
     * (EnterpriseWikiDocumentSourceElementService::inspect()), so the maintainer can reason about,
     * and later refer to, the concrete parts of the document a decision rests on instead of an
     * undifferentiated wall of text.
     *
     * This block REPLACES the flat "SOURCE TEXT" block whenever it is non-empty: the catalog is the
     * same document content, only addressable, so sending both would send the whole document twice.
     * When no structured elements exist (non-DOCX, unparsable, or a document whose file is gone),
     * the caller keeps rendering flat SOURCE TEXT exactly as before — see sourceContentBlock().
     *
     * Format is deliberately compact rather than JSON: one `[key] (type · section)` header line per
     * element followed by its own text. Measured on a real document this costs ~11% more characters
     * than the flat text it replaces, where pretty JSON would cost roughly 2.7x.
     *
     * @param  list<array<string, mixed>>  $sourceElements
     */
    public static function sourceElementsBlock(
        array $sourceElements,
        int $maxChars,
        ?int $maxElementChars = null,
        array $sectionKeysByLabel = [],
        bool $includeTypeLabels = true,
    ): string {
        $lines = [];
        $used = 0;
        $rendered = 0;
        $truncatedElements = 0;
        $currentSection = null;

        foreach ($sourceElements as $element) {
            $key = trim((string) ($element['source_element_key'] ?? ''));
            $type = trim((string) ($element['source_element_type'] ?? ''));
            $text = trim((string) ($element['reference_text'] ?? ''));

            if ($key === '' || $type === '' || $text === '') {
                continue;
            }

            // Per-element snippet cap: keeps EVERY element of a large document addressable within a
            // bounded prompt, instead of showing the first few in full and hiding the rest. The key
            // is what the maintainer cites; the full text is what generation reads.
            if ($maxElementChars !== null && mb_strlen($text) > $maxElementChars) {
                $text = mb_substr($text, 0, $maxElementChars).' […]';
                $truncatedElements++;
            }

            $section = EnterpriseWikiDocumentSectionMap::sectionLabel($element);

            // Elements arrive in document order, so the section is printed once per run of
            // elements that share it rather than repeated on every line — measured on a real
            // document, repeating it cost about a third of the flat text's own size. The section's
            // routable key is printed with it (see EnterpriseWikiDocumentSectionMap): a planning
            // call cites sections the same deterministic way it cites element keys.
            $sectionKey = $section !== '' ? ($sectionKeysByLabel[$section] ?? null) : null;
            $sectionLine = $section !== '' && $section !== $currentSection
                ? "\n# ".($sectionKey !== null ? "[{$sectionKey}] " : '').$section
                : null;
            $entry = $includeTypeLabels ? "[{$key}] ({$type}) ".$text : "[{$key}] ".$text;
            $cost = mb_strlen($entry) + 1 + ($sectionLine !== null ? mb_strlen($sectionLine) + 1 : 0);

            // Truncate on whole-element boundaries only: a half-rendered element would give the
            // model a key whose text it cannot actually see.
            if ($used + $cost > $maxChars) {
                $lines[] = '[... '.(count($sourceElements) - $rendered).' further element(s) truncated ...]';

                break;
            }

            if ($sectionLine !== null) {
                $lines[] = $sectionLine;
                $currentSection = $section;
            }

            $lines[] = $entry;
            $used += $cost;
            $rendered++;
        }

        if ($rendered === 0) {
            return '';
        }

        return implode("\n", array_merge(
            [
                'SOURCE ELEMENTS ('.$rendered.' of '.count($sourceElements).'):',
                'The source document, split into its addressable elements: '
                    .($includeTypeLabels ? '[key] (type) text' : '[key] text').', grouped',
                'under the "# " section they belong to. Keys are stable identifiers for this document',
                'version — refer to them when reasoning about which parts of the source a page rests on,',
                'and never invent one.',
                ...($truncatedElements > 0
                    ? ['A "[…]" marks an element whose text is shown shortened here; the key still refers to the whole element.']
                    : []),
            ],
            $lines,
        ));
    }

    /**
     * The source-content block for a maintainer prompt: the addressable SOURCE ELEMENTS catalog
     * when structured elements are available, otherwise the flat SOURCE TEXT exactly as before.
     *
     * One or the other — never both. Backward compatible by construction: every caller that passes
     * no source elements gets byte-identical output to the pre-8J-1B prompt.
     *
     * @param  list<array<string, mixed>>  $sourceElements
     */
    public static function sourceContentBlock(
        string $sourceText,
        array $sourceElements,
        int $maxChars,
        ?array $sectionKeys = null,
        bool $orientationView = false,
    ): string {
        $catalogElements = self::sourceCatalogElements($sourceElements);
        $map = EnterpriseWikiDocumentSectionMap::build($catalogElements);
        $sectionKeysByLabel = [];

        foreach ($map['sections'] as $section) {
            $sectionKeysByLabel[$section['label']] = $section['key'];
        }

        // Context routing: when the caller names the sections this call actually needs, the catalog
        // carries those sections in full text plus every section-less element — and the overview
        // block below still lists every section that exists, so nothing becomes silently invisible.
        // A caller that names nothing (Phase A, or any call that cannot route) keeps the whole
        // catalog exactly as before.
        $routed = $sectionKeys !== null;
        $renderedElements = $routed
            ? EnterpriseWikiDocumentSectionMap::elementsForSections($catalogElements, $sectionKeys, $map)
            : $catalogElements;

        // The catalog gets its own, much larger budget with a per-element snippet cap: every element
        // has to be citable for the owned-topic evidence contract, and $maxChars sizes flat text.
        $catalog = self::sourceElementsBlock(
            $renderedElements,
            max($maxChars, self::MAX_CATALOG_CHARS),
            $orientationView ? self::MAX_ORIENTATION_ELEMENT_CHARS : self::MAX_CATALOG_ELEMENT_CHARS,
            $sectionKeysByLabel,
            // The type label is dropped from the orientation view only, and only because it is
            // already there: element keys are minted per type (paragraph-0, listitem-0, tbl0-row0),
            // so "(paragraph)" after "[paragraph-12]" restates the key. A call that READS elements
            // keeps it — there the redundancy is cheap and the reader is judging substance.
            includeTypeLabels: ! $orientationView,
        );

        // The overview exists to disclose what a call was NOT given. When every section is included
        // — which is exactly the orientation view — each of its lines would read "full text below",
        // restating the "# [sec-N] label" headers the catalog already prints in document order.
        if ($catalog !== '' && $map['sections'] !== [] && ($routed || ! $orientationView)) {
            $catalog = EnterpriseWikiDocumentSectionMap::overviewBlock(
                $map,
                $routed ? $sectionKeys : EnterpriseWikiDocumentSectionMap::sectionKeys($map),
            )."\n\n".$catalog;
        }

        if ($catalog !== '') {
            return $catalog;
        }

        $text = trim($sourceText);

        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars)."\n[... text truncated ...]";
        }

        return implode("\n", [
            'SOURCE TEXT:',
            $text !== '' ? $text : '(empty)',
        ]);
    }

    /**
     * Renders the "EXISTING PAGE CANDIDATES" prompt block (Fase 8K-1): the real current content of
     * the few existing Wiki pages this source document plausibly touches
     * (EnterpriseWikiPatchCandidateService).
     *
     * The Wiki index this prompt already carries gives one 200-character excerpt per page — enough
     * to know a page exists, never enough to see a concrete threshold, deadline or rule sitting in
     * the middle of it. Run 25 showed the consequence: a document that explicitly revised existing
     * requirements produced only new pages, because the values it superseded were invisible to the
     * decision.
     *
     * Deliberately a separate block from SOURCE ELEMENTS: that one is the NEW document, this one is
     * what the Wiki already says. Conflating them would make it impossible for the maintainer — or
     * a later reader of this prompt — to tell new source material from existing knowledge.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    public static function existingPageCandidatesBlock(array $candidates): string
    {
        if ($candidates === []) {
            return '';
        }

        $parts = [
            'EXISTING PAGE CANDIDATES ('.count($candidates).' pages):',
            'These Wiki pages already exist for this customer and may hold knowledge the source',
            'document above affects. Some are mentioned directly in that document; others are closely',
            'related to those pages in the existing Wiki and can hold current knowledge the document',
            'revises without naming the page itself. What follows is their CURRENT content — the',
            'authoritative Wiki knowledge as it stands today, not part of the new source document.',
            '',
            'Use this to judge whether the new document changes something these pages already state:',
            '- A page here is a candidate, not a verdict — being listed does NOT mean it must change.',
            '- Judge each page on its actual content shown below against the new source document, not',
            '  on whether that document happens to mention the page by name.',
            '- Only treat existing content as superseded when the new source document actually says so;',
            '  leave a candidate untouched when its substance is not affected.',
            '- Never copy an existing page\'s content into the new source article or summary; refer to',
            '  the page instead, exactly as the page-responsibility rules require.',
            '- These pages are context for your create/update/reuse/reference_only decisions.',
        ];

        foreach ($candidates as $candidate) {
            $parts[] = '';
            $parts[] = sprintf(
                '[page %d] %s',
                (int) ($candidate['page_id'] ?? 0),
                (string) ($candidate['title'] ?? ''),
            );
            $parts[] = sprintf(
                'type: %s | slug: %s | current version: %d (v%d)%s',
                (string) ($candidate['page_type'] ?? ''),
                (string) ($candidate['slug'] ?? ''),
                (int) ($candidate['page_version_id'] ?? 0),
                (int) ($candidate['version_number'] ?? 0),
                ($candidate['truncated'] ?? false) ? ' | content truncated' : '',
            );
            $parts[] = sprintf(
                'page_has_subsections: %s | valid_target_headings: %s',
                ! empty($candidate['page_has_subsections']) ? 'true' : 'false',
                (string) json_encode(array_values((array) ($candidate['valid_target_headings'] ?? [])), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            $parts[] = 'content:';
            $parts[] = (string) ($candidate['content'] ?? '');
        }

        return implode("\n", $parts);
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
            $parts[] = (string) json_encode($figure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $parts);
    }

    /**
     * The sections a bounded repair receives in full text — derived from the source elements the
     * group's own objects already cite, never from their wording.
     *
     * @param  array<string, mixed>  $decision
     * @param  array{object_ids: list<string>, issues: list<string>, context_object_ids?: list<string>}  $group
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<string>|null Null means "not routable — send the whole catalog".
     */
    private static function repairSectionKeys(array $decision, array $group, array $sourceElements): ?array
    {
        $map = EnterpriseWikiDocumentSectionMap::build(self::sourceCatalogElements($sourceElements));
        $elementKeys = [];

        foreach (array_unique(array_merge($group['object_ids'], $group['context_object_ids'] ?? [])) as $objectId) {
            $object = EnterpriseWikiMaintainerDecisionObjectIndex::object($decision, $objectId) ?? [];

            foreach (EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries($object['owned_topics'] ?? []) as $topic) {
                $elementKeys = array_merge($elementKeys, $topic['source_element_keys']);
            }

            foreach ((array) ($object['planned_figures'] ?? []) as $figure) {
                if (is_array($figure) && ($figure['source_element_key'] ?? '') !== '') {
                    $elementKeys[] = (string) $figure['source_element_key'];
                }
            }

            $elementKeys = array_merge($elementKeys, array_values(array_filter(
                (array) ($object['source_element_keys'] ?? []),
                static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
            )));
        }

        $sectionKeys = EnterpriseWikiDocumentSectionMap::sectionsForElementKeys($elementKeys, $map);

        return $sectionKeys === [] ? null : $sectionKeys;
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
