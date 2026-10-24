<?php

namespace App\Services\Api\Presenters;

use App\Models\ManagementReview;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewPresenter as ReviewPagePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * A ledelsens gjennomgang as the external API presents it: structured fields only (D8).
 *
 * In: identifiers, title, status, period, meeting date, scope (fagområder or the whole
 * organisation), frameworks, ansvarlig, next review date, when it was finalized and its decisions
 * counted. The detail adds each section's state and judgement, and each decision and tiltak with its
 * kind, ansvarlig, frist, follow-up and status. Out: purpose, conclusion, section comments and notes,
 * decision texts and completion notes, amendments, participants' names, who finalized, the history,
 * the basis figures from the other modules and web routes.
 *
 * The detail is the review page's own reading (ManagementReviewPresenter::show() for the identity),
 * narrowed to these fields, so every gate it applies holds here too: a module section's judgement
 * only when the identity may see that section, a case in Avvik og forbedringer only when it may read
 * cases in that case's fagområde.
 */
class ManagementReviewPresenter
{
    public function __construct(private readonly ReviewPagePresenter $page) {}

    /**
     * @param  Collection<int, ManagementReview>  $reviews  already reached through ManagementReviewAccessService::visibleReviews(),
     *                                                      with owner and businessAreas loaded and the decision counts
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $reviews): array
    {
        return $reviews->map(fn (ManagementReview $review): array => $this->row($review))->values()->all();
    }

    /**
     * @param  ManagementReview  $review  already reached through ManagementReviewAccessService::findVisible(), as for rows()
     * @return array<string, mixed>
     */
    public function detail(User $identity, ManagementReview $review): array
    {
        $page = $this->page->show($identity, $review);

        return $this->row($review) + [
            'participant_count' => count($page['participants']),
            'sections' => array_map(fn (array $section): array => [
                'key' => $section['key'],
                'type' => $section['type'],
                'module' => $section['module'],
                'state' => $section['state'],
                'judgement' => $section['judgement'],
            ], $page['sections']),
            // Beside the counts in 'decisions', as kpi_list beside kpis on an objective.
            'decision_list' => array_map(fn (array $decision): array => [
                'id' => $decision['id'],
                'kind' => $decision['kind'],
                'section_key' => $decision['section_key'],
                'owner' => $decision['owner_user_id'] !== null ? ['id' => $decision['owner_user_id'], 'name' => $decision['owner_name']] : null,
                'due_on' => $decision['due_date'],
                'follow_up' => $decision['follow_up'],
                'status' => $decision['status'],
                'is_overdue' => $decision['overdue'],
                'completed_at' => $this->utc($decision['completed_at']),
                // Followed up in Avvik og forbedringer: the case, when the identity may read it there.
                'improvement_case' => isset($decision['case']['id']) ? [
                    'id' => $decision['case']['id'],
                    'status' => $decision['case']['status'],
                    'due_on' => $decision['case']['due_date'],
                ] : null,
            ], $page['decisions']),
        ];
    }

    /** @return array<string, mixed> */
    private function row(ManagementReview $review): array
    {
        return [
            'id' => (int) $review->id,
            'title' => $review->title,
            'status' => $review->status,
            'period_start' => $review->period_start?->toDateString(),
            'period_end' => $review->period_end?->toDateString(),
            'meeting_date' => $review->meeting_date?->toDateString(),
            'scope' => [
                'all_business_areas' => (bool) $review->all_business_areas,
                'business_areas' => $review->all_business_areas ? [] : $review->businessAreas
                    ->map(fn ($area): array => ['id' => (int) $area->id, 'name' => $area->name])
                    ->values()
                    ->all(),
            ],
            'frameworks' => array_values((array) $review->frameworks),
            'owner' => $review->owner_user_id !== null
                ? ['id' => (int) $review->owner_user_id, 'name' => $review->owner?->name]
                : null,
            'next_review_due_on' => $review->next_review_due_on?->toDateString(),
            'finalized_at' => $review->finalized_at?->utc()->toIso8601ZuluString(),
            'decisions' => [
                'total' => (int) $review->decisions_count,
                'actions_open' => (int) $review->open_actions_count,
            ],
            'created_at' => $review->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $review->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    private function utc(?string $time): ?string
    {
        return $time !== null ? CarbonImmutable::parse($time)->utc()->toIso8601ZuluString() : null;
    }
}
