<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\User;
use App\Support\Objectives\KpiPeriodFormatter;
use App\Support\Objectives\KpiTargetFormatter;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * «Trenger oppmerksomhet» for Mål og KPI — which of the user's objectives and KPIs need someone to
 * act now.
 *
 * Four fixed rules, each read off rows that already exist and explainable in one sentence:
 *
 *  - KPI ikke på mål:      the KPI's latest current measurement, judged against today's målverdi by
 *                          KpiTargetPolicy, is attention or off target. No measurement is no finding.
 *  - Måling mangler:       KpiMeasurementSchedule says an expected period is past its deadline
 *                          without a measurement. A KPI without a frequency is never missing one.
 *  - Måldato passert:      the objective's target_date is before today — from the day after it.
 *  - Mål mangler ansvarlig: the objective has no owner (the owner's user was deleted). A KPI without
 *                          its own owner falls back to the objective's and is no finding.
 *
 * Nothing is stored: the picture is recomputed on every read, so fixing the objective or KPI is the
 * only way to clear a finding.
 *
 * ACCESS COMES FIRST. The objectives are taken from ObjectiveAccessService::visibleObjectives()
 * before anything is counted, and KPIs, status history and measurements are fetched by the ids of
 * those objectives only. An objective outside the user's fagområder never enters the set, so it
 * cannot move a count.
 *
 * Only active KPIs under active objectives take part: a closed objective silences its KPIs too, and
 * a retired KPI is never missing a measurement. Their history is still shown on their own pages.
 *
 * TWO SUBJECTS, NO SCORE. Objectives and KPIs are counted apart, each once however many rules it
 * hits: a KPI both off target and missing a measurement is one KPI; an objective with a passed
 * target date and no owner is one objective. A KPI finding never makes its objective a finding.
 *
 * A fixed number of queries whatever the number of KPIs: objectives, their status history, their
 * areas, their active KPIs, the KPIs' status history and the measurements — each once.
 */
class ObjectiveAttentionService
{
    public const KPI_OFF_TARGET = 'kpi_off_target';

    public const MEASUREMENT_MISSING = 'measurement_missing';

    public const TARGET_DATE_PASSED = 'target_date_passed';

    public const OWNER_MISSING = 'owner_missing';

    public const SUBJECT_KPI = 'kpi';

    public const SUBJECT_OBJECTIVE = 'objective';

    /** Display order, and what each category is about. */
    public const CATEGORIES = [
        self::KPI_OFF_TARGET => self::SUBJECT_KPI,
        self::MEASUREMENT_MISSING => self::SUBJECT_KPI,
        self::TARGET_DATE_PASSED => self::SUBJECT_OBJECTIVE,
        self::OWNER_MISSING => self::SUBJECT_OBJECTIVE,
    ];

    public function __construct(
        private readonly ObjectiveAccessService $access,
        private readonly KpiTargetPolicy $policy,
        private readonly KpiMeasurementResolver $resolver,
        private readonly KpiMeasurementSchedule $schedule,
    ) {}

    /**
     * The register's panel. Only categories with at least one hit are returned, in a fixed order.
     *
     * @return array{
     *     objective_total: int,
     *     kpi_total: int,
     *     categories: list<array{key: string, subject: string, count: int, items: list<array<string, mixed>>}>
     * }
     */
    public function overview(User $user, ?CarbonInterface $today = null): array
    {
        $objectives = $this->access->visibleObjectives($user)
            ->where('objectives.status', Objective::STATUS_ACTIVE)
            ->with(['businessArea:id,name', 'statusChanges'])
            ->orderBy('objectives.title')
            ->orderBy('objectives.id')
            ->get(['objectives.*']);

        $findings = $this->findings($objectives, $this->day($today));
        $byCategory = array_fill_keys(array_keys(self::CATEGORIES), []);

        foreach ($findings['objectives'] as $finding) {
            foreach ($finding['reasons'] as $key => $detail) {
                $byCategory[$key][] = $this->objectiveRow($finding['objective']) + ['detail' => $detail];
            }
        }

        foreach ($findings['kpis'] as $finding) {
            foreach ($finding['reasons'] as $key => $detail) {
                $byCategory[$key][] = $this->kpiRow($finding['kpi']) + ['detail' => $detail];
            }
        }

        return [
            'objective_total' => count($findings['objectives']),
            'kpi_total' => count($findings['kpis']),
            'categories' => collect($byCategory)
                ->filter(fn (array $items): bool => $items !== [])
                ->map(fn (array $items, string $key): array => [
                    'key' => $key,
                    'subject' => self::CATEGORIES[$key],
                    'count' => count($items),
                    'items' => $items,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * One objective's own findings and how many of its KPIs need attention — for a small indicator
     * on the objective page, not the panel again. The objective must already have been reached
     * through ObjectiveAccessService. Null when there is nothing to say.
     *
     * @return array{kpi_count: int, reasons: list<array{key: string, detail: string}>}|null
     */
    public function forObjective(Objective $objective, ?CarbonInterface $today = null): ?array
    {
        if (! $objective->isActive()) {
            return null;
        }

        $objective->loadMissing(['businessArea:id,name', 'statusChanges']);
        $findings = $this->findings(new EloquentCollection([$objective]), $this->day($today));
        $reasons = [];

        foreach ($findings['objectives'] as $finding) {
            foreach ($finding['reasons'] as $key => $detail) {
                $reasons[] = ['key' => $key, 'detail' => $detail];
            }
        }

        if ($reasons === [] && $findings['kpis'] === []) {
            return null;
        }

        return ['kpi_count' => count($findings['kpis']), 'reasons' => $reasons];
    }

    /**
     * The flagged objectives and KPIs among the given active objectives, each with its reasons keyed
     * by category, in the objectives' order. Objectives and KPIs without a finding are left out.
     *
     * @param  EloquentCollection<int, Objective>  $objectives  active, statusChanges and businessArea loaded
     * @return array{
     *     objectives: list<array{objective: Objective, reasons: array<string, string>}>,
     *     kpis: list<array{kpi: Kpi, reasons: array<string, string>}>
     * }
     */
    private function findings(EloquentCollection $objectives, CarbonImmutable $today): array
    {
        $flaggedObjectives = [];
        $flaggedKpis = [];

        if ($objectives->isEmpty()) {
            return ['objectives' => [], 'kpis' => []];
        }

        $kpis = $this->activeKpis($objectives);
        $measurements = $this->measurementsByKpi($kpis);
        $periodFormatter = new KpiPeriodFormatter;

        foreach ($objectives as $objective) {
            $reasons = [];

            if ($objective->target_date !== null && CarbonImmutable::instance($objective->target_date)->startOfDay()->lessThan($today)) {
                $reasons[self::TARGET_DATE_PASSED] = (string) __('procynia.objectives.attention.reasons.target_date_passed', [
                    'date' => $periodFormatter->date($objective->target_date),
                ]);
            }

            if ($objective->owner_user_id === null) {
                $reasons[self::OWNER_MISSING] = (string) __('procynia.objectives.attention.reasons.owner_missing');
            }

            if ($reasons !== []) {
                $flaggedObjectives[] = ['objective' => $objective, 'reasons' => $reasons];
            }

            foreach ($kpis->get($objective->id, []) as $kpi) {
                $reasons = $this->kpiReasons($kpi, $measurements->get($kpi->id, collect()), $today, $periodFormatter);

                if ($reasons !== []) {
                    $flaggedKpis[] = ['kpi' => $kpi, 'reasons' => $reasons];
                }
            }
        }

        return ['objectives' => $flaggedObjectives, 'kpis' => $flaggedKpis];
    }

    /**
     * @param  Collection<int, KpiMeasurement>  $measurements  the KPI's non-withdrawn measurements
     * @return array<string, string>
     */
    private function kpiReasons(Kpi $kpi, Collection $measurements, CarbonImmutable $today, KpiPeriodFormatter $periodFormatter): array
    {
        $reasons = [];
        $latest = $this->resolver->latest($measurements);

        if ($latest !== null && $this->policy->evaluate($kpi->target(), $latest->value) !== KpiTargetPolicy::ON_TARGET) {
            $formatter = new KpiTargetFormatter;
            $below = $kpi->target()->min !== null && BigDecimal::of($latest->value)->isLessThan($kpi->target()->min);

            $reasons[self::KPI_OFF_TARGET] = (string) __('procynia.objectives.attention.reasons.'.($below ? 'kpi_below_target' : 'kpi_above_target'), [
                'value' => $formatter->amount($kpi, $latest->value),
                'target' => $formatter->target($kpi),
            ]);
        }

        $schedule = $this->schedule->for($kpi, $measurements, $today);

        if ($schedule->measurementMissing()) {
            $count = count($schedule->missing);
            $period = $periodFormatter->label($schedule->oldestMissing());

            $reasons[self::MEASUREMENT_MISSING] = $count === 1
                ? (string) __('procynia.objectives.attention.reasons.measurement_missing_one', ['period' => $period])
                : (string) __('procynia.objectives.attention.reasons.measurement_missing_many', ['count' => $count, 'period' => $period]);
        }

        return $reasons;
    }

    /**
     * The active KPIs of the given objectives, with their status history and their objective set,
     * grouped by objective and ordered by title — one query for the KPIs, one for the history.
     *
     * @param  EloquentCollection<int, Objective>  $objectives
     * @return Collection<int, EloquentCollection<int, Kpi>>
     */
    private function activeKpis(EloquentCollection $objectives): Collection
    {
        $byId = $objectives->keyBy('id');

        return Kpi::query()
            ->where('customer_id', (int) $objectives->first()->customer_id)
            ->whereIn('objective_id', $objectives->modelKeys())
            ->where('status', Kpi::STATUS_ACTIVE)
            ->with('statusChanges')
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            // The schedule reads the objective's status history; it is already at hand.
            ->each(fn (Kpi $kpi) => $kpi->setRelation('objective', $byId->get($kpi->objective_id)))
            ->groupBy('objective_id');
    }

    /**
     * Every non-withdrawn measurement of the given KPIs, in one query, grouped by KPI. Withdrawn rows
     * never count for the resolver or the schedule, so they are not fetched.
     *
     * @param  Collection<int, EloquentCollection<int, Kpi>>  $kpisByObjective
     * @return Collection<int, Collection<int, KpiMeasurement>>
     */
    private function measurementsByKpi(Collection $kpisByObjective): Collection
    {
        $ids = $kpisByObjective->flatten()->map(fn (Kpi $kpi): int => (int) $kpi->id)->all();

        if ($ids === []) {
            return collect();
        }

        return KpiMeasurement::query()
            ->whereIn('kpi_id', $ids)
            ->whereNull('withdrawn_at')
            ->get()
            ->groupBy('kpi_id');
    }

    private function day(?CarbonInterface $today): CarbonImmutable
    {
        return CarbonImmutable::parse(($today ?? now())->toDateString());
    }

    /** @return array{id: int, title: string, objective_title: ?string, area_name: ?string, url: string} */
    private function objectiveRow(Objective $objective): array
    {
        return [
            'id' => (int) $objective->id,
            'title' => $objective->title,
            'objective_title' => null,
            'area_name' => $objective->businessArea?->name,
            'url' => route('app.objectives.show', ['objectiveId' => $objective->id]),
        ];
    }

    /** @return array{id: int, title: string, objective_title: ?string, area_name: ?string, url: string} */
    private function kpiRow(Kpi $kpi): array
    {
        return [
            'id' => (int) $kpi->id,
            'title' => $kpi->title,
            'objective_title' => $kpi->objective->title,
            'area_name' => $kpi->objective->businessArea?->name,
            'url' => route('app.objectives.kpis.show', ['objectiveId' => $kpi->objective_id, 'kpiId' => $kpi->id]),
        ];
    }
}
