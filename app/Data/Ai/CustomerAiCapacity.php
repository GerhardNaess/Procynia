<?php

namespace App\Data\Ai;

use App\Data\Billing\BillingPeriod;

/**
 * One customer's shared AI capacity for one billing period, in AI units.
 *
 * The only shape the subscription page, the capacity gate and the hard-stop message read; none of
 * them recompute it. It carries no NOK, no tokens and no model — `toArray()` is safe to hand to the
 * customer as is.
 *
 * Settled usage is what was used. Reserved is what calls in flight, or calls whose cost is still
 * open, temporarily hold; it lowers what is available but is never presented as used.
 */
final readonly class CustomerAiCapacity
{
    public const STATUS_NORMAL = 'normal';

    public const STATUS_WARNING = 'warning';

    public const STATUS_EXHAUSTED = 'exhausted';

    /** Neither the plan nor the customer defines a shared capacity. Nothing is metered against it. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public function __construct(
        public int $customerId,
        public BillingPeriod $period,
        public ?int $includedUnits,
        // Exact figures, for comparisons. Displayed figures are derived from them below.
        public float $usedUnitsExact,
        public float $reservedUnitsExact,
        public int $usedUnits,
        public int $reservedUnits,
        public string $status,
        public bool $showsReservation,
    ) {}

    public function isConfigured(): bool
    {
        return $this->includedUnits !== null;
    }

    /** What a new operation may still draw on: included − settled − reserved, never negative. */
    public function availableUnitsExact(): ?float
    {
        return $this->includedUnits === null
            ? null
            : max(0.0, $this->includedUnits - $this->usedUnitsExact - $this->reservedUnitsExact);
    }

    /** Whole units left, so that used + reserved + remaining adds up to included on the page. */
    public function remainingUnits(): ?int
    {
        return $this->includedUnits === null
            ? null
            : max(0, $this->includedUnits - $this->usedUnits - $this->reservedUnits);
    }

    /** Share of the included capacity actually used (settled), 0–100 for the bar. */
    public function percentageUsed(): ?int
    {
        if ($this->includedUnits === null) {
            return null;
        }

        if ($this->includedUnits <= 0) {
            return 100;
        }

        return (int) min(100, floor($this->usedUnitsExact / $this->includedUnits * 100));
    }

    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_EXHAUSTED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'is_configured' => $this->isConfigured(),
            'included' => $this->includedUnits,
            'used' => $this->usedUnits,
            'reserved' => $this->reservedUnits,
            'remaining' => $this->remainingUnits(),
            'percentage_used' => $this->percentageUsed(),
            'status' => $this->status,
            'is_warning' => $this->isWarning(),
            'is_exhausted' => $this->isExhausted(),
            'shows_reservation' => $this->showsReservation,
            'period_start' => $this->period->start->toDateString(),
            // The period is half-open and renews on its end date (at the provider's time of day),
            // so the last whole day is the day before: periods read 15.10–14.11, 15.11–14.12.
            'period_end' => $this->period->end->subDay()->toDateString(),
            'next_period_start' => $this->period->end->toDateString(),
        ];
    }
}
