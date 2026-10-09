<?php

namespace App\Data\Ai\Usage;

/**
 * Which attempts a usage question is about. Trusted rows only unless a caller asks otherwise
 * on purpose — legacy rows are for debugging and trends, never an economic basis.
 */
final readonly class AiUsageFilter
{
    public function __construct(
        public AiUsagePeriod $period,
        public ?int $customerId = null,
        public ?string $feature = null,
        public ?string $operation = null,
        public bool $trustedOnly = true,
    ) {}
}
