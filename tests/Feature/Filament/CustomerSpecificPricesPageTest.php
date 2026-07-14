<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class CustomerSpecificPricesPageTest extends TestCase
{
    use RefreshDatabase;
    use UsesProjectPostgresConnection;

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

    public function test_manage_customer_billing_shows_customer_specific_price_details(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->createCustomer();

        $product = BillingProduct::query()->create([
            'key' => 'addon_customer_price_demo',
            'name' => 'Kundeavtale',
            'description' => 'Demolinje for kundespesifikk pris.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 11,
            'metadata' => [],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_customer_price_demo_monthly',
            'name' => 'Kundeavtale — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 199000,
            'stripe_price_id' => 'price_customer_price_demo',
            'tier_key' => 'customer_price_demo',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        app(BillingService::class)->addCustomerSpecificPrice(
            $customer,
            $price,
            149000,
            1,
            null,
            'Avtalt i kontrakt',
        );

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Kundespesifikke priser');
        $response->assertSee('Standardpris');
        $response->assertSee('Avtalt pris');
        $response->assertSee('1 990 kr');
        $response->assertSee('1 490 kr');
        $response->assertSee('Avtalt i kontrakt');
        $response->assertSee('Aktiv');
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
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
}
