<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Exceptions\EnterpriseWikiMaintainerDecisionBatchFailedException;
use App\Exceptions\EnterpriseWikiMaintainerDecisionPhaseFailedException;
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

    /** Phase 1A returns exactly two pages. */
    private const DOCUMENT_PLAN_EXPECTED_RESULT_OBJECTS = 2;

    /**
     * Phase 1B returns the mentions, the entity pages and the patch targets. Sized like the old
     * whole-phase call rather than smaller: the mention list is the one unbounded part of phase 1,
     * and the ceiling is a safety bound, never a target.
     */
    private const CANDIDATE_PLAN_EXPECTED_RESULT_OBJECTS = 2;

    public function __construct(
        private readonly EnterpriseWikiAiCapacityPlanner $capacityPlanner,
        private readonly EnterpriseWikiAiCapacityRetryExecutor $capacityRetryExecutor,
        private readonly EnterpriseWikiMaintainerDecisionMerger $merger,
        private readonly EnterpriseWikiAiRequestTimeoutPolicy $timeoutPolicy,
        private readonly EnterpriseWikiMaintainerDecisionGlobalPlanMerger $globalPlanMerger,
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
        EnterpriseWikiPlanningContext $planning,
        string $languageCode,
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();

        $prepared = $this->preparePersistedCandidateBatches($planning, $languageCode, $context);
        $globalPlan = $prepared['global_plan'];

        if ($prepared['batches'] === []) {
            return $this->merger->merge($globalPlan, []);
        }

        $batchResults = [];

        foreach ($prepared['batches'] as $batch) {
            try {
                // The SAME entrypoint the queued worker calls. There is one phase-2 implementation;
                // this loop only decides that the batches run here instead of on the queue.
                $batchResults[] = $this->decidePersistedCandidateBatch(
                    $planning,
                    $languageCode,
                    $globalPlan,
                    $batch['mentions'],
                    $batch['batch_number'],
                    $context,
                );
            } catch (Throwable $e) {
                throw new EnterpriseWikiMaintainerDecisionBatchFailedException(
                    $batch['batch_number'],
                    $batch['total_batches'],
                    count($batch['mentions']),
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

    /** @param list<array<string,mixed>> $batchResults @return array<string,mixed> */
    public function mergePersistedBatchResults(array $globalPlan, array $batchResults): array
    {
        return $this->merger->merge($globalPlan, $batchResults);
    }

    /**
     * Execute only the global-plan portion of the existing split decision and return the
     * complete, independently executable candidate-batch inputs.
     *
     * @return array{global_plan: array<string,mixed>, batches: list<array<string,mixed>>}
     */
    public function preparePersistedCandidateBatches(
        EnterpriseWikiPlanningContext $planning,
        string $languageCode,
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();
        // Identical phase-1 call to the in-process split flow above: same context object, same
        // view. Passing fewer facts here is exactly the divergence this consolidation removes.
        $globalPlan = EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan(
            $this->decideGlobalPlan($planning, $this->languageName($languageCode), $context),
        );
        $mentions = $globalPlan['concept_candidate_mentions'];
        $sizes = $this->capacityPlanner->planBatchCount(self::CAPACITY_OPERATION_TYPE, self::MODEL, count($mentions));
        $offset = 0;
        $batches = [];
        $total = count($sizes);

        foreach ($sizes as $index => $size) {
            $batches[] = [
                'global_plan' => $globalPlan,
                'mentions' => array_slice($mentions, $offset, $size),
                'batch_number' => $index + 1,
                'total_batches' => $total,
            ];
            $offset += $size;
        }

        return ['global_plan' => $globalPlan, 'batches' => $batches];
    }

    /** @return array<string, mixed> */
    /**
     * PHASE 1, as two sequential calls.
     *
     * One call generated ~6 400 tokens and took 126–152 s against a per-CALL timeout of 180 s. The
     * duration is generation-bound and its spread is provider-side: two identical prompts differed
     * by 20 %. Halving what each call must GENERATE is therefore the only lever that moves the
     * margin, and because the timeout is per call, sequential execution captures all of it — no
     * concurrency needed, and none introduced here.
     *
     * 1A decides the document's two pages and the figures. 1B decides the candidates, entity pages
     * and patch targets. The halves are field-disjoint (their schemas partition the global plan
     * exactly), and nothing downstream ever joined them: phase 2 is shown only the TITLES of the
     * pages phase 1 planned, never their owned topics.
     *
     * Sequencing buys one extra thing worth having: 1B can be told what 1A actually named, so an
     * entity page colliding with the article or summary is prevented rather than repaired. That is
     * passed as ordinary context, not as a dependency — pass null and 1B still produces a valid
     * half, which is what a future parallel version would do.
     *
     * @throws EnterpriseWikiMaintainerDecisionPhaseFailedException
     */
    private function decideGlobalPlan(
        EnterpriseWikiPlanningContext $planning,
        string $languageName,
        ?AiCallContext $context = null,
    ): array {
        $context ??= AiCallContext::none();
        $startedAt = microtime(true);

        try {
            $documentPlan = $this->decideDocumentPlan($planning, $languageName, $context);
        } catch (Throwable $e) {
            throw EnterpriseWikiMaintainerDecisionPhaseFailedException::documentPlan($e);
        }

        // The second call sees a truthful, shrinking job budget — the first one has already spent
        // part of it. EnterpriseWikiAiRequestTimeoutPolicy is what decides whether that still leaves
        // room for a full-length call; this only stops it from being lied to.
        $candidateContext = $context->withElapsedSeconds(microtime(true) - $startedAt);

        try {
            $candidatePlan = $this->decideCandidatePlan($planning, $languageName, $documentPlan, $candidateContext);
        } catch (Throwable $e) {
            throw EnterpriseWikiMaintainerDecisionPhaseFailedException::candidatePlan($e);
        }

        $globalPlan = $this->globalPlanMerger->merge($documentPlan, $candidatePlan);

        Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_SPLIT] Phase 1 assembled from two calls.', [
            'entity_pages' => count($globalPlan['entity_pages']),
            'concept_candidate_mentions' => count($globalPlan['concept_candidate_mentions']),
            'patch_targets' => count($globalPlan['patch_targets']),
            'phase_1_seconds' => round(microtime(true) - $startedAt, 1),
        ]);

        return $globalPlan;
    }

    /** PHASE 1A — the document's own two pages and their figures. */
    private function decideDocumentPlan(
        EnterpriseWikiPlanningContext $planning,
        string $languageName,
        AiCallContext $context,
    ): array {
        // Both halves work from the same PlanningContext and the same compact orientation view of
        // the catalog — the split changes what each call OUTPUTS, never what it may know.
        $userPromptText = $this->documentPlanUserPrompt($planning);
        $inputSizeChars = mb_strlen($userPromptText);

        return $this->capacityRetryExecutor->execute(
            'EnterpriseWikiMaintainerDecisionAiClient:document_plan',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->capacityPlanner->planGlobalPlanCall(
                self::CAPACITY_OPERATION_TYPE,
                self::MODEL,
                self::DOCUMENT_PLAN_EXPECTED_RESULT_OBJECTS,
                $inputSizeChars,
                $retryAttempt,
            ),
            fn (int $maxOutputTokens): array => $this->buildPhasePayload(
                EnterpriseWikiMaintainerDecisionPrompt::documentPlanSchema(),
                $this->documentPlanDeveloperPrompt($languageName),
                $userPromptText,
                $maxOutputTokens,
            ),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolveForGlobalPlan(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
            // Parsed inside the executor, so an UNUSABLE response (control bytes, invalid
            // JSON) meets the one shared corrupt-response policy — one retry, then hard
            // fail — while a readable-but-wrong plan still propagates to the validators.
            fn (array $decoded): array => EnterpriseWikiMaintainerDecisionPrompt::parseDocumentPlan($decoded),
        );
    }

    /**
     * PHASE 1B — candidates, entity pages, patch targets, warnings.
     *
     * @param  array<string, mixed>|null  $documentPlan  What 1A named, so 1B does not duplicate it.
     */
    private function decideCandidatePlan(
        EnterpriseWikiPlanningContext $planning,
        string $languageName,
        ?array $documentPlan,
        AiCallContext $context,
    ): array {
        $userPromptText = $this->candidatePlanUserPrompt($planning, $documentPlan);
        $inputSizeChars = mb_strlen($userPromptText);

        return $this->capacityRetryExecutor->execute(
            'EnterpriseWikiMaintainerDecisionAiClient:candidate_plan',
            $inputSizeChars,
            fn (int $retryAttempt): AiCapacityPlan => $this->capacityPlanner->planGlobalPlanCall(
                self::CAPACITY_OPERATION_TYPE,
                self::MODEL,
                self::CANDIDATE_PLAN_EXPECTED_RESULT_OBJECTS,
                $inputSizeChars,
                $retryAttempt,
            ),
            fn (int $maxOutputTokens): array => $this->buildPhasePayload(
                EnterpriseWikiMaintainerDecisionPrompt::candidatePlanSchema(),
                $this->candidatePlanDeveloperPrompt($languageName),
                $userPromptText,
                $maxOutputTokens,
            ),
            fn (AiCapacityPlan $plan, ?int $remainingJobBudgetSeconds) => $this->timeoutPolicy->resolveForGlobalPlan(new AiTimeoutRequest(
                operationType: self::CAPACITY_OPERATION_TYPE,
                inputSizeChars: $inputSizeChars,
                chosenMaxOutputTokens: $plan->chosenMaxOutputTokens,
                remainingJobBudgetSeconds: $remainingJobBudgetSeconds,
            )),
            $context,
            // Parsed inside the executor, so an UNUSABLE response (control bytes, invalid
            // JSON) meets the one shared corrupt-response policy — one retry, then hard
            // fail — while a readable-but-wrong plan still propagates to the validators.
            fn (array $decoded): array => EnterpriseWikiMaintainerDecisionPrompt::parseCandidatePlan($decoded),
        );
    }

    /**
     * @param  array<string, mixed>  $globalPlan
     * @param  list<array<string, mixed>>  $batchMentions
     * @return array<string, mixed>
     */
    private function decideCandidateBatch(
        EnterpriseWikiPlanningContext $planning,
        string $languageName,
        array $globalPlan,
        array $batchMentions,
        int $batchIndex,
        ?AiCallContext $context = null,
    ): array {
        // Phase B's view: the sections THIS batch's own candidates were placed in, the section
        // overview, the section-less elements, the index, phase 1's planned pages, its own mentions
        // and the figure candidates. Never the whole catalog unless routing is not possible.
        $userPromptText = $this->candidateBatchUserPrompt(
            $planning->sourceMeta,
            $planning->sourceText,
            $planning->wikiIndex,
            $globalPlan,
            $batchMentions,
            $planning->figureCandidates,
            $planning->catalogElements,
        );
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
            // Parsed inside the executor, so an UNUSABLE response (control bytes, invalid
            // JSON) meets the one shared corrupt-response policy — one retry, then hard
            // fail — while a readable-but-wrong plan still propagates to the validators.
            fn (array $decoded): array => EnterpriseWikiMaintainerDecisionPrompt::parseCandidateBatch($decoded),
        );
    }

    /**
     * THE phase-2 entrypoint — the queued worker and the in-process loop both come through here,
     * so there is one implementation, one retry policy and one set of failure semantics.
     *
     * @return array<string,mixed> A PARSED candidate batch.
     */
    public function decidePersistedCandidateBatch(
        EnterpriseWikiPlanningContext $planning,
        string $languageCode,
        array $globalPlan,
        array $mentions,
        int $batchNumber,
        ?AiCallContext $context = null,
    ): array {
        // The queued batch and the in-process batch are still two implementations, but they are no
        // longer two contexts: both go through decideCandidateBatch() with the same facts.
        return $this->decideCandidateBatch($planning, $this->languageName($languageCode), $globalPlan, $mentions, $batchNumber - 1, $context);
    }

    /**
     * @param  array<string, mixed>  $schemaBlock
     */
    private function buildPhasePayload(array $schemaBlock, string $developerPrompt, string $userPromptText, int $maxOutputTokens): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [['type' => 'input_text', 'text' => $developerPrompt]],
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

    /**
     * PHASE 1A developer prompt — the document's own two pages, and the figures.
     *
     * Carries every rule that governs those fields and nothing that governs the other half: no
     * candidate criteria, no entity rules, no patch-target contract. The page-responsibility and
     * owned-topic-evidence rules DO appear in both halves, because both halves plan pages that own
     * topics — that is one contract stated to two calls, not duplication that could be removed.
     */
    private function documentPlanDeveloperPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer making a planning decision. Output language: {$languageName}.",
            'This source document is large enough that the decision is split into several phases. This',
            'is PHASE 1A, and it decides exactly one thing: the two pages that represent THIS DOCUMENT.',
            'Concept candidates, entity pages and patch targets are decided by other phases — do not',
            'plan them, do not list them, and do not let them influence what these two pages own.',
            '',
            'DECISION RULES:',
            'source_article — one article page per source document:',
            '  action "create": no matching article for this source exists in the wiki index.',
            '  action "update": an article covering this source already exists in the index.',
            '',
            'source_summary — one summary page per source document:',
            '  Same create/update logic as source_article.',
            '',
            'no_action_reason: set if the source is empty, a duplicate, or should not produce a page.',
            '',
            'SLUG AND TITLE RULES:',
            '  proposed_slug: lowercase, hyphens only. No dots, spaces, or file extensions.',
            '  title: must not be a raw filename. Never include .pdf, .docx, etc.',
            '  source_article and source_summary slugs: append a short unique suffix (e.g. "tittel-ab1c2d").',
            '',
            ...$this->pageResponsibilityInstructionLines(),
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figurePlanningRules(),
            '',
            'Return JSON only. No text outside JSON.',
        ]);
    }

    /**
     * PHASE 1B developer prompt — candidates, entity pages, patch targets, warnings.
     *
     * Figures are absent on purpose, in the schema as well as here: phase 1A owns the whole-document
     * figure contract, and a figure planned by two phases onto two pages is a conflict no merge can
     * resolve honestly.
     */
    private function candidatePlanDeveloperPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer making a planning decision. Output language: {$languageName}.",
            'This source document is large enough that the decision is split into several phases. This',
            'is PHASE 1B: decide the shared ENTITY pages, and IDENTIFY (but do not yet fully evaluate)',
            'the concept candidates. A later phase decides each candidate\'s full',
            'disposition in smaller batches — do not try to do that work here.',
            'The pages representing THIS DOCUMENT — its article and its summary — were decided by an',
            'earlier phase and are listed below. Never plan them again under any slot.',
            'Do not plan figures. Figures belong to the document pages and are already decided.',
            'Do not plan changes to existing pages. Judging what an existing page states requires its',
            'current content, which this call is not given — the index below shows only that a page',
            'exists. A change to existing substance is decided later, by the phase that reads it.',
            '',
            'DECISION RULES:',
            'entity_pages — shared entity pages (organisations, clients, suppliers):',
            '  Zero or more. action "create" + page_id null: entity does not exist yet.',
            '  action "update" + integer page_id: entity page exists; use its ID from the index.',
            '',
            ...EnterpriseWikiMaintainerDecisionAiClient::existingPageSlotRules(),
            '',
            'warnings: non-blocking concerns (empty text, language mismatch, ambiguous title, etc.).',
            '',
            'SLUG AND TITLE RULES:',
            '  proposed_slug: lowercase, hyphens only. No dots, spaces, or file extensions.',
            '  title: must not be a raw filename. Never include .pdf, .docx, etc.',
            '  entity slugs: stable, no suffix — same page matched across sources.',
            '',
            ...$this->pageResponsibilityInstructionLines(),
            '',
            'CONCEPT CANDIDATE MENTIONS (identification only — do not decide anything about them yet):',
            '  List every central concept candidate this source document points to: an independent',
            '  subject-matter term, process, practice, methodology, or framework — not a mere mention of',
            '  a random word, and not the document\'s own filename, an illustration title, or a purely',
            '  local label used only inside this document.',
            '  Each concept_candidate_mentions entry has ONLY: name, concept_type, mentioned_context (a',
            '  short phrase naming WHERE it is mentioned, e.g. "document title", "section 2" — never a',
            '  quote or paraphrase of what the document says), and section_keys.',
            '  section_keys: copy the [sec-N] keys shown on the SOURCE ELEMENTS section headings for',
            '  every section this candidate actually appears in — one key when it belongs to a single',
            '  section, several when the document genuinely treats it in more than one place. Copy them',
            '  exactly and never invent one. This is ONLY used to decide which sections the next phase',
            '  is shown in full text; it is not a decision about the candidate, and that phase still',
            '  reads and judges the text itself. Leaving it empty is allowed and means "cannot place',
            '  it" — that phase then simply receives the whole document, so an empty list costs',
            '  context, it never loses it.',
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
            'YOU MAY NOT BE SEEING THE WHOLE DOCUMENT. SOURCE ELEMENTS carries the sections your own',
            'candidates were placed in, in full text, plus every element that belongs to no section.',
            'DOCUMENT SECTION OVERVIEW lists every section that exists and marks which ones you were',
            'given. Judge your candidates on the text you have; when a judgement would depend on a',
            'section you were not given, say so in the candidate\'s justification and choose the more',
            'conservative disposition rather than assuming the document is silent.',
            '',
            ...$this->conceptCandidateDecisionCriteriaLines(),
            '',
            'A candidate decided "reuse" references the EXISTING page that covers it through the slot for',
            'that page\'s own type — concept_pages for a "concept" page, entity_pages for an "entity" page',
            '(the wiki index shows each page\'s type). Never move an existing page into the other slot.',
            '',
            ...EnterpriseWikiMaintainerDecisionAiClient::existingPageSlotRules(),
            '',
            'For each candidate decided "create", add a matching entry to concept_pages (action "create",',
            'page_id null, proposed_slug, reason, and owned_topics/reference_only_topics/',
            'excluded_topics/related_page_guidance — same rules as any other concept page).',
            '',
            // The batch is where concept pages are actually created, so it needs the WHOLE
            // responsibility policy, not a one-line reference to it. Carrying only the evidence half
            // here is what the first runtime verification of this contract exposed: told to bind
            // every owned topic but never told that a page must own topics at all, the model created
            // 14 concept pages with no owned_topics whatsoever — technically valid, and pages with no
            // scope. Both halves, in the same prompt, always.
            ...$this->pageResponsibilityInstructionLines(),
            '',
            ...EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules(),
            '',
            'PATCH TARGETS IN THIS PHASE: phase 1 already returned the targets it could see. Add a target',
            'here ONLY for a candidate in this batch that you classify "substance_changed",',
            '"topic_extended" or "topic_specialized" — that classification is decided in this phase, so',
            'phase 1 could not have produced its target. Do not restate phase 1\'s targets, and do not',
            'target pages unrelated to this batch\'s own candidates.',
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
            ...EnterpriseWikiMaintainerDecisionAiClient::ownedTopicEvidenceRules(),
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
            '  A related_page_guidance target may also be this run\'s source_article or source_summary',
            '  when the relationship is source-level detail/orientation; in that case copy the exact',
            '  source_article/source_summary title. Never use source_article/source_summary as a',
            '  concept_candidates.owning_page_title or as a substitute for a needed concept/entity page.',
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
            '',
            'STANDALONE PAGE vs SECTION — most candidates are NOT worth a new page. Prefer sections over',
            'new pages when the source material does not justify a standalone reusable concept.',
            'A standalone page is normally RIGHT for: a genuinely independent concept, a clearly bounded',
            'named process, a durable entity, a topic with its own substantial source material, or a',
            'topic that would naturally be reused/linked from several OTHER wiki pages.',
            'A standalone page is normally WRONG for: a subsection/heading, a bullet-list item, a role',
            'mentioned briefly, an activity or process step, a practice mentioned briefly under an',
            'overarching framework (this belongs as a section of the framework\'s own page), a sub-topic',
            'without its own substantial source evidence, or a sibling concept that closely overlaps',
            'another candidate and would naturally be merged with it.',
            '',
            'Each candidate also reports relationship and existing_owner_page_id (nullable) — the',
            'granularity classification described under CANONICAL OWNERSHIP AND PAGE GRANULARITY. It',
            'gates "create" absolutely: "create" is only permitted with relationship',
            '"independent_new_topic". A candidate that changes, extends or specialises a topic an',
            'existing page owns belongs on that page as a patch target, never on a new page.',
            '',
            'Each candidate also reports has_separate_source_evidence (boolean) and has_reuse_value',
            '(boolean). Decide "create" only when ALL of the following hold:',
            '  1. The concept is explicitly named in the source document or planned article/summary.',
            '  2. It is an independent subject-matter term, process, practice, or framework — not an',
            '     arbitrary word or a passing reference mentioned once.',
            '  3. It is necessary to understand the article\'s professional context.',
            '  4. The article or summary otherwise needs to point the reader onward to it.',
            '  5. No existing page in the wiki index already covers it (check by meaning, not exact',
            '     string — e.g. "ITIL Incident Management" and "Incident Management" are the same',
            '     concept, not two different ones).',
            '  6. It is not a topic this decision itself excludes.',
            '  7. has_separate_source_evidence is true: the source gives THIS candidate its own',
            '     substantial passage or section, not just a brief mention alongside siblings (one line',
            '     in a shared bullet list is NOT separate substantial evidence).',
            '  8. has_reuse_value is true: the concept would plausibly be linked to from other,',
            '     unrelated wiki pages later, not just a detail specific to this document\'s narrative.',
            'Do NOT create a concept page when: it is mentioned only once in passing; it is not an',
            'independent concept; a suitable page already exists; it only belongs inside another page as',
            'a reference_only topic; it is excluded; a separate page would only duplicate the article',
            'without adding independent value; there is not enough information to give it a clear scope;',
            'it is only a document name, filename, illustration title, or local label; or either',
            'has_separate_source_evidence or has_reuse_value would be false — put that content as a',
            'section/owned_topic under its natural owner (an overarching framework, the article, another',
            'concept) instead.',
            'When several candidates all come from the same short passage or bullet list, do not turn',
            'each one into its own page merely because each has a name — set has_separate_source_evidence',
            'to false for each one unless the source genuinely elaborates on it separately.',
            'When two candidates are near-duplicates or heavily overlapping, decide "create" for at most',
            'one of them and "reuse"/"reference_only" (naming the kept one as owning_page_title) for the',
            'rest — never create separate pages for both.',
            'Consistency requirement: a candidate decided "reference_only" MUST name a page in',
            'owning_page_title that either already exists in the wiki index, is an entity page planned in',
            'phase 1, or is itself created by another candidate in THIS batch. source_article and',
            'source_summary are never valid owning pages — they describe the document, not the subject',
            'matter. When the topic is only explained by this document and no concept/entity page owns it',
            '(a local role, threshold, figure, log or procedure step), decide "exclude" instead: its',
            'content belongs in the article\'s own text. If it genuinely is a reusable concept with no',
            'owner yet, decide "create".',
            'KEEP FREE-TEXT FIELDS SHORT — this is a planning decision, not a report:',
            '  independent_reason: ONE short sentence (roughly 15 words or fewer).',
            '  mentioned_context: a short phrase naming WHERE it is mentioned — never a quote.',
            '  justification: ONE short sentence — do not repeat independent_reason or mentioned_context.',
        ];
    }

    /**
     * PHASE 1A's view: the compact orientation catalog, the Wiki index (to tell create from update)
     * and the figure candidates it alone may plan.
     */
    private function documentPlanUserPrompt(EnterpriseWikiPlanningContext $planning): string
    {
        return implode("\n", [
            ...$this->sourceHeaderLines($planning),
            $this->orientationCatalog($planning),
            '',
            'EXISTING WIKI INDEX ('.count($planning->wikiIndex).' pages):',
            $this->indexContextJson($planning->wikiIndex),
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figureCandidatesBlock($planning->figureCandidates),
        ]);
    }

    /**
     * PHASE 1B's view: the same orientation catalog and Wiki index, no figure candidates at all, and
     * — when phase 1A has already run — the two page identities it must not claim.
     *
     * That last block is why the split is sequential rather than parallel: it PREVENTS the one real
     * collision between the halves instead of repairing it afterwards. Passing null is supported and
     * falls back to the merger's deterministic guard, which is what a parallel version would rely on.
     *
     * @param  array<string, mixed>|null  $documentPlan
     */
    private function candidatePlanUserPrompt(EnterpriseWikiPlanningContext $planning, ?array $documentPlan): string
    {
        return implode("\n", [
            ...$this->sourceHeaderLines($planning),
            $this->orientationCatalog($planning),
            '',
            'EXISTING WIKI INDEX ('.count($planning->wikiIndex).' pages):',
            $this->indexContextJson($planning->wikiIndex),
            ...($documentPlan !== null
                ? [
                    '',
                    'ALREADY DECIDED DOCUMENT PAGES (phase 1A — do not plan these again):',
                    (string) json_encode([
                        'source_article' => $documentPlan['source_article']['title'] ?? null,
                        'source_summary' => $documentPlan['source_summary']['title'] ?? null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'An entity page may never carry either of those titles, slugs or page ids.',
                ]
                : []),
        ]);
    }

    /** @return list<string> */
    private function sourceHeaderLines(EnterpriseWikiPlanningContext $planning): array
    {
        return [
            'SOURCE METADATA:',
            'Title: '.(string) ($planning->sourceMeta['title'] ?? ''),
            'Original file: '.(string) ($planning->sourceMeta['filename'] ?? ''),
            '',
        ];
    }

    /**
     * Both phase-1 calls get EVERY element key — each has to place or cite anything in the document
     * — but as an ORIENTATION view: shorter snippets, no type label the key already carries, no
     * section overview of sections it was all given anyway. The routed phase-2 batch that actually
     * judges a section's substance still receives that section in full. Measured on the reference
     * document: 79 726 -> 42 600 catalog characters, zero elements dropped, all 515 keys addressable.
     */
    private function orientationCatalog(EnterpriseWikiPlanningContext $planning): string
    {
        return EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock(
            $planning->sourceText,
            $planning->catalogElements,
            self::MAX_SOURCE_TEXT_CHARS,
            null,
            orientationView: true,
        );
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
        array $sourceElements = [],
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
            // Context routing: this batch gets full text for the sections its OWN candidates were
            // placed in (plus every section-less element), and the overview block lists every other
            // section by name so nothing is silently invisible. A batch whose candidates carry no
            // resolvable section falls back to the complete catalog — see sectionKeysForMentions().
            EnterpriseWikiMaintainerDecisionAiClient::sourceContentBlock(
                $sourceText,
                $sourceElements,
                self::MAX_SOURCE_TEXT_CHARS,
                $this->sectionKeysForMentions($batchMentions, $sourceElements),
            ),
            '',
            'EXISTING WIKI INDEX ('.count($indexContext).' pages):',
            $this->indexContextJson($indexContext),
            '',
            // The source article/summary are shown so a batch knows they exist and does not plan a
            // duplicate — NOT as owning-page targets. Calling them "valid owning pages" here is
            // exactly what made run 51 unrepairable: 15 of its 21 validation issues were candidates
            // pointing owning_page_title at the source article, which
            // EnterpriseWikiMaintainerDecisionConsistencyValidator rejects by design (an article
            // describes the document, never owns the subject matter). The prompt and the validator
            // now state one and the same policy.
            'ALREADY PLANNED PAGES FROM PHASE 1 (do not redecide these):',
            (string) json_encode($plannedPages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Only the entity_pages above are valid owning_page_title targets, together with the pages in',
            'the wiki index and any page created by a candidate in THIS batch. source_article and',
            'source_summary are NEVER valid as owning_page_title.',
            '',
            'CANDIDATES TO DECIDE IN THIS BATCH ('.count($batchMentions).'):',
            (string) json_encode($batchMentions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figureCandidatesBlock($figureCandidates),
        ]);
    }

    /**
     * The sections this batch receives in full text: the union of every section its own candidates
     * were placed in by phase 1.
     *
     * Returns null — meaning "no routing, send the whole catalog" — whenever nothing resolves: a
     * stored plan predating section keys, a phase-1 response that placed nothing, or keys that do
     * not exist in this document version. Routing may narrow a batch's view only when the routing
     * information is actually there; it must never narrow it by accident.
     *
     * @param  list<array<string, mixed>>  $batchMentions
     * @param  list<array<string, mixed>>  $sourceElements
     * @return list<string>|null
     */
    private function sectionKeysForMentions(array $batchMentions, array $sourceElements): ?array
    {
        $map = EnterpriseWikiDocumentSectionMap::build(
            EnterpriseWikiMaintainerDecisionAiClient::sourceCatalogElements($sourceElements)
        );
        $known = array_flip(EnterpriseWikiDocumentSectionMap::sectionKeys($map));
        $keys = [];

        foreach ($batchMentions as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            foreach (EnterpriseWikiMaintainerDecisionPrompt::mentionSectionKeys($mention) as $key) {
                if (isset($known[$key])) {
                    $keys[$key] = true;
                }
            }
        }

        if ($keys === []) {
            Log::info('[PROCYNIA][WIKI_MAINTAINER_DECISION_SPLIT] Batch context not routable — sending the complete catalog.', [
                'candidates' => count($batchMentions),
                'document_sections' => count($map['sections']),
            ]);

            return null;
        }

        return array_keys($keys);
    }

    /**
     * Compact rather than pretty-printed: identical facts, ~19 % fewer characters. The wiki index is
     * consumed as data, not read as prose, so indentation buys nothing here. Representation only —
     * every field, value and ordering is unchanged.
     */
    private function indexContextJson(array $indexContext): string
    {
        return $indexContext !== []
            ? (string) json_encode($indexContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
