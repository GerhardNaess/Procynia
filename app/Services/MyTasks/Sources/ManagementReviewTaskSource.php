<?php

namespace App\Services\MyTasks\Sources;

use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\MyTasks\MyTask;
use App\Services\MyTasks\MyTaskSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ledelsens gjennomgåelse (docs/management-review-v1-plan.md §9.4):
 *
 *  - review_action: a tiltak followed up here, open, whose owner is the person. Due on its frist. A
 *    tiltak handed to Avvik og forbedringer is not here — it is that module's case, and
 *    ImprovementTaskSource shows it. One tiltak, one task.
 *  - review_complete: a draft review the person is responsible for. Due on the meeting date; overdue
 *    once the meeting has passed without the review being finalized.
 *  - review_due: «Planlegg neste ledelsens gjennomgåelse» for the person responsible for the latest
 *    finalized review, from config('management_review.next_review_task_days') before the date it set,
 *    until a newer review exists.
 *
 * ACCESS. The module and management_review.view (ManagementReviewAccessService). READ, NOT ACT.
 * Following up one's own tiltak needs only the view; finishing a review needs management_review.edit.
 */
class ManagementReviewTaskSource implements MyTaskSource
{
    public function __construct(
        private readonly ManagementReviewAccessService $access,
    ) {}

    public function module(): string
    {
        return 'management_review';
    }

    public function isAvailableFor(User $user): bool
    {
        return $this->access->canOpenModule($user);
    }

    public function openTasksFor(User $user, int $customerId, CarbonImmutable $today): Collection
    {
        if (! $this->isAvailableFor($user)) {
            return collect();
        }

        $userId = (int) $user->id;
        $canEdit = $this->access->canEdit($user);
        $tasks = collect();

        $actions = ManagementReviewDecision::query()
            ->where('customer_id', $customerId)
            ->whereIn('management_review_id', $this->access->visibleReviews($user)->select('management_reviews.id'))
            ->where('kind', ManagementReviewDecision::KIND_ACTION)
            ->where('follow_up', ManagementReviewDecision::FOLLOW_UP_OWN)
            ->where('status', ManagementReviewDecision::STATUS_OPEN)
            ->where('owner_user_id', $userId)
            ->with('review:id,title')
            ->orderBy('id')
            ->get();

        foreach ($actions as $action) {
            $overdue = $action->isOverdue($today);
            $tasks->push($this->task(
                'management-review-action-'.$action->id,
                'review_action',
                $action->review,
                $action->text,
                route('app.management-review.show', ['reviewId' => $action->management_review_id], false).'#decision-'.$action->id,
                [['key' => $overdue ? 'action_overdue' : 'action_open', 'due_on' => $action->due_date?->toDateString(), 'overdue' => $overdue, 'can_act' => true]],
                $user,
            ));
        }

        $drafts = $this->access->visibleReviews($user)
            ->where('status', ManagementReview::STATUS_DRAFT)
            ->where('owner_user_id', $userId)
            ->orderBy('id')
            ->get();

        foreach ($drafts as $review) {
            $passed = $review->meeting_date !== null && $review->meeting_date->toDateString() < $today->toDateString();
            $tasks->push($this->task(
                'management-review-'.$review->id,
                'review_complete',
                $review,
                $review->title,
                route('app.management-review.show', ['reviewId' => $review->id], false),
                [['key' => $passed ? 'meeting_passed' : 'review_open', 'due_on' => $review->meeting_date?->toDateString(), 'overdue' => $passed, 'can_act' => $canEdit]],
                $user,
            ));
        }

        $latest = $this->access->visibleReviews($user)
            ->where('status', ManagementReview::STATUS_FINALIZED)
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null
            && (int) $latest->owner_user_id === $userId
            && $latest->next_review_due_on !== null
            && $latest->next_review_due_on->toDateString() <= $today->addDays((int) config('management_review.next_review_task_days', 30))->toDateString()
            && ! $this->access->visibleReviews($user)->whereKeyNot($latest->id)->where('created_at', '>=', $latest->finalized_at)->exists()) {
            $due = $latest->next_review_due_on;
            $tasks->push($this->task(
                'management-review-due-'.$latest->id,
                'review_due',
                $latest,
                __('procynia.management_review.tasks.next_review'),
                route('app.management-review.index', [], false),
                [['key' => 'next_review_due', 'due_on' => $due->toDateString(), 'overdue' => $due->toDateString() < $today->toDateString(), 'can_act' => $canEdit]],
                $user,
                (int) config('management_review.next_review_task_days', 30),
            ));
        }

        return $tasks->values();
    }

    /** @param  list<array<string, mixed>>  $reasons */
    private function task(string $id, string $type, ?ManagementReview $review, string $title, string $url, array $reasons, User $user, ?int $dueSoonDays = null): MyTask
    {
        $due = $reasons[0]['due_on'] ?? null;

        return new MyTask(
            id: $id,
            module: $this->module(),
            type: $type,
            title: $title,
            subjectTitle: $review?->title,
            assigneeUserId: (int) $user->id,
            actionUrl: $url,
            dueOn: $due !== null ? CarbonImmutable::parse($due) : null,
            overdue: (bool) ($reasons[0]['overdue'] ?? false),
            reasons: $reasons,
            canAct: ! in_array(false, array_column($reasons, 'can_act'), true),
            details: ['management_review' => ['id' => (int) $review?->id, 'title' => $review?->title]],
            dueSoonDays: $dueSoonDays,
            subject: ['prefix' => 'management_review', 'metadata' => ['management_review_id' => (int) $review?->id]],
        );
    }
}
