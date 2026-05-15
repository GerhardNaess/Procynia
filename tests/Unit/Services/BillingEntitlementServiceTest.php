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
