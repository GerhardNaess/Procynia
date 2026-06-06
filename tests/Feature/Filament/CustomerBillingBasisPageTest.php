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

    public function test_manage_customer_billing_shows_business_overview_without_lines(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Tom Faktura AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0);

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Kundens avtale og fakturering');
        $response->assertSee('Kundens avtale');
        $response->assertSee('Hva skal faktureres');
        $response->assertSee('Faktura og betaling');
        $response->assertSee('Faktura mangler');
        $response->assertSee('Mangler fakturaoppsett');
        $response->assertSee('Kunden er ikke koblet til fakturasystemet.');
        $response->assertSee('Ingen faktura funnet');
        $response->assertSee('Plan');
        $response->assertSee('Faktureres');
        $response->assertSee('Rabatt');
        $response->assertSee('Inkludert');
        $response->assertSee('Ingen kundespesifikke priser registrert.');
        $response->assertSee('Tilleggstjenester');
        $response->assertDontSee('Kunde:');
        $this->assertSame(1, substr_count($response->getContent(), 'Ingen faktura funnet'));
        $response->assertDontSee('Kundespesifikk pris');
        $response->assertDontSee('Faktureringsgrunnlag');
        $response->assertDontSee('Faktureringsklarhet');
        $response->assertDontSee('Faktureringspreview');
        $response->assertDontSee('Avstemming');
        $response->assertDontSee('Stripe-subscription');
        $response->assertDontSee('InvoiceLog');
    }

    public function test_manage_customer_billing_shows_customer_specific_prices_discount_and_invoice_reconciliation(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Fakturakunde AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 12.5, 'cus_basis_ready');
        $this->createStripeSubscription($customer);

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
        $response->assertSee('Kundens avtale og fakturering');
        $response->assertSee('Faktura funnet');
        $response->assertSee('Betalt');
        $response->assertSee('Procynia har registrert faktura fra Stripe.');
        $response->assertSee('Kundespesifikk pris');
        $response->assertSee('1 990 NOK');
        $response->assertSee('1 490 NOK');
        $response->assertSee('500 NOK');
        $response->assertSee('12,50 %');
        $response->assertSee('Faktura og betaling');
        $response->assertSee('Siste faktura');
        $response->assertSee('Fakturastatus');
        $response->assertSee('Betalt beløp');
        $response->assertSee('Fakturadato');
        $response->assertSee('2 608 NOK');
        $response->assertDontSee('Faktureringspreview');
        $response->assertDontSee('Faktureringsklarhet');
        $response->assertDontSee('Avstemming');
    }

    public function test_manage_customer_billing_shows_not_beregnbar_when_a_line_is_excluded(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Delvis Faktura AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 5, 'cus_partial_basis');
        $this->createStripeSubscription($customer);

        $baseProduct = $this->createProduct('base_plan_preview_partial', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $basePrice = $this->createPrice($baseProduct, 'base_plan_preview_partial_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 200000);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $baseProduct->id,
            'billing_price_id' => $basePrice->id,
            'user_id' => null,
            'description' => 'Beregnbar preview-linje',
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'system',
            'metadata' => [
                'billing_price_key' => $basePrice->key,
                'billing_product_key' => $baseProduct->key,
            ],
        ]);

        $addonProduct = $this->createProduct('addon_preview_excluded', 'Kundeavtale', BillingProduct::CATEGORY_ADDON);
        $addonPrice = $this->createPrice($addonProduct, 'addon_preview_excluded_monthly', 'Kundeavtale — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $addonProduct->id,
            'billing_price_id' => $addonPrice->id,
            'user_id' => null,
            'description' => 'Kundespesifikk preview-linje',
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
            'metadata' => [
                'pricing_mode' => CustomerBillingLine::SOURCE_CUSTOMER_PRICE,
                'billing_price_key' => $addonPrice->key,
                'billing_product_key' => $addonProduct->key,
                'standard_unit_amount' => 199000,
                'standard_currency' => 'nok',
                'standard_interval' => BillingPrice::INTERVAL_MONTHLY,
            ],
        ]);

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Kundens avtale og fakturering');
        $response->assertSee('Ikke beregnbar');
        $response->assertSee('En eller flere linjer mangler pris, valuta eller antall.');
        $response->assertSee('Kundespesifikk pris mangler');
        $response->assertSee('Hva skal faktureres');
        $response->assertSee('Ingen faktura funnet');
        $response->assertDontSee('Faktureringspreview');
    }

    public function test_manage_customer_billing_shows_attention_when_stripe_customer_exists_without_subscription_or_invoice_logs(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();
        $customer = $this->createCustomer('Følg Opp AS', Customer::PLAN_PRO, Customer::BILLING_MONTHLY, 3, 0, 'cus_follow_up');

        $product = $this->createProduct('base_plan_follow_up', 'Pro', BillingProduct::CATEGORY_BASE_PLAN);
        $price = $this->createPrice($product, 'base_plan_follow_up_monthly', 'Pro — Månedlig', BillingPrice::INTERVAL_MONTHLY, 199000);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'user_id' => null,
            'description' => 'Beregnbar linje',
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'system',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        $response = $this->actingAs($admin)->get(CustomerResource::getUrl('billing', ['record' => $customer]));

        $response->assertOk();
        $response->assertSee('Må kontrolleres');
        $response->assertSee('Kunden har fakturakunde, men ingen aktiv avtale.');
        $response->assertSee('Faktura mangler');
        $response->assertSee('Ingen faktura funnet');
        $this->assertSame(1, substr_count($response->getContent(), 'Ingen faktura funnet'));
        $response->assertDontSee('Faktureringsklarhet');
        $response->assertDontSee('Faktureringspreview');
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
        ?string $stripeId = null,
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
            'stripe_id' => $stripeId,
            'subscription_plan' => $plan,
            'billing_interval' => $billingInterval,
            'included_users' => 0,
            'included_ai_credits' => $includedAiCredits,
            'billing_discount_percent' => $discountPercent,
        ]);
    }

    private function createStripeSubscription(Customer $customer, string $stripeStatus = 'active'): void
    {
        DB::table('subscriptions')->insert([
            'customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::lower(Str::random(12)),
            'stripe_status' => $stripeStatus,
            'stripe_price' => 'price_'.Str::lower(Str::random(12)),
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
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

    private function createPrice(
        BillingProduct $product,
        string $key,
        string $name,
        string $interval,
        int $unitAmount,
        string $currency = 'nok',
    ): BillingPrice
    {
        return BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => $key,
            'name' => $name,
            'interval' => $interval,
            'currency' => $currency,
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
