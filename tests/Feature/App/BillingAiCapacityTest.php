<?php

namespace Tests\Feature\App;

use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Abonnement shows one shared AI capacity in AI units — never tokens, models or money, and no
 * longer the Anbud AI-case quota as the customer's AI picture.
 */
class BillingAiCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        config()->set('ai_customer_capacity.nok_per_unit', 0.10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_page_shows_used_included_remaining_and_the_billing_period(): void
    {
        $customer = $this->customer(['included_ai_units' => 5000]);
        $this->period($customer, '2026-10-01 00:00:00', '2026-11-01 00:00:00');
        $this->attempt($customer, 320.0);

        $this->actingAs($this->owner($customer))->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_capacity.is_configured', true)
                ->where('ai_capacity.used', 3200)
                ->where('ai_capacity.included', 5000)
                ->where('ai_capacity.remaining', 1800)
                ->where('ai_capacity.percentage_used', 64)
                ->where('ai_capacity.status', 'normal')
                ->where('ai_capacity.period_start', '2026-10-01')
                ->where('ai_capacity.period_end', '2026-10-31')
                ->where('ai_capacity.next_period_start', '2026-11-01')
                ->missing('ai_quota'));
    }

    public function test_warning_and_exhausted_reach_the_page(): void
    {
        foreach ([[400.0, 'warning', false], [500.0, 'exhausted', true]] as [$costNok, $status, $exhausted]) {
            $customer = $this->customer(['included_ai_units' => 5000]);
            $this->attempt($customer, $costNok);

            $this->actingAs($this->owner($customer))->get('/app/billing')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('ai_capacity.status', $status)
                    ->where('ai_capacity.is_exhausted', $exhausted));
        }
    }

    public function test_a_customer_without_a_defined_capacity_is_told_so_rather_than_shown_zero(): void
    {
        $customer = $this->customer(['subscription_plan' => Customer::PLAN_ENTERPRISE]);

        $this->actingAs($this->owner($customer))->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_capacity.is_configured', false)
                ->where('ai_capacity.status', 'not_configured')
                ->where('ai_capacity.included', null));
    }

    public function test_the_page_carries_no_internal_cost_or_token_figures(): void
    {
        $customer = $this->customer(['included_ai_units' => 5000]);
        $this->attempt($customer, 12.34);

        $this->actingAs($this->owner($customer))->get('/app/billing')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                foreach (array_keys($props['ai_capacity']) as $key) {
                    $this->assertDoesNotMatchRegularExpression('/nok|cost|token|model|price|usd/i', $key);
                }

                $this->assertStringNotContainsString('12.34', json_encode($props['ai_capacity']));
                $this->assertArrayNotHasKey('included_ai_credits', (array) ($props['subscription'] ?? []));

                foreach ($props['available_plans'] as $plan) {
                    $this->assertArrayHasKey('included_ai_units', $plan);
                    $this->assertArrayNotHasKey('included_ai_credits', $plan);
                }
            });
    }

    private function owner(Customer $customer): User
    {
        return User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Owner '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);
    }

    private function period(Customer $customer, string $start, string $end): void
    {
        CustomerBillingPeriod::query()->create([
            'customer_id' => $customer->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_'.Str::random(6),
            'period_start' => $start, 'period_end' => $end,
            'interval' => 'month', 'subscription_status' => 'active', 'synced_at' => now(),
        ]);
    }

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

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create(array_merge([
            'name' => 'Billing capacity '.Str::random(8),
            'slug' => 'billing-capacity-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
        ], $attributes));
    }
}
