<?php

namespace App\Support\Objectives;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Models\User;
use App\Services\Objectives\KpiMeasurementResolver;
use App\Services\Objectives\KpiMeasurementSchedule;
use App\Services\Objectives\KpiPeriod;
use App\Services\Objectives\KpiPeriods;
use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\KpiTargetPolicy;
use App\Services\Objectives\ObjectiveAccessService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a KPI looks like on a page, in one place for the objective's KPI list, the register and the
 * KPI page.
 *
 * The responsible person is the KPI's own owner or, failing that, the objective's owner — marked
 * as the fallback so the page can say so. That is decided here, on the way out; the objective's
 * owner is never written into the KPI.
 *
 * TWO RESULTS, ON PURPOSE. The KPI's result now is its latest current measurement judged against
 * the målverdi as it is defined today. A row in the measurement history is judged against the
 * snapshot it was registered with. When the target has been tightened since, the same value can be
 * «Utenfor mål» today and «På mål» in the history — both are true. Neither is stored.
 */
final class KpiPresenter
{
    /** No current measurement: «Ikke målt». Not a KpiTargetPolicy result — there is nothing to judge. */
    public const NOT_MEASURED = 'not_measured';

    /** How far back Registrer måling offers periods, beyond the one it suggests. */
    private const PERIOD_OPTIONS = [
        Kpi::FREQUENCY_WEEKLY => 52,
        Kpi::FREQUENCY_MONTHLY => 24,
        Kpi::FREQUENCY_QUARTERLY => 12,
        Kpi::FREQUENCY_YEARLY => 5,
    ];

    public function __construct(
        private readonly ObjectiveAccessService $access,
        private readonly KpiTargetPolicy $policy,
        private readonly KpiMeasurementResolver $resolver,
        private readonly KpiMeasurementSchedule $schedule,
        private readonly KpiPeriods $periods,
    ) {}

    /**
     * A row for the objective's KPI list. Expects owner and objective.owner loaded, and the KPI's
     * latest current measurement from KpiMeasurementResolver (null when there is none).
     *
     * @return array<string, mixed>
     */
    public function row(Kpi $kpi, ?KpiMeasurement $latest): array
    {
        $formatter = new KpiTargetFormatter;
        $responsible = $kpi->responsible();

        return [
            'id' => (int) $kpi->id,
            'title' => $kpi->title,
            'status' => $kpi->status,
            'unit' => $kpi->unit,
            'unit_display' => $formatter->unit($kpi),
            'target_display' => $formatter->target($kpi),
            'frequency' => $kpi->frequency,
            'responsible_name' => $responsible?->name,
            'responsible_is_fallback' => $kpi->owner_user_id === null && $responsible !== null,
            'latest_value_display' => $latest !== null ? $formatter->amount($kpi, $latest->value) : null,
            'latest_period_label' => $latest !== null ? (new KpiPeriodFormatter)->range($latest->period_start, $latest->period_end) : null,
            'result' => $this->currentResult($kpi, $latest),
            'url' => route('app.objectives.kpis.show', ['objectiveId' => $kpi->objective_id, 'kpiId' => $kpi->id]),
        ];
    }

    /** The KPI's result now: its latest current measurement against today's målverdi. */
    public function currentResult(Kpi $kpi, ?KpiMeasurement $latest): string
    {
        return $latest !== null ? $this->policy->evaluate($kpi->target(), $latest->value) : self::NOT_MEASURED;
    }

    /**
     * «2 av 3 KPI-er på mål»: of the active KPIs, how many have a latest measurement on target
     * against today's målverdi. A KPI without a measurement is not on target. A count, not a score.
     * Null when the objective has no active KPI.
     *
     * @param  iterable<Kpi>  $kpis
     * @param  array<int, KpiMeasurement>  $latestByKpi  from KpiMeasurementResolver::latestForKpis()
     * @return array{on_target: int, total: int}|null
     */
    public function indicator(iterable $kpis, array $latestByKpi): ?array
    {
        $active = collect($kpis)->filter(fn (Kpi $kpi): bool => $kpi->isActive());

        if ($active->isEmpty()) {
            return null;
        }

        return [
            'on_target' => $active
                ->filter(fn (Kpi $kpi): bool => $this->currentResult($kpi, $latestByKpi[(int) $kpi->id] ?? null) === KpiTargetPolicy::ON_TARGET)
                ->count(),
            'total' => $active->count(),
        ];
    }

    /**
     * Everything the KPI page shows, plus the stored values its edit form starts from.
     *
     * @param  Collection<int, KpiMeasurement>  $measurements  all of the KPI's, withdrawn included
     * @return array<string, mixed>
     */
    public function detail(Kpi $kpi, Collection $measurements, CarbonInterface $today): array
    {
        $formatter = new KpiTargetFormatter;
        $periodFormatter = new KpiPeriodFormatter;
        $latest = $this->resolver->latest($measurements);
        $schedule = $this->schedule->for($kpi, $measurements, $today);
        $pending = $schedule->pending[0] ?? null;

        return $this->row($kpi, $latest) + [
            'objective_id' => (int) $kpi->objective_id,
            'description' => $kpi->description,
            'owner_user_id' => $kpi->owner_user_id !== null ? (int) $kpi->owner_user_id : null,
            'unit_label' => $kpi->unit_label,
            'currency_code' => $kpi->currency_code,
            // Plain decimals for the form, without the trailing zeros of the column.
            'target_min' => $this->plain($kpi->target_min),
            'target_max' => $this->plain($kpi->target_max),
            'tolerance' => $this->plain($kpi->tolerance),
            'tolerance_display' => $kpi->target()->hasTolerance()
                ? $formatter->amount($kpi, $kpi->tolerance)
                : null,
            'reporting_grace_days' => (int) $kpi->reporting_grace_days,
            'deadline_display' => $kpi->frequency !== null
                ? trans_choice('procynia.objectives.kpi.deadline_value', (int) $kpi->reporting_grace_days, ['count' => (int) $kpi->reporting_grace_days])
                : __('procynia.objectives.kpi.deadline_without_frequency'),
            // What a number means is fixed once one has been registered.
            'unit_locked' => $measurements->isNotEmpty(),
            'latest_recorded_at' => $latest?->recorded_at?->toIso8601String(),
            'schedule' => [
                'measurement_missing' => $schedule->measurementMissing(),
                'missing_label' => $schedule->measurementMissing() ? $periodFormatter->label($schedule->oldestMissing()) : null,
                'missing_count' => $schedule->measurementMissing() ? count($schedule->missing) : 0,
                'missing_count_display' => $schedule->measurementMissing() && count($schedule->missing) > 1
                    ? trans_choice('procynia.objectives.measurement.missing_more', count($schedule->missing), ['count' => count($schedule->missing)])
                    : null,
                'pending_label' => $pending !== null ? $periodFormatter->label($pending) : null,
                'pending_deadline' => $pending !== null
                    ? $periodFormatter->date($this->periods->reportingDeadline($pending, (int) $kpi->reporting_grace_days))
                    : null,
                'upcoming_label' => $schedule->upcoming !== null ? $periodFormatter->label($schedule->upcoming) : null,
                'upcoming_from' => $schedule->upcoming !== null ? $periodFormatter->date($schedule->upcoming->end->addDay()) : null,
            ],
            'updated_at' => $kpi->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Målehistorikk: every measurement, newest period first and, within a period, the newest
     * registration first. Each judged against the målverdi it was registered with, and marked
     * current, superseded (Erstattet) or withdrawn (Tilbaketrukket). Expects recordedBy and
     * withdrawnBy loaded.
     *
     * @param  Collection<int, KpiMeasurement>  $measurements
     * @return list<array<string, mixed>>
     */
    public function history(Kpi $kpi, Collection $measurements, bool $canWithdraw): array
    {
        $formatter = new KpiTargetFormatter;
        $periodFormatter = new KpiPeriodFormatter;
        $states = $this->resolver->states($measurements);

        return $measurements
            ->sortBy([
                fn (KpiMeasurement $a, KpiMeasurement $b): int => $b->period_end <=> $a->period_end,
                fn (KpiMeasurement $a, KpiMeasurement $b): int => $b->period_start <=> $a->period_start,
                fn (KpiMeasurement $a, KpiMeasurement $b): int => $b->recorded_at <=> $a->recorded_at,
                fn (KpiMeasurement $a, KpiMeasurement $b): int => (int) $b->id <=> (int) $a->id,
            ])
            ->map(fn (KpiMeasurement $measurement): array => [
                'id' => (int) $measurement->id,
                'period_label' => $periodFormatter->range($measurement->period_start, $measurement->period_end),
                'value_display' => $formatter->amount($kpi, $measurement->value),
                'state' => $states[(int) $measurement->id],
                // Against the target that applied when it was registered — not today's.
                'result' => $this->policy->evaluate($measurement->snapshotTarget(), $measurement->value),
                'target_display' => $formatter->target($kpi, $measurement->snapshotTarget()),
                'comment' => $measurement->comment,
                'recorded_by_name' => $measurement->recordedBy?->name,
                'recorded_at' => $measurement->recorded_at?->toIso8601String(),
                'withdrawn_at' => $measurement->withdrawn_at?->toIso8601String(),
                'withdrawn_by_name' => $measurement->withdrawnBy?->name,
                'withdrawal_reason' => $measurement->withdrawal_reason,
                'can_withdraw' => $canWithdraw && ! $measurement->isWithdrawn(),
            ])
            ->values()
            ->all();
    }

    /**
     * What Registrer måling chooses from. A KPI with a frequency picks an ended calendar period —
     * the suggested one first chosen: the oldest expected period without a measurement, or else the
     * latest ended one. A KPI without picks a day up to today. Either way the page is told which
     * choices already have a value, so it can say the new one is a correction.
     *
     * @param  Collection<int, KpiMeasurement>  $measurements
     * @return array<string, mixed>
     */
    public function measurementForm(Kpi $kpi, Collection $measurements, CarbonInterface $today): array
    {
        $formatter = new KpiTargetFormatter;
        $periodFormatter = new KpiPeriodFormatter;
        $current = $this->resolver->currentByPeriod($measurements);
        $valueFor = fn (KpiPeriod $period): ?string => isset($current[$period->rangeKey()])
            ? $formatter->amount($kpi, $current[$period->rangeKey()]->value)
            : null;

        if ($kpi->frequency === null) {
            $existing = [];

            foreach ($current as $measurement) {
                if ($measurement->period_start->equalTo($measurement->period_end)) {
                    $existing[$measurement->period_start->format('Y-m-d')] = $formatter->amount($kpi, $measurement->value);
                }
            }

            return [
                'mode' => 'date',
                'today' => $today->format('Y-m-d'),
                'existing_by_date' => $existing,
            ];
        }

        $suggested = $this->schedule->for($kpi, $measurements, $today)->suggested();
        $period = $this->periods->previous($this->periods->containing($kpi->frequency, $today));
        $options = [];

        for ($count = 0; $count < self::PERIOD_OPTIONS[$kpi->frequency]
            || ($suggested !== null && $period->start->greaterThanOrEqualTo($suggested->start)); $count++) {
            $options[] = [
                'key' => $period->key,
                'label' => $periodFormatter->label($period),
                'current_value_display' => $valueFor($period),
            ];
            $period = $this->periods->previous($period);
        }

        return [
            'mode' => 'period',
            'period_options' => $options,
            'suggested_key' => $suggested?->key ?? $options[0]['key'],
        ];
    }

    /**
     * The people who may own a KPI under this objective: active users of the same customer who can
     * read objectives in its fagområde. The server checks the same again on save.
     *
     * @return list<array{id: int, name: string}>
     */
    public function ownerOptions(Objective $objective): array
    {
        $viewerAreas = $this->access->viewerAreaIdsByUser((int) $objective->customer_id);
        $areaId = (int) $objective->business_area_id;

        return User::query()
            ->where('customer_id', (int) $objective->customer_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $candidate): bool => in_array($areaId, $viewerAreas[(int) $candidate->id] ?? [], true))
            ->map(fn (User $candidate): array => ['id' => (int) $candidate->id, 'name' => $candidate->name])
            ->values()
            ->all();
    }

    /**
     * What the KPI form chooses from.
     *
     * @return array<string, mixed>
     */
    public function formOptions(Objective $objective): array
    {
        return [
            'units' => Kpi::UNITS,
            'labelled_units' => Kpi::LABELLED_UNITS,
            'frequencies' => Kpi::FREQUENCIES,
            'default_currency_code' => Kpi::DEFAULT_CURRENCY_CODE,
            'default_grace_days' => Kpi::DEFAULT_REPORTING_GRACE_DAYS,
            'owner_options' => $this->ownerOptions($objective),
            'objective_owner_name' => $objective->owner?->name,
        ];
    }

    private function plain(?string $value): ?string
    {
        $decimal = KpiTarget::decimal($value);

        return $decimal !== null ? (string) $decimal->strippedOfTrailingZeros() : null;
    }
}
