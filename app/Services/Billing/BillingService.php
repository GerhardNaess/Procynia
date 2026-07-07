<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\CustomerUserServiceLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BillingService
{
    public function hasStripeCustomer(Customer $customer): bool
    {
        return filled($customer->stripe_id);
    }

    public function ensureStripeCustomer(Customer $customer): Customer
    {
        if ($customer->stripe_id) {
            return $customer->fresh();
        }

        $customer->createOrGetStripeCustomer([
            'name' => $customer->name,
        ]);

        $customer->refresh();

        $this->recordEvent($customer, 'stripe_customer_created', 'system', 'Stripe-kunde ble opprettet.');

        return $customer;
    }

    public function createAccountBilling(Customer $customer, array $payload = []): array
    {
        $customer = $this->ensureStripeCustomer($customer);

        return $this->syncAccountBillingState($customer, $payload, true);
    }

    public function syncLocalAccountBilling(Customer $customer, array $payload = []): array
    {
        return $this->syncAccountBillingState($customer->fresh(), $payload, false);
    }

    private function syncAccountBillingState(Customer $customer, array $payload = [], bool $syncStripe = true): array
    {
        if (isset($payload['plan_key'], $payload['billing_interval'])) {
            $planKey = (string) $payload['plan_key'];
            $billingInterval = (string) $payload['billing_interval'];
            $source = (string) ($payload['source'] ?? 'system');

            $this->syncPlanMeta($customer, $planKey, $billingInterval);
            $this->syncBasePlanLine($customer, $planKey, $billingInterval, $source);
        }

        if ($syncStripe) {
            $this->recalculateSubscriptionItems($customer);
        }

        $customer = $customer->fresh();

        return [
            'customer' => $customer,
            'subscription' => $syncStripe ? $customer->subscription('default') : null,
            'billing_lines' => $this->activeBillingLines($customer),
        ];
    }

    public function closeAccountBilling(Customer $customer, string $mode): array
    {
        $customer = $customer->fresh();
        $subscription = $customer->subscription('default');

        if ($subscription) {
            if ($mode === 'period_end') {
                $subscription->cancel();
            } else {
                $subscription->cancelNow();
            }
        }

        if ($mode === 'period_end') {
            $this->markRecurringLines($customer, 'pending_cancel');
        } else {
            $this->markRecurringLines($customer, 'ended');
            $this->markUserServiceLevels($customer, 'ended');
        }

        if ($mode !== 'period_end') {
            $this->syncPlanMeta($customer, Customer::PLAN_FREE, Customer::BILLING_MONTHLY);
            $this->syncBasePlanLine($customer, Customer::PLAN_FREE, Customer::BILLING_MONTHLY);
        }

        $this->recordEvent($customer, 'subscription_closed', 'system', "Abonnement ble avsluttet ({$mode}).");

        return [
            'customer' => $customer->fresh(),
            'subscription' => $customer->fresh()->subscription('default'),
        ];
    }

    public function materializeFreePlanLine(Customer $customer): ?CustomerBillingLine
    {
        return $this->syncBasePlanLine($customer, Customer::PLAN_FREE, Customer::BILLING_MONTHLY);
    }

    public function resumeAccountBilling(Customer $customer): array
    {
        $customer = $customer->fresh();
        $subscription = $customer->subscription('default');

        if (! $subscription) {
            throw new RuntimeException('Kan ikke gjenoppta et abonnement som ikke finnes.');
        }

        $subscription->resume();

        $this->markRecurringLines($customer, 'active');
        $this->markUserServiceLevels($customer, 'active');
        $this->recordEvent($customer, 'subscription_resumed', 'system', 'Abonnement ble gjenopptatt.');

        return [
            'customer' => $customer->fresh(),
            'subscription' => $customer->fresh()->subscription('default'),
        ];
    }

    public function addRecurringLine(Customer $customer, BillingPrice $price, int $quantity, ?User $user = null, string $source = 'admin'): CustomerBillingLine
    {
        $this->assertRecurringPrice($price);
        $customer = $this->ensureStripeCustomer($customer);

        $line = CustomerBillingLine::query()
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $price->id)
            ->where('user_id', $user?->id)
            ->where('status', 'active')
            ->where('source', $source)
            ->first();

        if ($line) {
            $line->fill([
                'billing_product_id' => $price->billing_product_id,
                'description' => $price->name,
                'quantity' => max(1, $quantity),
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => $customer->subscription('default')?->stripe_id,
                'stripe_subscription_item_id' => $this->stripeSubscriptionItemIdForPrice($customer, $price->stripe_price_id),
                'stripe_invoice_id' => null,
                'source' => $source,
                'metadata' => [
                    'billing_price_key' => $price->key,
                    'billing_product_key' => $price->product?->key,
                ],
            ]);
            $line->save();
        } else {
            $line = CustomerBillingLine::create([
                'customer_id' => $customer->id,
                'billing_product_id' => $price->billing_product_id,
                'billing_price_id' => $price->id,
                'user_id' => $user?->id,
                'description' => $price->name,
                'quantity' => max(1, $quantity),
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => $customer->subscription('default')?->stripe_id,
                'stripe_subscription_item_id' => $this->stripeSubscriptionItemIdForPrice($customer, $price->stripe_price_id),
                'stripe_invoice_id' => null,
                'source' => $source,
                'metadata' => [
                    'billing_price_key' => $price->key,
                    'billing_product_key' => $price->product?->key,
                ],
            ]);
        }

        $this->recalculateSubscriptionItems($customer);
        $this->recordEvent($customer, 'recurring_line_added', $source, "Lagt til linje {$price->key}.", null, $line->fresh()->toArray());

        return $line->fresh();
    }

    public function updateRecurringLineQuantity(Customer $customer, BillingPrice $price, int $quantity): CustomerBillingLine
    {
        $this->assertRecurringPrice($price);

        $line = CustomerBillingLine::query()
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $price->id)
            ->where('status', 'active')
            ->whereNull('user_id')
            ->where('source', '!=', CustomerBillingLine::SOURCE_CUSTOMER_PRICE)
            ->firstOrFail();

        $before = $line->toArray();

        $line->update([
            'quantity' => max(1, $quantity),
        ]);

        $this->recalculateSubscriptionItems($customer);
        $this->recordEvent($customer, 'recurring_quantity_changed', 'admin', "Endret quantity for {$price->key}.", $before, $line->fresh()->toArray());

        return $line->fresh();
    }

    public function removeRecurringLine(Customer $customer, CustomerBillingLine $line): CustomerBillingLine
    {
        $before = $line->toArray();

        $line->update([
            'status' => 'cancelled',
            'ends_at' => now(),
        ]);

        $this->recalculateSubscriptionItems($customer);
        $this->recordEvent($customer, 'recurring_line_removed', 'admin', "Fjernet linje {$line->id}.", $before, $line->fresh()->toArray());

        return $line->fresh();
    }

    public function customerSpecificPriceLines(Customer $customer): Collection
    {
        return $customer->billingLines()
            ->with(['billingProduct', 'billingPrice', 'user'])
            ->customerSpecificPrice()
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->get();
    }

    public function addCustomerSpecificPrice(
        Customer $customer,
        BillingPrice $price,
        int $customUnitAmount,
        int $quantity = 1,
        ?User $user = null,
        ?string $notes = null,
    ): CustomerBillingLine {
        $this->assertRecurringPrice($price);

        $customer = $customer->fresh();
        $customUnitAmount = max(0, $customUnitAmount);
        $quantity = max(1, $quantity);

        $existing = CustomerBillingLine::query()
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $price->id)
            ->where('user_id', $user?->id)
            ->where('source', CustomerBillingLine::SOURCE_CUSTOMER_PRICE)
            ->whereIn('status', ['active', 'pending_cancel'])
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->first();

        if ($existing) {
            $this->deactivateCustomerSpecificPrice($existing, 'customer_specific_price_replaced');
        }

        $line = CustomerBillingLine::create([
            'customer_id' => $customer->id,
            'billing_product_id' => $price->billing_product_id,
            'billing_price_id' => $price->id,
            'user_id' => $user?->id,
            'description' => $price->name,
            'quantity' => $quantity,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'metadata' => $this->customerSpecificPriceMetadata($price, $customUnitAmount, $notes),
        ]);

        $this->recordEvent(
            $customer,
            'customer_specific_price_added',
            'admin',
            "La til kundespesifikk pris for {$price->key}.",
            null,
            $line->fresh()->toArray(),
        );

        return $line->fresh();
    }

    public function replaceCustomerSpecificPrice(
        CustomerBillingLine $line,
        int $customUnitAmount,
        int $quantity = 1,
        ?string $notes = null,
    ): CustomerBillingLine {
        if ($line->source !== CustomerBillingLine::SOURCE_CUSTOMER_PRICE) {
            throw new RuntimeException('Linjen er ikke markert som kundespesifikk pris.');
        }

        $customer = $line->customer()->firstOrFail();
        $price = $line->billingPrice()->firstOrFail();
        $before = $line->toArray();

        $this->deactivateCustomerSpecificPrice($line, 'customer_specific_price_replaced');

        $newLine = CustomerBillingLine::create([
            'customer_id' => $customer->id,
            'billing_product_id' => $price->billing_product_id,
            'billing_price_id' => $price->id,
            'user_id' => $line->user_id,
            'description' => $price->name,
            'quantity' => max(1, $quantity),
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'metadata' => $this->customerSpecificPriceMetadata($price, max(0, $customUnitAmount), $notes),
        ]);

        $this->recordEvent(
            $customer,
            'customer_specific_price_replaced',
            'admin',
            "Oppdaterte kundespesifikk pris for {$price->key}.",
            $before,
            $newLine->fresh()->toArray(),
        );

        return $newLine->fresh();
    }

    public function deactivateCustomerSpecificPrice(CustomerBillingLine $line, string $eventType = 'customer_specific_price_deactivated'): CustomerBillingLine
    {
        if ($line->source !== CustomerBillingLine::SOURCE_CUSTOMER_PRICE) {
            throw new RuntimeException('Linjen er ikke markert som kundespesifikk pris.');
        }

        $customer = $line->customer()->first();
        $before = $line->toArray();

        $line->update([
            'status' => 'ended',
            'ends_at' => now(),
        ]);

        $this->recordEvent(
            $customer,
            $eventType,
            'admin',
            "Deaktiverte kundespesifikk pris {$line->id}.",
            $before,
            $line->fresh()->toArray(),
        );

        return $line->fresh();
    }

    public function addOneTimeCharge(Customer $customer, BillingPrice $price, int $quantity, string $description, bool $invoiceImmediately = true): CustomerBillingLine
    {
        $this->assertOneTimePrice($price);
        $customer = $this->ensureStripeCustomer($customer);

        $line = CustomerBillingLine::create([
            'customer_id' => $customer->id,
            'billing_product_id' => $price->billing_product_id,
            'billing_price_id' => $price->id,
            'description' => $description,
            'quantity' => max(1, $quantity),
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'admin',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $price->product?->key,
            ],
        ]);

        $invoice = null;

        if ($invoiceImmediately) {
            $invoice = $this->invoiceBillingLine($customer, $line, $price, max(1, $quantity), $description);

            $line->update([
                'stripe_invoice_id' => $invoice?->id ?? null,
            ]);
        }

        $this->recordEvent($customer, 'one_time_charge_added', 'admin', "Lagt til engangslinje {$price->key}.", null, $line->fresh()->toArray());

        return $line->fresh();
    }

    public function invoiceOneTimeCharges(Customer $customer): array
    {
        $customer = $customer->fresh();

        $lines = $customer->billingLines()
            ->whereHas('billingPrice', fn ($query) => $query->where('interval', BillingPrice::INTERVAL_ONE_TIME))
            ->where('status', 'active')
            ->whereNull('stripe_invoice_id')
            ->with(['billingPrice', 'billingProduct'])
            ->get();

        if ($lines->isEmpty()) {
            return [
                'invoice' => null,
                'invoiced_lines' => [],
            ];
        }

        foreach ($lines as $line) {
            $price = $line->billingPrice;
            if (! $price instanceof BillingPrice) {
                continue;
            }

            $customer->tabPrice($price->stripe_price_id, $line->quantity, [
                'description' => $line->description,
            ]);
        }

        $invoice = $customer->invoice();

        $lines->each(function (CustomerBillingLine $line) use ($invoice): void {
            $line->update([
                'stripe_invoice_id' => $invoice->id,
            ]);
        });

        $this->recordEvent($customer, 'invoice_created', 'system', 'Engangslinjer ble fakturert.', null, [
            'invoice_id' => $invoice->id,
            'line_ids' => $lines->pluck('id')->all(),
        ]);

        return [
            'invoice' => $invoice,
            'invoiced_lines' => $lines->fresh()->all(),
        ];
    }

    public function assignUserServiceLevel(Customer $customer, User $user, BillingPrice $price, ?User $assignedBy = null): CustomerUserServiceLevel
    {
        $this->assertRecurringPrice($price);

        $level = CustomerUserServiceLevel::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'billing_price_id' => $price->id,
                'status' => 'active',
            ],
            [
                'billing_product_id' => $price->billing_product_id,
                'level_key' => $price->tier_key ?? $price->key,
                'assigned_by' => $assignedBy?->id,
                'starts_at' => now(),
                'ends_at' => null,
                'metadata' => [
                    'billing_price_key' => $price->key,
                    'billing_product_key' => $price->product?->key,
                ],
            ]
        );

        $this->syncUserServiceLevelBillingLine($customer, $user, $price, 1, 'admin');
        $this->recalculateSubscriptionItems($customer);
        $this->recordEvent($customer, 'user_service_level_assigned', 'admin', "Tildelte service level {$price->key} til bruker {$user->id}.", null, $level->fresh()->toArray());

        return $level->fresh();
    }

    public function removeUserServiceLevel(Customer $customer, User $user, BillingPrice $price): CustomerUserServiceLevel
    {
        $level = CustomerUserServiceLevel::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $user->id)
            ->where('billing_price_id', $price->id)
            ->where('status', 'active')
            ->firstOrFail();

        $before = $level->toArray();

        $level->update([
            'status' => 'ended',
            'ends_at' => now(),
        ]);

        CustomerBillingLine::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $user->id)
            ->where('billing_price_id', $price->id)
            ->where('status', 'active')
            ->update([
                'status' => 'ended',
                'ends_at' => now(),
            ]);

        $this->recalculateSubscriptionItems($customer);
        $this->recordEvent($customer, 'user_service_level_removed', 'admin', "Fjernet service level {$price->key} fra bruker {$user->id}.", $before, $level->fresh()->toArray());

        return $level->fresh();
    }

    public function syncSubscriptionFromStripe(Customer $customer): array
    {
        $customer = $customer->fresh();
        $subscription = $customer->subscription('default');

        if (! $subscription) {
            return [
                'subscription' => null,
                'synced_items' => [],
            ];
        }

        $stripeSubscription = rescue(
            fn () => $subscription->asStripeSubscription(),
            null,
            false,
        );

        $syncedItems = [];
        $syncedStripeItemIds = [];

        if ($stripeSubscription) {
            foreach ($stripeSubscription->items->data as $item) {
                $priceId = $item->price->id ?? null;
                $stripeItemId = $item->id ?? null;

                if (! $priceId || ! $stripeItemId) {
                    continue;
                }

                $billingPrice = BillingPrice::query()->where('stripe_price_id', $priceId)->first();

                if (! $billingPrice) {
                    continue;
                }

                $syncedItems[] = $priceId;
                $syncedStripeItemIds[] = $stripeItemId;

                $line = CustomerBillingLine::query()
                    ->where('customer_id', $customer->id)
                    ->where('billing_price_id', $billingPrice->id)
                    ->whereNull('user_id')
                    ->where('source', '!=', CustomerBillingLine::SOURCE_CUSTOMER_PRICE)
                    ->first();

                if ($line) {
                    $line->update([
                        'billing_product_id' => $billingPrice->billing_product_id,
                        'description' => $billingPrice->name,
                        'quantity' => (int) ($item->quantity ?? 1),
                        'status' => $stripeSubscription->cancel_at_period_end ? 'pending_cancel' : 'active',
                        'starts_at' => now(),
                        'ends_at' => null,
                        'stripe_subscription_id' => $subscription->stripe_id,
                        'stripe_subscription_item_id' => $stripeItemId,
                        'source' => 'webhook',
                        'metadata' => [
                            'billing_price_key' => $billingPrice->key,
                            'billing_product_key' => $billingPrice->product?->key,
                        ],
                    ]);
                } else {
                    CustomerBillingLine::create([
                        'customer_id' => $customer->id,
                        'billing_product_id' => $billingPrice->billing_product_id,
                        'billing_price_id' => $billingPrice->id,
                        'user_id' => null,
                        'description' => $billingPrice->name,
                        'quantity' => (int) ($item->quantity ?? 1),
                        'status' => $stripeSubscription->cancel_at_period_end ? 'pending_cancel' : 'active',
                        'starts_at' => now(),
                        'ends_at' => null,
                        'stripe_subscription_id' => $subscription->stripe_id,
                        'stripe_subscription_item_id' => $stripeItemId,
                        'source' => 'webhook',
                        'metadata' => [
                            'billing_price_key' => $billingPrice->key,
                            'billing_product_key' => $billingPrice->product?->key,
                        ],
                    ]);
                }
            }

            $query = $customer->billingLines()
                ->whereNull('user_id')
                ->whereIn('status', ['active', 'pending_cancel'])
                ->whereHas('billingPrice', fn ($priceQuery) => $priceQuery->where('interval', '!=', BillingPrice::INTERVAL_ONE_TIME))
                ->where('source', '!=', CustomerBillingLine::SOURCE_CUSTOMER_PRICE);

            if ($syncedStripeItemIds === []) {
                $query->update([
                    'status' => 'ended',
                    'ends_at' => now(),
                ]);
            } else {
                $query->whereNotIn('stripe_subscription_item_id', $syncedStripeItemIds)
                    ->update([
                        'status' => 'ended',
                        'ends_at' => now(),
                    ]);
            }
        }

        return [
            'subscription' => $subscription->fresh(),
            'synced_items' => $syncedItems,
        ];
    }

    public function recalculateSubscriptionItems(Customer $customer): array
    {
        $customer = $this->ensureStripeCustomer($customer);
        $desiredItems = $this->desiredSubscriptionItems($customer);
        $subscription = $customer->subscription('default');

        if ($desiredItems === []) {
            if ($subscription) {
                $subscription->cancelNow();
            }

            $this->markRecurringLines($customer, 'ended');

            return [
                'subscription' => $customer->fresh()->subscription('default'),
                'items' => [],
            ];
        }

        if ($subscription) {
            $subscription->swap($desiredItems);
        } else {
            $builder = $customer->newSubscription('default', $desiredItems);
            $basePlanPrice = $this->resolveBasePlanPriceFromItems($desiredItems);

            if ($basePlanPrice && ($trialDays = $this->trialDaysForPrice($basePlanPrice)) > 0 && ! $customer->hasExpiredTrial()) {
                $builder->trialDays($trialDays);
            }

            $builder->create();
        }

        $customer = $customer->fresh();
        $this->syncSubscriptionFromStripe($customer);
        $this->syncCustomerPlanMetaFromActiveSubscription($customer);

        $this->recordEvent($customer, 'subscription_recalculated', 'system', 'Stripe subscription items ble synkronisert.', null, $desiredItems);

        return [
            'subscription' => $customer->subscription('default'),
            'items' => $desiredItems,
        ];
    }

    public function planOptions(): array
    {
        $options = [];

        BillingPrice::query()
            ->with('product')
            ->active()
            ->recurring()
            ->orderBy('key')
            ->get()
            ->each(function (BillingPrice $price) use (&$options): void {
                $product = $price->product;

                if (! $product instanceof BillingProduct) {
                    return;
                }

                if ($product->category !== BillingProduct::CATEGORY_BASE_PLAN) {
                    return;
                }

                $label = $price->interval === BillingPrice::INTERVAL_YEARLY
                    ? 'Årlig'
                    : 'Månedlig';

                $options[$price->key] = "{$price->name} ({$label})";
            });

        return $options;
    }

    public function recurringPriceOptions(?string $category = null): array
    {
        $query = BillingPrice::query()
            ->with('product')
            ->active()
            ->recurring()
            ->orderBy('key');

        if ($category !== null) {
            $query->whereHas('product', fn ($productQuery) => $productQuery->where('category', $category));
        }

        return $query->get()->mapWithKeys(function (BillingPrice $price): array {
            return [$price->id => $this->priceLabel($price)];
        })->all();
    }

    public function oneTimePriceOptions(): array
    {
        return BillingPrice::query()
            ->with('product')
            ->active()
            ->where('interval', BillingPrice::INTERVAL_ONE_TIME)
            ->orderBy('key')
            ->get()
            ->mapWithKeys(function (BillingPrice $price): array {
                return [$price->id => $this->priceLabel($price)];
            })
            ->all();
    }

    public function resolvePriceId(string $plan, string $interval): ?string
    {
        $billingPrice = BillingPrice::query()
            ->where('key', "{$plan}_{$interval}")
            ->first();

        if ($billingPrice?->stripe_price_id) {
            return $billingPrice->stripe_price_id;
        }

        return config("procynia_plans.{$plan}." . ($interval === Customer::BILLING_YEARLY ? 'stripe_yearly' : 'stripe_monthly')) ?: null;
    }

    public function resolvePlanPrice(string $plan, string $interval): ?BillingPrice
    {
        return BillingPrice::query()
            ->where('key', "{$plan}_{$interval}")
            ->first();
    }

    public function syncPlanMeta(Customer $customer, string $plan, string $interval): void
    {
        $config = config("procynia_plans.{$plan}", []);

        $customer->update([
            'subscription_plan' => $plan,
            'billing_interval' => $interval,
            'included_users' => $config['included_users'] ?? 1,
            'included_ai_credits' => $config['included_ai_credits'] ?? 0,
        ]);
    }

    public function syncPlanFromPriceId(Customer $customer, string $stripePriceId): void
    {
        $price = BillingPrice::query()
            ->where('stripe_price_id', $stripePriceId)
            ->with('product')
            ->first();

        if ($price && $price->product instanceof BillingProduct && $price->product->category === BillingProduct::CATEGORY_BASE_PLAN) {
            $planKey = $price->metadata['plan_key'] ?? $price->tier_key ?? $price->product->metadata['plan_key'] ?? null;
            if ($planKey) {
                $this->syncPlanMeta($customer, (string) $planKey, $price->interval);
                return;
            }
        }

        foreach (config('procynia_plans', []) as $planKey => $plan) {
            foreach ([Customer::BILLING_MONTHLY, Customer::BILLING_YEARLY] as $interval) {
                $key = $interval === Customer::BILLING_YEARLY ? 'stripe_yearly' : 'stripe_monthly';

                if (($plan[$key] ?? null) === $stripePriceId) {
                    $this->syncPlanMeta($customer, $planKey, $interval);
                    return;
                }
            }
        }
    }

    public function syncPlanFromCurrentSubscription(Customer $customer): void
    {
        $this->syncCustomerPlanMetaFromActiveSubscription($customer->fresh());
    }

    public function activeBillingLines(Customer $customer): Collection
    {
        return $customer->billingLines()
            ->with(['billingProduct', 'billingPrice', 'user'])
            ->whereIn('status', ['active', 'pending_cancel'])
            ->where('source', '!=', CustomerBillingLine::SOURCE_CUSTOMER_PRICE)
            ->orderBy('created_at')
            ->get();
    }

    public function activeServiceLevels(Customer $customer): Collection
    {
        return $customer->userServiceLevels()
            ->with(['billingProduct', 'billingPrice', 'user', 'assignedByUser'])
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get();
    }

    public function markRecurringLines(Customer $customer, string $status): void
    {
        $customer->billingLines()
            ->whereHas('billingPrice', fn ($query) => $query->where('interval', '!=', BillingPrice::INTERVAL_ONE_TIME))
            ->update([
                'status' => $status,
                'ends_at' => in_array($status, ['ended', 'cancelled'], true) ? now() : null,
            ]);
    }

    public function markUserServiceLevels(Customer $customer, string $status): void
    {
        $customer->userServiceLevels()
            ->update([
                'status' => $status,
                'ends_at' => in_array($status, ['ended', 'cancelled'], true) ? now() : null,
            ]);
    }

    private function desiredSubscriptionItems(Customer $customer): array
    {
        $items = [];

        $customer->billingLines()
            ->with('billingPrice')
            ->whereIn('status', ['active', 'pending_cancel'])
            ->where('source', '!=', CustomerBillingLine::SOURCE_CUSTOMER_PRICE)
            ->get()
            ->each(function (CustomerBillingLine $line) use (&$items): void {
                $price = $line->billingPrice;

                if (! $price instanceof BillingPrice || ! $price->is_active || ! $price->is_recurring || blank($price->stripe_price_id)) {
                    return;
                }

                $items[$price->stripe_price_id] = [
                    'quantity' => max(1, ($items[$price->stripe_price_id]['quantity'] ?? 0) + max(1, $line->quantity)),
                ];
            });

        $customer->userServiceLevels()
            ->with('billingPrice')
            ->where('status', 'active')
            ->get()
            ->groupBy('billing_price_id')
            ->each(function (Collection $levels, $billingPriceId) use (&$items): void {
                /** @var BillingPrice|null $price */
                $price = $levels->first()->billingPrice;

                if (! $price instanceof BillingPrice || ! $price->is_active || ! $price->is_recurring || blank($price->stripe_price_id)) {
                    return;
                }

                $items[$price->stripe_price_id] = [
                    'quantity' => max(1, ($items[$price->stripe_price_id]['quantity'] ?? 0) + $levels->count()),
                ];
            });

        return array_map(
            fn (array $payload, string $priceId): array => array_merge(['price' => $priceId], $payload),
            $items,
            array_keys($items)
        );
    }

    private function resolveBasePlanPriceFromItems(array $items): ?BillingPrice
    {
        foreach ($items as $item) {
            $priceId = is_array($item) ? ($item['price'] ?? null) : $item;
            if (! $priceId) {
                continue;
            }

            $price = BillingPrice::query()->with('product')->where('stripe_price_id', $priceId)->first();

            if ($price instanceof BillingPrice && $price->product instanceof BillingProduct && $price->product->category === BillingProduct::CATEGORY_BASE_PLAN) {
                return $price;
            }
        }

        return null;
    }

    private function syncBasePlanLine(Customer $customer, string $planKey, string $interval, string $source = 'system'): ?CustomerBillingLine
    {
        $price = $this->resolvePlanPrice($planKey, $interval);

        if (! $price instanceof BillingPrice || ! $price->is_active || ! $price->is_recurring) {
            return null;
        }

        $price->loadMissing('product');

        if (! $price->product instanceof BillingProduct || $price->product->category !== BillingProduct::CATEGORY_BASE_PLAN) {
            return null;
        }

        $customer->billingLines()
            ->whereNull('user_id')
            ->whereIn('status', ['active', 'pending_cancel'])
            ->whereHas('billingPrice.product', fn ($productQuery) => $productQuery->where('category', BillingProduct::CATEGORY_BASE_PLAN))
            ->where('billing_price_id', '!=', $price->id)
            ->where('source', '!=', CustomerBillingLine::SOURCE_CUSTOMER_PRICE)
            ->update([
                'status' => 'ended',
                'ends_at' => now(),
            ]);

        $line = CustomerBillingLine::query()
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $price->id)
            ->whereNull('user_id')
            ->where('source', $source)
            ->first();

        if ($line) {
            $line->update([
                'billing_product_id' => $price->billing_product_id,
                'description' => $price->name,
                'quantity' => 1,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => $customer->subscription('default')?->stripe_id,
                'stripe_subscription_item_id' => $this->stripeSubscriptionItemIdForPrice($customer, $price->stripe_price_id),
                'stripe_invoice_id' => null,
                'source' => $source,
                'metadata' => [
                    'billing_price_key' => $price->key,
                    'billing_product_key' => $price->product?->key,
                    'plan_key' => $planKey,
                    'billing_interval' => $interval,
                ],
            ]);
        } else {
            $line = CustomerBillingLine::create([
                'customer_id' => $customer->id,
                'billing_product_id' => $price->billing_product_id,
                'billing_price_id' => $price->id,
                'user_id' => null,
                'description' => $price->name,
                'quantity' => 1,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => $customer->subscription('default')?->stripe_id,
                'stripe_subscription_item_id' => $this->stripeSubscriptionItemIdForPrice($customer, $price->stripe_price_id),
                'stripe_invoice_id' => null,
                'source' => $source,
                'metadata' => [
                    'billing_price_key' => $price->key,
                    'billing_product_key' => $price->product?->key,
                    'plan_key' => $planKey,
                    'billing_interval' => $interval,
                ],
            ]);
        }

        return $line->fresh();
    }

    private function trialDaysForPrice(BillingPrice $price): int
    {
        $planKey = $price->metadata['plan_key'] ?? $price->tier_key ?? $price->product?->metadata['plan_key'] ?? null;

        if (! $planKey) {
            return 0;
        }

        return (int) config("procynia_plans.{$planKey}.trial_days", 0);
    }

    private function syncCustomerPlanMetaFromActiveSubscription(Customer $customer): void
    {
        $subscription = $customer->subscription('default');

        if (! $subscription) {
            return;
        }

        $basePlanPriceId = null;

        foreach ($subscription->items as $item) {
            $price = BillingPrice::query()->with('product')->where('stripe_price_id', $item->stripe_price)->first();

            if ($price instanceof BillingPrice && $price->product instanceof BillingProduct && $price->product->category === BillingProduct::CATEGORY_BASE_PLAN) {
                $basePlanPriceId = $price->id;
                $planKey = $price->metadata['plan_key'] ?? $price->tier_key ?? $price->product->metadata['plan_key'] ?? null;

                if ($planKey) {
                    $this->syncPlanMeta($customer, (string) $planKey, $price->interval);
                }

                break;
            }
        }

        if ($basePlanPriceId === null && $customer->subscription_plan === null) {
            $this->syncPlanMeta($customer, Customer::PLAN_FREE, Customer::BILLING_MONTHLY);
        }
    }

    private function syncUserServiceLevelBillingLine(Customer $customer, User $user, BillingPrice $price, int $quantity, string $source): CustomerBillingLine
    {
        return CustomerBillingLine::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'billing_price_id' => $price->id,
                'user_id' => $user->id,
                'source' => $source,
            ],
            [
                'billing_product_id' => $price->billing_product_id,
                'description' => $price->name,
                'quantity' => max(1, $quantity),
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'stripe_subscription_id' => $customer->subscription('default')?->stripe_id,
                'stripe_subscription_item_id' => $this->stripeSubscriptionItemIdForPrice($customer, $price->stripe_price_id),
                'metadata' => [
                    'billing_price_key' => $price->key,
                    'billing_product_key' => $price->product?->key,
                    'user_id' => $user->id,
                ],
            ]
        );
    }

    private function invoiceBillingLine(Customer $customer, CustomerBillingLine $line, BillingPrice $price, int $quantity, string $description)
    {
        try {
            if ($price->stripe_price_id) {
                return $customer->invoicePrice($price->stripe_price_id, $quantity, [
                    'description' => $description,
                ]);
            }

            if ($price->unit_amount !== null) {
                return $customer->invoiceFor($description, $price->unit_amount * $quantity);
            }
        } catch (Throwable $e) {
            Log::warning('[PROCYNIA][BILLING] Failed to create one-time invoice.', [
                'customer_id' => $customer->id,
                'billing_line_id' => $line->id,
                'billing_price_id' => $price->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        throw new RuntimeException('Engangsprisen mangler beløp eller Stripe price ID.');
    }

    private function stripeSubscriptionItemIdForPrice(Customer $customer, ?string $stripePriceId): ?string
    {
        if (! $stripePriceId) {
            return null;
        }

        $subscription = $customer->subscription('default');

        if (! $subscription) {
            return null;
        }

        $item = $subscription->items->firstWhere('stripe_price', $stripePriceId);

        return $item?->stripe_id;
    }

    private function priceLabel(BillingPrice $price): string
    {
        $product = $price->product;
        $intervalLabel = match ($price->interval) {
            BillingPrice::INTERVAL_YEARLY => 'Årlig',
            BillingPrice::INTERVAL_ONE_TIME => 'Engangs',
            default => 'Månedlig',
        };

        if ($product instanceof BillingProduct && $product->category === BillingProduct::CATEGORY_BASE_PLAN) {
            return "{$price->name} ({$intervalLabel})";
        }

        return "{$price->name} ({$intervalLabel})";
    }

    private function assertRecurringPrice(BillingPrice $price): void
    {
        if (! $price->is_active || ! $price->is_recurring || $price->interval === BillingPrice::INTERVAL_ONE_TIME) {
            throw new RuntimeException('Valgt pris kan ikke brukes som recurring linje.');
        }
    }

    private function assertOneTimePrice(BillingPrice $price): void
    {
        if (! $price->is_active || $price->interval !== BillingPrice::INTERVAL_ONE_TIME) {
            throw new RuntimeException('Valgt pris er ikke satt opp som engangspris.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSpecificPriceMetadata(BillingPrice $price, int $customUnitAmount, ?string $notes = null): array
    {
        return [
            'pricing_mode' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'billing_price_key' => $price->key,
            'billing_product_key' => $price->product?->key,
            'standard_unit_amount' => $price->unit_amount,
            'custom_unit_amount' => $customUnitAmount,
            'standard_currency' => $price->currency,
            'custom_currency' => $price->currency,
            'standard_interval' => $price->interval,
            'notes' => $notes,
        ];
    }

    private function recordEvent(?Customer $customer, string $eventType, string $source, string $description, ?array $before = null, ?array $after = null, ?string $stripeEventId = null, ?User $user = null): void
    {
        BillingEvent::create([
            'customer_id' => $customer?->id,
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'source' => $source,
            'description' => $description,
            'before' => $before,
            'after' => $after,
            'stripe_event_id' => $stripeEventId,
        ]);
    }
}
