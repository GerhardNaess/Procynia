<?php

namespace Tests\Unit\Services;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingEntitlementServiceTest extends TestCase
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

    public function test_can_add_user_respects_included_users_limit(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);

        User::factory()->create([
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->assertFalse($service->canAddUser($customer));
    }

    public function test_can_use_ai_offer_uses_plan_and_catalog_features(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);

        $this->assertFalse($service->canUseAiOffer($customer));
        $this->assertFalse($service->canUseFeature($customer, 'ai_offer'));

        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
        ])->save();

        $this->assertTrue($service->canUseAiOffer($customer));
        $this->assertFalse($service->canUseFeature($customer, 'ai_offer'));

        $product = BillingProduct::query()->create([
            'key' => 'ai_offer_addon',
            'name' => 'AI-tilbud',
            'description' => 'Tillegg for AI-tilbud.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 99,
            'metadata' => ['features' => ['ai_offer']],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'ai_offer_addon_monthly',
            'name' => 'AI-tilbud — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 99000,
            'stripe_price_id' => null,
            'tier_key' => 'ai_offer',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => ['features' => ['ai_offer']],
        ]);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'description' => $price->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'system',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        $this->assertTrue($service->customerHasFeature($customer, 'ai_offer'));
        $this->assertTrue($service->canUseAiOffer($customer));
        $this->assertTrue($service->canUseFeature($customer, 'ai_offer'));
    }

    public function test_customer_without_explicit_plan_gets_free_plan_features_from_plan_config_when_no_catalog_lines_exist(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);

        $this->assertTrue($service->customerHasFeature($customer, 'anbudssok'));
        $this->assertTrue($service->customerHasFeature($customer, 'email_varsel'));
    }

    public function test_customer_can_get_free_plan_features_via_active_catalog_line_when_free_config_has_no_features(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalFreePlanConfig = config('procynia_plans.free');

        try {
            config()->set('procynia_plans.free.features', []);

            $this->attachBillingLineWithFeature(
                $customer,
                'free_catalog_line',
                productMetadata: ['features' => ['anbudssok', 'email_varsel']],
                priceMetadata: ['features' => []]
            );

            $this->assertTrue($service->customerHasFeature($customer, 'anbudssok'));
            $this->assertTrue($service->customerHasFeature($customer, 'email_varsel'));
        } finally {
            config()->set('procynia_plans.free', $originalFreePlanConfig);
        }
    }

    public function test_customer_has_feature_uses_market_insight_as_the_canonical_feature_key_in_plan_config(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalProPlanConfig = config('procynia_plans.pro');

        try {
            config()->set('procynia_plans.pro.features', ['market_insight']);

            $customer->forceFill([
                'subscription_plan' => Customer::PLAN_PRO,
                'billing_interval' => Customer::BILLING_MONTHLY,
            ])->save();

            $this->assertTrue($service->customerHasFeature($customer, 'market_insight'));
            $this->assertFalse($service->customerHasFeature($customer, 'markedsinnsikt'));
        } finally {
            config()->set('procynia_plans.pro', $originalProPlanConfig);
        }
    }

    public function test_customer_has_feature_uses_market_insight_as_the_canonical_feature_key_on_catalog_lines(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalFreePlanConfig = config('procynia_plans.free');

        try {
            config()->set('procynia_plans.free.features', []);

            $this->attachBillingLineWithFeature($customer, 'market_insight');

            $this->assertTrue($service->customerHasFeature($customer, 'market_insight'));
            $this->assertFalse($service->customerHasFeature($customer, 'markedsinnsikt'));
        } finally {
            config()->set('procynia_plans.free', $originalFreePlanConfig);
        }
    }

    public function test_customer_has_feature_returns_true_when_feature_exists_in_plan_config_only(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalPlanConfig = config('procynia_plans.pro');

        try {
            config()->set('procynia_plans.pro.features', ['config_only_test_feature']);

            $customer->forceFill([
                'subscription_plan' => Customer::PLAN_PRO,
                'billing_interval' => Customer::BILLING_MONTHLY,
            ])->save();

            $this->assertTrue($service->customerHasFeature($customer, 'config_only_test_feature'));
        } finally {
            config()->set('procynia_plans.pro', $originalPlanConfig);
        }
    }

    public function test_customer_has_feature_returns_true_when_feature_exists_on_active_catalog_line_only(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalFreePlanConfig = config('procynia_plans.free');

        try {
            config()->set('procynia_plans.free.features', []);

            $this->attachBillingLineWithFeature($customer, 'catalog_only_test_feature');

            $this->assertTrue($service->customerHasFeature($customer, 'catalog_only_test_feature'));
        } finally {
            config()->set('procynia_plans.free', $originalFreePlanConfig);
        }
    }

    public function test_customer_has_feature_returns_false_when_feature_is_missing_from_config_and_catalog(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalFreePlanConfig = config('procynia_plans.free');

        try {
            config()->set('procynia_plans.free.features', []);

            $this->assertFalse($service->customerHasFeature($customer, 'missing_test_feature'));
        } finally {
            config()->set('procynia_plans.free', $originalFreePlanConfig);
        }
    }

    public function test_customer_has_feature_still_returns_true_when_plan_config_provides_feature_even_if_catalog_line_does_not(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalPlanConfig = config('procynia_plans.pro');

        try {
            config()->set('procynia_plans.pro.features', ['conflict_test_feature']);

            $customer->forceFill([
                'subscription_plan' => Customer::PLAN_PRO,
                'billing_interval' => Customer::BILLING_MONTHLY,
            ])->save();

            $this->attachBillingLineWithoutFeature($customer);

            $this->assertTrue($service->customerHasFeature($customer, 'conflict_test_feature'));
        } finally {
            config()->set('procynia_plans.pro', $originalPlanConfig);
        }
    }

    public function test_customer_has_feature_still_returns_true_when_catalog_line_provides_feature_even_if_plan_config_does_not(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalFreePlanConfig = config('procynia_plans.free');

        try {
            config()->set('procynia_plans.free.features', []);

            $this->attachBillingLineWithFeature($customer, 'conflict_test_feature');

            $this->assertTrue($service->customerHasFeature($customer, 'conflict_test_feature'));
        } finally {
            config()->set('procynia_plans.free', $originalFreePlanConfig);
        }
    }

    public function test_customer_has_feature_does_not_use_price_metadata_features_alone_for_runtime_access(): void
    {
        $customer = $this->createCustomer();
        $service = app(BillingEntitlementService::class);
        $originalFreePlanConfig = config('procynia_plans.free');

        try {
            config()->set('procynia_plans.free.features', []);

            $this->attachBillingLineWithFeature(
                $customer,
                'missing_test_feature',
                productMetadata: [],
                priceMetadata: ['price_only_test_feature']
            );

            $this->assertFalse($service->customerHasFeature($customer, 'price_only_test_feature'));
        } finally {
            config()->set('procynia_plans.free', $originalFreePlanConfig);
        }
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

    private function attachBillingLineWithFeature(
        Customer $customer,
        string $featureKey,
        ?array $productMetadata = null,
        ?array $priceMetadata = null
    ): CustomerBillingLine {
        $product = BillingProduct::query()->create([
            'key' => $featureKey.'_product',
            'name' => Str::headline(str_replace('_', ' ', $featureKey)).' product',
            'description' => 'Test product for '.$featureKey,
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 99,
            'metadata' => $productMetadata ?? ['features' => [$featureKey]],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => $featureKey.'_monthly',
            'name' => Str::headline(str_replace('_', ' ', $featureKey)).' — Monthly',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 9900,
            'stripe_price_id' => null,
            'tier_key' => $featureKey,
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => $priceMetadata ?? ['features' => [$featureKey]],
        ]);

        return CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'description' => $price->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'system',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]);
    }

    private function attachBillingLineWithoutFeature(Customer $customer): CustomerBillingLine
    {
        return $this->attachBillingLineWithFeature(
            $customer,
            'no_feature_test_line',
            productMetadata: ['features' => []],
            priceMetadata: ['features' => []]
        );
    }

    private function useProjectPostgresConnection(): void
    {
        $connectionName = 'feature_pgsql';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'pgsql',
                'host' => 'postgres',
                'port' => '5432',
                'database' => 'procynia_test',
                'username' => 'gehard',
                'password' => 'Opaque01',
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

}
