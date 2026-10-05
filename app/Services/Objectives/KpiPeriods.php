<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendar periods for KPIs, and when a period's measurement is due.
 *
 * Periods follow the calendar, never an interval added to a date: a month is the calendar month
 * (31 January is followed by February, not 3 March), a quarter is Q1–Q4, a year is the calendar
 * year, and a week is the ISO week (Monday–Sunday, numbered by its Thursday, so 29 December 2025 is
 * in 2026-W01 and 1 January 2027 is in 2026-W53).
 *
 * The next and previous period are found from the day after the end and the day before the start,
 * so no period length is ever assumed.
 *
 * A period's measurement is due on its last day plus the KPI's reporting_grace_days; only from the
 * day after that does a missing measurement count as missing. With 0 grace days, the day after the
 * period ends.
 *
 * Pure date arithmetic, no data: this does not know whether a measurement exists.
 */
final class KpiPeriods
{
    /** The period the given day falls in. */
    public function containing(string $frequency, CarbonInterface $date): KpiPeriod
    {
        $day = CarbonImmutable::instance($date)->startOfDay();

        return match ($frequency) {
            Kpi::FREQUENCY_WEEKLY => $this->period(
                $frequency,
                $day->startOfWeek(CarbonInterface::MONDAY),
                $day->endOfWeek(CarbonInterface::SUNDAY),
                // ISO year (o) with ISO week (W): the week of 29.12.2025 is 2026-W01.
                $day->format('o-\WW'),
            ),
            Kpi::FREQUENCY_MONTHLY => $this->period($frequency, $day->startOfMonth(), $day->endOfMonth(), $day->format('Y-m')),
            Kpi::FREQUENCY_QUARTERLY => $this->period(
                $frequency,
                $day->startOfQuarter(),
                $day->endOfQuarter(),
                $day->format('Y').'-Q'.$day->quarter,
            ),
            Kpi::FREQUENCY_YEARLY => $this->period($frequency, $day->startOfYear(), $day->endOfYear(), $day->format('Y')),
            default => throw new InvalidArgumentException("Unknown KPI frequency [{$frequency}]."),
        };
    }

    public function next(KpiPeriod $period): KpiPeriod
    {
        return $this->containing($period->frequency, $period->end->addDay());
    }

    public function previous(KpiPeriod $period): KpiPeriod
    {
        return $this->containing($period->frequency, $period->start->subDay());
    }

    /**
     * A period from its key, for a period someone chose. Refuses anything that is not the canonical
     * key of a real period (2026-13, 2026-Q5, 2025-W53 — 2025 has 52 ISO weeks).
     */
    public function fromKey(string $frequency, string $key): KpiPeriod
    {
        $day = match ($frequency) {
            Kpi::FREQUENCY_WEEKLY => preg_match('/^(\d{4})-W(\d{2})$/', $key, $m) === 1
                ? CarbonImmutable::now()->setISODate((int) $m[1], (int) $m[2])
                : null,
            Kpi::FREQUENCY_MONTHLY => preg_match('/^(\d{4})-(\d{2})$/', $key, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12
                ? CarbonImmutable::create((int) $m[1], (int) $m[2], 1)
                : null,
            Kpi::FREQUENCY_QUARTERLY => preg_match('/^(\d{4})-Q([1-4])$/', $key, $m) === 1
                ? CarbonImmutable::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1)
                : null,
            Kpi::FREQUENCY_YEARLY => preg_match('/^(\d{4})$/', $key, $m) === 1
                ? CarbonImmutable::create((int) $m[1], 1, 1)
                : null,
            default => throw new InvalidArgumentException("Unknown KPI frequency [{$frequency}]."),
        };

        $period = $day !== null ? $this->containing($frequency, $day) : null;

        // setISODate() rolls week 53 of a 52-week year into the next year; the round trip catches it.
        if ($period === null || $period->key !== $key) {
            throw new InvalidArgumentException("Not a {$frequency} period [{$key}].");
        }

        return $period;
    }

    /** The last day a measurement for the period is on time. */
    public function reportingDeadline(KpiPeriod $period, int $graceDays): CarbonImmutable
    {
        if ($graceDays < 0) {
            throw new InvalidArgumentException('Reporting grace days cannot be negative.');
        }

        return $period->end->addDays($graceDays);
    }

    /** Whether, on the given day, a measurement for the period that does not exist counts as missing. */
    public function isOverdue(KpiPeriod $period, int $graceDays, CarbonInterface $today): bool
    {
        return CarbonImmutable::instance($today)->startOfDay()->greaterThan($this->reportingDeadline($period, $graceDays));
    }

    /**
     * The latest period whose measurement is due by the given day — the one a missing measurement
     * would be reported for. The current period is never due while it runs; the one before it is
     * due once its grace days have passed.
     */
    public function latestDuePeriod(string $frequency, int $graceDays, CarbonInterface $today): KpiPeriod
    {
        $period = $this->previous($this->containing($frequency, $today));

        while (! $this->isOverdue($period, $graceDays, $today)) {
            $period = $this->previous($period);
        }

        return $period;
    }

    private function period(string $frequency, CarbonImmutable $start, CarbonImmutable $end, string $key): KpiPeriod
    {
        return new KpiPeriod($frequency, $start->startOfDay(), $end->startOfDay(), $key);
    }
}
