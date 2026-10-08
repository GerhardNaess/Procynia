<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;

/**
 * Keeps customer_billing_periods in step with the provider's subscription object.
 *
 * Called from the Stripe webhooks (subscription created/updated/deleted — a renewal arrives as
 * `updated` with a new current period), from BillingService::syncSubscriptionFromStripe, and once
 * per customer by `billing:sync-subscriptions`. It is the only writer of that table.
 *
 * Guarantees:
 *  - idempotent: the same subscription state recorded twice leaves one row unchanged in meaning;
 *  - tenant-safe: the subscription must name this customer's Stripe id, and a subscription id
 *    already recorded for another customer is refused;
 *  - out-of-order safe: a webhook older than the state a row already reflects is ignored;
 *  - history-preserving: a new period never rewrites an earlier one, except to truncate it where
 *    the provider reset the cycle, so periods never overlap.
 */
class CustomerBillingPeriodRecorder
{
    /**
     * @param  array<string, mixed>  $subscription  A Stripe subscription object as an array.
     * @param  int|null  $providerEventAt  The webhook event's `created`; null for a fresh fetch,
     *                                     which is current by definition.
     */
    public function recordStripeSubscription(Customer $customer, array $subscription, ?int $providerEventAt = null): ?CustomerBillingPeriod
    {
        $subscriptionId = (string) ($subscription['id'] ?? '');
        $stripeCustomerId = $subscription['customer'] ?? null;
        $stripeCustomerId = is_array($stripeCustomerId) ? ($stripeCustomerId['id'] ?? null) : $stripeCustomerId;

        if ($subscriptionId === '' || $customer->stripe_id === null || $stripeCustomerId !== $customer->stripe_id) {
            Log::warning('[PROCYNIA][BILLING] Subscription does not belong to this customer; billing period not recorded.', [
                'customer_id' => $customer->id,
                'subscription_id' => $subscriptionId,
            ]);

            return null;
        }

        [$start, $end] = $this->periodBounds($subscription);

        if ($start === null || $end === null) {
            return null;
        }

        $status = (string) ($subscription['status'] ?? 'unknown');
        $endedAt = $this->timestamp($subscription['ended_at'] ?? null);

        // An immediate cancellation ends the period at the moment it ended, not at the period end
        // Stripe still reports.
        if ($endedAt !== null && $endedAt->lt($end)) {
            $end = $endedAt;
        }

        if ($end->lte($start)) {
            return null;
        }

        [$interval, $intervalCount] = $this->interval($subscription);
        $eventAt = $providerEventAt !== null ? CarbonImmutable::createFromTimestampUTC($providerEventAt) : CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($customer, $subscriptionId, $start, $end, $status, $interval, $intervalCount, $eventAt, $subscription): ?CustomerBillingPeriod {
            // Serialise per customer: two webhooks for the same customer must not interleave the
            // upsert and the overlap normalisation.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $foreign = CustomerBillingPeriod::query()
                ->where('provider_subscription_id', $subscriptionId)
                ->where('customer_id', '!=', $customer->id)
                ->exists();

            if ($foreign) {
                Log::error('[PROCYNIA][BILLING] Subscription is already recorded for another customer; refused.', [
                    'customer_id' => $customer->id,
                    'subscription_id' => $subscriptionId,
                ]);

                return null;
            }

            $period = CustomerBillingPeriod::query()
                ->where('customer_id', $customer->id)
                ->where('period_start', $start)
                ->first();

            if ($period instanceof CustomerBillingPeriod && $period->provider_event_at !== null && $eventAt->lt($period->provider_event_at)) {
                return $period;
            }

            $period ??= new CustomerBillingPeriod(['customer_id' => $customer->id, 'period_start' => $start]);
            $period->fill([
                'provider' => CustomerBillingPeriod::PROVIDER_STRIPE,
                'provider_subscription_id' => $subscriptionId,
                'period_end' => $end,
                'interval' => $interval,
                'interval_count' => $intervalCount,
                'subscription_status' => $status,
                'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
                'provider_event_at' => $eventAt,
                'synced_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $this->removeOverlaps($customer);

            return $period->fresh();
        });
    }

    /**
     * Fetch the customer's default subscription from Stripe and record its current period.
     * The only place a billing period is read from the provider's API.
     */
    public function syncFromProvider(Customer $customer): ?CustomerBillingPeriod
    {
        $subscription = $customer->subscription('default');

        if (! $subscription instanceof Subscription) {
            return null;
        }

        $data = $this->fetchStripeSubscription($subscription);

        return $data === null ? null : $this->recordStripeSubscription($customer, $data);
    }

    /** @return array<string, mixed>|null */
    public function fetchStripeSubscription(Subscription $subscription): ?array
    {
        return $subscription->asStripeSubscription()->toArray();
    }

    /**
     * Truncate any period that runs past the start of the next one — a provider reset (plan or
     * interval change, cycle-anchor reset). Only ever shortens; history before the reset stays.
     */
    private function removeOverlaps(Customer $customer): void
    {
        $periods = CustomerBillingPeriod::query()
            ->where('customer_id', $customer->id)
            ->orderBy('period_start')
            ->get();

        foreach ($periods->values() as $index => $period) {
            $next = $periods->get($index + 1);

            if ($next instanceof CustomerBillingPeriod && $period->period_end->gt($next->period_start)) {
                $period->forceFill(['period_end' => $next->period_start])->save();
            }
        }
    }

    /**
     * Stripe API 2025-03-31 (basil) moved the current period from the subscription to its items;
     * older versions carry it on the subscription. Multi-item: earliest start, latest end — the
     * same reduction Cashier applies.
     *
     * @param  array<string, mixed>  $subscription
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function periodBounds(array $subscription): array
    {
        $start = $this->timestamp($subscription['current_period_start'] ?? null);
        $end = $this->timestamp($subscription['current_period_end'] ?? null);

        if ($start !== null && $end !== null) {
            return [$start, $end];
        }

        foreach ((array) data_get($subscription, 'items.data', []) as $item) {
            $itemStart = $this->timestamp(data_get($item, 'current_period_start'));
            $itemEnd = $this->timestamp(data_get($item, 'current_period_end'));

            if ($itemStart !== null && ($start === null || $itemStart->lt($start))) {
                $start = $itemStart;
            }

            if ($itemEnd !== null && ($end === null || $itemEnd->gt($end))) {
                $end = $itemEnd;
            }
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array{0: ?string, 1: ?int}
     */
    private function interval(array $subscription): array
    {
        $recurring = data_get($subscription, 'items.data.0.price.recurring') ?? data_get($subscription, 'plan');
        $interval = data_get($recurring, 'interval');
        $count = data_get($recurring, 'interval_count');

        return [is_string($interval) ? $interval : null, is_numeric($count) ? (int) $count : null];
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }
}
