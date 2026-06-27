<?php

namespace Tests\Unit\Services;

use App\Models\BillingEvent;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\CustomerUserServiceLevel;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;
use Mockery;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_add_recurring_line_creates_local_billing_line_and_audit_event(): void
    {
        $customer = $this->createCustomer();
        $customer->forceFill(['stripe_id' => 'cus_test_123'])->save();

        $product = BillingProduct::query()->create([
            'key' => 'addon_report',
            'name' => 'Rapport',
            'description' => 'Løpende tillegg.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
            'metadata' => [],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_report_monthly',
            'name' => 'Rapport — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 25000,
            'stripe_price_id' => 'price_addon_report',
            'tier_key' => 'report',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        $this->partialMock(BillingService::class, function ($mock): void {
            $mock->shouldReceive('recalculateSubscriptionItems')
                ->once()
                ->andReturn([
                    'subscription' => null,
                    'items' => [],
                ]);
        });

        $service = app(BillingService::class);
        $line = $service->addRecurringLine($customer, $price, 3);

        $this->assertInstanceOf(CustomerBillingLine::class, $line);
        $this->assertDatabaseHas('customer_billing_lines', [
            'customer_id' => $customer->id,
            'billing_price_id' => $price->id,
            'quantity' => 3,
            'status' => 'active',
            'source' => 'admin',
        ]);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'event_type' => 'recurring_line_added',
        ]);
    }

    public function test_ensure_stripe_customer_uses_customer_helper_when_missing_stripe_id(): void
    {
        $customer = $this->createCustomer();

        $mockCustomer = Mockery::mock($customer)->makePartial();
        $mockCustomer->shouldReceive('createOrGetStripeCustomer')
            ->once()
            ->with(['name' => $customer->name])
            ->andReturnNull();
        $mockCustomer->shouldReceive('refresh')
            ->once()
            ->andReturnSelf();

        $service = app(BillingService::class);
        $result = $service->ensureStripeCustomer($mockCustomer);

        $this->assertSame($mockCustomer, $result);
        $this->assertDatabaseHas('billing_events', [
            'event_type' => 'stripe_customer_created',
            'description' => 'Stripe-kunde ble opprettet.',
        ]);
    }

    public function test_assign_user_service_level_creates_level_and_local_line(): void
    {
        $customer = $this->createCustomer();
        $customer->forceFill(['stripe_id' => 'cus_test_456'])->save();

        $product = BillingProduct::query()->create([
            'key' => 'test_ai_offer',
            'name' => 'AI-tilbud',
            'description' => 'Brukerbasert tjeneste.',
            'category' => BillingProduct::CATEGORY_USER_SERVICE,
            'billing_scope' => BillingProduct::BILLING_SCOPE_USER,
            'is_active' => true,
            'sort_order' => 2,
            'metadata' => ['features' => ['ai_offer']],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'test_ai_offer_monthly',
            'name' => 'AI-tilbud — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 49000,
            'stripe_price_id' => 'price_ai_offer',
            'tier_key' => 'ai_offer',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => ['features' => ['ai_offer']],
        ]);

        $user = User::query()->create([
            'name' => 'AI Bruker',
            'email' => 'ai.bruker@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $assignedBy = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->partialMock(BillingService::class, function ($mock): void {
            $mock->shouldReceive('recalculateSubscriptionItems')
                ->once()
                ->andReturn([
                    'subscription' => null,
                    'items' => [],
                ]);
        });

        $service = app(BillingService::class);
        $level = $service->assignUserServiceLevel($customer, $user, $price, $assignedBy);

        $this->assertInstanceOf(CustomerUserServiceLevel::class, $level);
        $this->assertDatabaseHas('customer_user_service_levels', [
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'billing_price_id' => $price->id,
            'status' => 'active',
            'level_key' => 'ai_offer',
        ]);
        $this->assertDatabaseHas('customer_billing_lines', [
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'billing_price_id' => $price->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'event_type' => 'user_service_level_assigned',
        ]);
    }

    public function test_add_one_time_charge_can_be_queued_without_immediate_invoice(): void
    {
        $customer = $this->createCustomer();
        $customer->forceFill(['stripe_id' => 'cus_test_789'])->save();

        $product = BillingProduct::query()->create([
            'key' => 'test_onboarding',
            'name' => 'Onboarding',
            'description' => 'Engangsoppdrag.',
            'category' => BillingProduct::CATEGORY_ONE_OFF,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 3,
            'metadata' => [],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'test_onboarding_one_time',
            'name' => 'Onboarding — Engangs',
            'interval' => BillingPrice::INTERVAL_ONE_TIME,
            'currency' => 'nok',
            'unit_amount' => 125000,
            'stripe_price_id' => null,
            'tier_key' => 'onboarding',
            'is_recurring' => false,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        $service = app(BillingService::class);
        $line = $service->addOneTimeCharge($customer, $price, 2, 'Oppstartsmøte og datavask', false);

        $this->assertInstanceOf(CustomerBillingLine::class, $line);
        $this->assertDatabaseHas('customer_billing_lines', [
            'customer_id' => $customer->id,
            'billing_price_id' => $price->id,
            'quantity' => 2,
            'status' => 'active',
            'stripe_invoice_id' => null,
        ]);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'event_type' => 'one_time_charge_added',
        ]);
    }

    public function test_customer_specific_price_lines_are_kept_separate_from_standard_billing_lines(): void
    {
        $customer = $this->createCustomer();
        $customer->forceFill(['stripe_id' => 'cus_test_custom_price'])->save();

        $product = BillingProduct::query()->create([
            'key' => 'addon_consulting',
            'name' => 'Konsulenttillegg',
            'description' => 'Tilpasset kundearrangement.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 4,
            'metadata' => [],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_consulting_monthly',
            'name' => 'Konsulenttillegg — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 199000,
            'stripe_price_id' => 'price_consulting_standard',
            'tier_key' => 'consulting',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        $service = app(BillingService::class);
        $customLine = $service->addCustomerSpecificPrice($customer, $price, 149000, 2, null, 'Avtalt rabatt');

        $this->assertSame(CustomerBillingLine::SOURCE_CUSTOMER_PRICE, $customLine->source);
        $this->assertDatabaseHas('customer_billing_lines', [
            'id' => $customLine->id,
            'customer_id' => $customer->id,
            'billing_price_id' => $price->id,
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'status' => 'active',
            'quantity' => 2,
        ]);
        $this->assertSame(149000, data_get($customLine->metadata, 'custom_unit_amount'));
        $this->assertSame(199000, data_get($customLine->metadata, 'standard_unit_amount'));
        $this->assertSame('Avtalt rabatt', data_get($customLine->metadata, 'notes'));

        $this->assertCount(0, $service->activeBillingLines($customer));
        $this->assertCount(1, $service->customerSpecificPriceLines($customer));

        $this->partialMock(BillingService::class, function ($mock): void {
            $mock->shouldReceive('recalculateSubscriptionItems')
                ->once()
                ->andReturn([
                    'subscription' => null,
                    'items' => [],
                ]);
        });

        $standardLine = app(BillingService::class)->addRecurringLine($customer, $price, 1);

        $this->assertNotSame($customLine->id, $standardLine->id);
        $this->assertDatabaseHas('customer_billing_lines', [
            'customer_id' => $customer->id,
            'billing_price_id' => $price->id,
            'source' => 'admin',
            'status' => 'active',
        ]);
        $this->assertCount(1, $service->customerSpecificPriceLines($customer));
        $this->assertCount(1, $service->activeBillingLines($customer));
    }

    public function test_customer_specific_price_lines_preserve_history_when_replaced_and_deactivated(): void
    {
        $customer = $this->createCustomer();

        $product = BillingProduct::query()->create([
            'key' => 'addon_retainer',
            'name' => 'Retainer',
            'description' => 'Løpende kundetilpasning.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 5,
            'metadata' => [],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_retainer_monthly',
            'name' => 'Retainer — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 249000,
            'stripe_price_id' => 'price_retainer_standard',
            'tier_key' => 'retainer',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        $service = app(BillingService::class);
        $first = $service->addCustomerSpecificPrice($customer, $price, 189000, 1, null, 'Første avtale');
        $second = $service->replaceCustomerSpecificPrice($first, 169000, 3, 'Ny avtale');

        $this->assertSame('ended', $first->fresh()->status);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame(169000, data_get($second->metadata, 'custom_unit_amount'));
        $this->assertSame(3, $second->quantity);

        $service->deactivateCustomerSpecificPrice($second);

        $this->assertSame('ended', $second->fresh()->status);
        $this->assertCount(2, $service->customerSpecificPriceLines($customer));
    }

    public function test_resolve_price_id_prefers_local_billing_price_over_config_fallback(): void
    {
        Config::set('procynia_plans.pro.stripe_monthly', 'price_config_fallback');

        $priceKey = Customer::PLAN_PRO.'_'.Customer::BILLING_MONTHLY;
        $price = BillingPrice::query()->where('key', $priceKey)->first();

        if ($price === null) {
            $product = BillingProduct::query()->create([
                'key' => 'pro_plan_catalog',
                'name' => 'Pro plan',
                'description' => 'Tjenestekatalog base plan.',
                'category' => BillingProduct::CATEGORY_BASE_PLAN,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'is_active' => true,
                'sort_order' => 1,
                'metadata' => [],
            ]);

            $price = BillingPrice::query()->create([
                'billing_product_id' => $product->id,
                'key' => $priceKey,
                'name' => 'Pro — Månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 199000,
                'stripe_price_id' => 'price_local_catalog',
                'tier_key' => 'pro',
                'is_recurring' => true,
                'is_active' => true,
                'included_quantity' => 1,
                'metadata' => [],
            ]);
        } else {
            $price->forceFill(['stripe_price_id' => 'price_local_catalog'])->save();
        }

        $result = app(BillingService::class)->resolvePriceId(Customer::PLAN_PRO, Customer::BILLING_MONTHLY);

        $this->assertSame('price_local_catalog', $result);
    }

    private function createCustomer(string $name = 'Procynia AS'): Customer
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
        ]);
    }

    private function useProjectPostgresConnection(): void
    {
        $connectionName = 'feature_pgsql';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'pgsql',
                'host' => $this->projectEnv('DB_HOST', '127.0.0.1'),
                'port' => $this->projectEnv('DB_PORT', '5432'),
                'database' => $this->projectEnv('DB_DATABASE', 'procynia'),
                'username' => $this->projectEnv('DB_USERNAME', 'gehard'),
                'password' => $this->projectEnv('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);
    }

    private function projectEnv(string $key, string $default): string
    {
        $value = env($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }
}
