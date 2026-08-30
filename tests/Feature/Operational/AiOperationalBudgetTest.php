<?php

namespace Tests\Feature\Operational;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\AiModelPrice;
use App\Models\AiOperationalBudgetPeriod;
use App\Models\AiRuntimeControl;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiOperationalLimit;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Services\Ai\Operational\AiOperationalBudgetService;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiCallContextScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The NOK safety budgets: Procynia's own exposure limit, which is not the customer's quota and can
 * stop work the customer's plan would otherwise permit.
 */
class AiOperationalBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        $this->seedPricing();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // Customer budgets
    // =========================================================================

    public function test_a_call_under_the_daily_limit_is_allowed_and_holds_a_reservation(): void
    {
        $customer = $this->customer();
        $this->customerLimit($customer, daily: 1000.0);

        $decision = app(AiCostControlService::class)->authorize($this->context($customer));

        $this->assertNotNull($decision->budgetReservation);
        $this->assertGreaterThan(0.0, $decision->budgetReservation->reservedNok);
        $this->assertGreaterThan(
            0.0,
            (float) $this->period(AiOperationalBudgetPeriod::SCOPE_CUSTOMER, $customer->id, 'daily')->reserved_nok,
        );
    }

    public function test_reaching_the_customer_daily_limit_blocks_with_its_own_reason(): void
    {
        $customer = $this->customer();
        $this->customerLimit($customer, daily: 1.0);

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::CUSTOMER_DAILY_BUDGET_EXHAUSTED));
        app(AiCostControlService::class)->authorize($this->context($customer));
    }

    public function test_reaching_the_customer_monthly_limit_blocks_with_its_own_reason(): void
    {
        $customer = $this->customer();
        $this->customerLimit($customer, daily: 100000.0, monthly: 1.0);

        try {
            app(AiCostControlService::class)->authorize($this->context($customer));
            $this->fail('The monthly ceiling must refuse the call.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::CUSTOMER_MONTHLY_BUDGET_EXHAUSTED, $exception->reason);
        }

        // A refusal must not leave money held against the daily window it had already passed. The
        // whole reservation is one transaction, so the earlier hold is rolled back with it.
        $this->assertSame(
            0.0,
            (float) (AiOperationalBudgetPeriod::query()
                ->where(['scope' => AiOperationalBudgetPeriod::SCOPE_CUSTOMER, 'customer_id' => $customer->id, 'window' => 'daily'])
                ->value('reserved_nok') ?? 0.0),
        );
    }

    public function test_an_operational_budget_stops_an_unlimited_commercial_plan(): void
    {
        // The whole point of the operational layer: the plan says unlimited AI cases, Procynia's
        // own spending ceiling still applies.
        $customer = $this->customer(Customer::PLAN_ENTERPRISE);
        $this->customerLimit($customer, daily: 1.0);

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::CUSTOMER_DAILY_BUDGET_EXHAUSTED));
        app(AiCostControlService::class)->authorize($this->context($customer));
    }

    public function test_an_operational_budget_stops_further_work_on_an_already_activated_case(): void
    {
        $customer = $this->customer();
        $notice = $this->notice($customer);
        CustomerAiCaseUsage::query()->create([
            'customer_id' => $customer->id, 'saved_notice_id' => $notice->id, 'activated_at' => now(),
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'source_operation_key' => 'test',
        ]);
        $this->customerLimit($customer, daily: 1.0);

        // Commercial policy would allow this — the case is already activated. Operational does not.
        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::CUSTOMER_DAILY_BUDGET_EXHAUSTED));
        app(AiCostControlService::class)->authorize(
            $this->context($customer, savedNoticeId: $notice->id, commercialCredit: true),
        );
    }

    public function test_a_customer_without_a_limit_is_not_constrained_by_one(): void
    {
        $customer = $this->customer();

        $decision = app(AiCostControlService::class)->authorize($this->context($customer));

        $this->assertTrue($decision->budgetReservation->isEmpty());
    }

    // =========================================================================
    // Global budgets
    // =========================================================================

    public function test_the_global_daily_budget_blocks_every_customer(): void
    {
        $this->globalLimit(daily: 1.0);
        $first = $this->customer();
        $second = $this->customer();

        foreach ([$first, $second] as $customer) {
            try {
                app(AiCostControlService::class)->authorize($this->context($customer));
                $this->fail('The global ceiling must refuse every customer.');
            } catch (AiCostControlException $exception) {
                $this->assertSame(AiCostControlException::GLOBAL_DAILY_BUDGET_EXHAUSTED, $exception->reason);
            }
        }
    }

    public function test_the_global_monthly_budget_blocks_with_its_own_reason(): void
    {
        $this->globalLimit(daily: 100000.0, monthly: 1.0);

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::GLOBAL_MONTHLY_BUDGET_EXHAUSTED));
        app(AiCostControlService::class)->authorize($this->context($this->customer()));
    }

    public function test_the_global_budget_blocks_system_work_that_has_no_customer(): void
    {
        $this->globalLimit(daily: 1.0);

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::GLOBAL_DAILY_BUDGET_EXHAUSTED));
        app(AiCostControlService::class)->authorize(new AiCallContext(
            feature: 'enterprise_wiki', operation: 'enterprise_wiki.ingest', model: 'gpt-5',
        ));
    }

    public function test_a_budget_block_stops_the_call_before_any_http_request(): void
    {
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        $customer = $this->customer();
        $this->globalLimit(daily: 1.0);

        try {
            app(AiCallContextScope::class)->within(
                new AiCallContext(customerId: $customer->id, feature: 'wiki', operation: 'wiki.ask.answer'),
                fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]),
            );
            $this->fail('The provider boundary must refuse this call.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::GLOBAL_DAILY_BUDGET_EXHAUSTED, $exception->reason);
        }

        Http::assertNothingSent();
    }

    // =========================================================================
    // Manual stop vs budget stop
    // =========================================================================

    public function test_the_manual_emergency_stop_and_the_budget_stop_are_distinct_reasons(): void
    {
        $customer = $this->customer();
        $control = AiRuntimeControl::query()->orderBy('id')->firstOrFail();
        $control->forceFill(['global_ai_stop' => true])->save();

        try {
            app(AiCostControlService::class)->authorize($this->context($customer));
            $this->fail('The manual stop must refuse the call.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::GLOBAL_STOP, $exception->reason);
        }
    }

    public function test_no_operator_override_can_lift_a_global_budget_stop(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $this->globalLimit(daily: 1.0);

        try {
            app(AiCostControlService::class)->authorize(new AiCallContext(
                customerId: $customer->id,
                feature: 'enterprise_wiki',
                operation: 'operator.wiki.recover_document_flow',
                model: 'gpt-5',
                operatorOverride: true,
                operatorActorUserId: $admin->id,
                operatorOverrideReason: 'Gjenoppretting',
            ));
            $this->fail('A platform safety ceiling must not be overridable.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::GLOBAL_DAILY_BUDGET_EXHAUSTED, $exception->reason);
        }

        $this->assertDatabaseMissing('billing_events', ['event_type' => 'ai_operator_override_used']);
    }

    // =========================================================================
    // Settlement
    // =========================================================================

    public function test_a_certain_failure_gives_the_reservation_back(): void
    {
        $customer = $this->customer();
        $this->customerLimit($customer, daily: 1000.0);
        $service = app(AiCostControlService::class);

        $decision = $service->authorize($this->context($customer));
        $service->failHttp($decision, 400);

        $period = $this->period(AiOperationalBudgetPeriod::SCOPE_CUSTOMER, $customer->id, 'daily');
        $this->assertSame(0.0, (float) $period->reserved_nok);
        $this->assertSame(0.0, (float) $period->committed_nok);
    }

    public function test_a_timeout_keeps_the_money_as_spent_rather_than_returning_it(): void
    {
        $customer = $this->customer();
        $this->customerLimit($customer, daily: 1000.0);
        $service = app(AiCostControlService::class);

        $decision = $service->authorize($this->context($customer));
        $reserved = $decision->budgetReservation->reservedNok;
        $service->failHttp($decision, 504);

        $period = $this->period(AiOperationalBudgetPeriod::SCOPE_CUSTOMER, $customer->id, 'daily');

        // The provider may well have done — and charged for — the work.
        $this->assertSame(0.0, (float) $period->reserved_nok);
        $this->assertEqualsWithDelta($reserved, (float) $period->committed_nok, 0.01);
        $this->assertSame(1, (int) $period->unknown_cost_count);
    }

    public function test_spend_accumulates_until_the_limit_refuses_the_next_call(): void
    {
        $customer = $this->customer();
        $service = app(AiCostControlService::class);

        // One call's conservative estimate, used to size a limit that allows exactly two.
        $this->customerLimit($customer, daily: 1_000_000.0);
        $estimate = $service->authorize($this->context($customer))->budgetReservation->reservedNok;
        AiOperationalBudgetPeriod::query()->delete();

        $this->customerLimit($customer, daily: $estimate * 2.5);
        $service->authorize($this->context($customer));
        $service->authorize($this->context($customer));

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::CUSTOMER_DAILY_BUDGET_EXHAUSTED));
        $service->authorize($this->context($customer));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function context(Customer $customer, ?int $savedNoticeId = null, bool $commercialCredit = false): AiCallContext
    {
        return new AiCallContext(
            customerId: $customer->id,
            feature: 'wiki',
            operation: 'wiki.ask.answer',
            savedNoticeId: $savedNoticeId,
            commercialCredit: $commercialCredit,
            model: 'gpt-5',
        );
    }

    private function period(string $scope, ?int $customerId, string $window): AiOperationalBudgetPeriod
    {
        return AiOperationalBudgetPeriod::query()
            ->where(['scope' => $scope, 'customer_id' => $customerId, 'window' => $window])
            ->firstOrFail();
    }

    private function customerLimit(Customer $customer, ?float $daily = null, ?float $monthly = null): void
    {
        CustomerAiOperationalLimit::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            ['is_enabled' => true, 'daily_nok_limit' => $daily, 'monthly_nok_limit' => $monthly],
        );
    }

    private function globalLimit(?float $daily = null, ?float $monthly = null): void
    {
        AiRuntimeControl::query()->orderBy('id')->firstOrFail()->forceFill([
            'operational_budget_enabled' => true,
            'global_daily_nok_limit' => $daily,
            'global_monthly_nok_limit' => $monthly,
        ])->save();
    }

    private function seedPricing(): void
    {
        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-5', 'currency' => 'USD',
            'input_price_per_1m_tokens' => 10.0, 'output_price_per_1m_tokens' => 30.0,
            'valid_from' => '2026-01-01', 'is_active' => true, 'last_verified_at' => '2026-09-14 00:00:00',
        ]);
        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate' => 10.0,
            'rate_date' => '2026-09-15', 'source' => ExchangeRate::SOURCE_NORGES_BANK, 'fetched_at' => now(),
        ]);
    }

    private function notice(Customer $customer): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id, 'external_id' => 'BUD-'.Str::random(10),
            'title' => 'Budget notice', 'buyer_name' => 'Procynia', 'status' => 'ACTIVE',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'customer_id' => null, 'name' => 'Procynia Admin',
            'email' => 'admin-'.Str::lower(Str::random(8)).'@procynia.test',
            'password' => bcrypt('secret-password'), 'role' => User::ROLE_SUPER_ADMIN, 'is_active' => true,
        ]);
    }

    private function customer(string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Budget '.Str::random(8),
            'slug' => 'budget-'.Str::lower(Str::random(10)),
            'language_id' => $language->id, 'nationality_id' => $nationality->id, 'is_active' => true,
            'subscription_plan' => $plan, 'included_ai_credits' => 10,
        ]);
    }
}
