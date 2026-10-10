<?php

namespace App\Services\ManagementReview;

use App\Models\ImprovementCase;
use App\Models\ManagementReviewDecision;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A decision as a row of data — for «Tidligere beslutninger», for the review's own decisions in its
 * snapshot, and for the report. A tiltak in Avvik og forbedringer carries its case under `case`, with
 * the case's fagområde: SectionPresenter drops it for a reader who cannot see cases there, and the
 * decision stays.
 */
final class DecisionRows
{
    /**
     * @param  Collection<int, ManagementReviewDecision>  $decisions  with review, owner and improvementCase loaded
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $decisions, CarbonInterface $today): array
    {
        return $decisions->map(fn (ManagementReviewDecision $decision): array => $this->row($decision, $today))->values()->all();
    }

    /** @return array<string, mixed> */
    public function row(ManagementReviewDecision $decision, CarbonInterface $today): array
    {
        $row = [
            'id' => (int) $decision->id,
            'title' => $decision->text,
            'url' => route('app.management-review.show', ['reviewId' => $decision->management_review_id], false).'#decision-'.$decision->id,
            'kind' => $decision->kind,
            'section_key' => $decision->section_key,
            'review_id' => (int) $decision->management_review_id,
            'fields' => SectionPayload::fields([
                'review' => ['text', $decision->review?->title],
                'kind' => ['enum', 'kind_'.$decision->kind],
                'owner' => ['text', $decision->owner?->name],
                'due_date' => ['date', $decision->due_date?->toDateString()],
                'follow_up' => ['enum', $decision->follow_up !== null ? 'follow_up_'.$decision->follow_up : null],
                'action_status' => ['enum', $decision->isFollowedUpHere() ? 'action_'.$decision->status : null],
                'overdue' => ['enum', $decision->isOverdue($today) ? 'yes' : null],
            ]),
        ];

        $case = $decision->improvementCase;

        if ($decision->isInImprovements() && $case instanceof ImprovementCase) {
            $row['case'] = [
                'id' => (int) $case->id,
                'title' => $case->title,
                'url' => route('app.improvements.show', ['caseId' => $case->id], false),
                'area_id' => (int) $case->business_area_id,
                'fields' => SectionPayload::fields([
                    'status' => ['enum', 'case_'.$case->status],
                    'owner' => ['text', $case->owner?->name],
                    'due_date' => ['date', $case->due_date?->toDateString()],
                    'overdue' => ['enum', $case->isActive() && $case->due_date !== null && $case->due_date->toDateString() < $today->toDateString() ? 'yes' : null],
                ]),
            ];
        }

        return $row;
    }
}
