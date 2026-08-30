<?php

namespace App\Data\Ai\Operational;

use App\Models\AiModelPrice;

/** Whether a model can be priced at all, and how much the price should be trusted. */
final readonly class AiPriceState
{
    public const KNOWN = 'known';
    public const STALE_WARNING = 'stale_warning';
    public const STALE_CRITICAL = 'stale_critical';
    public const MISSING = 'missing';

    public function __construct(
        public string $state,
        public ?AiModelPrice $price,
        public ?int $ageDays,
    ) {}

    public function isMissing(): bool
    {
        return $this->state === self::MISSING || ! $this->price instanceof AiModelPrice;
    }

    public function isStale(): bool
    {
        return in_array($this->state, [self::STALE_WARNING, self::STALE_CRITICAL], true);
    }
}
