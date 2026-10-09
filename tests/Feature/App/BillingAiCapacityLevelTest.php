<?php

namespace Tests\Feature\App;

use App\Models\AiUsageAttempt;
use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer chooses its own AI capacity level on Abonnement. The page shows what each level
 * would include for this customer; the server validates the key, the permission and the override.
 */
class BillingAiCapacityLevelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $customersHoldTenderPackage = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        config()->set('ai_customer_capacity.nok_per_unit', 0.10);
        config()->set('ai_customer_capacity.base', ['basis' => 1000, 'per_user' => 100, 'options' => ['risk' => 200, 'supplier' => 300]]);
        config()->set('ai_customer_capacity.tiers', [
            'level_1' => ['name' => 'Level 1', 'multiplier' => 1.00, 'active' => true, 'sort_order' => 10],
            'level_2' => ['name' => 'Level 2', 'multiplier' => 1.50, 'active' => true, 'sort_order' => 20],
            'level_3' => ['name' => 'Level 3', 'multiplier' => 2.00, 'active' => true, 'sort_order' => 30],
            'retired' => ['name' => 'Retired', 'multiplier' => 5.00, 'active' => false, 'sort_order' => 40],
        ]);
        config()->set('ai_customer_capacity.default_tier', 'level_1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_page_offers_three_levels_with_this_customers_capacity_and_no_formula(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        // base = 1000 + 200 + 300 + 2 users × 100 = 1700

        $this->actingAs($owner)->get('/app/billing')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame([
                    ['key' => 'level_1', 'name' => 'Nivå 1', 'included' => 1700, 'is_current' => true],
                    ['key' => 'level_2', 'name' => 'Nivå 2', 'included' => 2550, 'is_current' => false],
                    ['key' => 'level_3', 'name' => 'Nivå 3', 'included' => 3400, 'is_current' => false],
                ], $props['ai_capacity_levels'], 'Inactive tiers are not offered.');
                $this->assertSame('Nivå 1', $props['ai_capacity']['tier_name']);
                $this->assertSame(1700, $props['ai_capacity']['included']);
                $this->assertTrue($props['ai_capacity']['level_changeable']);

                $json = json_encode([$props['ai_capacity'], $props['ai_capacity_levels']]);
                $this->assertDoesNotMatchRegularExpression('/multiplier|weight|per_user|nok|token|model|cost|price/i', $json);
            });
    }

    public function test_an_authorized_user_changes_the_level_and_it_is_audited(): void
    {
        $customer = $this->customer();
        $this->attempt($customer, 40.0);

        foreach ([User::BID_ROLE_SYSTEM_OWNER => 'level_2', User::BID_ROLE_BID_MANAGER => 'level_3'] as $role => $level) {
            $actor = $this->user($customer, $role);
            $before = $customer->fresh()->ai_capacity_tier;

            $this->actingAs($actor)->post('/app/billing/ai-capacity/level', ['level' => $level])
                ->assertRedirect('/app/billing')
                ->assertSessionHas('success');

            $this->assertSame($level, $customer->fresh()->ai_capacity_tier, $role);
            $event = BillingEvent::query()->where('customer_id', $customer->id)->where('user_id', $actor->id)->sole();
            $this->assertSame('ai_capacity_tier_changed', $event->event_type);
            $this->assertSame($before ?? 'level_1', $event->before['ai_capacity_tier']);
            $this->assertSame($level, $event->after['ai_capacity_tier']);
            $this->assertIsInt($event->before['included_units']);
            $this->assertGreaterThan($event->before['included_units'], $event->after['included_units']);
            $this->assertNotNull($event->created_at);
        }

        // Usage is not touched and the billing period does not restart.
        $this->assertSame(1, AiUsageAttempt::query()->where('customer_id', $customer->id)->count());
        $this->actingAs($this->user($customer, User::BID_ROLE_SYSTEM_OWNER))->get('/app/billing')
            ->assertInertia(fn ($page) => $page
                ->where('ai_capacity.tier_name', 'Nivå 3')
                ->where('ai_capacity.used', 400)
                ->where('ai_capacity.period_start', '2026-10-08'));
    }

    public function test_a_user_without_billing_permission_cannot_change_the_level(): void
    {
        $customer = $this->customer();
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($contributor)->post('/app/billing/ai-capacity/level', ['level' => 'level_3'])->assertForbidden();

        $this->assertNull($customer->fresh()->ai_capacity_tier);
        $this->assertSame(0, BillingEvent::query()->count());
    }

    public function test_an_unknown_inactive_or_missing_level_is_rejected(): void
    {
        $customer = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        foreach (['level_9', 'retired', ''] as $level) {
            $response = $this->actingAs($owner)->post('/app/billing/ai-capacity/level', ['level' => $level]);
            $level === '' ? $response->assertSessionHasErrors('level') : $response->assertSessionHas('error');
        }

        $this->actingAs($owner)->post('/app/billing/ai-capacity/level', ['level' => 'level_1'])
            ->assertSessionHas('error', __('procynia.billing.ai_capacity.level_refused.unchanged'));

        $this->assertNull($customer->fresh()->ai_capacity_tier);
        $this->assertSame(0, BillingEvent::query()->count());
    }

    public function test_the_level_is_changed_only_for_the_signed_in_users_own_customer(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)->post('/app/billing/ai-capacity/level', ['level' => 'level_2', 'customer_id' => $other->id])
            ->assertSessionHas('success');

        $this->assertSame('level_2', $customer->fresh()->ai_capacity_tier);
        $this->assertNull($other->fresh()->ai_capacity_tier);
    }

    public function test_under_an_override_the_level_cannot_be_changed_and_the_page_says_so(): void
    {
        $customer = $this->customer(['included_ai_units' => 9000]);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)->get('/app/billing')
            ->assertInertia(fn ($page) => $page
                ->where('ai_capacity.included', 9000)
                ->where('ai_capacity.source', 'override')
                ->where('ai_capacity.tier_name', null)
                ->where('ai_capacity.level_changeable', false));

        $this->actingAs($owner)->post('/app/billing/ai-capacity/level', ['level' => 'level_3'])
            ->assertSessionHas('error', __('procynia.billing.ai_capacity.level_refused.override'));

        $this->assertNull($customer->fresh()->ai_capacity_tier);
        $this->assertSame(9000, app(CustomerAiCapacityService::class)->forCustomer($customer->fresh())->includedUnits);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function attempt(Customer $customer, float $costNok): void
    {
        AiUsageAttempt::query()->create([
            'customer_id' => $customer->id, 'attribution' => 'customer',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => 'wiki', 'operation_key' => 'wiki.generate_page',
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-5',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110,
            'cost_nok' => $costNok, 'cost_status' => 'known',
            'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'User '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create(array_merge([
            'name' => 'Level '.Str::random(8),
            'slug' => 'level-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
        ], $attributes));

        foreach (['basis', 'risk', 'supplier'] as $package) {
            app(ModuleEntitlementService::class)->activatePackage($customer, $package);
        }

        return $customer->fresh();
    }
}
