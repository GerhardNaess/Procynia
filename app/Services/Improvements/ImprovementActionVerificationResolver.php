<?php

namespace App\Services\Improvements;

use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;

/**
 * The one definition of «the current effektverifisering of a tiltak».
 *
 *  - A tiltak's CURRENT COMPLETION is the newest status change (changed_at, then id) that set it to
 *    completed — and only while the tiltak is completed. A planned, under arbeid or cancelled tiltak
 *    has none, whatever its history holds.
 *  - The CURRENT VERIFICATION is the newest verification (verified_at, then id) of that completion.
 *    A verification of an earlier completion never counts: a reopened and again completed tiltak is
 *    unverified until someone judges the new completion.
 *
 * Batched: two queries for any number of tiltak, whatever their statuses. Has no access rules of its
 * own — give it only tiltak reached through ImprovementCaseAccessService::visibleCases().
 */
class ImprovementActionVerificationResolver
{
    /**
     * For each completed tiltak among the given ones: its current completion and that completion's
     * current verification (null when it has none). Tiltak that are not completed are absent.
     *
     * @param  iterable<ImprovementAction>  $actions
     * @return array<int, array{completion: ImprovementActionStatusChange, verification: ?ImprovementActionVerification}>
     */
    public function forActions(iterable $actions): array
    {
        $completedIds = [];

        foreach ($actions as $action) {
            if ($action->status === ImprovementAction::STATUS_COMPLETED) {
                $completedIds[] = (int) $action->id;
            }
        }

        if ($completedIds === []) {
            return [];
        }

        $completions = ImprovementActionStatusChange::query()
            ->whereIn('improvement_action_id', $completedIds)
            ->where('to_status', ImprovementAction::STATUS_COMPLETED)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('improvement_action_id')
            ->keyBy('improvement_action_id');

        $verifications = $completions->isEmpty() ? collect() : ImprovementActionVerification::query()
            ->whereIn('completion_status_change_id', $completions->pluck('id')->all())
            ->with('verifiedBy:id,name')
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->get()
            ->unique('completion_status_change_id')
            ->keyBy('completion_status_change_id');

        $current = [];

        foreach ($completions as $actionId => $completion) {
            $current[(int) $actionId] = [
                'completion' => $completion,
                'verification' => $verifications->get($completion->id),
            ];
        }

        return $current;
    }
}
