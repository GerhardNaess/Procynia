<?php

namespace App\Data\Ai;

use App\Data\Ai\Operational\AiBudgetReservation;

/**
 * What the guard decided about one imminent provider call, and what it is now holding on its
 * behalf: a commercial credit reservation and an operational NOK reservation. Both have to be
 * settled by the same boundary that opened them.
 */
final readonly class AiCostControlDecision
{
    public function __construct(
        public AiCallContext $context,
        public string $policy,
        public ?int $reservationId,
        public int $used,
        public ?int $included,
        public ?int $remaining,
        public ?string $periodStart,
        public ?string $periodEnd,
        public string $status,
        public ?AiBudgetReservation $budgetReservation = null,
    ) {}

    public function withBudgetReservation(AiBudgetReservation $reservation): self
    {
        return new self(
            $this->context, $this->policy, $this->reservationId, $this->used, $this->included,
            $this->remaining, $this->periodStart, $this->periodEnd, $this->status, $reservation,
        );
    }
}
