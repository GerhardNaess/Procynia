<?php

namespace Tests\Feature\Services;

use App\Data\Ai\CustomerAiCapacity;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Commercial\AiBaseCapacityCalculator;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Base capacity = Basis + per user + each active option (fixed weights); included = base × the
 * tier multiplier. Users and options resize the one pool; the customer's tier never moves.
 */
class AiBaseCapacityTest extends TestCase
{
    use RefreshDatabase;

    // Start without Tender so each package is added deliberately.
    protected bool $customersHoldTenderPackage = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        config()->set('ai_customer_capacity.nok_per_unit', 0.10);
        config()->set('ai_customer_capacity.base', [
            'basis' => 1000,
            'per_user' => 100,
            'options' => ['risk' => 200, 'objectives' => 300, 'compliance' => 400, 'supplier' => 500, 'tender' => 600],
        ]);
        config()->set('ai_customer_capacity.tiers', [
            'level_1' => ['name' => 'Level 1', 'multiplier' => 1.00, 'active' => true, 'sort_order' => 10],
            'level_2' => ['name' => 'Level 2', 'multiplier' => 1.50, 'active' => true, 'sort_order' => 20],
            'level_3' => ['name' => 'Level 3', 'multiplier' => 2.00, 'active' => true, 'sort_order' => 30],
        ]);
        config()->set('ai_customer_capacity.default_tier', 'level_1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── base calculation ───────────────────────────────────────────────────

    public function test_basis_alone_gives_the_basis_weight(): void
    {
        $this->assertSame(1000, $this->base($this->customer(['basis'])));
        $this->assertSame(0, $this->base($this->customer([])), 'Nothing held, nobody active: no base.');
    }

    public function test_each_active_user_adds_the_user_weight_and_inactive_ones_do_not(): void
    {
        $customer = $this->customer(['basis']);
        $this->user($customer);
        $this->user($customer);
        $this->user($customer, ['is_active' => false]);

        $this->assertSame(1000 + 2 * 100, $this->base($customer));
    }

    public function test_each_option_adds_its_own_weight(): void
    {
        foreach (['risk' => 200, 'objectives' => 300, 'compliance' => 400, 'supplier' => 500, 'tender' => 600] as $option => $weight) {
            $this->assertSame(1000 + $weight, $this->base($this->customer(['basis', $option])), $option);
        }
    }

    public function test_several_options_and_users_add_up(): void
    {
        $customer = $this->customer(['basis', 'risk', 'supplier', 'tender']);
        $this->user($customer);
        $this->user($customer);
        $this->user($customer);

        $this->assertSame(1000 + 200 + 500 + 600 + 300, $this->base($customer));
    }

    public function test_one_customer_never_sizes_another(): void
    {
        $big = $this->customer(['basis', 'risk', 'objectives', 'compliance', 'supplier', 'tender']);
        foreach (range(1, 5) as $_) {
            $this->user($big);
        }
        $small = $this->customer(['basis']);
        $this->user($small);

        $this->assertSame(1000 + 2000 + 500, $this->base($big));
        $this->assertSame(1100, $this->base($small));
        $this->assertSame(1100, $this->capacity($small)->includedUnits);
    }

    public function test_the_weights_create_no_module_pool_only_one_shared_pool(): void
    {
        $customer = $this->customer(['basis', 'risk', 'tender']);
        $this->attempt($customer, 'tender', 50.0);
        $this->attempt($customer, 'risk', 30.0);
        $this->attempt($customer, 'wiki', 20.0);

        $capacity = $this->capacity($customer);
        $this->assertSame(1800, $capacity->includedUnits);
        $this->assertSame(1000, $capacity->usedUnits, 'Every module draws on the one pool, whatever weight it added.');
        $this->assertSame(800, $capacity->remainingUnits());
        $this->assertSame(['customer_id', 'is_configured', 'source', 'tier_key', 'tier_name', 'level_changeable', 'included', 'used', 'reserved', 'remaining'],
            array_slice(array_keys($capacity->toArray()), 0, 10), 'No per-module figures in the payload.');
    }

    // ── tiers ──────────────────────────────────────────────────────────────

    public function test_each_tier_multiplies_the_base_and_level_1_is_the_default(): void
    {
        $customer = $this->customer(['basis', 'risk']); // base 1200

        $this->assertSame(1200, $this->capacity($customer)->includedUnits, 'No tier chosen: Level 1.');
        $this->assertSame('level_1', $this->capacity($customer)->tierKey);

        foreach (['level_1' => 1200, 'level_2' => 1800, 'level_3' => 2400] as $tier => $units) {
            $customer->update(['ai_capacity_tier' => $tier]);
            $capacity = $this->capacity($customer);
            $this->assertSame($units, $capacity->includedUnits, $tier);
            $this->assertSame(CustomerAiCapacity::SOURCE_TIER, $capacity->includedSource);
        }
    }

    public function test_rounding_is_half_up_per_month_and_a_year_is_twelve_months(): void
    {
        config()->set('ai_customer_capacity.base', ['basis' => 333, 'per_user' => 0, 'options' => []]);
        $customer = $this->customer(['basis'], ['ai_capacity_tier' => 'level_2']);

        $this->assertSame(500, $this->capacity($customer)->includedUnits, '333 × 1.5 = 499.5 → 500');

        $customer->update(['billing_interval' => Customer::BILLING_YEARLY]);
        CustomerBillingPeriod::query()->create([
            'customer_id' => $customer->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_'.Str::random(6),
            'period_start' => '2026-03-01 00:00:00', 'period_end' => '2027-03-01 00:00:00',
            'interval' => 'year', 'subscription_status' => 'active', 'synced_at' => now(),
        ]);
        $this->assertSame(6000, $this->capacity($customer)->includedUnits);
    }

    public function test_the_level_options_show_what_each_tier_includes_for_this_customer(): void
    {
        $customer = $this->customer(['basis', 'risk', 'tender'], ['ai_capacity_tier' => 'level_2']); // base 1800
        $this->user($customer);                                                                         // 1900

        $options = app(CustomerAiCapacityService::class)->levelOptions($customer->fresh());

        $this->assertSame([
            ['key' => 'level_1', 'name' => 'Nivå 1', 'included' => 1900, 'is_current' => false],
            ['key' => 'level_2', 'name' => 'Nivå 2', 'included' => 2850, 'is_current' => true],
            ['key' => 'level_3', 'name' => 'Nivå 3', 'included' => 3800, 'is_current' => false],
        ], $options);
    }

    public function test_heavy_usage_never_changes_the_tier(): void
    {
        $customer = $this->customer(['basis'], ['ai_capacity_tier' => 'level_1']);
        $this->attempt($customer, 'wiki', 5000.0);

        $this->assertSame(CustomerAiCapacity::STATUS_EXHAUSTED, $this->capacity($customer)->status);
        $this->assertSame('level_1', $customer->fresh()->ai_capacity_tier, 'The level is the customer\'s commercial choice.');

        $customer->update(['ai_capacity_tier' => 'level_3']);
        $this->assertSame('level_3', $customer->fresh()->ai_capacity_tier);
        $this->assertSame('level_3', $this->capacity($customer)->tierKey, 'Light usage does not step it down either.');
    }

    // ── recalculation ──────────────────────────────────────────────────────

    public function test_adding_and_removing_users_recalculates_and_keeps_the_tier(): void
    {
        $customer = $this->customer(['basis'], ['ai_capacity_tier' => 'level_2']);
        foreach (range(1, 5) as $_) {
            $this->user($customer);
        }
        $this->assertSame((1000 + 500) * 1.5, (float) $this->capacity($customer)->includedUnits);

        $added = [];
        foreach (range(1, 5) as $_) {
            $added[] = $this->user($customer);
        }
        $this->assertSame(3000, $this->capacity($customer)->includedUnits, '10 users at Level 2');

        $added[0]->update(['is_active' => false]);
        $added[1]->delete();
        $this->assertSame(2700, $this->capacity($customer)->includedUnits, '8 users at Level 2');
        $this->assertSame('level_2', $customer->fresh()->ai_capacity_tier);
    }

    public function test_ordering_and_cancelling_options_recalculates_and_keeps_the_tier(): void
    {
        $customer = $this->customer(['basis', 'risk', 'supplier'], ['ai_capacity_tier' => 'level_2']);
        $modules = app(ModuleEntitlementService::class);
        $this->assertSame(2550, $this->capacity($customer)->includedUnits, '(1000 + 200 + 500) × 1.5');

        $modules->cancelOption($customer, 'supplier');
        $this->assertSame(1800, $this->capacity($customer)->includedUnits, '(1000 + 200) × 1.5');
        $this->assertSame('level_2', $customer->fresh()->ai_capacity_tier);

        $modules->activatePackage($customer, 'compliance');
        $this->assertSame(2400, $this->capacity($customer)->includedUnits, '(1000 + 200 + 400) × 1.5');
        $this->assertSame('level_2', $customer->fresh()->ai_capacity_tier);
    }

    public function test_an_override_ignores_users_options_and_tier(): void
    {
        $customer = $this->customer(['basis', 'risk'], ['ai_capacity_tier' => 'level_3', 'included_ai_units' => 777]);
        $this->user($customer);

        $capacity = $this->capacity($customer);
        $this->assertSame(777, $capacity->includedUnits);
        $this->assertSame(CustomerAiCapacity::SOURCE_OVERRIDE, $capacity->includedSource);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'tender');
        $this->assertSame(777, $this->capacity($customer)->includedUnits);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function base(Customer $customer): int
    {
        return app(AiBaseCapacityCalculator::class)->unitsPerMonth($customer->fresh());
    }

    private function capacity(Customer $customer): CustomerAiCapacity
    {
        return app(CustomerAiCapacityService::class)->forCustomer($customer->fresh());
    }

    private function attempt(Customer $customer, string $feature, float $costNok): void
    {
        AiUsageAttempt::query()->create([
            'customer_id' => $customer->id, 'attribution' => 'customer',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => $feature, 'operation_key' => 'wiki.verify_claim',
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110,
            'cost_nok' => $costNok, 'cost_status' => 'known',
            'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function user(Customer $customer, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'User '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  list<string>  $packages
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $packages, array $attributes = []): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create(array_merge([
            'name' => 'Base '.Str::random(8),
            'slug' => 'base-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
        ], $attributes));

        foreach ($packages as $package) {
            app(ModuleEntitlementService::class)->activatePackage($customer, $package);
        }

        return $customer->fresh();
    }
}
