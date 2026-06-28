<?php

namespace Tests\Unit\Services;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
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

    public function test_subscribe_to_free_plan_materializes_free_plan_as_active_catalog_line(): void
    {
        $customer = $this->createCustomer();
        $service = app(SubscriptionService::class);

        $freeProduct = BillingProduct::query()->firstOrCreate(
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

        $freePrice = BillingPrice::query()->firstOrCreate(
            ['key' => 'free_monthly'],
            [
                'billing_product_id' => $freeProduct->id,
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

        $service->subscribe($customer, Customer::PLAN_FREE, Customer::BILLING_MONTHLY);
        $service->subscribe($customer, Customer::PLAN_FREE, Customer::BILLING_MONTHLY);

        $customer->refresh();

        $this->assertSame(Customer::PLAN_FREE, $customer->subscription_plan);
        $this->assertSame(Customer::BILLING_MONTHLY, $customer->billing_interval);

        $freeLines = CustomerBillingLine::query()
            ->with('billingProduct')
            ->where('customer_id', $customer->id)
            ->where('billing_price_id', $freePrice->id)
            ->where('status', 'active')
            ->get();

        $this->assertCount(1, $freeLines);
        $this->assertSame('plan_free', $freeLines->first()->billingProduct?->key);
        $this->assertNull($freeLines->first()->stripe_subscription_item_id);
        $this->assertNull($freePrice->stripe_price_id);
        $this->assertSame(['anbudssok', 'email_varsel'], $freeLines->first()->billingProduct?->metadata['features'] ?? []);
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
