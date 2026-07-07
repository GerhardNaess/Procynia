<?php

namespace Tests\Unit\Services\Billing;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Billing\BillingEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingEntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Purpose: Verify that a monthly base-plan billing line returns the correct NOK monthly value.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_base_plan_monthly_value_returns_correct_nok_for_monthly_price(): void
    {
        $customer = $this->createCustomer('Monthly Plan Kunde AS');
        $product = $this->createProduct('test-base-plan-monthly');
        $price = $this->createPrice($product, BillingPrice::INTERVAL_MONTHLY, 150000, 'nok');
        $this->createLine($customer, $product, $price);

        $result = app(BillingEntitlementService::class)->basePlanMonthlyValueNok($customer);

        $this->assertEqualsWithDelta(1500.0, $result, 0.0001);
    }

    /**
     * Purpose: Verify that a yearly base-plan billing line returns the monthly equivalent (unit_amount / 100 / 12).
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_base_plan_monthly_value_divides_yearly_price_by_twelve(): void
    {
        $customer = $this->createCustomer('Yearly Plan Kunde AS');
        $product = $this->createProduct('test-base-plan-yearly');
        $price = $this->createPrice($product, BillingPrice::INTERVAL_YEARLY, 1200000, 'nok');
        $this->createLine($customer, $product, $price);

        $result = app(BillingEntitlementService::class)->basePlanMonthlyValueNok($customer);

        $this->assertEqualsWithDelta(1000.0, $result, 0.0001);
    }

    /**
     * Purpose: Verify that a customer-specific price uses metadata.custom_unit_amount over BillingPrice.unit_amount.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_base_plan_monthly_value_uses_custom_unit_amount_for_customer_price_source(): void
    {
        $customer = $this->createCustomer('Kundepris Kunde AS');
        $product = $this->createProduct('test-base-plan-custom');
        $price = $this->createPrice($product, BillingPrice::INTERVAL_MONTHLY, 150000, 'nok');
        $this->createLine($customer, $product, $price, [
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'metadata' => ['custom_unit_amount' => 50000],
        ]);

        $result = app(BillingEntitlementService::class)->basePlanMonthlyValueNok($customer);

        $this->assertEqualsWithDelta(500.0, $result, 0.0001);
    }

    /**
     * Purpose: Verify that a customer with no active base-plan billing line gets null.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_base_plan_monthly_value_returns_null_when_no_active_billing_line_exists(): void
    {
        $customer = $this->createCustomer('Ingen Linje Kunde AS');

        $result = app(BillingEntitlementService::class)->basePlanMonthlyValueNok($customer);

        $this->assertNull($result);
    }

    /**
     * Purpose: Verify that a billing line with null unit_amount returns null when no custom amount is set.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_base_plan_monthly_value_returns_null_when_unit_amount_is_null(): void
    {
        $customer = $this->createCustomer('Null Pris Kunde AS');
        $product = $this->createProduct('test-base-plan-null-amount');
        $price = $this->createPrice($product, BillingPrice::INTERVAL_MONTHLY, null, 'nok');
        $this->createLine($customer, $product, $price);

        $result = app(BillingEntitlementService::class)->basePlanMonthlyValueNok($customer);

        $this->assertNull($result);
    }

    /**
     * Purpose: Verify that a billing line with a non-NOK currency returns null.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_base_plan_monthly_value_returns_null_when_currency_is_not_nok(): void
    {
        $customer = $this->createCustomer('USD Kunde AS');
        $product = $this->createProduct('test-base-plan-usd');
        $price = $this->createPrice($product, BillingPrice::INTERVAL_MONTHLY, 150000, 'usd');
        $this->createLine($customer, $product, $price);

        $result = app(BillingEntitlementService::class)->basePlanMonthlyValueNok($customer);

        $this->assertNull($result);
    }

    /**
     * Purpose: Verify that a zero snapshot (NOT NULL DEFAULT 0) falls through to planConfig.
     * After a DB reset, customers have included_ai_credits = 0 because syncPlanMeta was never
     * called. This must not block AI access on paid plans.
     */
    public function test_included_ai_credits_zero_snapshot_falls_through_to_plan_config(): void
    {
        // PLAN_PRO has included_ai_credits = 3 in procynia_plans.php
        $customer = $this->createCustomer('Zero Snapshot Pro Kunde AS', Customer::PLAN_PRO);

        $result = app(BillingEntitlementService::class)->includedAiCredits($customer);

        $this->assertSame(3, $result);
    }

    /**
     * Purpose: Verify that a zero snapshot on the free plan still returns 0 (free plan has 0 AI credits).
     */
    public function test_included_ai_credits_zero_snapshot_on_free_plan_returns_zero(): void
    {
        $customer = $this->createCustomer('Zero Snapshot Free Kunde AS', Customer::PLAN_FREE);

        $result = app(BillingEntitlementService::class)->includedAiCredits($customer);

        $this->assertSame(0, $result);
    }

    /**
     * Purpose: Verify canUseAiOffer returns true when planConfig provides credits and the DB snapshot is the default 0.
     */
    public function test_can_use_ai_offer_returns_true_when_plan_config_provides_credits_and_snapshot_is_default_zero(): void
    {
        // PLAN_ULTRA has included_ai_credits = 60 in procynia_plans.php
        $customer = $this->createCustomer('Ultra Plan Kunde AS', Customer::PLAN_ULTRA);

        $result = app(BillingEntitlementService::class)->canUseAiOffer($customer);

        $this->assertTrue($result);
    }

    /**
     * Purpose: Verify canUseAiOffer returns false for free plan even when snapshot is the default 0.
     * The free plan has 0 AI credits; the zero snapshot must not accidentally unblock access.
     */
    public function test_can_use_ai_offer_returns_false_for_free_plan_with_default_zero_snapshot(): void
    {
        $customer = $this->createCustomer('Free Plan Kunde AS', Customer::PLAN_FREE);

        $result = app(BillingEntitlementService::class)->canUseAiOffer($customer);

        $this->assertFalse($result);
    }

    /**
     * Purpose: Verify that a billing line with included_ai_offers still takes Priority 1
     * even when the plan config would also grant credits.
     */
    public function test_included_ai_credits_billing_line_wins_over_plan_config_when_both_are_present(): void
    {
        $customer = $this->createCustomer('Billing Line Beats Config AS', Customer::PLAN_ULTRA);
        $product = $this->createProduct('test-bp-line-vs-config');
        // Billing line says 8; plan config says 60
        $price = $this->createPrice($product, BillingPrice::INTERVAL_MONTHLY, 649000, 'nok', 8);
        $this->createLine($customer, $product, $price);

        $result = app(BillingEntitlementService::class)->includedAiCredits($customer);

        $this->assertSame(8, $result);
    }

    /**
     * Purpose: Verify that includedAiCredits() still reads from Tjenestekatalog after activeBasePlanLine() was extracted.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_included_ai_credits_prefers_tjenestekatalog_over_customer_snapshot_when_active_line_exists(): void
    {
        $customer = $this->createCustomer('Tjenestekatalog Kapasitet AS');
        $customer->forceFill(['included_ai_credits' => 5])->save();
        $product = $this->createProduct('test-base-plan-credits');
        $price = $this->createPrice($product, BillingPrice::INTERVAL_MONTHLY, 649000, 'nok', 60);
        $this->createLine($customer, $product, $price);

        $result = app(BillingEntitlementService::class)->includedAiCredits($customer);

        $this->assertSame(60, $result);
    }

    /**
     * Purpose: Verify that includedAiCredits() falls back to the customer snapshot before plan config when no active billing line exists.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes fixture rows to the test database.
     */
    public function test_included_ai_credits_prefers_customer_snapshot_over_config_fallback_when_no_active_line_exists(): void
    {
        $customer = $this->createCustomer('Customer Snapshot Fallback AS');
        $customer->forceFill(['included_ai_credits' => 7])->save();

        $result = app(BillingEntitlementService::class)->includedAiCredits($customer);

        $this->assertSame(7, $result);
    }

    /**
     * Purpose: Create a minimal customer fixture for BillingEntitlementService tests.
     * Inputs: Customer name and optional subscription plan (defaults to PLAN_PRO).
     * Returns: The persisted Customer model.
     * Side effects: Writes language, nationality and customer rows.
     */
    private function createCustomer(string $name, string $plan = Customer::PLAN_PRO): Customer
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
            'is_active' => true,
            'subscription_plan' => $plan,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 0,
        ]);
    }

    /**
     * Purpose: Create a base-plan BillingProduct fixture.
     * Inputs: A unique product key.
     * Returns: The persisted BillingProduct model.
     * Side effects: Writes one billing_products row.
     */
    private function createProduct(string $key): BillingProduct
    {
        return BillingProduct::query()->create([
            'key' => $key,
            'name' => 'Test Base Plan',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a BillingPrice fixture for a given product.
     * Inputs: Product, interval, unit amount in øre (nullable), currency, and optional AI offers.
     * Returns: The persisted BillingPrice model.
     * Side effects: Writes one billing_prices row.
     */
    private function createPrice(
        BillingProduct $product,
        string $interval,
        ?int $unitAmount,
        string $currency = 'nok',
        int $includedAiOffers = 0,
    ): BillingPrice {
        return BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => $product->key.'-'.$interval.'-'.Str::lower(Str::random(4)),
            'name' => 'Test Price',
            'interval' => $interval,
            'currency' => $currency,
            'unit_amount' => $unitAmount,
            'is_recurring' => $interval !== BillingPrice::INTERVAL_ONE_TIME,
            'is_active' => true,
            'included_quantity' => 1,
            'included_ai_offers' => $includedAiOffers,
        ]);
    }

    /**
     * Purpose: Create a CustomerBillingLine fixture linking a customer to a billing price.
     * Inputs: Customer, product, price, and optional attribute overrides.
     * Returns: The persisted CustomerBillingLine model.
     * Side effects: Writes one customer_billing_lines row.
     */
    private function createLine(
        Customer $customer,
        BillingProduct $product,
        BillingPrice $price,
        array $attributes = [],
    ): CustomerBillingLine {
        return CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'description' => 'Test base plan line',
            'quantity' => 1,
            'status' => $attributes['status'] ?? 'active',
            'source' => $attributes['source'] ?? 'system',
            'metadata' => $attributes['metadata'] ?? [],
        ]);
    }
}
