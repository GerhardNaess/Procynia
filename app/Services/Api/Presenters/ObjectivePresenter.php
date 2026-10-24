<?php

namespace App\Services\Api\Presenters;

use App\Models\Kpi;
use App\Models\Objective;
use App\Services\Objectives\KpiMeasurementResolver;
use App\Services\Objectives\ObjectiveAttentionService;
use App\Support\Objectives\KpiPresenter as KpiPagePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * A mål as the external API presents it: structured fields only (D8).
 *
 * In: identifiers, title, status, fagområde, ansvarlig, måldato, when it was closed, its KPIs
 * counted (all, active, on target, needing attention) and its own attention codes. Out: beskrivelse,
 * avslutningsnotat, who closed it, the status history, process context and web routes.
 *
 * Nothing is judged here. «On target» is KpiPresenter::indicator() — the register's «2 av 3 KPI-er
 * på mål» — and the attention codes and the KPI count needing attention are ObjectiveAttentionService's
 * findings, the same rules as «Trenger oppmerksomhet». A KPI finding never becomes an objective code.
 */
class ObjectivePresenter
{
    public function __construct(
        private readonly KpiPagePresenter $kpis,
        private readonly KpiMeasurementResolver $resolver,
        private readonly ObjectiveAttentionService $attention,
    ) {}

    /**
     * @param  Collection<int, Objective>  $objectives  already reached through ObjectiveAccessService::visibleObjectives(),
     *                                                  with businessArea and owner loaded
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $objectives, CarbonImmutable $today): array
    {
        if ($objectives->isEmpty()) {
            return [];
        }

        // The KPIs of these objectives only — they were reached through the objectives.
        $kpis = Kpi::query()
            ->where('customer_id', (int) $objectives->first()->customer_id)
            ->whereIn('objective_id', $objectives->modelKeys())
            ->get();
        $latest = $this->resolver->latestForKpis($kpis);
        $kpisByObjective = $kpis->groupBy('objective_id');

        $findings = $this->attention->findingsForObjectives($objectives, $today);
        $codes = [];
        $kpisNeedingAttention = [];

        foreach ($findings['objectives'] as $finding) {
            $codes[(int) $finding['objective']->id] = array_keys($finding['reasons']);
        }

        foreach ($findings['kpis'] as $finding) {
            $objectiveId = (int) $finding['kpi']->objective_id;
            $kpisNeedingAttention[$objectiveId] = ($kpisNeedingAttention[$objectiveId] ?? 0) + 1;
        }

        return $objectives
            ->map(function (Objective $objective) use ($kpisByObjective, $latest, $codes, $kpisNeedingAttention): array {
                $own = $kpisByObjective->get($objective->id, collect());
                $indicator = $this->kpis->indicator($own, $latest);

                return [
                    'id' => (int) $objective->id,
                    'title' => $objective->title,
                    'status' => $objective->status,
                    'business_area' => [
                        'id' => (int) $objective->business_area_id,
                        'name' => $objective->businessArea?->name,
                    ],
                    'owner' => $objective->owner_user_id !== null
                        ? ['id' => (int) $objective->owner_user_id, 'name' => $objective->owner?->name]
                        : null,
                    'target_date' => $objective->target_date?->toDateString(),
                    'closed_at' => $objective->closed_at?->utc()->toIso8601ZuluString(),
                    'kpis' => [
                        'total' => $own->count(),
                        'active' => $indicator['total'] ?? 0,
                        'on_target' => $indicator['on_target'] ?? 0,
                        'needing_attention' => $kpisNeedingAttention[(int) $objective->id] ?? 0,
                    ],
                    'attention' => $codes[(int) $objective->id] ?? [],
                    'created_at' => $objective->created_at?->utc()->toIso8601ZuluString(),
                    'updated_at' => $objective->updated_at?->utc()->toIso8601ZuluString(),
                ];
            })
            ->values()
            ->all();
    }
}
