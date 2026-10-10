<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Models\User;
use App\Services\Improvements\ImprovementAttentionService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;
use Carbon\CarbonImmutable;

/**
 * Avvik og forbedringer. Read through ImprovementCaseAccessService, per fagområde.
 *
 * In the period — and in the period of the same length before it, for the trend: avvik and
 * forbedringer registered, cases closed and cancelled (from the immutable status history, never
 * closed_at, which a reopening clears), tiltak completed, and effect verifications. Now: open cases,
 * and what Trenger oppmerksomhet flags.
 */
final class ImprovementsSection implements SectionBuilder
{
    public function __construct(
        private readonly ImprovementCaseAccessService $access,
        private readonly ImprovementAttentionService $attention,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $payload = (new SectionPayload(true, (int) config('management_review.list_limit', 50)))
            ->metrics('period', ['deviations_registered', 'improvements_registered', 'cases_closed', 'cases_cancelled', 'actions_completed', 'verified_effective', 'verified_not_effective'])
            ->metrics('previous', ['deviations_registered', 'improvements_registered', 'cases_closed'])
            ->metrics('status', ['open_deviations', 'open_improvements', 'case_overdue', 'action_overdue', 'awaiting_verification', 'not_effective'])
            ->list('open_cases');

        $cases = $this->access->visibleCases($user)
            ->whereIn('improvement_cases.business_area_id', $areaIds === [] || $areaIds === null ? [0] : $areaIds)
            ->with(['businessArea:id,name', 'owner:id,name'])
            ->get(['improvement_cases.*']);

        foreach ($cases as $case) {
            $payload->area((int) $case->business_area_id, (string) $case->businessArea?->name);
        }

        $byId = $cases->keyBy('id');
        $ids = $cases->modelKeys() ?: [0];
        $areaOf = fn (int $caseId): int => (int) $byId->get($caseId)?->business_area_id;
        [$previousFrom, $previousUntil] = $scope->previousPeriod();
        $inPrevious = fn (mixed $moment): bool => $moment !== null
            && CarbonImmutable::parse($moment)->gte($previousFrom) && CarbonImmutable::parse($moment)->lt($previousUntil);

        foreach ($cases as $case) {
            $metric = $case->type === ImprovementCase::TYPE_DEVIATION ? 'deviations_registered' : 'improvements_registered';

            if ($scope->inPeriod($case->created_at)) {
                $payload->count('period', $metric, 1, (int) $case->business_area_id);
            } elseif ($inPrevious($case->created_at)) {
                $payload->count('previous', $metric, 1, (int) $case->business_area_id);
            }
        }

        $changes = ImprovementCaseStatusChange::query()
            ->whereIn('improvement_case_id', $ids)
            ->whereIn('to_status', [ImprovementCase::STATUS_CLOSED, ImprovementCase::STATUS_CANCELLED])
            ->where('changed_at', '>=', $previousFrom)
            ->where('changed_at', '<', $scope->until())
            ->get(['improvement_case_id', 'to_status', 'changed_at']);

        foreach ($changes as $change) {
            $areaId = $areaOf((int) $change->improvement_case_id);

            if ($scope->inPeriod($change->changed_at)) {
                $payload->count('period', $change->to_status === ImprovementCase::STATUS_CLOSED ? 'cases_closed' : 'cases_cancelled', 1, $areaId);
            } elseif ($change->to_status === ImprovementCase::STATUS_CLOSED) {
                $payload->count('previous', 'cases_closed', 1, $areaId);
            }
        }

        $actionCase = ImprovementAction::query()->whereIn('improvement_case_id', $ids)->pluck('improvement_case_id', 'id');

        ImprovementActionStatusChange::query()
            ->whereIn('improvement_action_id', $actionCase->keys()->all() ?: [0])
            ->where('to_status', ImprovementAction::STATUS_COMPLETED)
            ->where('changed_at', '>=', $scope->from())
            ->where('changed_at', '<', $scope->until())
            ->get(['improvement_action_id'])
            ->each(fn ($change) => $payload->count('period', 'actions_completed', 1, $areaOf((int) $actionCase->get($change->improvement_action_id))));

        ImprovementActionVerification::query()
            ->whereIn('improvement_action_id', $actionCase->keys()->all() ?: [0])
            ->where('verified_at', '>=', $scope->from())
            ->where('verified_at', '<', $scope->until())
            ->get(['improvement_action_id', 'result'])
            ->each(fn ($verification) => $payload->count(
                'period',
                $verification->result === ImprovementActionVerification::RESULT_EFFECTIVE ? 'verified_effective' : 'verified_not_effective',
                1,
                $areaOf((int) $actionCase->get($verification->improvement_action_id)),
            ));

        $active = $cases->filter(fn (ImprovementCase $case): bool => $case->isActive())
            ->sortBy(fn (ImprovementCase $case): string => ($case->type === ImprovementCase::TYPE_DEVIATION ? '0' : '1').'|'.$case->created_at?->format('YmdHis'))
            ->values();

        $findings = $this->attention->findingsForCases($active, $scope->today);

        foreach ($findings['cases'] as $row) {
            if (in_array(ImprovementAttentionService::CASE_OVERDUE, array_keys($row['reasons']), true)) {
                $payload->count('status', 'case_overdue', 1, (int) $row['case']->business_area_id);
            }
        }

        foreach ($findings['actions'] as $row) {
            $areaId = $areaOf((int) $row['action']->improvement_case_id);

            foreach ([ImprovementAttentionService::ACTION_OVERDUE, ImprovementAttentionService::AWAITING_VERIFICATION, ImprovementAttentionService::NOT_EFFECTIVE] as $key) {
                if (isset($row['reasons'][$key])) {
                    $payload->count('status', $key, 1, $areaId);
                }
            }
        }

        foreach ($active as $case) {
            $areaId = (int) $case->business_area_id;
            $payload->count('status', $case->type === ImprovementCase::TYPE_DEVIATION ? 'open_deviations' : 'open_improvements', 1, $areaId);
            $payload->item('open_cases', [
                'id' => (int) $case->id,
                'title' => $case->title,
                'url' => route('app.improvements.show', ['caseId' => $case->id], false),
                'fields' => SectionPayload::fields([
                    'type' => ['enum', $case->type],
                    'area' => ['text', $case->businessArea?->name],
                    'status' => ['enum', 'case_'.$case->status],
                    'registered_on' => ['date', $case->created_at?->toDateString()],
                    'due_date' => ['date', $case->due_date?->toDateString()],
                ]),
            ], $areaId);
        }

        return $payload->toArray();
    }
}
