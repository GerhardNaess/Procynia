<?php

namespace Tests\Feature\App;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Models\CustomerUserServiceLevel;
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

    public function test_system_owner_can_view_billing_page_with_lines_and_service_levels(): void
    {
        $context = $this->systemOwnerContext();
        $customer = $context['customer'];

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
            'stripe_price_id' => 'price_test_ai_offer',
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

        $user = User::query()->create([
            'name' => 'Billing Bruker',
            'email' => 'billing.bruker@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        CustomerUserServiceLevel::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'billing_product_id' => $product->id,
            'billing_price_id' => $price->id,
            'level_key' => 'ai_offer',
            'status' => 'active',
            'assigned_by' => $context['owner']->id,
            'starts_at' => now(),
            'metadata' => [
                'billing_price_key' => $price->key,
                'billing_product_key' => $product->key,
            ],
        ]);

        $response = $this->actingAs($context['owner'])->get('/app/billing');

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($price, $user): bool {
            return data_get($page, 'component') === 'App/Billing/Index'
                && collect(data_get($page, 'props.billing_lines', []))->contains(fn (array $line): bool => $line['billing_price_key'] === $price->key && $line['quantity'] === 2)
                && collect(data_get($page, 'props.service_levels', []))->contains(fn (array $level): bool => $level['billing_price_key'] === $price->key && $level['user_name'] === $user->name);
        });
    }

    public function test_non_system_owner_is_forbidden_from_billing_page(): void
    {
        $context = $this->customerAdminContext();

        $response = $this->actingAs($context['user'])->get('/app/billing');

        $response->assertForbidden();
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
            'stripe_price_id' => 'price_test_support',
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
        static $values = null;

        if (! is_array($values)) {
            $values = [];

            foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                    continue;
                }

                [$envKey, $envValue] = explode('=', $trimmed, 2);
                $values[trim($envKey)] = trim($envValue, " \t\n\r\0\x0B\"'");
            }
        }

        return (string) ($values[$key] ?? $default);
    }
}
