<?php

namespace Tests\Feature\App;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
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

    public function test_system_owner_can_view_billing_page_with_lines_and_subscription(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];
        $customer->update([
            'included_users' => 15,
            'included_ai_credits' => 60,
        ]);

        $baseProduct = BillingProduct::query()->create([
            'key' => 'base_plan_ultra',
            'name' => 'Ultra',
            'description' => 'Hovedabonnementet til kunden.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
            'metadata' => ['plan_key' => 'ultra'],
        ]);

        $basePrice = BillingPrice::query()->create([
            'billing_product_id' => $baseProduct->id,
            'key' => 'base_plan_ultra_yearly',
            'name' => 'Ultra årlig',
            'interval' => BillingPrice::INTERVAL_YEARLY,
            'currency' => 'nok',
            'unit_amount' => 5192100,
            'stripe_price_id' => $this->uniqueStripePriceId('price_test_ultra_yearly'),
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => ['plan_key' => 'ultra'],
        ]);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $baseProduct->id,
            'billing_price_id' => $basePrice->id,
            'description' => $basePrice->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'system',
            'metadata' => [
                'billing_price_key' => $basePrice->key,
                'billing_product_key' => $baseProduct->key,
                'plan_key' => 'ultra',
                'billing_interval' => BillingPrice::INTERVAL_YEARLY,
            ],
        ]);

        $product = BillingProduct::query()->create([
            'key' => 'addon_ai_offer',
            'name' => 'AI-tilbud',
            'description' => 'Tillegg for AI-tilbud.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 10,
            'metadata' => ['features' => ['ai_offer']],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_ai_offer_monthly',
            'name' => 'AI-tilbud — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 99000,
            'stripe_price_id' => $this->uniqueStripePriceId('price_test_ai_offer'),
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
            'quantity' => 2,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'admin',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        $response = $this->actingAs($context['owner'])->get('/app/billing');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($price): bool {
            $billingLines = collect(data_get($page, 'props.billing_lines', []));

            return data_get($page, 'component') === 'App/Billing/Index'
                && data_get($page, 'props.subscription.plan_label') === 'Ultra'
                && data_get($page, 'props.subscription.plan') === 'ultra'
                && data_get($page, 'props.subscription.billing_interval') === BillingPrice::INTERVAL_YEARLY
                && data_get($page, 'props.subscription.included_users') === 15
                && data_get($page, 'props.subscription.included_ai_credits') === 60
                && data_get($page, 'props.subscription.cancel_at_period_end') === false
                && $billingLines->contains(fn (array $line): bool => $line['billing_price_key'] === $price->key && $line['quantity'] === 2)
                && $billingLines->doesntContain(fn (array $line): bool => $line['billing_price_key'] === 'base_plan_ultra_yearly');
        });
    }

    public function test_system_owner_sees_registered_subscription_from_local_plan_with_commercial_details(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];

        $customer->update([
            'subscription_plan' => Customer::PLAN_ULTRA,
            'billing_interval' => Customer::BILLING_YEARLY,
            'included_users' => 15,
            'included_ai_credits' => 60,
        ]);

        $response = $this->actingAs($context['owner'])->get('/app/billing');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/Billing/Index'
                && data_get($page, 'props.subscription.plan') === 'ultra'
                && data_get($page, 'props.subscription.plan_label') === 'Ultra'
                && data_get($page, 'props.subscription.billing_interval') === BillingPrice::INTERVAL_YEARLY
                && data_get($page, 'props.subscription.included_users') === 15
                && data_get($page, 'props.subscription.included_ai_credits') === 60
                && data_get($page, 'props.subscription.cancel_at_period_end') === false;
        });
    }

    public function test_non_system_owner_is_forbidden_from_billing_page(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['user'])->get('/app/billing');

        $response->assertForbidden();

        $response = $this->actingAs($context['user'])->post('/app/billing/cancel');
        $response->assertForbidden();

        $response = $this->actingAs($context['user'])->post('/app/billing/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);
        $response->assertForbidden();
    }

    public function test_viewer_is_forbidden_from_billing_page(): void
    {
        $context = $this->viewerContext();

        $response = $this->actingAs($context['user'])->get('/app/billing');

        $response->assertForbidden();
    }

    public function test_bid_manager_can_view_billing_page_and_cancel_billing(): void
    {
        $context = $this->bidManagerContext();
        $customer = $context['customer'];

        $product = BillingProduct::query()->create([
            'key' => 'addon_priority_support',
            'name' => 'Prioritert support',
            'description' => 'Tillegg for prioritert support.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 20,
            'metadata' => [],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_priority_support_monthly',
            'name' => 'Prioritert support — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 149000,
            'stripe_price_id' => $this->uniqueStripePriceId('price_test_priority_support'),
            'tier_key' => 'priority_support',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'description' => $price->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'admin',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        $this->partialMock(SubscriptionService::class, function ($mock) use ($customer): void {
            $mock->shouldReceive('cancel')
                ->once()
                ->withArgs(function (Customer $passedCustomer) use ($customer): bool {
                    return $passedCustomer->is($customer);
                })
                ->andReturnNull();
        });

        $response = $this->actingAs($context['user'])->get('/app/billing');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($price): bool {
            $billingLines = collect(data_get($page, 'props.billing_lines', []));

            return data_get($page, 'component') === 'App/Billing/Index'
                && $billingLines->contains(fn (array $line): bool => $line['billing_price_key'] === $price->key);
        });

        $response = $this->actingAs($context['user'])->post('/app/billing/cancel');

        $response->assertRedirect('/app/billing');
        $response->assertSessionHas('success', 'Abonnementet er satt til å avsluttes ved periodeslutt.');
    }

    public function test_billing_page_only_shows_logged_in_customers_data(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];
        $otherCustomer = $this->createCustomer('Annen Kunde AS');

        $product = BillingProduct::query()->create([
            'key' => 'addon_customer_scope',
            'name' => 'Kundespesifikk støtte',
            'description' => 'Tillegg for å teste kundescope.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 30,
            'metadata' => [],
        ]);

        $currentPrice = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_customer_scope_current_monthly',
            'name' => 'Kundespesifikk støtte — Gjeldende',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 99000,
            'stripe_price_id' => $this->uniqueStripePriceId('price_test_customer_scope_current'),
            'tier_key' => 'customer_scope_current',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        $otherPrice = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_customer_scope_other_monthly',
            'name' => 'Kundespesifikk støtte — Annen kunde',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 199000,
            'stripe_price_id' => $this->uniqueStripePriceId('price_test_customer_scope_other'),
            'tier_key' => 'customer_scope_other',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $currentPrice->id,
            'description' => $currentPrice->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'admin',
            'metadata' => [
                'billing_price_key' => $currentPrice->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        CustomerBillingLine::query()->create([
            'customer_id' => $otherCustomer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $otherPrice->id,
            'description' => $otherPrice->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'admin',
            'metadata' => [
                'billing_price_key' => $otherPrice->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        $response = $this->actingAs($context['owner'])->get('/app/billing');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($currentPrice, $otherPrice): bool {
            $billingLines = collect(data_get($page, 'props.billing_lines', []));

            return data_get($page, 'component') === 'App/Billing/Index'
                && $billingLines->contains(fn (array $line): bool => $line['billing_price_key'] === $currentPrice->key)
                && $billingLines->doesntContain(fn (array $line): bool => $line['billing_price_key'] === $otherPrice->key);
        });
    }

    public function test_system_owner_can_cancel_billing_and_period_end_lines_become_pending_cancel(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];

        $product = BillingProduct::query()->create([
            'key' => 'addon_support',
            'name' => 'Prioritert support',
            'description' => 'Tillegg for prioritert support.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 20,
            'metadata' => [],
        ]);

        $price = BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'key' => 'addon_support_monthly',
            'name' => 'Prioritert support — Månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 149000,
            'stripe_price_id' => $this->uniqueStripePriceId('price_test_support'),
            'tier_key' => 'support',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 1,
            'metadata' => [],
        ]);

        CustomerBillingLine::query()->create([
            'customer_id' => $customer->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'description' => $price->name,
            'quantity' => 1,
            'status' => 'active',
            'starts_at' => now(),
            'source' => 'admin',
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        $response = $this->actingAs($context['owner'])->post('/app/billing/cancel');

        $response->assertRedirect('/app/billing');
        $response->assertSessionHas('success', 'Abonnementet er satt til å avsluttes ved periodeslutt.');

        $this->assertDatabaseHas('customer_billing_lines', [
            'customer_id' => $customer->id,
            'billing_price_id' => $price->id,
            'status' => 'pending_cancel',
        ]);
    }

    public function test_system_owner_receives_plan_change_data_on_billing_page(): void
    {
        $context = $this->systemOwnerContext();

        $response = $this->actingAs($context['owner'])->get('/app/billing');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $availablePlans = collect(data_get($page, 'props.available_plans', []));

            return data_get($page, 'component') === 'App/Billing/Index'
                && data_get($page, 'props.customer_plan.plan') === 'free'
                && $availablePlans->contains(fn (array $plan): bool => $plan['key'] === 'pro')
                && $availablePlans->contains(fn (array $plan): bool => $plan['key'] === 'max')
                && $availablePlans->contains(fn (array $plan): bool => $plan['key'] === 'ultra');
        });
    }

    public function test_non_system_owner_cannot_change_plan(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['user'])->post('/app/billing/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertForbidden();
    }

    public function test_plan_change_validates_selected_plan_and_interval(): void
    {
        $context = $this->systemOwnerContext();

        $response = $this->actingAs($context['owner'])->post('/app/billing/change-plan', [
            'plan' => 'invalid_plan',
            'interval' => 'monthly',
        ]);

        $response->assertSessionHasErrors(['plan']);

        $response = $this->actingAs($context['owner'])->post('/app/billing/change-plan', [
            'plan' => 'pro',
            'interval' => 'invalid_interval',
        ]);

        $response->assertSessionHasErrors(['interval']);
    }

    public function test_plan_change_uses_existing_subscription_service_logic(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];

        $this->partialMock(SubscriptionService::class, function ($mock) use ($customer): void {
            $mock->shouldReceive('changePlan')
                ->once()
                ->withArgs(function (Customer $passedCustomer, string $plan, string $interval) use ($customer): bool {
                    return $passedCustomer->is($customer)
                        && $plan === 'pro'
                        && $interval === 'monthly';
                })
                ->andReturnNull();
        });

        $response = $this->actingAs($context['owner'])->post('/app/billing/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertRedirect('/app/billing');
        $response->assertSessionHas('success', __('procynia.billing.plan_change.success'));
    }

    public function test_bid_manager_can_change_plan_locally_without_stripe_connection(): void
    {
        $context = $this->bidManagerContext();
        $customer = $context['customer'];

        $product = BillingProduct::query()->updateOrCreate(
            ['key' => 'plan_pro'],
            [
                'name' => 'Pro',
                'description' => 'Pro base plan used for local plan changes.',
                'category' => BillingProduct::CATEGORY_BASE_PLAN,
                'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
                'is_active' => true,
                'sort_order' => 3,
                'metadata' => ['plan_key' => 'pro'],
            ]
        );

        $price = BillingPrice::query()->updateOrCreate(
            ['key' => 'pro_monthly'],
            [
                'billing_product_id' => $product->id,
                'name' => 'Pro — Månedlig',
                'interval' => BillingPrice::INTERVAL_MONTHLY,
                'currency' => 'nok',
                'unit_amount' => 199000,
                'stripe_price_id' => null,
                'tier_key' => 'pro',
                'is_recurring' => true,
                'is_active' => true,
                'included_quantity' => 1,
                'metadata' => ['plan_key' => 'pro'],
            ]
        );

        $response = $this->actingAs($context['user'])->post('/app/billing/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertRedirect('/app/billing');
        $response->assertSessionHas('success', __('procynia.billing.plan_change.success'));

        $customer->refresh();

        $this->assertSame(Customer::PLAN_PRO, $customer->subscription_plan);
        $this->assertSame(Customer::BILLING_MONTHLY, $customer->billing_interval);
        $this->assertNull($customer->stripe_id);
        $this->assertDatabaseHas('customer_billing_lines', [
            'customer_id' => $customer->id,
            'billing_price_id' => $price->id,
            'status' => 'active',
            'source' => 'system',
            'stripe_subscription_item_id' => null,
        ]);
    }

    public function test_plan_change_service_errors_are_handled_controlled(): void
    {
        $context = $this->systemOwnerContext();

        $this->partialMock(SubscriptionService::class, function ($mock): void {
            $mock->shouldReceive('changePlan')
                ->once()
                ->andThrow(new \RuntimeException('Boom'));
        });

        $response = $this->actingAs($context['owner'])->post('/app/billing/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertSessionHasErrors(['plan']);
        $this->assertSame(
            __('procynia.billing.plan_change.error'),
            session('errors')->getBag('default')->first('plan')
        );
    }

    public function test_plan_change_shows_clear_message_when_payment_setup_is_missing(): void
    {
        $context = $this->systemOwnerContext();

        $this->partialMock(SubscriptionService::class, function ($mock): void {
            $mock->shouldReceive('changePlan')
                ->once()
                ->andThrow(new \RuntimeException(__('procynia.billing.plan_change.payment_setup_missing')));
        });

        $response = $this->actingAs($context['owner'])->post('/app/billing/change-plan', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertSessionHasErrors(['plan']);
        $this->assertSame(
            __('procynia.billing.plan_change.payment_setup_missing'),
            session('errors')->getBag('default')->first('plan')
        );
    }

    private function systemOwnerContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => Str::slug($customerName).'.system.owner@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'owner' => $owner,
        ];
    }

    private function customerAdminContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $user = User::query()->create([
            'name' => 'Admin Bruker',
            'email' => Str::slug($customerName).'.admin@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    private function bidManagerContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $user = User::query()->create([
            'name' => 'Bid Manager',
            'email' => Str::slug($customerName).'.bid.manager@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    private function viewerContext(string $customerName = 'Procynia AS'): array
    {
        $customer = $this->createCustomer($customerName);

        $user = User::query()->create([
            'name' => 'Viewer',
            'email' => Str::slug($customerName).'.viewer@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
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

    private function uniqueStripePriceId(string $prefix): string
    {
        return $prefix.'_'.Str::lower(Str::random(12));
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
