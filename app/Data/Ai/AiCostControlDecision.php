<?php

namespace App\Data\Ai;

/** Structured result retained by the provider boundary for finalization and future customer UX. */
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
    ) {}
}
