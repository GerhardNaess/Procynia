<?php

namespace App\Data\Ai;

use App\Models\Customer;

/**
 * One customer's commercial AI-case position for a period, as the customer should understand it.
 *
 * This is the only shape the billing page, the AI workspace and the threshold notifications read.
 * None of them recompute the quota: a second calculation is how "80%" once came to mean two
 * different things in two places.
 */
final readonly class AiQuotaStatus
{
    public const STATUS_NORMAL = 'normal';

    public const STATUS_WARNING = 'warning';

    public const STATUS_CRITICAL = 'critical';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_SUSPENDED = 'suspended';

    public function __construct(
        public int $customerId,
        public string $quotaType,
        public int $included,
        public int $extra,
        public int $used,
        public int $reserved,
        public ?int $remaining,
        public string $periodStart,
        public string $periodEnd,
        public ?int $percentageUsed,
        public string $status,
        public bool $isUnlimited,
        public bool $isSuspended,
    ) {}

    /**
     * What the customer may actually spend this period.
     *
     * Floored at zero because an administrative withdrawal can take the net below the plan's own
     * allowance. The signed components stay visible to admins; the spendable figure never goes
     * negative, in the UI or in the guard.
     */
    public function allowance(): int
    {
        return max(0, $this->included + $this->extra);
    }

    /** True when a threshold notification could ever be meaningful for this customer. */
    public function hasFiniteQuota(): bool
    {
        return $this->quotaType === AiQuotaPolicy::FINITE && $this->allowance() > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'quota_type' => $this->quotaType,
            'included' => $this->included,
            'extra' => $this->extra,
            'allowance' => $this->allowance(),
            'used' => $this->used,
            'reserved' => $this->reserved,
            'remaining' => $this->remaining,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'percentage_used' => $this->percentageUsed,
            'status' => $this->status,
            'is_unlimited' => $this->isUnlimited,
            'is_suspended' => $this->isSuspended,
        ];
    }

    public static function suspendedFor(Customer $customer, self $underlying): self
    {
        return new self(
            customerId: $underlying->customerId,
            quotaType: $underlying->quotaType,
            included: $underlying->included,
            extra: $underlying->extra,
            used: $underlying->used,
            reserved: $underlying->reserved,
            remaining: $underlying->remaining,
            periodStart: $underlying->periodStart,
            periodEnd: $underlying->periodEnd,
            percentageUsed: $underlying->percentageUsed,
            status: self::STATUS_SUSPENDED,
            isUnlimited: $underlying->isUnlimited,
            isSuspended: true,
        );
    }
}
