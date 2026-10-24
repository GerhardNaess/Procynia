<?php

namespace App\Services\Api\Presenters;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use App\Services\Objectives\KpiMeasurementResolver;
use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\KpiTargetPolicy;
use App\Services\Objectives\ObjectiveAttentionService;
use App\Support\Objectives\KpiPresenter as KpiPagePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A KPI as the external API presents it: structured fields only (D8).
 *
 * In: identifiers, the objective and its fagområde (a KPI has none of its own), title, status,
 * ansvarlig, the unit and the målverdi as stored, startverdi, frequency and grace days, the latest
 * measurement, the result now, the measured periods and the attention codes. Out: beskrivelse,
 * measurement comments, who registered or withdrew a measurement, withdrawal reasons, superseded and
 * withdrawn rows, process context and web routes.
 *
 * Numbers are exact decimal strings without trailing zeros («99.5»), never floats, and never
 * formatted for a language.
 *
 * The full history of one KPI (history()) is the same measured periods, every one of them, in the
 * same fields.
 *
 * Nothing is judged here. Which measurement counts is KpiMeasurementResolver's; the result now is
 * the KPI page's (the latest current measurement against today's målverdi); each measured period is
 * judged against the målverdi it was registered with, as Målehistorikk does; the attention codes are
 * ObjectiveAttentionService's.
 */
class KpiPresenter
{
    /** How many measured periods a row carries, the latest ones — the KPI page's «Utvikling over tid». */
    public const MEASUREMENT_PERIODS = 24;

    public function __construct(
        private readonly KpiPagePresenter $page,
        private readonly KpiTargetPolicy $policy,
        private readonly KpiMeasurementResolver $resolver,
        private readonly ObjectiveAttentionService $attention,
    ) {}

    /**
     * @param  Collection<int, Kpi>  $kpis  already reached through ObjectiveAccessService::visibleKpis(), with
     *                                      owner, objective, objective.owner and objective.businessArea loaded
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $kpis, CarbonImmutable $today): array
    {
        if ($kpis->isEmpty()) {
            return [];
        }

        // Withdrawn rows never count, so they are not fetched.
        $measurements = KpiMeasurement::query()
            ->whereIn('kpi_id', $kpis->modelKeys())
            ->whereNull('withdrawn_at')
            ->get()
            ->groupBy('kpi_id');
        $attention = $this->attentionByKpi($kpis, $today);

        return $kpis
            ->map(fn (Kpi $kpi): array => $this->row($kpi, $measurements->get($kpi->id, collect()), $attention[(int) $kpi->id] ?? []))
            ->values()
            ->all();
    }

    /**
     * @param  SupportCollection<int, KpiMeasurement>  $measurements
     * @param  list<string>  $attention
     * @return array<string, mixed>
     */
    private function row(Kpi $kpi, SupportCollection $measurements, array $attention): array
    {
        $current = $this->resolver->currentByPeriod($measurements);
        $latest = $this->resolver->latest($measurements);
        $responsible = $kpi->responsible();
        $target = $kpi->target();

        return [
            'id' => (int) $kpi->id,
            'objective_id' => (int) $kpi->objective_id,
            'business_area' => [
                'id' => (int) $kpi->objective->business_area_id,
                'name' => $kpi->objective->businessArea?->name,
            ],
            'title' => $kpi->title,
            'status' => $kpi->status,
            // The KPI's own owner, or else the objective's (inherited) — presentation, never stored.
            'owner' => $responsible !== null
                ? ['id' => (int) $responsible->id, 'name' => $responsible->name, 'inherited' => $kpi->owner_user_id === null]
                : null,
            'unit' => $kpi->unit,
            'unit_label' => $kpi->unit_label,
            'currency_code' => $kpi->currency_code,
            'target' => [
                'type' => $target->type(),
                'min' => $this->plain($kpi->target_min),
                'max' => $this->plain($kpi->target_max),
                'tolerance' => $this->plain($kpi->tolerance),
            ],
            'baseline' => $kpi->hasBaseline()
                ? ['value' => $this->plain($kpi->baseline_value), 'date' => $kpi->baseline_date?->toDateString()]
                : null,
            'frequency' => $kpi->frequency,
            'reporting_grace_days' => (int) $kpi->reporting_grace_days,
            'latest_measurement' => $latest !== null ? $this->measurement($latest) : null,
            'result' => $this->page->currentResult($kpi, $latest),
            'measurements' => $this->measuredPeriods($current, self::MEASUREMENT_PERIODS),
            'attention' => $attention,
            'created_at' => $kpi->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $kpi->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * Målehistorikk for one KPI: every measured period, oldest first, each with its current
     * measurement only — superseded and withdrawn rows do not count, as in the KPI rows.
     *
     * @param  Kpi  $kpi  already reached through ObjectiveAccessService::visibleKpis()
     * @return list<array{period_start: string, period_end: string, value: ?string, recorded_at: ?string, result: string}>
     */
    public function history(Kpi $kpi): array
    {
        $measurements = KpiMeasurement::query()
            ->where('kpi_id', $kpi->id)
            ->where('customer_id', $kpi->customer_id)
            ->whereNull('withdrawn_at')
            ->get();

        return $this->measuredPeriods($this->resolver->currentByPeriod($measurements), null);
    }

    /**
     * The measured periods, oldest first — the latest $periods of them when given — each judged
     * against the målverdi that applied when it was registered, not today's.
     *
     * @param  iterable<KpiMeasurement>  $current  one current measurement per period
     * @return list<array{period_start: string, period_end: string, value: ?string, recorded_at: ?string, result: string}>
     */
    private function measuredPeriods(iterable $current, ?int $periods): array
    {
        $sorted = collect($current)->sortBy([
            fn (KpiMeasurement $a, KpiMeasurement $b): int => $a->period_end <=> $b->period_end,
            fn (KpiMeasurement $a, KpiMeasurement $b): int => $a->period_start <=> $b->period_start,
        ]);

        return ($periods !== null ? $sorted->take(-$periods) : $sorted)
            ->map(fn (KpiMeasurement $measurement): array => $this->measurement($measurement) + [
                'result' => $this->policy->evaluate($measurement->snapshotTarget(), $measurement->value),
            ])
            ->values()
            ->all();
    }

    /** @return array{period_start: string, period_end: string, value: ?string, recorded_at: ?string} */
    private function measurement(KpiMeasurement $measurement): array
    {
        return [
            'period_start' => $measurement->period_start->toDateString(),
            'period_end' => $measurement->period_end->toDateString(),
            'value' => $this->plain($measurement->value),
            'recorded_at' => $measurement->recorded_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * The attention codes per KPI, over the objectives of these KPIs. Only active KPIs under active
     * objectives can have one, as on the register's panel.
     *
     * @param  Collection<int, Kpi>  $kpis
     * @return array<int, list<string>>
     */
    private function attentionByKpi(Collection $kpis, CarbonImmutable $today): array
    {
        $objectives = new Collection($kpis->map(fn (Kpi $kpi): Objective => $kpi->objective)->unique('id')->values()->all());
        $codes = [];

        foreach ($this->attention->findingsForObjectives($objectives, $today)['kpis'] as $finding) {
            $codes[(int) $finding['kpi']->id] = array_keys($finding['reasons']);
        }

        return $codes;
    }

    private function plain(?string $value): ?string
    {
        $decimal = KpiTarget::decimal($value);

        return $decimal !== null ? (string) $decimal->strippedOfTrailingZeros() : null;
    }
}
