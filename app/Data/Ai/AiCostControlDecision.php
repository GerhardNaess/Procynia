<?php

namespace App\Data\Ai;

use App\Data\Ai\Operational\AiBudgetReservation;
use App\Models\AiUsageAttempt;

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
        // The pre-call estimate for this operation (operation registry × price × FX, padded). Kept
        // apart from the actual cost on purpose: it decides whether the call may start, and is
        // written to the attempt as `reserved_cost_nok` — never into `cost_nok`.
        public ?float $estimatedCostNok = null,
        // The attempt opened for this call while the customer was locked (AiCostControlService::
        // admit()). It already holds the call's reservation; the meter finishes it, never opens a
        // second one. Null for a preflight authorize() and for calls without a customer.
        public ?AiUsageAttempt $attempt = null,
    ) {}

    public function withBudgetReservation(AiBudgetReservation $reservation, ?float $estimatedCostNok = null): self
    {
        return new self(
            $this->context, $this->policy, $this->reservationId, $this->used, $this->included,
            $this->remaining, $this->periodStart, $this->periodEnd, $this->status, $reservation,
            $estimatedCostNok, $this->attempt,
        );
    }

    public function withAttempt(?AiUsageAttempt $attempt): self
    {
        return new self(
            $this->context, $this->policy, $this->reservationId, $this->used, $this->included,
            $this->remaining, $this->periodStart, $this->periodEnd, $this->status, $this->budgetReservation,
            $this->estimatedCostNok, $attempt,
        );
    }
}
