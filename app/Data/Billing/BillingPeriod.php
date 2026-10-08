<?php

namespace App\Data\Billing;

use App\Data\Ai\Usage\AiUsagePeriod;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One customer's billing period: the half-open window [start, end), UTC.
 *
 * `source` says where the window came from, so a reader can tell an actual provider period from
 * a derived one:
 *  - provider:        a period the payment provider reported (customer_billing_periods)
 *  - anchor:          derived from customers.billing_anchor_at — the contract for manual billing
 *  - account_created: derived from the customer's creation date, when no anchor is set
 */
final readonly class BillingPeriod
{
    public const SOURCE_PROVIDER = 'provider';

    public const SOURCE_ANCHOR = 'anchor';

    public const SOURCE_ACCOUNT_CREATED = 'account_created';

    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public function __construct(
        public int $customerId,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $source,
        public ?string $interval = null,
        public ?string $providerSubscriptionId = null,
        public ?string $subscriptionStatus = null,
    ) {}

    /** Start inclusive, end exclusive: a call at exactly `end` belongs to the next period. */
    public function contains(DateTimeInterface $at): bool
    {
        $at = CarbonImmutable::instance($at);

        return $at->gte($this->start) && $at->lt($this->end);
    }

    public function isProviderPeriod(): bool
    {
        return $this->source === self::SOURCE_PROVIDER;
    }

    public function toUsagePeriod(): AiUsagePeriod
    {
        return new AiUsagePeriod($this->start, $this->end);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'source' => $this->source,
            'interval' => $this->interval,
            'provider_subscription_id' => $this->providerSubscriptionId,
            'subscription_status' => $this->subscriptionStatus,
        ];
    }
}
