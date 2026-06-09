<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\BillingOverview;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_billing_overview_exposes_expected_navigation_metadata(): void
    {
        $this->assertSame('Fakturering', BillingOverview::getNavigationGroup());
        $this->assertSame('Oversikt', BillingOverview::getNavigationLabel());
        $this->assertSame('Fakturering', (new BillingOverview())->getTitle());
        $this->assertTrue(BillingOverview::shouldRegisterNavigation());
    }

    public function test_internal_admin_can_open_billing_overview_and_see_customers(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->internalAdmin();
        $alpha = $this->createCustomer('Alpha Faktura AS', Customer::PLAN_ULTRA, 'cus_alpha_billing_overview', '2026-07-01 00:00:00');
        $beta = $this->createCustomer('Beta Faktura AS', Customer::PLAN_ENTERPRISE);
        $gamma = $this->createCustomer('Gamma Faktura AS', Customer::PLAN_FREE, 'cus_gamma_billing_overview');

        $this->createStripeSubscription($alpha, 'trialing');

        $this->actingAs($admin);

        $this->assertTrue(BillingOverview::canAccess());

        $response = $this->get(BillingOverview::getUrl());

        $response->assertOk();
        $response->assertSee('Fakturering');
        $response->assertSee('Kunde');
        $response->assertSee('Plan');
        $response->assertSee('Status');
        $response->assertSee('Prøveperiode slutter');
        $response->assertSee('Alpha Faktura AS');
        $response->assertSee('Beta Faktura AS');
        $response->assertSee('Gamma Faktura AS');
        $response->assertSeeInOrder([
            'Alpha Faktura AS',
            'ultra',
            'trialing',
            '01.07.2026',
            'Administrer',
        ]);
        $response->assertSeeInOrder([
            'Beta Faktura AS',
            'enterprise',
            'Ikke koblet til Stripe',
        ]);
        $response->assertSeeInOrder([
            'Gamma Faktura AS',
            'Ingen abonnement',
        ]);
        $response->assertSee('Administrer');
        $response->assertSee(CustomerResource::getUrl('billing', ['record' => $alpha]), false);
    }

    public function test_customer_admin_cannot_access_billing_overview(): void
    {
        $customer = $this->createCustomer('Kunde Admin AS', Customer::PLAN_PRO);
        $user = User::query()->create([
            'name' => 'Customer Admin',
            'email' => 'customer.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(BillingOverview::canAccess());
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function createCustomer(
        string $name,
        string $plan,
        ?string $stripeId = null,
        ?string $trialEndsAt = null,
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'stripe_id' => $stripeId,
            'subscription_plan' => $plan,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'trial_ends_at' => $trialEndsAt ? Carbon::parse($trialEndsAt) : null,
            'included_users' => 0,
            'included_ai_credits' => 0,
            'billing_discount_percent' => 0,
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
}
