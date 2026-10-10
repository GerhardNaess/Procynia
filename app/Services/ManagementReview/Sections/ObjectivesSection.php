<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
use App\Models\User;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;
use App\Services\Objectives\KpiMeasurementResolver;
use App\Services\Objectives\KpiTargetPolicy;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Objectives\ObjectiveAttentionService;
use App\Support\Objectives\KpiPresenter;

/**
 * Mål og KPI. Read through ObjectiveAccessService, per fagområde.
 *
 * In the period: objectives closed (achieved, not achieved, cancelled — from the immutable status
 * history), and measurements whose period ends in it, each judged against the target it was
 * registered with (the measurement's own snapshot). Now: active objectives and KPIs, each KPI's latest
 * result against today's target, and what Trenger oppmerksomhet flags.
 */
final class ObjectivesSection implements SectionBuilder
{
    public function __construct(
        private readonly ObjectiveAccessService $access,
        private readonly ObjectiveAttentionService $attention,
        private readonly KpiMeasurementResolver $resolver,
        private readonly KpiTargetPolicy $policy,
        private readonly KpiPresenter $presenter,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $payload = (new SectionPayload(true, (int) config('management_review.list_limit', 50)))
            ->metrics('period', ['objectives_achieved', 'objectives_not_achieved', 'objectives_cancelled', 'measurements', 'measurements_on_target', 'measurements_off_target'])
            ->metrics('status', ['objectives_active', 'kpis_active', 'kpis_on_target', 'kpis_attention', 'kpis_off_target', 'kpis_not_measured', 'target_date_passed', 'measurement_missing'])
            ->list('kpis')
            ->list('objectives_closed');

        $objectives = $this->access->visibleObjectives($user)
            ->whereIn('objectives.business_area_id', $areaIds === [] || $areaIds === null ? [0] : $areaIds)
            ->with(['businessArea:id,name', 'owner:id,name'])
            ->orderBy('objectives.title')
            ->orderBy('objectives.id')
            ->get(['objectives.*']);

        foreach ($objectives as $objective) {
            $payload->area((int) $objective->business_area_id, (string) $objective->businessArea?->name);
        }

        $byId = $objectives->keyBy('id');
        $area = fn (int $objectiveId): int => (int) $byId->get($objectiveId)?->business_area_id;

        // Closed in the period: the immutable history, not closed_at, which a reopening clears.
        $closings = ObjectiveStatusChange::query()
            ->whereIn('objective_id', $objectives->modelKeys() ?: [0])
            ->whereIn('to_status', Objective::CLOSED_STATUSES)
            ->where('changed_at', '>=', $scope->from())
            ->where('changed_at', '<', $scope->until())
            ->orderBy('changed_at')
            ->get();

        foreach ($closings as $closing) {
            $metric = match ($closing->to_status) {
                Objective::STATUS_ACHIEVED => 'objectives_achieved',
                Objective::STATUS_NOT_ACHIEVED => 'objectives_not_achieved',
                default => 'objectives_cancelled',
            };
            $objective = $byId->get($closing->objective_id);
            $payload->count('period', $metric, 1, $area((int) $closing->objective_id));
            $payload->item('objectives_closed', [
                'id' => (int) $objective->id,
                'title' => $objective->title,
                'url' => route('app.objectives.show', ['objectiveId' => $objective->id], false),
                'fields' => SectionPayload::fields([
                    'area' => ['text', $objective->businessArea?->name],
                    'outcome' => ['enum', $closing->to_status],
                    'closed_on' => ['date', $closing->changed_at?->toDateString()],
                ]),
            ], (int) $objective->business_area_id);
        }

        $kpis = Kpi::query()
            ->whereIn('objective_id', $objectives->modelKeys() ?: [0])
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->each(fn (Kpi $kpi) => $kpi->setRelation('objective', $byId->get($kpi->objective_id)));

        $measurements = KpiMeasurement::query()
            ->whereIn('kpi_id', $kpis->modelKeys() ?: [0])
            ->whereNull('withdrawn_at')
            ->whereBetween('period_end', [$scope->periodStart->toDateString(), $scope->periodEnd->toDateString()])
            ->get()
            ->groupBy('kpi_id');

        foreach ($measurements as $kpiId => $rows) {
            $areaId = $area((int) $kpis->firstWhere('id', $kpiId)?->objective_id);

            foreach ($this->resolver->currentByPeriod($rows) as $measurement) {
                $result = $this->policy->evaluate($measurement->snapshotTarget(), (string) $measurement->value);
                $payload->count('period', 'measurements', 1, $areaId);

                if ($result === KpiTargetPolicy::ON_TARGET) {
                    $payload->count('period', 'measurements_on_target', 1, $areaId);
                } elseif ($result === KpiTargetPolicy::OFF_TARGET) {
                    $payload->count('period', 'measurements_off_target', 1, $areaId);
                }
            }
        }

        $active = $objectives->filter(fn (Objective $objective): bool => $objective->isActive());
        $activeKpis = $kpis->filter(fn (Kpi $kpi): bool => $kpi->isActive() && (bool) $kpi->objective?->isActive())->values();
        $latest = $this->resolver->latestForKpis($activeKpis);

        foreach ($active as $objective) {
            $payload->count('status', 'objectives_active', 1, (int) $objective->business_area_id);
        }

        $rank = [KpiTargetPolicy::OFF_TARGET => 0, KpiTargetPolicy::ATTENTION => 1, KpiPresenter::NOT_MEASURED => 2, KpiTargetPolicy::ON_TARGET => 3];
        $rows = $activeKpis
            ->map(fn (Kpi $kpi): array => ['kpi' => $kpi, 'result' => $this->presenter->currentResult($kpi, $latest[(int) $kpi->id] ?? null)])
            ->sortBy(fn (array $row): string => ($rank[$row['result']] ?? 9).'|'.mb_strtolower($row['kpi']->title))
            ->values();

        foreach ($rows as ['kpi' => $kpi, 'result' => $result]) {
            $areaId = (int) $kpi->objective->business_area_id;
            $payload->count('status', 'kpis_active', 1, $areaId);
            $payload->count('status', match ($result) {
                KpiTargetPolicy::ON_TARGET => 'kpis_on_target',
                KpiTargetPolicy::ATTENTION => 'kpis_attention',
                KpiTargetPolicy::OFF_TARGET => 'kpis_off_target',
                default => 'kpis_not_measured',
            }, 1, $areaId);
            $measurement = $latest[(int) $kpi->id] ?? null;
            $payload->item('kpis', [
                'id' => (int) $kpi->id,
                'title' => $kpi->title,
                'url' => route('app.objectives.kpis.show', ['objectiveId' => $kpi->objective_id, 'kpiId' => $kpi->id], false),
                'fields' => SectionPayload::fields([
                    'objective' => ['text', $kpi->objective->title],
                    'area' => ['text', $kpi->objective->businessArea?->name],
                    'result' => ['enum', $result],
                    'last_period_end' => ['date', $measurement?->period_end?->toDateString()],
                ]),
            ], $areaId);
        }

        $findings = $this->attention->findingsForObjectives($active->values(), $scope->today);

        foreach ($findings['objectives'] as $finding) {
            if (isset($finding['reasons'][ObjectiveAttentionService::TARGET_DATE_PASSED])) {
                $payload->count('status', 'target_date_passed', 1, (int) $finding['objective']->business_area_id);
            }
        }

        foreach ($findings['kpis'] as $finding) {
            if (isset($finding['reasons'][ObjectiveAttentionService::MEASUREMENT_MISSING])) {
                $payload->count('status', 'measurement_missing', 1, $area((int) $finding['kpi']->objective_id));
            }
        }

        return $payload->toArray();
    }
}
