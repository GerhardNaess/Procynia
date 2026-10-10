<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\User;
use App\Services\ManagementReview\DecisionRows;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;
use Carbon\CarbonImmutable;

/**
 * Status for tidligere beslutninger og tiltak: what earlier finalized reviews decided, and how the
 * follow-up stands now.
 *
 *  - Every tiltak still open, from any earlier review, and every tiltak finished since the latest
 *    earlier review was finalized — so the management sees what it asked for last time land.
 *  - The plain decisions of the latest earlier review, to confirm they were followed.
 *
 * Decisions and tiltak are the reviews' own content (management_review.view). A tiltak followed up in
 * Avvik og forbedringer carries its case with the case's fagområde; its status, owner and due date
 * are shown only to a reader who can read cases there (SectionPresenter).
 */
final class PreviousDecisionsSection implements SectionBuilder
{
    public function __construct(
        private readonly DecisionRows $rows,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $payload = (new SectionPayload(false, (int) config('management_review.list_limit', 50)))
            ->metrics('status', ['previous_reviews', 'decisions_previous', 'actions_open', 'actions_overdue', 'actions_completed', 'actions_cancelled', 'actions_in_improvements'])
            ->list('decisions');

        $previous = ManagementReview::query()
            ->where('customer_id', $scope->customerId)
            ->where('status', ManagementReview::STATUS_FINALIZED)
            ->whereKeyNot($scope->reviewId)
            ->where('finalized_at', '<=', $scope->capturedAt)
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'finalized_at']);

        $payload->count('status', 'previous_reviews', $previous->count());

        if ($previous->isEmpty()) {
            return $payload->toArray();
        }

        $since = CarbonImmutable::parse($previous->first()->finalized_at);
        $latestId = (int) $previous->first()->id;

        $decisions = ManagementReviewDecision::query()
            ->where('customer_id', $scope->customerId)
            ->whereIn('management_review_id', $previous->modelKeys())
            ->with(['review:id,title', 'owner:id,name', 'improvementCase.owner:id,name'])
            ->get()
            ->filter(fn (ManagementReviewDecision $decision): bool => $this->belongs($decision, $latestId, $since))
            ->sortBy(fn (ManagementReviewDecision $decision): string => sprintf(
                '%d|%s|%s|%010d',
                $decision->isAction() ? 0 : 1,
                $decision->isOpen() || ($decision->improvementCase?->isActive() ?? false) ? '0' : '1',
                $decision->due_date?->toDateString() ?? '9999-12-31',
                $decision->id,
            ))
            ->values();

        foreach ($decisions as $decision) {
            if (! $decision->isAction()) {
                $payload->count('status', 'decisions_previous');
            } elseif ($decision->isInImprovements()) {
                $payload->count('status', 'actions_in_improvements');
            } else {
                $payload->count('status', 'actions_'.$decision->status);

                if ($decision->isOverdue($scope->today)) {
                    $payload->count('status', 'actions_overdue');
                }
            }
        }

        foreach ($this->rows->rows($decisions, $scope->today) as $row) {
            $payload->item('decisions', $row);
        }

        return $payload->toArray();
    }

    private function belongs(ManagementReviewDecision $decision, int $latestId, CarbonImmutable $since): bool
    {
        if (! $decision->isAction()) {
            return (int) $decision->management_review_id === $latestId;
        }

        if ($decision->isInImprovements()) {
            $case = $decision->improvementCase;

            return $case instanceof ImprovementCase
                && ($case->isActive() || ($case->closed_at !== null && $case->closed_at->gte($since)));
        }

        if ($decision->status === ManagementReviewDecision::STATUS_OPEN) {
            return true;
        }

        $finishedAt = $decision->completed_at ?? $decision->updated_at;

        return $finishedAt !== null && $finishedAt->gte($since);
    }
}
