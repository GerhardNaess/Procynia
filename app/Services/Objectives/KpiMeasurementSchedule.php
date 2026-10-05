<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Objective;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which periods of a KPI are expected to have a measurement, and which of them lack one.
 *
 * ONE PRINCIPLE: a period is expected only if the KPI — and its objective — was active at the end
 * of the period. That is read from the status history (kpi_status_changes and
 * objective_status_changes), not from today's status, which a reopening overwrites. So:
 *
 *  - a KPI created on 15 October is expected to report October (it was active on the 31st);
 *  - a KPI retired on 20 October is not expected to report October;
 *  - after a reopening, the periods that ended while it was retired stay unexpected;
 *  - the first expected period is the one the KPI was created in.
 *
 * A period «has a measurement» when KpiMeasurementResolver finds a current one for it, so a
 * withdrawn measurement leaves its period without one, and a correction keeps it covered.
 *
 * A change of frequency starts a new rhythm: the schedule begins after the last measured period
 * that does not fit the current frequency, so months measured before a switch to quarters do not
 * make the earlier quarters «missing».
 *
 * Only ended periods are expected; the running one is reported as upcoming. Calendar arithmetic is
 * KpiPeriods'; this adds the KPI's history and measurements.
 */
final class KpiMeasurementSchedule
{
    public function __construct(
        private readonly KpiPeriods $periods,
        private readonly KpiMeasurementResolver $resolver,
    ) {}

    /**
     * @param  iterable<KpiMeasurement>  $measurements  the KPI's measurements, withdrawn included or not
     */
    public function for(Kpi $kpi, iterable $measurements, CarbonInterface $today): KpiSchedule
    {
        if ($kpi->frequency === null) {
            return KpiSchedule::none();
        }

        $today = CarbonImmutable::instance($today)->startOfDay();
        $objective = $kpi->objective;
        $kpiChanges = $kpi->relationLoaded('statusChanges') ? $kpi->statusChanges : $kpi->statusChanges()->get();
        $objectiveChanges = $objective->relationLoaded('statusChanges') ? $objective->statusChanges : $objective->statusChanges()->get();
        $current = $this->resolver->currentByPeriod($measurements);
        $measuredNow = $kpi->isActive() && $objective->isActive();

        $latestEnded = $this->periods->previous($this->periods->containing($kpi->frequency, $today));
        $period = $this->periods->containing($kpi->frequency, $this->scheduleStart($kpi, $current));
        $missing = [];
        $pending = [];

        while ($period->end->lessThanOrEqualTo($latestEnded->end)) {
            $end = $period->end->endOfDay();
            $expected = $this->wasActiveAt($kpi->created_at, $kpiChanges, $end, Kpi::STATUS_ACTIVE)
                && $this->wasActiveAt($objective->created_at, $objectiveChanges, $end, Objective::STATUS_ACTIVE);

            if ($expected && ! isset($current[$period->rangeKey()])) {
                if ($this->periods->isOverdue($period, (int) $kpi->reporting_grace_days, $today)) {
                    $missing[] = $period;
                } else {
                    $pending[] = $period;
                }
            }

            $period = $this->periods->next($period);
        }

        return new KpiSchedule(
            $missing,
            $pending,
            $measuredNow ? $this->periods->containing($kpi->frequency, $today) : null,
            $measuredNow,
        );
    }

    /**
     * The day the schedule starts from: the KPI's creation, or — after a change of frequency — the
     * day after the last measured period that does not fit the current frequency.
     *
     * @param  array<string, KpiMeasurement>  $current
     */
    private function scheduleStart(Kpi $kpi, array $current): CarbonImmutable
    {
        $start = CarbonImmutable::instance($kpi->created_at ?? now());

        foreach ($current as $measurement) {
            $period = $this->periods->fromRange($measurement->period_start, $measurement->period_end);

            if ($period !== null && $period->frequency === $kpi->frequency) {
                continue;
            }

            $after = CarbonImmutable::instance($measurement->period_end)->addDay();

            if ($after->greaterThan($start)) {
                $start = $after;
            }
        }

        return $start;
    }

    /**
     * Whether something that starts active on creation and changes through the given history was
     * active at the given moment.
     *
     * @param  Collection<int, object{to_status: string, changed_at: CarbonInterface}>  $changes
     */
    private function wasActiveAt(?CarbonInterface $createdAt, Collection $changes, CarbonImmutable $moment, string $activeStatus): bool
    {
        if ($createdAt !== null && $createdAt->greaterThan($moment)) {
            return false;
        }

        $latest = $changes
            ->filter(fn (object $change): bool => $change->changed_at->lessThanOrEqualTo($moment))
            ->sortBy([['changed_at', 'desc'], ['id', 'desc']])
            ->first();

        return $latest === null || $latest->to_status === $activeStatus;
    }
}
