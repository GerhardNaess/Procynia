<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use Illuminate\Support\Collection;

/**
 * Which measurement counts. The one place this is decided — the KPI page, the objective's KPI list,
 * the register's indicator and the missing-measurement schedule all ask here.
 *
 * For one period (period_start, period_end): the newest row that is not withdrawn, by recorded_at
 * and then id. Older rows for the period are superseded (Erstattet); withdrawn rows never count.
 * When the newest row is withdrawn, the one before it counts again; when every row is withdrawn,
 * the period has no measurement.
 *
 * For the KPI as a whole: among each period's current measurement, the one whose period ends last;
 * between periods ending on the same day (after a change of frequency), the latest registration.
 */
final class KpiMeasurementResolver
{
    public const STATE_CURRENT = 'current';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_WITHDRAWN = 'withdrawn';

    /**
     * The current measurement of each period, keyed by KpiMeasurement::periodKey().
     *
     * @param  iterable<KpiMeasurement>  $measurements  one KPI's measurements, in any order
     * @return array<string, KpiMeasurement>
     */
    public function currentByPeriod(iterable $measurements): array
    {
        $current = [];

        foreach ($measurements as $measurement) {
            if ($measurement->isWithdrawn()) {
                continue;
            }

            $key = $measurement->periodKey();

            if (! isset($current[$key]) || $this->registeredLater($measurement, $current[$key])) {
                $current[$key] = $measurement;
            }
        }

        return $current;
    }

    /**
     * The KPI's latest current measurement, or null when nothing counts.
     *
     * @param  iterable<KpiMeasurement>  $measurements  one KPI's measurements, in any order
     */
    public function latest(iterable $measurements): ?KpiMeasurement
    {
        $latest = null;

        foreach ($this->currentByPeriod($measurements) as $measurement) {
            if ($latest === null) {
                $latest = $measurement;

                continue;
            }

            $comparison = $measurement->period_end->getTimestamp() <=> $latest->period_end->getTimestamp();

            if ($comparison > 0 || ($comparison === 0 && $this->registeredLater($measurement, $latest))) {
                $latest = $measurement;
            }
        }

        return $latest;
    }

    /**
     * Each measurement's state in the history: current, superseded or withdrawn, keyed by id.
     *
     * @param  iterable<KpiMeasurement>  $measurements  one KPI's measurements
     * @return array<int, string>
     */
    public function states(iterable $measurements): array
    {
        $currentIds = array_map(fn (KpiMeasurement $measurement): int => (int) $measurement->id, $this->currentByPeriod($measurements));
        $states = [];

        foreach ($measurements as $measurement) {
            $states[(int) $measurement->id] = match (true) {
                $measurement->isWithdrawn() => self::STATE_WITHDRAWN,
                in_array((int) $measurement->id, $currentIds, true) => self::STATE_CURRENT,
                default => self::STATE_SUPERSEDED,
            };
        }

        return $states;
    }

    /**
     * The latest current measurement of each KPI, in one query for all of them — for the objective
     * page and the register, which must not ask per KPI. KPIs without one are absent.
     *
     * @param  Collection<int, Kpi>|iterable<Kpi>  $kpis
     * @return array<int, KpiMeasurement> keyed by KPI id
     */
    public function latestForKpis(iterable $kpis): array
    {
        $ids = collect($kpis)->map(fn (Kpi $kpi): int => (int) $kpi->id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return KpiMeasurement::query()
            ->whereIn('kpi_id', $ids)
            ->whereNull('withdrawn_at')
            ->get()
            ->groupBy('kpi_id')
            ->map(fn (Collection $rows): ?KpiMeasurement => $this->latest($rows))
            ->filter()
            ->mapWithKeys(fn (KpiMeasurement $measurement, int|string $kpiId): array => [(int) $kpiId => $measurement])
            ->all();
    }

    private function registeredLater(KpiMeasurement $candidate, KpiMeasurement $than): bool
    {
        $comparison = $candidate->recorded_at->getTimestamp() <=> $than->recorded_at->getTimestamp();

        return $comparison > 0 || ($comparison === 0 && (int) $candidate->id > (int) $than->id);
    }
}
