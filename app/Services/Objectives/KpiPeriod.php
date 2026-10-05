<?php

namespace App\Services\Objectives;

use Carbon\CarbonImmutable;

/**
 * One reporting period of a KPI: a calendar week, month, quarter or year, from its first to its
 * last day, both inclusive. Dates only — a period has no time of day.
 *
 * The key names the period the way people say it, and is stable enough to store with a measurement
 * later: 2026-W01, 2026-01, 2026-Q1, 2026. Made only by KpiPeriods.
 */
final class KpiPeriod
{
    public function __construct(
        public readonly string $frequency,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $key,
    ) {}

    public function contains(CarbonImmutable $date): bool
    {
        $day = $date->startOfDay();

        return $day->greaterThanOrEqualTo($this->start) && $day->lessThanOrEqualTo($this->end);
    }

    public function equals(self $other): bool
    {
        return $this->frequency === $other->frequency && $this->key === $other->key;
    }
}
