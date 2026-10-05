<?php

namespace App\Services\Objectives;

/**
 * Where one KPI stands against its reporting rhythm on one day. Computed by KpiMeasurementSchedule,
 * never stored.
 *
 *  - missing:      the expected periods past their reporting deadline without a measurement,
 *                  oldest first. measurementMissing() is the attention signal «Måling mangler».
 *  - pending:      the expected periods that have ended without a measurement but are still within
 *                  their grace days, oldest first.
 *  - upcoming:     the running period, when the KPI is measured now — registrable once it ends.
 *  - suggested:    what Registrer måling proposes: the oldest expected period without a measurement.
 */
final class KpiSchedule
{
    /**
     * @param  list<KpiPeriod>  $missing
     * @param  list<KpiPeriod>  $pending
     */
    public function __construct(
        public readonly array $missing,
        public readonly array $pending,
        public readonly ?KpiPeriod $upcoming,
        public readonly bool $measuredNow,
    ) {}

    public static function none(): self
    {
        return new self([], [], null, false);
    }

    /**
     * «Måling mangler»: the KPI and its objective are active, the KPI has a frequency, and an
     * expected period is past its deadline without a measurement.
     */
    public function measurementMissing(): bool
    {
        return $this->measuredNow && $this->missing !== [];
    }

    /** The oldest missing period — the one the page names. */
    public function oldestMissing(): ?KpiPeriod
    {
        return $this->missing[0] ?? null;
    }

    /** The oldest expected period that has ended without a measurement, overdue or not. */
    public function suggested(): ?KpiPeriod
    {
        return $this->missing[0] ?? $this->pending[0] ?? null;
    }
}
