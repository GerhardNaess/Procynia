<?php

namespace App\Data\Ai\Operational;

/**
 * The NOK a call is holding across the budget periods it touches, until it settles.
 *
 * A single call encumbers up to four periods at once — customer daily and monthly, global daily
 * and monthly — so the reservation has to be released or committed as one unit.
 */
final readonly class AiBudgetReservation
{
    /** @param list<int> $periodIds */
    public function __construct(
        public array $periodIds,
        public float $reservedNok,
    ) {}

    public static function none(): self
    {
        return new self([], 0.0);
    }

    public function isEmpty(): bool
    {
        return $this->periodIds === [];
    }
}
