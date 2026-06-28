<?php

namespace Tests\Feature\Console;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillFreePlanBillingLinesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dry_run_reports_eligible_customers_without_writing(): void
    {
        $this->ensureFreePlanCatalog();

        $eligibleNullCustomer = $this->createCustomer('Eligible Null AS');
        $eligibleFreeCustomer = $this->createCustomer('Eligible Free AS', Customer::PLAN_FREE);

        $this->artisan('billing:backfill-free-plan-lines')
            ->expectsOutputToContain('mode=dry-run')
            ->expectsOutputToContain('materialized=0')
            ->expectsOutputToContain('Eligible Null AS')
            ->expectsOutputToContain('Eligible Free AS')
            ->assertSuccessful();

        $this->assertDatabaseMissing('customer_billing_lines', [
            'customer_id' => $eligibleNullCustomer->id,
        ]);
        $this->assertDatabaseMissing('customer_billing_lines', [
            'customer_id' => $eligibleFreeCustomer->id,
        ]);
    }

    public function test_command_apply_materializes_free_plan_lines_and_is_idempotent(): void
    {
        $this->ensureFreePlanCatalog();

        $customer = $this->createCustomer('Apply Eligible AS');

        $this->artisan('billing:backfill-free-plan-lines', ['--apply' => true])
            ->expectsOutputToContain('mode=apply')
            ->expectsOutputToContain('materialized=')
            ->expectsOutputToContain('Apply Eligible AS')
            ->assertSuccessful();

        $freePrice = BillingPrice::query()->where('key', 'free_monthly')->firstOrFail();

        $lines = CustomerBillingLine::query()
            ->with('billingProduct')
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $freePrice->id)
            ->where('status', 'active')
            ->get();

        $this->assertCount(1, $lines);
        $this->assertSame('plan_free', $lines->first()->billingProduct?->key);
        $this->assertNull($lines->first()->stripe_subscription_item_id);
        $this->assertNull($freePrice->stripe_price_id);
        $this->assertSame(['anbudssok', 'email_varsel'], $lines->first()->billingProduct?->metadata['features'] ?? []);

        $this->artisan('billing:backfill-free-plan-lines', ['--apply' => true])
            ->expectsOutputToContain('mode=apply')
            ->expectsOutputToContain('materialized=0')
            ->expectsOutputToContain('skipped_existing_active_plan_free=')
            ->assertSuccessful();

        $this->assertCount(1, CustomerBillingLine::query()
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $freePrice->id)
            ->where('status', 'active')
            ->get());
    }

    public function test_command_skips_customers_with_active_paid_baseplan(): void
    {
        $this->ensureFreePlanCatalog();
        $paidPrice = $this->ensurePaidBasePlanCatalog();

        $customer = $this->createCustomer('Paid Baseplan AS');
        $this->createActiveBillingLine($customer, $paidPrice);

        $this->artisan('billing:backfill-free-plan-lines', ['--apply' => true])
            ->expectsOutputToContain('skipped_active_paid_baseplan=')
            ->assertSuccessful();

        $this->assertDatabaseMissing('customer_billing_lines', [
            'customer_id' => $customer->id,
            'billing_price_id' => BillingPrice::query()->where('key', 'free_monthly')->value('id'),
            'status' => 'active',
        ]);
    }

    public function test_command_skips_customers_with_active_cashier_subscription(): void
    {
        $this->ensureFreePlanCatalog();

        $customer = $this->createCustomer('Subscribed Customer AS');
        $this->createActiveCashierSubscription($customer);

        $this->artisan('billing:backfill-free-plan-lines', ['--apply' => true])
            ->expectsOutputToContain('skipped_active_cashier_subscription=')
            ->assertSuccessful();

        $freePrice = BillingPrice::query()->where('key', 'free_monthly')->firstOrFail();

        $this->assertDatabaseMissing('customer_billing_lines', [
            'customer_id' => $customer->id,
            'billing_price_id' => $freePrice->id,
            'status' => 'active',
        ]);
    }

    public function test_command_skips_customers_with_existing_active_plan_free_line(): void
    {
        $freePrice = $this->ensureFreePlanCatalog();

        $customer = $this->createCustomer('Existing Free Line AS');
        $this->createActiveBillingLine($customer, $freePrice);

        $this->artisan('billing:backfill-free-plan-lines', ['--apply' => true])
            ->expectsOutputToContain('skipped_existing_active_plan_free=')
            ->assertSuccessful();

        $this->assertCount(1, CustomerBillingLine::query()
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $freePrice->id)
            ->where('status', 'active')
            ->get());
    }

    public function test_command_skips_customers_with_non_free_plan_values(): void
    {
        $this->ensureFreePlanCatalog();

        $customer = $this->createCustomer('Ultra Customer AS', 'ultra');

        $this->artisan('billing:backfill-free-plan-lines', ['--apply' => true])
            ->expectsOutputToContain('skipped_non_free_plan=')
            ->assertSuccessful();

        $this->assertDatabaseMissing('customer_billing_lines', [
            'customer_id' => $customer->id,
        ]);
    }

    private function ensureFreePlanCatalog(): BillingPrice
    {
        $product = BillingProduct::query()->updateOrCreate(
            ['key' => 'plan_free'],
            [
                'name' => 'Free',
                'description' => 'Free base plan used for customers without an active paid subscription.',
                'category' => BillingProduct::CATEGORY_BASE_PLAN,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'is_active' => true,
                'sort_order' => 2,
                'metadata' => ['plan_key' => 'free', 'features' => ['anbudssok', 'email_varsel']],
            ]
        );

        return BillingPrice::query()->updateOrCreate(
            ['key' => 'free_monthly'],
            [
                'billing_product_id' => $product->id,
                'name' => 'Free — Monthly',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 0,
                'stripe_price_id' => null,
                'tier_key' => 'free',
                'is_recurring' => true,
                'is_active' => true,
                'included_quantity' => 1,
                'metadata' => ['plan_key' => 'free'],
            ]
        );
    }

    private function ensurePaidBasePlanCatalog(): BillingPrice
    {
        $product = BillingProduct::query()->updateOrCreate(
            ['key' => 'plan_ultra_test'],
            [
                'name' => 'Ultra',
                'description' => 'Paid base plan used for skip checks.',
                'category' => BillingProduct::CATEGORY_BASE_PLAN,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'is_active' => true,
                'sort_order' => 3,
                'metadata' => ['plan_key' => 'ultra'],
            ]
        );

        return BillingPrice::query()->updateOrCreate(
            ['key' => 'ultra_monthly_test'],
            [
                'billing_product_id' => $product->id,
                'name' => 'Ultra — Monthly',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 99900,
                'stripe_price_id' => 'price_ultra_test',
                'tier_key' => 'ultra',
                'is_recurring' => true,
                'is_active' => true,
                'included_quantity' => 1,
                'metadata' => ['plan_key' => 'ultra'],
            ]
        );
    }

    private function createActiveBillingLine(Customer $customer, BillingPrice $price): CustomerBillingLine
    {
        return CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $price->billing_product_id,
            'billing_price_id' => $price->id,
            'description' => $price->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => 'system',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $price->product?->key,
            ],
        ]);
    }

    private function createActiveCashierSubscription(Customer $customer): void
    {
        DB::table('subscriptions')->insert([
            'customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::lower(Str::random(12)),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_active',
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCustomer(string $name, ?string $plan = null): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'subscription_plan' => $plan,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }
}
