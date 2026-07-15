<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use Illuminate\Support\Facades\DB;

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
 */
class RequirementWikiAnswerService
{
    public const ENGINE_VERSION = 'wiki_reader_alignment_v3';

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
     *         the language to answer in, and the user triggering generation.
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
    ): SavedNoticeAiRequirementWikiAnswer {
        $context = $this->researchService->research($requirement, $customerId, $languageCode);

        $claimTextsByPageId = $this->claimTextsByPageId($context['pages']);
        $pagesForAi = $this->pagesForAi($context['pages'], $claimTextsByPageId);

        $answer = $this->answerAiClient->generateAnswer(
            (string) ($requirement->requirement_identifier ?? ''),
            (string) $requirement->requirement_text,
            $pagesForAi,
            $languageCode,
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

        return $this->persist($requirement, [
            'coverage_status' => $coverageStatus,
            'answer_text' => $answerText,
            'missing_summary' => $missingSummary,
            'sources' => $this->sourcesPayload($context['pages'], $usedPageIds),
            'model' => 'gpt-4.1-mini',
            'research_trace' => ['research' => $context, 'answer' => ['answer_sections' => $answerSections]],
            'alignment_trace' => $this->buildAlignmentTrace($answerSections, $alignmentBefore, $alignmentFinal, $revisionInfo, $coverageStatus, $hasPossibleConflict),
            'has_possible_conflict' => $hasPossibleConflict,
            'engine_version' => self::ENGINE_VERSION,
        ], $userId);
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
     *         assessments, the revision metadata, and the computed coverage/conflict results.
     * Returns: A structure suitable for jsonb storage and frontend consumption — see class docblock
     *          and CLAUDE.md's Fase 9 Wiki-answer section for the full field list.
     * Side effects: None.
     */
    private function buildAlignmentTrace(
        array $answerSections,
        array $alignmentBefore,
        array $alignmentFinal,
        array $revisionInfo,
        string $coverageStatus,
        bool $hasPossibleConflict,
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
            static function (array $assessment) use ($headingByKey, $beforeByKey, $revisedKeys): array {
                $key = $assessment['section_key'];
                $wasRevised = isset($revisedKeys[$key]);

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
                ];
            },
            $alignmentFinal,
        );

        return [
            'sections' => $sections,
            'coverage_status' => $coverageStatus,
            'has_possible_conflict' => $hasPossibleConflict,
            'revision' => $revisionInfo,
        ];
    }

    /**
     * Purpose: Fetch the claim text for every claim id referenced by any read page, in one query.
     * Inputs: The research context's read pages (each carrying supporting_claim_ids).
     * Returns: page_id => list of claim texts for that page.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array<int, list<string>>
     */
    private function claimTextsByPageId(array $pages): array
    {
        $allClaimIds = array_values(array_unique(array_merge(
            [],
            ...array_map(static fn (array $page): array => $page['supporting_claim_ids'], $pages),
        )));

        if ($allClaimIds === []) {
            return [];
        }

        $claimTextById = EnterpriseWikiClaim::query()
            ->whereIn('id', $allClaimIds)
            ->pluck('claim_text', 'id');

        $byPageId = [];

        foreach ($pages as $page) {
            $byPageId[$page['page_id']] = array_values(array_filter(array_map(
                static fn (int $claimId): ?string => $claimTextById[$claimId] ?? null,
                $page['supporting_claim_ids'],
            )));
        }

        return $byPageId;
    }

    /**
     * Purpose: Build the shared page payload shape consumed by the answer, alignment, and revision
     *          AI clients alike.
     * Inputs: The research context's read pages and their claim texts.
     * Returns: One entry per page: identity, content, and its verified-fact claim texts.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  array<int, list<string>>  $claimTextsByPageId
     * @return list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, claim_texts: list<string>}>
     */
    private function pagesForAi(array $pages, array $claimTextsByPageId): array
    {
        return array_map(
            static fn (array $page): array => [
                'page_id' => $page['page_id'],
                'title' => $page['title'],
                'page_type' => $page['page_type'],
                'content_mode' => $page['content_mode'],
                'content_markdown' => $page['content_markdown'],
                'selected_headings' => $page['selected_headings'],
                'claim_texts' => $claimTextsByPageId[$page['page_id']] ?? [],
            ],
            $pages,
        );
    }

    /**
     * Purpose: Convert the pages actually cited anywhere in the answer into the persisted sources
     *          payload.
     * Inputs: All read pages from the research context and the union of used_page_ids across every
     *         final answer section.
     * Returns: One entry per cited page (already unique — a page is read at most once per run),
     *          carrying its discovery provenance so the UI can distinguish direct-search hits from
     *          pages found by following Wiki links/backlinks.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<int>  $usedPageIds
     * @return list<array{enterprise_wiki_page_id: int, page_title: string, page_slug: string, page_type: string, selection_type: string, discovered_from_page_id: ?int, discovered_from_title: ?string, link_direction: ?string, supporting_claim_ids: list<int>}>
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
