<?php

namespace App\Services\Billing;

use App\Data\Billing\BillingPeriod;
use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The one answer to "which billing period does timestamp X belong to for customer Y?".
 *
 * Reads the local database only — never the payment provider — so usage queries and a future
 * capacity engine can call it per request.
 *
 * Precedence:
 *  1. A provider period (customer_billing_periods) containing X. Provider periods never overlap.
 *  2. Otherwise a period derived from the customer's anchor — `billing_anchor_at`, or the
 *     account's creation date when no anchor is set — stepped by `billing_interval` (monthly or
 *     yearly). This covers enterprise/manual billing and customers without a subscription.
 *     A derived period is clipped to the gap between provider periods, so a timestamp can never
 *     belong to two periods and nothing is counted twice.
 */
class CustomerBillingPeriodResolver
{
    public function current(Customer|int $customer): BillingPeriod
    {
        return $this->at($customer, CarbonImmutable::now('UTC'));
    }

    public function at(Customer|int $customer, DateTimeInterface $at): BillingPeriod
    {
        $customer = $customer instanceof Customer ? $customer : Customer::query()->findOrFail($customer);
        $at = CarbonImmutable::instance($at)->utc();

        $provider = CustomerBillingPeriod::query()
            ->where('customer_id', $customer->id)
            ->where('period_start', '<=', $at)
            ->where('period_end', '>', $at)
            ->orderByDesc('period_start')
            ->first();

        if ($provider instanceof CustomerBillingPeriod) {
            return new BillingPeriod(
                customerId: (int) $customer->id,
                start: $provider->period_start->utc(),
                end: $provider->period_end->utc(),
                source: BillingPeriod::SOURCE_PROVIDER,
                interval: $provider->interval,
                providerSubscriptionId: $provider->provider_subscription_id,
                subscriptionStatus: $provider->subscription_status,
            );
        }

        return $this->derived($customer, $at);
    }

    private function derived(Customer $customer, CarbonImmutable $at): BillingPeriod
    {
        $anchor = $customer->billing_anchor_at ?? $customer->created_at;
        $source = $customer->billing_anchor_at !== null ? BillingPeriod::SOURCE_ANCHOR : BillingPeriod::SOURCE_ACCOUNT_CREATED;
        $anchor = CarbonImmutable::instance($anchor ?? $at->startOfMonth())->utc();
        $interval = $customer->billing_interval === Customer::BILLING_YEARLY ? BillingPeriod::INTERVAL_YEAR : BillingPeriod::INTERVAL_MONTH;
        $months = $interval === BillingPeriod::INTERVAL_YEAR ? 12 : 1;

        // Step from the anchor (never from the previous boundary) so a 31st anchor stays on the
        // 31st where the month has one, the way providers bill.
        $steps = intdiv(($at->year - $anchor->year) * 12 + ($at->month - $anchor->month), $months);
        $boundary = fn (int $n): CarbonImmutable => $anchor->addMonthsNoOverflow($n * $months);

        while ($boundary($steps)->gt($at)) {
            $steps--;
        }

        while ($boundary($steps + 1)->lte($at)) {
            $steps++;
        }

        $start = $boundary($steps);
        $end = $boundary($steps + 1);

        $previousProviderEnd = CustomerBillingPeriod::query()
            ->where('customer_id', $customer->id)
            ->where('period_end', '<=', $at)
            ->max('period_end');
        $nextProviderStart = CustomerBillingPeriod::query()
            ->where('customer_id', $customer->id)
            ->where('period_start', '>', $at)
            ->min('period_start');

        if ($previousProviderEnd !== null && CarbonImmutable::parse($previousProviderEnd, 'UTC')->gt($start)) {
            $start = CarbonImmutable::parse($previousProviderEnd, 'UTC');
        }

        if ($nextProviderStart !== null && CarbonImmutable::parse($nextProviderStart, 'UTC')->lt($end)) {
            $end = CarbonImmutable::parse($nextProviderStart, 'UTC');
        }

        return new BillingPeriod(
            customerId: (int) $customer->id,
            start: $start,
            end: $end,
            source: $source,
            interval: $interval,
        );
    }
}
