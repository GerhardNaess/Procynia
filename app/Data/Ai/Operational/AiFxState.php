<?php

namespace App\Data\Ai\Operational;

/** The rate used to express a provider's USD cost in NOK, and how current it is. */
final readonly class AiFxState
{
    public const FRESH = 'fresh';
    public const STALE_WARNING = 'stale_warning';
    public const STALE_CRITICAL = 'stale_critical';
    public const MISSING = 'missing';

    public function __construct(
        public string $state,
        public float $rate,
        public float $effectiveRate,
        public ?string $rateDate,
        public ?int $ageDays,
    ) {}

    public function isMissing(): bool
    {
        return $this->state === self::MISSING;
    }
}
