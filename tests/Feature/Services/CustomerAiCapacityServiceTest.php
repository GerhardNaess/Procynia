<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\CustomerAiCapacity;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\AiModelPrice;
use App\Models\AiOperationalBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiOperationalLimit;
use App\Models\CustomerBillingPeriod;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\Commercial\AiUnitConverter;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Ai\Operational\AiOperationalPricingService;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiCallContextScope;
use App\Support\Ai\AiCostControlPresenter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The shared AI capacity: one pool of AI units per customer and billing period, read from the
 * usage ledger (settled = used, open = reserved) through one configurable conversion, and a gate
 * at the provider boundary that can refuse an operation that does not fit.
 */
class CustomerAiCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        config()->set('ai_operations.context_enforcement', 'warn');
        config()->set('ai_customer_capacity.nok_per_unit', 0.10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── included capacity ──────────────────────────────────────────────────

    public function test_included_units_come_from_the_plan_per_month_and_a_customer_override_wins(): void
    {
        $customer = $this->customer();
        $this->assertSame(2000, $this->capacity($customer)->includedUnits, 'Pro includes 2 000 units a month.');

        $customer->update(['included_ai_units' => 50_000]);
        $this->assertSame(50_000, $this->capacity($customer)->includedUnits);

        // An explicit zero is a real value, not "not set".
        $customer->update(['included_ai_units' => 0]);
        $capacity = $this->capacity($customer);
        $this->assertSame(0, $capacity->includedUnits);
        $this->assertSame(CustomerAiCapacity::STATUS_EXHAUSTED, $capacity->status);
    }

    public function test_a_yearly_period_includes_twelve_months_of_the_plan(): void
    {
        $customer = $this->customer(['billing_interval' => Customer::BILLING_YEARLY]);
        $this->period($customer, '2026-03-01 00:00:00', '2027-03-01 00:00:00', 'year');

        $capacity = $this->capacity($customer);

        $this->assertSame(24_000, $capacity->includedUnits);
        $this->assertSame('2026-03-01', $capacity->toArray()['period_start']);
        $this->assertSame('2027-02-28', $capacity->toArray()['period_end']);
    }

    public function test_without_a_plan_or_customer_capacity_nothing_is_metered_or_refused(): void
    {
        $customer = $this->customer(['subscription_plan' => Customer::PLAN_ENTERPRISE]);
        $this->attempt($customer, ['cost_nok' => 999.0]);

        $capacity = $this->capacity($customer);

        $this->assertFalse($capacity->isConfigured());
        $this->assertSame(CustomerAiCapacity::STATUS_NOT_CONFIGURED, $capacity->status);
        $this->assertNull($capacity->toArray()['remaining']);
        $this->assertNull(app(CustomerAiCapacityService::class)->refusalFor($customer, 1000.0));

        // Enterprise gets its capacity per customer.
        $customer->update(['included_ai_units' => 100_000]);
        $this->assertSame(100_000, $this->capacity($customer)->includedUnits);
    }

    // ── used, reserved, remaining ──────────────────────────────────────────

    public function test_settled_cost_is_used_open_cost_is_reserved_and_the_rest_remains(): void
    {
        $customer = $this->customer(['included_ai_units' => 1000]);
        $this->attempt($customer, ['cost_nok' => 30.0]);
        $this->attempt($customer, ['cost_nok' => 2.04]);
        $this->attempt($customer, ['settlement_status' => 'pending', 'cost_status' => 'uncertain', 'cost_nok' => null, 'reserved_cost_nok' => 4.0]);
        $this->attempt($customer, ['settlement_status' => 'unresolved', 'cost_status' => 'unknown', 'cost_nok' => null, 'reserved_cost_nok' => 1.0]);
        // A released call cost nothing and holds nothing.
        $this->attempt($customer, ['settlement_status' => 'released', 'status' => 'failed', 'cost_nok' => null, 'reserved_cost_nok' => 9.0]);
        // Legacy rows are never part of any economic figure.
        $this->attempt($customer, ['cost_nok' => 500.0, 'ledger_version' => null, 'attribution' => null, 'settlement_status' => null]);

        $capacity = $this->capacity($customer);

        $this->assertEqualsWithDelta(320.4, $capacity->usedUnitsExact, 0.0001);
        $this->assertSame(321, $capacity->usedUnits, 'Shown rounded up, never below what was used.');
        $this->assertSame(50, $capacity->reservedUnits);
        $this->assertSame(629, $capacity->remainingUnits());
        $this->assertEqualsWithDelta(629.6, $capacity->availableUnitsExact(), 0.0001);
        $this->assertSame(32, $capacity->percentageUsed());
        $this->assertSame(CustomerAiCapacity::STATUS_NORMAL, $capacity->status);
        $this->assertTrue($capacity->showsReservation, '50 of 1 000 units (5 %) is worth mentioning.');
    }

    public function test_the_status_follows_the_configured_thresholds_of_settled_usage(): void
    {
        $customer = $this->customer(['included_ai_units' => 100]);
        $this->attempt($customer, ['cost_nok' => 7.9]);
        $this->assertSame(CustomerAiCapacity::STATUS_NORMAL, $this->capacity($customer)->status);

        $this->attempt($customer, ['cost_nok' => 0.1]);
        $capacity = $this->capacity($customer);
        $this->assertSame(CustomerAiCapacity::STATUS_WARNING, $capacity->status);
        $this->assertTrue($capacity->isWarning());

        $this->attempt($customer, ['cost_nok' => 2.0]);
        $capacity = $this->capacity($customer);
        $this->assertSame(CustomerAiCapacity::STATUS_EXHAUSTED, $capacity->status);
        $this->assertTrue($capacity->isExhausted());
        $this->assertSame(0, $capacity->remainingUnits());
        $this->assertSame(100, $capacity->percentageUsed());

        config()->set('ai_customer_capacity.thresholds.exhausted_percent', 150);
        $this->assertSame(CustomerAiCapacity::STATUS_WARNING, $this->capacity($customer)->status);
    }

    public function test_reservations_never_count_as_used_and_small_ones_are_not_mentioned(): void
    {
        $customer = $this->customer(['included_ai_units' => 1000]);
        $this->attempt($customer, ['settlement_status' => 'pending', 'cost_status' => 'uncertain', 'cost_nok' => null, 'reserved_cost_nok' => 0.3]);

        $capacity = $this->capacity($customer);

        $this->assertSame(0, $capacity->usedUnits);
        $this->assertSame(3, $capacity->reservedUnits);
        $this->assertSame(CustomerAiCapacity::STATUS_NORMAL, $capacity->status);
        $this->assertFalse($capacity->showsReservation);
    }

    public function test_a_reservation_that_leaves_nothing_available_is_mentioned_but_not_called_used_up(): void
    {
        $customer = $this->customer(['included_ai_units' => 1000]);
        $this->attempt($customer, ['cost_nok' => 98.0]);
        $this->attempt($customer, ['settlement_status' => 'pending', 'cost_status' => 'uncertain', 'cost_nok' => null, 'reserved_cost_nok' => 2.0]);

        $capacity = $this->capacity($customer);

        $this->assertSame(CustomerAiCapacity::STATUS_WARNING, $capacity->status);
        $this->assertTrue($capacity->showsReservation);
        $this->assertSame(0, $capacity->remainingUnits());
        $this->assertSame(CustomerAiCapacityService::REFUSE_INSUFFICIENT, app(CustomerAiCapacityService::class)->refusalFor($customer, 0.01));
    }

    // ── periods and isolation ──────────────────────────────────────────────

    public function test_capacity_resets_in_the_next_billing_period_and_the_boundary_counts_once(): void
    {
        $customer = $this->customer(['included_ai_units' => 100]);
        $this->period($customer, '2026-09-15 06:00:00', '2026-10-15 06:00:00');
        $this->period($customer, '2026-10-15 06:00:00', '2026-11-15 06:00:00');
        $this->attempt($customer, ['cost_nok' => 10.0, 'started_at' => '2026-09-15 06:00:00']);
        $this->attempt($customer, ['cost_nok' => 3.0, 'started_at' => '2026-10-15 06:00:00']);

        $this->assertTrue($this->capacity($customer)->isExhausted());

        $next = app(CustomerAiCapacityService::class)->forCustomer($customer, CarbonImmutable::parse('2026-10-15 06:00:00'));
        $this->assertSame(30, $next->usedUnits);
        $this->assertSame(CustomerAiCapacity::STATUS_NORMAL, $next->status);
        $this->assertSame('2026-11-14', $next->toArray()['period_end']);
        $this->assertSame('2026-11-15', $next->toArray()['next_period_start']);
    }

    public function test_a_customer_never_sees_another_customers_usage(): void
    {
        $customer = $this->customer(['included_ai_units' => 100]);
        $other = $this->customer(['included_ai_units' => 100]);
        $this->attempt($customer, ['cost_nok' => 1.0]);
        $this->attempt($other, ['cost_nok' => 9.0]);

        $this->assertSame(10, $this->capacity($customer)->usedUnits);
        $this->assertSame(90, $this->capacity($other)->usedUnits);
    }

    // ── conversion ─────────────────────────────────────────────────────────

    public function test_the_conversion_is_central_configurable_and_rounds_once_on_the_aggregate(): void
    {
        $converter = app(AiUnitConverter::class);

        $this->assertEqualsWithDelta(32.0, $converter->unitsForCost(3.2), 0.000001);
        $this->assertSame(32, $converter->displayUnits(0.1 * 320), 'Float noise must not round 32 up to 33.');
        $this->assertSame(1, $converter->displayUnits(0.0001));
        $this->assertSame(0, $converter->displayUnits(0.0));

        // A thousand tiny calls are a fraction of a unit each — rounding happens on the sum.
        $customer = $this->customer(['included_ai_units' => 1000]);
        foreach (range(1, 10) as $ignored) {
            $this->attempt($customer, ['cost_nok' => 0.002]);
        }
        $this->assertSame(1, $this->capacity($customer)->usedUnits);

        // Changing the rate re-reads the same ledger; the ledger itself is untouched.
        config()->set('ai_customer_capacity.nok_per_unit', 0.01);
        $this->assertSame(2, $this->capacity($customer)->usedUnits);
        $this->assertEqualsWithDelta(0.02, (float) AiUsageAttempt::query()->sum('cost_nok'), 0.000001);

        config()->set('ai_customer_capacity.nok_per_unit', 0);
        $this->expectException(InvalidArgumentException::class);
        $converter->nokPerUnit();
    }

    public function test_the_customer_payload_carries_units_only(): void
    {
        $customer = $this->customer(['included_ai_units' => 5000]);
        $this->attempt($customer, ['cost_nok' => 320.0]);

        $payload = $this->capacity($customer)->toArray();

        $this->assertSame(3200, $payload['used']);
        $this->assertSame(5000, $payload['included']);
        $this->assertSame(1800, $payload['remaining']);
        $this->assertSame(64, $payload['percentage_used']);
        $this->assertSame('normal', $payload['status']);

        foreach (array_keys($payload) as $key) {
            $this->assertDoesNotMatchRegularExpression('/nok|cost|token|model|price|usd/i', $key);
        }
    }

    // ── the gate at the provider boundary ──────────────────────────────────

    public function test_an_operation_that_fits_runs_reserves_its_estimate_and_settles_at_actual_cost(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'enforce');
        $customer = $this->customer(['included_ai_units' => 1000]);
        $this->seedPrices();
        $seen = null;
        Http::fake(function () use ($customer, &$seen) {
            // While the call is in flight its estimate is already held against the capacity.
            $seen = $this->capacity($customer);

            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 10_000, 'output_tokens' => 1_000, 'total_tokens' => 11_000]], 200);
        });

        $this->callAi($customer);

        $estimateUnits = $this->estimateNok() / 0.10;
        $this->assertGreaterThan(0, $seen->reservedUnits);
        $this->assertEqualsWithDelta($estimateUnits, $seen->reservedUnitsExact, 0.0001);
        $this->assertSame(0, $seen->usedUnits);

        $after = $this->capacity($customer);
        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::SETTLEMENT_SETTLED, $attempt->settlement_status);
        $this->assertSame(0, $after->reservedUnits, 'The reservation turned into usage by itself.');
        $this->assertEqualsWithDelta((float) $attempt->cost_nok / 0.10, $after->usedUnitsExact, 0.0001);
    }

    public function test_a_warning_does_not_stop_an_operation(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'enforce');
        $customer = $this->customer(['included_ai_units' => 100]);
        $this->seedPrices();
        $this->attempt($customer, ['cost_nok' => 9.0]);
        $this->fakeProvider();

        $this->callAi($customer);

        $this->assertTrue($this->capacity($customer)->isWarning());
        Http::assertSentCount(1);
    }

    public function test_exhausted_capacity_refuses_before_the_provider_and_holds_nothing(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'enforce');
        $customer = $this->customer(['included_ai_units' => 100]);
        $this->seedPrices();
        CustomerAiOperationalLimit::query()->create(['customer_id' => $customer->id, 'is_enabled' => true, 'daily_nok_limit' => 1000]);
        $this->attempt($customer, ['cost_nok' => 10.0]);
        Http::fake();

        try {
            $this->callAi($customer);
            $this->fail('Exhausted capacity must refuse the call.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::CAPACITY_EXHAUSTED, $exception->reason);
        }

        Http::assertNothingSent();
        $this->assertSame(1, AiUsageAttempt::query()->count(), 'No new attempt for a refused call.');
        $budget = AiOperationalBudgetPeriod::query()->where(['scope' => 'customer', 'customer_id' => $customer->id, 'window' => 'daily'])->first();
        $this->assertEqualsWithDelta(0.0, (float) ($budget?->reserved_nok ?? 0), 0.0001, 'The NOK hold is given back.');
    }

    public function test_reservations_that_leave_too_little_refuse_with_the_insufficient_reason(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'enforce');
        $customer = $this->customer(['included_ai_units' => 100]);
        $this->seedPrices();
        $this->attempt($customer, ['cost_nok' => 5.0]);
        // Everything else is held by open calls; nothing is "used up".
        $this->attempt($customer, ['settlement_status' => 'pending', 'cost_status' => 'uncertain', 'cost_nok' => null, 'reserved_cost_nok' => 4.99]);
        Http::fake();

        try {
            $this->callAi($customer);
            $this->fail('Insufficient available capacity must refuse the call.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::CAPACITY_INSUFFICIENT, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_observe_mode_logs_a_refusal_and_lets_the_call_through(): void
    {
        $this->assertSame('observe', config('ai_customer_capacity.enforcement'), 'Observe is the default while the AI-case quota still gates Anbud.');
        $customer = $this->customer(['included_ai_units' => 100]);
        $this->seedPrices();
        $this->attempt($customer, ['cost_nok' => 10.0]);
        $this->fakeProvider();
        Log::spy();

        $this->callAi($customer);

        Http::assertSentCount(1);
        Log::shouldHaveReceived('notice')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '[AI_CAPACITY]')
            && $context['reason'] === AiCostControlException::CAPACITY_EXHAUSTED)->once();
    }

    public function test_off_mode_does_not_evaluate_the_gate(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'off');
        $customer = $this->customer(['included_ai_units' => 0]);
        $this->seedPrices();
        $this->fakeProvider();

        $this->callAi($customer);

        Http::assertSentCount(1);
    }

    public function test_a_certain_failure_gives_the_capacity_back_and_a_timeout_keeps_it_reserved(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'enforce');
        $customer = $this->customer(['included_ai_units' => 1000]);
        $this->seedPrices();

        Http::fakeSequence('https://openai.test/v1/responses')
            ->push(['error' => ['message' => 'bad request']], 400)
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => 10_000, 'output_tokens' => 1_000, 'total_tokens' => 11_000]], 200);

        $this->callIgnoringFailure($customer);
        $capacity = $this->capacity($customer);
        $this->assertSame(0, $capacity->usedUnits);
        $this->assertSame(0, $capacity->reservedUnits);

        $this->callIgnoringFailure($customer);
        $capacity = $this->capacity($customer);
        $this->assertSame(0, $capacity->usedUnits, 'An uncertain call is never charged as used.');
        $this->assertEqualsWithDelta($this->estimateNok() / 0.10, $capacity->reservedUnitsExact, 0.0001);

        // The retry that succeeds settles once; the doubtful first attempt stays reserved.
        $this->callAi($customer);
        $capacity = $this->capacity($customer);
        $this->assertGreaterThan(0.0, $capacity->usedUnitsExact);
        $this->assertEqualsWithDelta($this->estimateNok() / 0.10, $capacity->reservedUnitsExact, 0.0001);
    }

    public function test_an_operator_override_bypasses_exhausted_capacity_and_is_audited(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'enforce');
        $customer = $this->customer(['included_ai_units' => 10]);
        $this->seedPrices();
        $this->attempt($customer, ['cost_nok' => 1.0]);
        $this->fakeProvider();
        $operator = User::factory()->create(['customer_id' => null, 'role' => 'super_admin']);

        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id, operatorOverride: true, operatorActorUserId: $operator->id, operatorOverrideReason: 'Recovery run'),
            fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'tender.requirement_answer'),
        );

        Http::assertSentCount(1);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id, 'event_type' => 'ai_operator_override_used',
        ]);
        $this->assertSame(AiCostControlException::CAPACITY_EXHAUSTED, BillingEvent::query()->where('event_type', 'ai_operator_override_used')->sole()->before['blocked_by']);
    }

    public function test_the_hard_stop_messages_say_when_capacity_returns_and_never_overstate(): void
    {
        $customer = $this->customer(['included_ai_units' => 100]);
        $this->period($customer, '2026-10-01 00:00:00', '2026-11-01 00:00:00');
        $presenter = app(AiCostControlPresenter::class);

        $exhausted = $presenter->message(new AiCostControlException(AiCostControlException::CAPACITY_EXHAUSTED), $customer);
        $this->assertSame('AI-kapasiteten for denne perioden er brukt opp. Ny kapasitet blir tilgjengelig 1. november 2026.', $exhausted);

        $insufficient = $presenter->message(new AiCostControlException(AiCostControlException::CAPACITY_INSUFFICIENT), $customer);
        $this->assertStringNotContainsString('brukt opp', $insufficient);
        $this->assertStringContainsString('midlertidig reservert', $insufficient);
        $this->assertStringContainsString('1. november 2026', $insufficient);
    }

    // ── Anbud keeps working ────────────────────────────────────────────────

    public function test_an_anbud_ai_case_still_takes_its_credit_and_draws_on_the_shared_capacity(): void
    {
        $customer = $this->customer(['included_ai_units' => 1000]);
        $this->seedPrices();
        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id, 'external_id' => 'CAP-'.Str::random(8),
            'title' => 'Capacity notice', 'buyer_name' => 'Procynia', 'status' => 'ACTIVE',
        ]);
        $this->fakeProvider();

        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id, savedNoticeId: $notice->id, commercialCredit: true),
            fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'tender.requirement_answer'),
        );

        $this->assertSame(1, CustomerAiCaseUsage::query()->where('customer_id', $customer->id)->count(), 'The AI case is still committed.');
        $this->assertGreaterThan(0.0, $this->capacity($customer)->usedUnitsExact);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function capacity(Customer $customer): CustomerAiCapacity
    {
        return app(CustomerAiCapacityService::class)->forCustomer($customer->fresh());
    }

    private function callAi(Customer $customer): mixed
    {
        return app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id),
            fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'tender.requirement_answer'),
        );
    }

    private function callIgnoringFailure(Customer $customer): void
    {
        try {
            $this->callAi($customer);
        } catch (RuntimeException|ConnectionException) {
        }
    }

    private function fakeProvider(): void
    {
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'status' => 'completed',
            'usage' => ['input_tokens' => 10_000, 'output_tokens' => 1_000, 'total_tokens' => 11_000],
        ], 200)]);
    }

    private function estimateNok(): float
    {
        return (float) app(AiOperationalPricingService::class)->estimateMaxCostNok('openai', 'gpt-4.1-mini', null, null, 'tender.requirement_answer');
    }

    private function seedPrices(): void
    {
        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'currency' => 'usd',
            'input_price_per_1m_tokens' => 0.40, 'cached_input_price_per_1m_tokens' => 0.10,
            'output_price_per_1m_tokens' => 1.60, 'valid_from' => '2026-10-01',
            'is_active' => true, 'last_verified_at' => now(),
        ]);

        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate' => 10.0,
            'rate_date' => '2026-10-08', 'source' => ExchangeRate::SOURCE_NORGES_BANK, 'fetched_at' => now(),
        ]);
    }

    private function period(Customer $customer, string $start, string $end, string $interval = 'month'): void
    {
        CustomerBillingPeriod::query()->create([
            'customer_id' => $customer->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_'.Str::random(6),
            'period_start' => $start, 'period_end' => $end,
            'interval' => $interval, 'subscription_status' => 'active', 'synced_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function attempt(Customer $customer, array $overrides = []): AiUsageAttempt
    {
        return AiUsageAttempt::query()->create(array_merge([
            'customer_id' => $customer->id,
            'attribution' => 'customer',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => 'wiki',
            'operation_key' => 'wiki.verify_claim',
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110,
            'cost_status' => 'known', 'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'started_at' => now(), 'finished_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create(array_merge([
            'name' => 'Capacity '.Str::random(8),
            'slug' => 'capacity-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 5,
        ], $attributes));
    }
}
