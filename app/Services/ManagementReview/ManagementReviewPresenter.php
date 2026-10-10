<?php

namespace App\Services\ManagementReview;

use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewAmendment;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewEvent;
use App\Models\ManagementReviewParticipant;
use App\Models\ManagementReviewSection;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything one person may see of one review, shaped once for the page, the print view and the PDF
 * (plan §4, §11). Every gate is applied here, on the server, on every read:
 *
 *  - the basis of a draft is built live with the reader's access; of a finalized review it is the
 *    snapshot narrowed to the reader's access today (ManagementReviewBasisService);
 *  - the judgement and comment of a module section are left out unless the reader may see that
 *    section's basis;
 *  - the live status of a case in Avvik og forbedringer is left out unless the reader may read cases
 *    in its fagområde.
 */
final class ManagementReviewPresenter
{
    public function __construct(
        private readonly ManagementReviewBasisService $basis,
        private readonly Catalog $catalog,
        private readonly ManagementReviewAccessService $access,
        private readonly ManagementReviewDecisionService $decisions,
        private readonly ManagementReviewReadiness $readiness,
        private readonly FrameworkCoverage $frameworks,
        private readonly BasisFormatter $formatter,
    ) {}

    /** @return array<string, mixed> */
    public function show(User $viewer, ManagementReview $review): array
    {
        $review->loadMissing(['owner:id,name', 'businessAreas:id,name', 'finalizedBy:id,name']);
        $finalized = $review->isFinalized();
        $views = $finalized ? $this->basis->snapshot($viewer, $review) : $this->basis->live($viewer, $review);
        $sections = $review->sections()->get()->keyBy('section_key');
        $participants = $review->participants()->get();
        $decisions = $this->basis->decisions($review);
        $canEdit = ! $finalized && $this->access->canEdit($viewer);

        return [
            'review' => $this->reviewRow($review),
            'sections' => array_values(array_map(fn (array $view): array => $this->section($view, $sections->get($view['key']), $canEdit, $finalized), $views)),
            'participants' => $participants->map(fn (ManagementReviewParticipant $participant): array => [
                'id' => (int) $participant->id,
                'user_id' => $participant->user_id !== null ? (int) $participant->user_id : null,
                'name' => $participant->name,
                'role_label' => $participant->role_label,
            ])->values()->all(),
            'decisions' => $this->decisionRows($viewer, $review, $decisions, $finalized),
            'readiness' => $finalized ? null : $this->readiness->evaluate($review, $views, $sections, $participants->count()),
            'frameworks' => $this->frameworks->evaluate($review, $views, $sections, $decisions->count()),
            'amendments' => $review->amendments()->get()->map(fn (ManagementReviewAmendment $amendment): array => [
                'id' => (int) $amendment->id,
                'text' => $amendment->text,
                'reason' => $amendment->reason,
                'created_by_name' => $amendment->created_by_name,
                'created_at' => $amendment->created_at?->toIso8601String(),
            ])->values()->all(),
            'history' => $review->events()->limit(100)->get()->map(fn (ManagementReviewEvent $event): array => [
                'id' => (int) $event->id,
                'event' => $event->event,
                'actor_name' => $event->actor_name,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'decision_id' => $event->decision_id !== null ? (int) $event->decision_id : null,
            ])->values()->all(),
            'permissions' => [
                'can_edit' => $canEdit,
                'can_finalize' => ! $finalized && $this->access->canFinalize($viewer),
                'can_delete' => $this->access->canDelete($viewer) && $review->isDeletable(),
                'can_amend' => $finalized && $this->access->canFinalize($viewer),
                'can_plan_next' => $this->access->canEdit($viewer),
                'can_hand_off' => $canEdit && $this->decisions->canHandOff($viewer),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function reviewRow(ManagementReview $review): array
    {
        return [
            'id' => (int) $review->id,
            'title' => $review->title,
            'purpose' => $review->purpose,
            'status' => $review->status,
            'period_start' => $review->period_start?->toDateString(),
            'period_end' => $review->period_end?->toDateString(),
            'meeting_date' => $review->meeting_date?->toDateString(),
            'all_business_areas' => (bool) $review->all_business_areas,
            'business_areas' => $review->all_business_areas ? [] : $review->businessAreas->map(fn ($area): array => ['id' => (int) $area->id, 'name' => $area->name])->values()->all(),
            'frameworks' => array_values((array) $review->frameworks),
            'owner_user_id' => $review->owner_user_id !== null ? (int) $review->owner_user_id : null,
            'owner_name' => $review->owner?->name,
            'conclusion' => $review->conclusion,
            'next_review_due_on' => $review->next_review_due_on?->toDateString(),
            'finalized_at' => $review->finalized_at?->toIso8601String(),
            'finalized_by_name' => $review->finalized_by_name,
            'url' => route('app.management-review.show', ['reviewId' => $review->id]),
        ];
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private function section(array $view, ?ManagementReviewSection $section, bool $canEdit, bool $finalized): array
    {
        $key = $view['key'];
        $judgementVisible = ! $this->catalog->judgementFollowsBasis($key) || $view['state'] === Catalog::STATE_AVAILABLE;

        return [
            'key' => $key,
            'type' => $view['type'],
            'state' => $view['state'],
            'module' => $view['module'],
            'has_basis' => $view['has_basis'],
            'basis' => $this->formatter->format($key, $view['basis'], $finalized),
            'coverage' => $view['coverage'],
            'captured_at' => $view['captured_at'],
            'judgement_visible' => $judgementVisible,
            'judgement' => $judgementVisible ? $section?->judgement : null,
            'comment' => $judgementVisible ? $section?->comment : null,
            'notes' => $section?->notes,
            'can_assess' => $canEdit && $judgementVisible,
        ];
    }

    /**
     * Each decision as it is now, and for a finalized review as it stood at finalization.
     *
     * @param  Collection<int, ManagementReviewDecision>  $decisions
     * @return list<array<string, mixed>>
     */
    private function decisionRows(User $viewer, ManagementReview $review, Collection $decisions, bool $finalized): array
    {
        $today = CarbonImmutable::today();
        $caseAreas = $this->catalog->caseAreaIds($viewer);
        $atFinalization = $finalized ? $this->basis->decisionsAtFinalization($viewer, $review) : [];
        $canEdit = ! $finalized && $this->access->canEdit($viewer);
        $canHandOff = $canEdit && $this->decisions->canHandOff($viewer);

        return $decisions->map(function (ManagementReviewDecision $decision) use ($viewer, $today, $caseAreas, $atFinalization, $canEdit, $canHandOff): array {
            $case = $decision->improvementCase;
            $caseVisible = $case instanceof ImprovementCase && in_array((int) $case->business_area_id, $caseAreas, true);
            $then = $atFinalization[(int) $decision->id] ?? null;

            return [
                'id' => (int) $decision->id,
                'kind' => $decision->kind,
                'text' => $decision->text,
                'section_key' => $decision->section_key,
                'owner_user_id' => $decision->owner_user_id !== null ? (int) $decision->owner_user_id : null,
                'owner_name' => $decision->owner?->name,
                'due_date' => $decision->due_date?->toDateString(),
                'follow_up' => $decision->follow_up,
                'status' => $decision->status,
                'overdue' => $decision->isOverdue($today),
                'completed_at' => $decision->completed_at?->toIso8601String(),
                'completion_note' => $decision->completion_note,
                'case' => $decision->isInImprovements() ? ($caseVisible ? [
                    'id' => (int) $case->id,
                    'title' => $case->title,
                    'url' => route('app.improvements.show', ['caseId' => $case->id]),
                    'status' => $case->status,
                    'owner_name' => $case->owner?->name,
                    'due_date' => $case->due_date?->toDateString(),
                    'origin' => $decision->improvement_origin,
                ] : ['hidden' => true, 'origin' => $decision->improvement_origin]) : null,
                'at_finalization' => $then !== null ? $this->formatter->row($then) : null,
                'permissions' => [
                    'can_edit' => $canEdit && ! $decision->isInImprovements(),
                    'can_follow_up' => $this->decisions->canFollowUp($viewer, $decision),
                    'can_reassign' => $decision->isFollowedUpHere() && $this->access->canEdit($viewer),
                    'can_hand_off' => $canHandOff && $decision->isFollowedUpHere(),
                ],
            ];
        })->values()->all();
    }
}
