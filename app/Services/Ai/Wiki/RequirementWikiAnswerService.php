<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates a Wiki-based expert answer for an already-extracted requirement (Fase 9 — "Generer
 * Wiki-svar"), using the Karpathy Wiki-research flow (search → read → navigate → synthesize, see
 * RequirementWikiResearchService) combined with a balanced answer model: the customer's own Wiki is
 * the first-priority source, but the answer may also draw on recognized professional best practice
 * where the Wiki has genuine gaps — this is not treated as a defect. What matters is that the two
 * are kept visibly distinct for a human reviewer.
 *
 * Orchestration across four collaborators:
 * - RequirementWikiResearchService: search/read/navigate/stop (unchanged from the prior Fase 9
 *   correction — this class does not alter that flow's principles).
 * - RequirementWikiAnswerAiClient: writes the expert answer as sections, each citing the Wiki
 *   pages that ground it (possibly none, for best-practice content).
 * - RequirementWikiAlignmentAiClient: classifies, section by section, how each section relates to
 *   the Wiki actually read — aligned / partially_aligned / best_practice / possible_conflict. A
 *   missing Wiki detail is never treated as a conflict; only a real apparent contradiction is.
 * - RequirementWikiAnswerRevisionAiClient: used for at most ONE automatic revision pass, and only
 *   for sections flagged possible_conflict — never for best_practice or partially_aligned sections,
 *   and never more than once per generation (no repair loop).
 *
 * coverage_status is no longer self-reported by the answer-writing AI: it is computed here, from
 * the validated alignment result, and describes coverage IN THE WIKI specifically — not a judgement
 * of whether the answer itself is good or complete. A 'none' coverage answer is still a fully
 * usable expert draft; answer_text is never forced to null because of coverage_status alone.
 *
 * Deliberately a fully separate flow from the existing answer-draft engine
 * (RequirementAnswerDraftService): no new requirement extraction, no parallel requirement table,
 * no dependency on KnowledgeItem/KnowledgeItemChunk, no reuse of the existing Knowledge Base/RAG
 * retrieval, and no writes to answer_draft_* — every Wiki answer is persisted on its own
 * SavedNoticeAiRequirementWikiAnswer row.
 *
 * Claims/source references keep their original role (see EnterpriseWikiClaim/
 * EnterpriseWikiSourceReference) — a documentation, quality, and anti-fabrication layer for
 * concrete commitments, and now also the fact-checking basis for the alignment/revision steps. They
 * are never the sole permitted knowledge source, and a missing claim never by itself invalidates a
 * professionally sound best-practice statement.
 *
 * Prompt layering (AI-to-Wiki consolidation): generate()'s optional $caseInstructions parameter
 * (the owning customer's shared ai_instructions) is passed straight through to
 * RequirementWikiAnswerAiClient::generateAnswer() as a subordinate style directive — governs HOW the
 * answer is phrased (tone/terminology/style/capitalization), never WHAT it claims. It is never used
 * for the revision pass (reviseConflictingSectionsOnce()): that step's only job is resolving a
 * detected possible_conflict against the Wiki text itself, not re-applying style preferences.
 *
 * generate()'s optional $requirementUserPrompt parameter carries the same one-off, per-generation
 * guidance the legacy RequirementAnswerDraftService accepted as user_answer_prompt (feature-parity
 * gap closed during the "make Wiki answers the sole operational draft" consolidation) — applied after
 * $caseInstructions, with the same subordinate, style-only status, never forwarded to the revision
 * pass for the same reason.
 *
 * updateAnswerText() lets a user hand-edit the generated answer text in place, mirroring
 * RequirementAnswerDraftService::updateAnswerDraft() — the operative answer becomes editable content,
 * not a read-only AI output, once generated.
 */
class RequirementWikiAnswerService
{
    public const ENGINE_VERSION = 'wiki_reader_alignment_v3';

    // Deterministic per-section provenance (v0.9 provenance-gap closure) — computed here from each
    // used page's actual claim content_origin, never self-reported by an AI client. A DIFFERENT axis
    // from alignment_status: alignment_status judges whether the section's substance is supported by
    // the Wiki's own text; provenance_type judges whether the concrete facts it states were actually
    // customer-documented (source_based) or a professional addition (best_practice/mixed).
    public const PROVENANCE_SOURCE_BASED = 'source_based';

    public const PROVENANCE_BEST_PRACTICE = 'best_practice';

    public const PROVENANCE_MIXED = 'mixed';

    public function __construct(
        private readonly RequirementWikiResearchService $researchService,
        private readonly RequirementWikiAnswerAiClient $answerAiClient,
        private readonly RequirementWikiAlignmentAiClient $alignmentAiClient,
        private readonly RequirementWikiAnswerRevisionAiClient $revisionAiClient,
    ) {}

    /**
     * Purpose: Generate (or regenerate) the Wiki-based expert answer for one requirement.
     * Inputs: The requirement, the customer id it belongs to (never trusted from the requirement
     *         itself — always the owning SavedNotice's own customer_id, resolved by the caller),
     *         the language to answer in, the user triggering generation, the owning SavedNotice's
     *         free-text case_instructions (the customer's shared ai_instructions, applying to every
     *         case that customer owns) — governs tone/terminology/style only,
     *         see RequirementWikiAnswerAiClient::generateAnswer() for the exact priority contract —
     *         and an optional one-off requirementUserPrompt for this specific generation only (same
     *         subordinate, style-only status as case_instructions, applied after it).
     * Returns: The persisted (created or updated in place) Wiki-answer row.
     * Side effects: Writes exactly one row to saved_notice_ai_requirement_wiki_answers, upserted
     *               by saved_notice_ai_requirement_id. Never touches saved_notice_ai_requirements
     *               itself or any answer_draft_* column. Makes 2 or 3 OpenAI calls for the answer
     *               and alignment steps (plus research navigation calls), and one further call only
     *               when a possible conflict triggers the single automatic revision pass.
     */
    public function generate(
        SavedNoticeAiRequirement $requirement,
        int $customerId,
        string $languageCode,
        ?int $userId = null,
        ?string $caseInstructions = null,
        ?string $requirementUserPrompt = null,
    ): SavedNoticeAiRequirementWikiAnswer {
        $context = $this->researchService->research($requirement, $customerId, $languageCode);

        $claimTextsByPageId = $this->claimTextsByPageIdAndOrigin($context['pages']);
        $pagesForAi = $this->pagesForAi($context['pages'], $claimTextsByPageId);

        $answer = $this->answerAiClient->generateAnswer(
            (string) ($requirement->requirement_identifier ?? ''),
            (string) $requirement->requirement_text,
            $pagesForAi,
            $languageCode,
            $caseInstructions,
            $requirementUserPrompt,
        );
        $answerSections = $answer['answer_sections'];

        $alignmentBefore = $context['pages'] === []
            ? $this->deterministicBestPracticeAlignment($answerSections)
            : $this->alignmentAiClient->assessAlignment(
                (string) ($requirement->requirement_identifier ?? ''),
                (string) $requirement->requirement_text,
                $answerSections,
                $pagesForAi,
                $languageCode,
            );

        [$answerSections, $alignmentFinal, $revisionInfo] = $this->reviseConflictingSectionsOnce(
            $requirement,
            $answerSections,
            $alignmentBefore,
            $pagesForAi,
            $context,
            $languageCode,
        );

        $coverageStatus = $this->computeCoverageStatus($alignmentFinal);
        $hasPossibleConflict = $this->hasPossibleConflict($alignmentFinal);
        $missingSummary = $this->computeMissingSummary($alignmentFinal, $coverageStatus, $context);
        $usedPageIds = $this->unionUsedPageIds($answerSections);
        $answerText = implode("\n\n", array_column($answerSections, 'text'));
        $answerFigures = $this->answerFigures($answerSections, $pagesForAi);
        $provenanceBySectionKey = $this->computeSectionsProvenance($answerSections, $pagesForAi);

        return $this->persist($requirement, [
            'coverage_status' => $coverageStatus,
            'answer_text' => $answerText,
            'answer_figures' => $answerFigures,
            'missing_summary' => $missingSummary,
            'sources' => $this->sourcesPayload($context['pages'], $usedPageIds),
            'model' => 'gpt-4.1-mini',
            'research_trace' => ['research' => $context, 'answer' => ['answer_sections' => $answerSections]],
            'alignment_trace' => $this->buildAlignmentTrace($answerSections, $alignmentBefore, $alignmentFinal, $revisionInfo, $coverageStatus, $hasPossibleConflict, $provenanceBySectionKey),
            'has_possible_conflict' => $hasPossibleConflict,
            'engine_version' => self::ENGINE_VERSION,
            'stale_at' => null,
            'stale_reason' => null,
            'stale_context' => null,
        ], $userId);
    }

    /**
     * Purpose: Persist a hand-edit of an already-generated Wiki answer's text — mirrors
     *          RequirementAnswerDraftService::updateAnswerDraft() so the Wiki answer is an editable
     *          operative draft, not read-only AI output.
     * Inputs: The requirement and the edited answer text.
     * Returns: The persisted (refreshed) Wiki-answer row.
     * Side effects: Updates answer_text on the existing saved_notice_ai_requirement_wiki_answers row.
     *
     * @throws RuntimeException when the text is empty/whitespace-only, or when the requirement has
     *                          no Wiki answer yet to edit (generate() must run at least once first).
     */
    public function updateAnswerText(SavedNoticeAiRequirement $requirement, string $answerText): SavedNoticeAiRequirementWikiAnswer
    {
        $normalizedText = trim(str_replace(["\r\n", "\r"], "\n", $answerText));

        if ($normalizedText === '') {
            throw new RuntimeException('Wiki answer text cannot be empty.');
        }

        $wikiAnswer = $requirement->wikiAnswer()->first();

        if ($wikiAnswer === null) {
            throw new RuntimeException('Cannot edit a Wiki answer that has not been generated yet.');
        }

        return DB::transaction(function () use ($wikiAnswer, $normalizedText): SavedNoticeAiRequirementWikiAnswer {
            $wikiAnswer->forceFill(['answer_text' => $normalizedText])->save();

            return $wikiAnswer->refresh();
        });
    }

    /**
     * Purpose: Run the single allowed automatic revision pass when the alignment assessment found
     *          a possible conflict, then re-assess alignment once so the persisted result reflects
     *          the revised text. Sections that are not in conflict are never touched or re-sent.
     * Inputs: The requirement, the answer's sections, the pre-revision alignment assessment, the
     *         pages available for grounding, the research context (used only to gate the
     *         alignment-vs-deterministic choice consistently with the initial assessment), and the
     *         language code.
     * Returns: [finalAnswerSections, finalAlignment, revisionInfo]. When no section is flagged
     *          possible_conflict, this is a no-op: the inputs are returned unchanged with
     *          revisionInfo = {attempted: false, section_keys: []}.
     * Side effects: Up to 2 further OpenAI calls (one revision + one re-assessment), only when a
     *               conflict was found and the customer's Wiki was actually read for this run.
     *
     * @param  list<array{key: string, heading: string, text: string, used_page_ids: list<int>}>  $answerSections
     * @param  list<array{section_key: string, alignment_status: string, supporting_page_ids: list<int>, supported_points: list<string>, uncovered_points: list<string>, conflict_summary: ?string, review_note: ?string}>  $alignmentBefore
     * @param  list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>  $pagesForAi
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array{attempted: bool, section_keys: list<string>}}
     */
    private function reviseConflictingSectionsOnce(
        SavedNoticeAiRequirement $requirement,
        array $answerSections,
        array $alignmentBefore,
        array $pagesForAi,
        array $context,
        string $languageCode,
    ): array {
        $conflictByKey = [];

        foreach ($alignmentBefore as $assessment) {
            if ($assessment['alignment_status'] === RequirementWikiAlignmentAiClient::STATUS_POSSIBLE_CONFLICT) {
                $conflictByKey[$assessment['section_key']] = $assessment;
            }
        }

        // A missing Wiki page set (nothing was ever read) can never itself be revised against — a
        // conflict cannot be detected or fixed without a real Wiki fasit, so this path is skipped
        // entirely (and, per deterministicBestPracticeAlignment(), never produces a conflict anyway).
        if ($conflictByKey === [] || $context['pages'] === []) {
            return [$answerSections, $alignmentBefore, ['attempted' => false, 'section_keys' => []]];
        }

        $sectionsByKey = [];

        foreach ($answerSections as $section) {
            $sectionsByKey[$section['key']] = $section;
        }

        $sectionsToRevise = [];

        foreach ($conflictByKey as $key => $assessment) {
            if (! isset($sectionsByKey[$key])) {
                continue;
            }

            $sectionsToRevise[] = [
                ...$sectionsByKey[$key],
                'conflict_summary' => $assessment['conflict_summary'] ?? 'Mulig motstrid med virksomhetens dokumenterte Wiki-kunnskap.',
            ];
        }

        if ($sectionsToRevise === []) {
            return [$answerSections, $alignmentBefore, ['attempted' => false, 'section_keys' => []]];
        }

        $revised = $this->revisionAiClient->reviseSections(
            (string) ($requirement->requirement_identifier ?? ''),
            (string) $requirement->requirement_text,
            $sectionsToRevise,
            $pagesForAi,
            $languageCode,
        );

        $revisedAnswerSections = array_map(
            static function (array $section) use ($revised): array {
                $fix = $revised[$section['key']] ?? null;

                if ($fix === null) {
                    return $section;
                }

                return [
                    'key' => $section['key'],
                    'heading' => $fix['heading'] !== '' ? $fix['heading'] : $section['heading'],
                    'text' => $fix['text'],
                    'used_page_ids' => $fix['used_page_ids'],
                    // The revision pass rewrites wording to remove a possible conflict; it is not
                    // given the figure contract and has no opinion on illustration. The section
                    // keeps the figure it was generated with.
                    'figure_refs' => $section['figure_refs'] ?? [],
                ];
            },
            $answerSections,
        );

        $revisionInfo = ['attempted' => true, 'section_keys' => array_values(array_keys($revised))];

        $finalAlignment = $this->alignmentAiClient->assessAlignment(
            (string) ($requirement->requirement_identifier ?? ''),
            (string) $requirement->requirement_text,
            $revisedAnswerSections,
            $pagesForAi,
            $languageCode,
        );

        return [$revisedAnswerSections, $finalAlignment, $revisionInfo];
    }

    /**
     * Purpose: Compute the persisted Wiki-coverage status from the validated alignment result.
     * Inputs: The final (post-revision, if any) alignment assessment for every answer section.
     * Returns: 'full' when every gradable section is aligned; 'partial' when there is a genuine mix
     *          of Wiki-grounded and best-practice/partial content; 'none' when nothing is
     *          meaningfully Wiki-grounded. Sections flagged possible_conflict are excluded from this
     *          computation (they never alone decide full/partial/none — see has_possible_conflict).
     * Side effects: None.
     *
     * @param  list<array{alignment_status: string}>  $alignmentSections
     */
    private function computeCoverageStatus(array $alignmentSections): string
    {
        $gradable = array_values(array_filter(
            $alignmentSections,
            static fn (array $section): bool => in_array($section['alignment_status'], [
                RequirementWikiAlignmentAiClient::STATUS_ALIGNED,
                RequirementWikiAlignmentAiClient::STATUS_PARTIALLY_ALIGNED,
                RequirementWikiAlignmentAiClient::STATUS_BEST_PRACTICE,
            ], true),
        ));

        if ($gradable === []) {
            return SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE;
        }

        $allAligned = true;
        $hasAlignedOrPartial = false;
        $hasPartialOrBestPractice = false;

        foreach ($gradable as $section) {
            $status = $section['alignment_status'];

            if ($status !== RequirementWikiAlignmentAiClient::STATUS_ALIGNED) {
                $allAligned = false;
            }

            if (in_array($status, [RequirementWikiAlignmentAiClient::STATUS_ALIGNED, RequirementWikiAlignmentAiClient::STATUS_PARTIALLY_ALIGNED], true)) {
                $hasAlignedOrPartial = true;
            }

            if (in_array($status, [RequirementWikiAlignmentAiClient::STATUS_PARTIALLY_ALIGNED, RequirementWikiAlignmentAiClient::STATUS_BEST_PRACTICE], true)) {
                $hasPartialOrBestPractice = true;
            }
        }

        if ($allAligned) {
            return SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL;
        }

        if ($hasAlignedOrPartial && $hasPartialOrBestPractice) {
            return SavedNoticeAiRequirementWikiAnswer::COVERAGE_PARTIAL;
        }

        return SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE;
    }

    /** @param  list<array{alignment_status: string}>  $alignmentSections */
    private function hasPossibleConflict(array $alignmentSections): bool
    {
        foreach ($alignmentSections as $section) {
            if ($section['alignment_status'] === RequirementWikiAlignmentAiClient::STATUS_POSSIBLE_CONFLICT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Purpose: Build the persisted missing_summary from the alignment result's uncovered_points.
     * Inputs: The final alignment sections, the computed coverage status, and the research context
     *         (used only for a fixed fallback message when literally no Wiki pages were read).
     * Returns: Null when coverage is 'full'; otherwise the distinct uncovered points joined for
     *          display, or a fixed "no Wiki information available" message when there is nothing
     *          more specific to say.
     * Side effects: None.
     *
     * @param  list<array{uncovered_points: list<string>}>  $alignmentSections
     */
    private function computeMissingSummary(array $alignmentSections, string $coverageStatus, array $context): ?string
    {
        if ($coverageStatus === SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL) {
            return null;
        }

        $points = [];

        foreach ($alignmentSections as $section) {
            foreach ($section['uncovered_points'] as $point) {
                $points[$point] = true;
            }
        }

        if ($points === []) {
            return $context['pages'] === [] ? $this->noPagesReadMessage($context) : null;
        }

        return implode(' ', array_keys($points));
    }

    /**
     * Purpose: Deterministically classify every section as best_practice when no Wiki page was ever
     *          read — there is no Wiki content to align against, so a real AI alignment call would
     *          be wasted, and no possible_conflict can be detected without a fasit to conflict with.
     * Inputs: The answer's sections.
     * Returns: One best_practice assessment per section, with empty supporting data.
     * Side effects: None.
     *
     * @param  list<array{key: string}>  $answerSections
     * @return list<array{section_key: string, alignment_status: string, supporting_page_ids: list<int>, supported_points: list<string>, uncovered_points: list<string>, conflict_summary: ?string, review_note: ?string}>
     */
    private function deterministicBestPracticeAlignment(array $answerSections): array
    {
        return array_map(static fn (array $section): array => [
            'section_key' => $section['key'],
            'alignment_status' => RequirementWikiAlignmentAiClient::STATUS_BEST_PRACTICE,
            'supporting_page_ids' => [],
            'supported_points' => [],
            'uncovered_points' => [],
            'conflict_summary' => null,
            'review_note' => null,
        ], $answerSections);
    }

    /** @param  list<array{used_page_ids: list<int>}>  $answerSections */
    private function unionUsedPageIds(array $answerSections): array
    {
        $ids = [];

        foreach ($answerSections as $section) {
            foreach ($section['used_page_ids'] as $pageId) {
                $ids[$pageId] = true;
            }
        }

        return array_values(array_keys($ids));
    }

    /**
     * Purpose: Build the persisted alignment_trace payload, auditable per section.
     * Inputs: The final answer sections (for heading lookup), the pre- and post-revision alignment
     *         assessments, the revision metadata, the computed coverage/conflict results, and the
     *         deterministically computed per-section provenance (see computeSectionsProvenance()).
     * Returns: A structure suitable for jsonb storage and frontend consumption — see class docblock
     *          and CLAUDE.md's Fase 9 Wiki-answer section for the full field list. has_source_based_
     *          support and no_source_based_support_message are computed from provenance_type alone
     *          (claim content_origin), never from alignment_status — a section can be "aligned" with
     *          the Wiki's own descriptive text while still having no source_based claim behind it.
     * Side effects: None.
     *
     * @param  array<string, array{provenance_type: string, source_based_page_ids: list<int>, best_practice_page_ids: list<int>}>  $provenanceBySectionKey
     */
    private function buildAlignmentTrace(
        array $answerSections,
        array $alignmentBefore,
        array $alignmentFinal,
        array $revisionInfo,
        string $coverageStatus,
        bool $hasPossibleConflict,
        array $provenanceBySectionKey,
    ): array {
        $headingByKey = [];

        foreach ($answerSections as $section) {
            $headingByKey[$section['key']] = $section['heading'];
        }

        $beforeByKey = [];

        foreach ($alignmentBefore as $assessment) {
            $beforeByKey[$assessment['section_key']] = $assessment['alignment_status'];
        }

        $revisedKeys = array_flip($revisionInfo['section_keys']);

        $sections = array_map(
            static function (array $assessment) use ($headingByKey, $beforeByKey, $revisedKeys, $provenanceBySectionKey): array {
                $key = $assessment['section_key'];
                $wasRevised = isset($revisedKeys[$key]);
                $provenance = $provenanceBySectionKey[$key] ?? [
                    'provenance_type' => self::PROVENANCE_BEST_PRACTICE,
                    'source_based_page_ids' => [],
                    'best_practice_page_ids' => [],
                ];

                return [
                    'section_key' => $key,
                    'heading' => $headingByKey[$key] ?? '',
                    'alignment_status' => $assessment['alignment_status'],
                    'alignment_status_before_revision' => $wasRevised ? ($beforeByKey[$key] ?? null) : null,
                    'supporting_page_ids' => $assessment['supporting_page_ids'],
                    'supported_points' => $assessment['supported_points'],
                    'uncovered_points' => $assessment['uncovered_points'],
                    'conflict_summary' => $assessment['conflict_summary'],
                    'review_note' => $assessment['review_note'],
                    'revised' => $wasRevised,
                    'provenance_type' => $provenance['provenance_type'],
                    'source_based_page_ids' => $provenance['source_based_page_ids'],
                    'best_practice_page_ids' => $provenance['best_practice_page_ids'],
                ];
            },
            $alignmentFinal,
        );

        $hasSourceBasedSupport = array_filter(
            $sections,
            static fn (array $section): bool => in_array($section['provenance_type'], [self::PROVENANCE_SOURCE_BASED, self::PROVENANCE_MIXED], true),
        ) !== [];

        return [
            'sections' => $sections,
            'coverage_status' => $coverageStatus,
            'has_possible_conflict' => $hasPossibleConflict,
            'revision' => $revisionInfo,
            // Deterministic flag only — no message text is persisted here. When a customer-fact
            // question has no source_based/mixed section at all, the frontend renders the
            // corresponding translated notice from has_source_based_support alone (see
            // wiki_answer_no_source_based_support_message in lang/{no,en}/procynia.php), so the
            // wording stays translatable rather than frozen into stored jsonb.
            'has_source_based_support' => $hasSourceBasedSupport,
        ];
    }

    /**
     * Purpose: Fetch the claim text for every claim id referenced by any read page, in one query,
     *          split by content_origin so the answer/alignment/revision clients can keep documented
     *          fact and best-practice suggestion visibly distinct instead of one undifferentiated
     *          "VERIFIED FACTS" block.
     * Inputs: The research context's read pages (each carrying source_based_claim_ids and
     *         best_practice_claim_ids — see RequirementWikiResearchService::supportingClaimsByOrigin()).
     * Returns: page_id => ['source_based' => list<string>, 'best_practice' => list<string>].
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array<int, array{source_based: list<string>, best_practice: list<string>}>
     */
    private function claimTextsByPageIdAndOrigin(array $pages): array
    {
        $allClaimIds = array_values(array_unique(array_merge(
            [],
            ...array_map(static fn (array $page): array => array_merge(
                $page['source_based_claim_ids'] ?? [],
                $page['best_practice_claim_ids'] ?? [],
            ), $pages),
        )));

        $claimTextById = $allClaimIds === []
            ? collect()
            : EnterpriseWikiClaim::query()->whereIn('id', $allClaimIds)->pluck('claim_text', 'id');

        $byPageId = [];

        foreach ($pages as $page) {
            $lookup = static fn (int $claimId): ?string => $claimTextById[$claimId] ?? null;

            $byPageId[$page['page_id']] = [
                'source_based' => array_values(array_filter(array_map($lookup, $page['source_based_claim_ids'] ?? []))),
                'best_practice' => array_values(array_filter(array_map($lookup, $page['best_practice_claim_ids'] ?? []))),
            ];
        }

        return $byPageId;
    }

    /**
     * Purpose: Build the shared page payload shape consumed by the answer, alignment, and revision
     *          AI clients alike.
     * Inputs: The research context's read pages and their origin-split claim texts.
     * Returns: One entry per page: identity, content, and its claim texts split into
     *          source_based_claim_texts (documented in the customer's own sources — may be presented
     *          as customer fact) and best_practice_claim_texts (a professional addition — must never
     *          be presented as documented customer fact, only as a suggestion/recommendation).
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  array<int, array{source_based: list<string>, best_practice: list<string>}>  $claimTextsByPageId
     * @return list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, source_based_claim_texts: list<string>, best_practice_claim_texts: list<string>}>
     */
    /**
     * Purpose: Reduce the model's per-section figure choices to the flat, ordered figure list
     *          persisted alongside the answer.
     * Inputs: The final answer sections (each carrying already-validated figure_refs) and the
     *         pagesForAi DTO the figures were offered from.
     * Returns: One entry per chosen figure, in answer order:
     *          {figure_ref, document_id, source_image_key, page_id, section_key, section_index}.
     * Side effects: None.
     *
     * Only identity is stored — never a caption, an alt text or a URL. Everything a reader sees is
     * resolved from live Wiki state when the answer is displayed, so a figure whose page or source
     * document later changes or disappears cannot leave stale text or a dead image behind in a
     * saved answer.
     *
     * @param  list<array<string, mixed>>  $answerSections
     * @param  list<array<string, mixed>>  $pagesForAi
     * @return list<array<string, mixed>>
     */
    private function answerFigures(array $answerSections, array $pagesForAi): array
    {
        $offered = [];

        foreach ($pagesForAi as $page) {
            foreach ((array) ($page['figures'] ?? []) as $figure) {
                $ref = (string) ($figure['figure_ref'] ?? '');

                if ($ref !== '') {
                    $offered[$ref] = ['figure' => $figure, 'page_id' => (int) $page['page_id']];
                }
            }
        }

        $answerFigures = [];
        $seenRefs = [];

        foreach (array_values($answerSections) as $sectionIndex => $section) {
            foreach ((array) ($section['figure_refs'] ?? []) as $ref) {
                $ref = (string) $ref;
                $match = $offered[$ref] ?? null;

                // Re-checked here and not merely trusted from the AI client: this is the last step
                // before the reference becomes persisted state. A figure is also shown once — the
                // same illustration repeated across sections reads as padding.
                if ($match === null || isset($seenRefs[$ref])) {
                    continue;
                }

                $seenRefs[$ref] = true;

                $answerFigures[] = [
                    'figure_ref' => $ref,
                    'document_id' => (int) $match['figure']['document_id'],
                    'source_image_key' => (string) $match['figure']['source_image_key'],
                    'page_id' => $match['page_id'],
                    'section_key' => (string) $section['key'],
                    'section_index' => $sectionIndex,
                ];
            }
        }

        return $answerFigures;
    }

    private function pagesForAi(array $pages, array $claimTextsByPageId): array
    {
        return array_map(
            static function (array $page) use ($claimTextsByPageId): array {
                $texts = $claimTextsByPageId[$page['page_id']] ?? ['source_based' => [], 'best_practice' => []];

                return [
                    'page_id' => $page['page_id'],
                    'title' => $page['title'],
                    'page_type' => $page['page_type'],
                    'content_mode' => $page['content_mode'],
                    'content_markdown' => $page['content_markdown'],
                    'selected_headings' => $page['selected_headings'],
                    'figures' => array_values((array) ($page['figures'] ?? [])),
                    'source_based_claim_texts' => $texts['source_based'],
                    'best_practice_claim_texts' => $texts['best_practice'],
                ];
            },
            $pages,
        );
    }

    /**
     * Purpose: Deterministically classify each answer section's provenance from the actual claim
     *          content_origin of the pages it cites — never from an AI's self-report. This is the
     *          concrete enforcement of the binding rule that best-practice content must never be
     *          presented as documented customer fact (docs/enterprise-llm-wiki-plan.md, "Arkitektur-
     *          notat — v0.9").
     * Inputs: The final answer sections (used_page_ids) and the pagesForAi DTO (each page's
     *         source_based_claim_texts/best_practice_claim_texts).
     * Returns: section_key => provenance_type ('source_based' when every grounding signal is
     *          source_based, 'best_practice' when the section cites no page at all or only
     *          best-practice-marked claims, 'mixed' when both are present), plus the page_ids that
     *          contributed each kind of grounding. A page cited with no matching claim at all still
     *          counts as source_based grounding — an approved Wiki page's own content_markdown
     *          originates from the customer's own documents; content_origin only exists at the
     *          finer-grained claim level to flag specific additions within it.
     * Side effects: None.
     *
     * @param  list<array{key: string, used_page_ids: list<int>}>  $answerSections
     * @param  list<array{page_id: int, source_based_claim_texts: list<string>, best_practice_claim_texts: list<string>}>  $pagesForAi
     * @return array<string, array{provenance_type: string, source_based_page_ids: list<int>, best_practice_page_ids: list<int>}>
     */
    private function computeSectionsProvenance(array $answerSections, array $pagesForAi): array
    {
        $pageHasBestPracticeOnly = [];

        foreach ($pagesForAi as $page) {
            // A page whose ONLY matching claims are best_practice-marked never itself counts as
            // source_based grounding; a page with any source_based claim, or no matching claims at
            // all (grounded purely in the page's own approved content), does.
            $pageHasBestPracticeOnly[$page['page_id']] = $page['best_practice_claim_texts'] !== []
                && $page['source_based_claim_texts'] === [];
        }

        $result = [];

        foreach ($answerSections as $section) {
            $usedPageIds = $section['used_page_ids'];

            if ($usedPageIds === []) {
                $result[$section['key']] = [
                    'provenance_type' => self::PROVENANCE_BEST_PRACTICE,
                    'source_based_page_ids' => [],
                    'best_practice_page_ids' => [],
                ];

                continue;
            }

            $sourceBasedPageIds = [];
            $bestPracticePageIds = [];

            foreach ($usedPageIds as $pageId) {
                if ($pageHasBestPracticeOnly[$pageId] ?? false) {
                    $bestPracticePageIds[] = $pageId;
                } else {
                    $sourceBasedPageIds[] = $pageId;
                }
            }

            $provenanceType = match (true) {
                $sourceBasedPageIds !== [] && $bestPracticePageIds !== [] => self::PROVENANCE_MIXED,
                $bestPracticePageIds !== [] => self::PROVENANCE_BEST_PRACTICE,
                default => self::PROVENANCE_SOURCE_BASED,
            };

            $result[$section['key']] = [
                'provenance_type' => $provenanceType,
                'source_based_page_ids' => $sourceBasedPageIds,
                'best_practice_page_ids' => $bestPracticePageIds,
            ];
        }

        return $result;
    }

    /**
     * Purpose: Convert the pages actually cited anywhere in the answer into the persisted sources
     *          payload.
     * Inputs: All read pages from the research context and the union of used_page_ids across every
     *         final answer section.
     * Returns: One entry per cited page (already unique — a page is read at most once per run),
     *          carrying its discovery provenance so the UI can distinguish direct-search hits from
     *          pages found by following Wiki links/backlinks.
     * has_source_based_claims/has_best_practice_claims let the UI scope citation links to only the
     * source_based basis, per the binding provenance rule — a best-practice-only page must never be
     * presented as documentary evidence of what the customer's own sources say.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<int>  $usedPageIds
     * @return list<array{enterprise_wiki_page_id: int, page_title: string, page_slug: string, page_type: string, selection_type: string, discovered_from_page_id: ?int, discovered_from_title: ?string, link_direction: ?string, supporting_claim_ids: list<int>, has_source_based_claims: bool, has_best_practice_claims: bool}>
     */
    private function sourcesPayload(array $pages, array $usedPageIds): array
    {
        if ($usedPageIds === []) {
            return [];
        }

        $usedPageIds = array_flip($usedPageIds);

        return array_values(array_map(
            static fn (array $page): array => [
                'enterprise_wiki_page_id' => $page['page_id'],
                'page_title' => $page['title'],
                'page_slug' => $page['slug'],
                'page_type' => $page['page_type'],
                'selection_type' => $page['selection_type'],
                'discovered_from_page_id' => $page['discovered_from_page_id'],
                'discovered_from_title' => $page['discovered_from_title'],
                'link_direction' => $page['link_direction'],
                'supporting_claim_ids' => $page['supporting_claim_ids'],
                'has_source_based_claims' => ($page['source_based_claim_ids'] ?? []) !== [],
                'has_best_practice_claims' => ($page['best_practice_claim_ids'] ?? []) !== [],
            ],
            array_values(array_filter($pages, static fn (array $page): bool => isset($usedPageIds[$page['page_id']]))),
        ));
    }

    private function noPagesReadMessage(array $context): string
    {
        $stopReason = $context['limits']['stop_reason'] ?? null;

        return $stopReason === 'no_relevant_candidates'
            ? 'Ingen godkjent Wiki-informasjon er tilgjengelig for dette kravet i kundemiljøet. Svaret bygger på faglig beste praksis.'
            : 'Wiki-forskningen fant ingen sider som kunne dokumentere dette kravet. Svaret bygger på faglig beste praksis.';
    }

    private function persist(SavedNoticeAiRequirement $requirement, array $attributes, ?int $userId): SavedNoticeAiRequirementWikiAnswer
    {
        return DB::transaction(function () use ($requirement, $attributes, $userId): SavedNoticeAiRequirementWikiAnswer {
            return SavedNoticeAiRequirementWikiAnswer::query()->updateOrCreate(
                ['saved_notice_ai_requirement_id' => $requirement->id],
                array_merge($attributes, [
                    'generated_by_user_id' => $userId,
                    'generated_at' => now(),
                ]),
            );
        });
    }
}
