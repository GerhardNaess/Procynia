<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerResource;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\InvoiceLog;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerBillingBasisPageTest extends TestCase
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
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_manage_customer_billing_shows_billing_basis_section_and_not_calculable_warning_without_lines(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Tom Faktura AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Faktureringsgrunnlag');
        $response->assertSee('Denne visningen viser Procynias interne operative faktureringsgrunnlag.');
        $response->assertSee('Beregnbare interne linjer');
        $response->assertSee('Sum aktive interne linjer');
        $response->assertSee('Ikke beregnbar');
        $response->assertSee('Standard planpris kan ikke brukes som faktisk kundeavtale alene.');
        $response->assertSee('Avstemmingsstatus');
        $response->assertSee('Kan ikke avstemmes');
        $response->assertSee('Ingen faktura funnet.');
    }

    public function test_manage_customer_billing_shows_customer_specific_prices_discount_and_invoice_reconciliation(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Fakturakunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 12.5);

        $product = $this->createProduct('addon_customer_basis', 'Kundeavtale', BillingProduct::CATEGORY_ADDON);
        $price = $this->createPrice($product, 'addon_customer_basis_monthly', 'Kundeavtale — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'user_id' => null,
            'description' => $price->name,
            'quantity' => 2,
            'status' => 'active',
            'starts_at' => now(),
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'stripe_invoice_id' => 'in_basis_123',
            'metadata' => [
                'pricing_mode' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
                'standard_unit_amount' => 199000,
                'custom_unit_amount' => 149000,
                'standard_currency' => 'nok',
                'custom_currency' => 'nok',
                'standard_interval' => BillingPrice::INTERVAL_MONTHLY,
                'notes' => 'Avtalt i kontrakt',
            ],
        ]);

        InvoiceLog::query()->create([
            'customer_id' => $customer->id,
            'stripe_invoice_id' => 'in_basis_123',
            'status' => 'paid',
            'amount_paid' => 260750,
            'currency' => 'nok',
            'line_items' => [],
            'invoice_date' => Carbon::parse('2026-06-14 10:00:00'),
        ]);

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Faktureringsgrunnlag');
        $response->assertSee('Beregnbar for interne linjer');
        $response->assertSee('Kundespesifikke priser');
        $response->assertSee('Standardpris');
        $response->assertSee('Avtalt pris');
        $response->assertSee('1 990 kr');
        $response->assertSee('1 490 kr');
        $response->assertSee('500 NOK');
        $response->assertSee('12,50 %');
        $response->assertSee('Faktisk fakturert og avstemming');
        $response->assertSee('Siste faktura');
        $response->assertSee('Ingen aktiv Stripe-subscription er tilgjengelig ennå.');
        $response->assertSee('Kan avstemmes');
        $response->assertSee('Direkte kobling');
        $response->assertSee('2 608 NOK');
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

    private function createCustomer(
        string $name,
        string $plan,
        string $billingInterval,
        int $includedAiCredits,
        float $discountPercent,
    ): Customer {
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
            'billing_interval' => $billingInterval,
            'included_users' => 0,
            'included_ai_credits' => $includedAiCredits,
            'billing_discount_percent' => $discountPercent,
        ]);
    }

    private function createProduct(string $key, string $name, string $category): BillingProduct
    {
        return BillingProduct::query()->create([
            'key' => $key,
            'name' => $name,
            'description' => $name.' beskrivelse',
            'category' => $category,
            'billing_scope' => match ($category) {
                BillingProduct::CATEGORY_USER_SEAT => BillingProduct::BILLING_SCOPE_QUANTITY,
                BillingProduct::CATEGORY_USER_SERVICE => BillingProduct::BILLING_SCOPE_USER,
                default => BillingProduct::BILLING_SCOPE_CUSTOMER,
            },
            'is_active' => true,
            'sort_order' => 1,
            'metadata' => [],
        ]);
    }

    private function createPrice(BillingProduct $product, string $key, string $name, string $interval, int $unitAmount): BillingPrice
    {
        return BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => $key,
            'name' => $name,
            'interval' => $interval,
            'currency' => 'nok',
            'unit_amount' => $unitAmount,
            'stripe_price_id' => 'price_'.Str::lower(Str::random(12)),
            'tier_key' => Str::slug($name),
            'is_recurring' => $interval !== BillingPrice::INTERVAL_ONE_TIME,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
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
