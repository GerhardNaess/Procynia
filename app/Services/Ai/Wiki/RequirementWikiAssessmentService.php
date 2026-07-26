<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Commercial\CustomerAiCaseUsageRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the "Vurdering" (assessment) judgment for one requirement from Enterprise Wiki knowledge
 * instead of the Knowledge Base — the last active Knowledge Base AI consumer moved off it (AI-to-Wiki
 * consolidation, final functional phase). Reuses the same Wiki-research flow
 * (RequirementWikiResearchService) as the answer engine: approved-and-current Wiki pages only,
 * customer-scoped, real search/read/navigate, never an unbounded Wiki read.
 *
 * Deliberately a single AI call (RequirementWikiAssessmentAiClient) producing the full judgment
 * directly — coverage_status, has_possible_conflict, risk_level, and four free-text fields — matching
 * the shape the pre-existing Knowledge-Base-grounded RequirementAssessmentService already
 * established. This is a holistic judgment task, not a multi-section synthesis, so it does not need
 * a separate alignment/revision pass the way RequirementWikiAnswerService does for the answer text.
 *
 * Persists to the same saved_notice_ai_requirement_assessments table (Phase 5: reuse existing fields
 * where semantics are compatible) — coverage_status/risk_level/the four text fields keep their exact
 * meaning; wiki_sources_snapshot (renamed from source_evidence_snapshot) now holds Wiki page
 * citations instead of Knowledge Base chunk citations; has_possible_conflict and engine_version are
 * new columns mirroring SavedNoticeAiRequirementWikiAnswer's own pattern. Old, Knowledge-Base-sourced
 * assessment rows remain fully readable — nothing is backfilled or deleted.
 */
class RequirementWikiAssessmentService
{
    public const ENGINE_VERSION = 'wiki_assessment_v1';

    public function __construct(
        private readonly RequirementWikiResearchService $researchService,
        private readonly RequirementWikiAssessmentAiClient $assessmentAiClient,
        private readonly CustomerAiCaseUsageRecorder $caseUsageRecorder = new CustomerAiCaseUsageRecorder,
    ) {}

    /**
     * Purpose: Assess (or re-assess) one requirement against approved Enterprise Wiki knowledge.
     * Inputs: The requirement, the customer id it belongs to (never trusted from the requirement
     *         itself — always the owning SavedNotice's own customer_id, resolved by the caller), the
     *         language to write in, the user triggering the assessment, the owning SavedNotice's
     *         case_instructions, and an optional one-off requirementUserPrompt — both subordinate,
     *         style-only directives, same contract as RequirementWikiAnswerService::generate().
     * Returns: The persisted (created or updated in place) assessment row.
     * Side effects: Writes exactly one row to saved_notice_ai_requirement_assessments, upserted by
     *               saved_notice_ai_requirement_id. Never touches saved_notice_ai_requirement_wiki_answers
     *               or any Knowledge Base table. Records case usage only after a successful AI call.
     *
     * @throws Throwable on research/AI failure — callers must catch and persist a failed-status row
     *                   (mirrors the legacy RequirementAssessmentService's own contract: this
     *                   service never silently swallows a failure into a fake "covered" result).
     */
    public function assessRequirement(
        SavedNoticeAiRequirement $requirement,
        int $customerId,
        string $languageCode,
        ?int $userId = null,
        ?string $caseInstructions = null,
        ?string $requirementUserPrompt = null,
    ): SavedNoticeAiRequirementAssessment {
        $context = $this->researchService->research($requirement, $customerId, $languageCode);

        $claimTextsByPageId = $this->claimTextsByPageIdAndOrigin($context['pages']);
        $pagesForAi = $this->pagesForAi($context['pages'], $claimTextsByPageId);

        $result = $this->assessmentAiClient->assessRequirement(
            (string) ($requirement->requirement_identifier ?? ''),
            (string) $requirement->requirement_text,
            $pagesForAi,
            $languageCode,
            $caseInstructions,
            $requirementUserPrompt,
        );

        $this->recordAiCaseUsageAfterSuccessfulAssessment($requirement, $userId);

        return $this->persist($requirement, [
            'coverage_status' => $result['coverage_status'],
            'has_possible_conflict' => $result['has_possible_conflict'],
            'risk_level' => $result['risk_level'],
            'requirement_summary' => $result['requirement_summary'],
            'coverage_rationale' => $result['coverage_rationale'],
            'missing_information' => $result['missing_information'],
            'recommended_next_step' => $result['recommended_next_step'],
            'wiki_sources_snapshot' => $this->sourcesPayload($context['pages']),
            'engine_version' => self::ENGINE_VERSION,
        ], $userId);
    }

    /**
     * Purpose: Fetch the claim text for every claim id referenced by any read page, split by
     *          content_origin — same provenance split RequirementWikiAnswerService uses, so
     *          source-documented fact and best-practice suggestion stay visibly distinct in the
     *          assessment prompt too.
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
     * @param  list<array<string, mixed>>  $pages
     * @param  array<int, array{source_based: list<string>, best_practice: list<string>}>  $claimTextsByPageId
     * @return list<array{page_id: int, title: string, page_type: string, content_mode: string, content_markdown: string, selected_headings: list<string>, source_based_claim_texts: list<string>, best_practice_claim_texts: list<string>}>
     */
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
                    'source_based_claim_texts' => $texts['source_based'],
                    'best_practice_claim_texts' => $texts['best_practice'],
                ];
            },
            $pages,
        );
    }

    /**
     * Purpose: Snapshot every Wiki page read during research as the assessment's knowledge basis —
     *          mirrors the legacy service's own semantics (it snapshotted every evidence row sent to
     *          the prompt, not a filtered "cited" subset; the assessment AI call here is a single
     *          holistic judgment with no per-page citation structure to filter by).
     * Inputs: All pages read during research.
     * Returns: One entry per read page, carrying its discovery provenance and claim-origin coverage.
     * Side effects: None.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array{enterprise_wiki_page_id: int, page_title: string, page_slug: string, page_type: string, selection_type: string, discovered_from_page_id: ?int, discovered_from_title: ?string, link_direction: ?string, has_source_based_claims: bool, has_best_practice_claims: bool}>
     */
    private function sourcesPayload(array $pages): array
    {
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
                'has_source_based_claims' => ($page['source_based_claim_ids'] ?? []) !== [],
                'has_best_practice_claims' => ($page['best_practice_claim_ids'] ?? []) !== [],
            ],
            $pages,
        ));
    }

    private function persist(SavedNoticeAiRequirement $requirement, array $attributes, ?int $userId): SavedNoticeAiRequirementAssessment
    {
        return DB::transaction(function () use ($requirement, $attributes, $userId): SavedNoticeAiRequirementAssessment {
            return SavedNoticeAiRequirementAssessment::query()->updateOrCreate(
                ['saved_notice_ai_requirement_id' => $requirement->id],
                array_merge($attributes, [
                    'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
                    'assessed_by_user_id' => $userId,
                    'assessed_at' => now(),
                ]),
            );
        });
    }

    /**
     * Purpose: Record the SavedNotice as AI-active after a successful assessment — same operation
     *          key the legacy service used, since this is still the same product operation, only
     *          Wiki-powered now.
     * Inputs: The assessed requirement and optional user id from the triggering request.
     * Returns: None.
     * Side effects: Writes one case usage row through the non-blocking recorder; never throws.
     */
    private function recordAiCaseUsageAfterSuccessfulAssessment(SavedNoticeAiRequirement $requirement, ?int $userId): void
    {
        try {
            $savedNotice = $requirement->savedNotice;

            if (! $savedNotice instanceof SavedNotice) {
                return;
            }

            $this->caseUsageRecorder->record(
                $savedNotice,
                AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
                $this->resolveRecorderUser($userId),
            );
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][AI_CASE_USAGE] Failed to record AI case usage after Wiki assessment.', [
                'saved_notice_ai_requirement_id' => $requirement->id,
                'saved_notice_id' => $requirement->saved_notice_id,
                'user_id' => $userId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function resolveRecorderUser(?int $userId): ?User
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        try {
            $user = User::query()->find($userId);

            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }
}
