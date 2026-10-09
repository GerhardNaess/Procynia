<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Data\Billing\BillingPeriod;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\AiModelPrice;
use App\Models\AiOperationalBudgetPeriod;
use App\Models\AiRuntimeControl;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerAiOperationalLimit;
use App\Models\CustomerAiUsageReservation;
use App\Models\CustomerBillingPeriod;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Services\Ai\AiUsageMeter;
use App\Services\Ai\Commercial\AiQuotaStatusService;
use App\Services\Ai\Usage\AiUsageLedger;
use App\Services\Billing\CustomerBillingPeriodResolver;
use App\Services\OpenAi\OpenAiClient;
use App\Services\Operations\RuntimePreflightService;
use App\Support\Ai\AiCallContextScope;
use App\Support\Ai\AiOperationCatalog;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Settlement of AI cost: an attempt's cost is either final (settled), owed by nobody (released),
 * possibly incurred (pending) or incurred but not establishable (unresolved). Open settlements
 * keep their reservation, are never summed as zero and never charged as settled; usage is read
 * per actual billing period.
 */
class AiUsageSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        config()->set('ai_operations.context_enforcement', 'warn');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── failure semantics ──────────────────────────────────────────────────

    public function test_a_call_refused_before_the_provider_writes_no_attempt_and_holds_nothing(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        $this->limit($customer);
        AiRuntimeControl::query()->firstOrFail()->forceFill(['global_ai_stop' => true])->save();
        Http::fake();

        try {
            $this->callAi($customer);
            $this->fail('The global stop must refuse the call.');
        } catch (AiCostControlException) {
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiUsageAttempt::query()->count());
        $this->assertSame(0, AiOperationalBudgetPeriod::query()->where('reserved_nok', '>', 0)->count());
    }

    public function test_a_refused_request_is_released_with_no_actual_cost(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        $this->limit($customer);
        Http::fake(['https://openai.test/v1/responses' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $this->callIgnoringFailure($customer);

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::SETTLEMENT_RELEASED, $attempt->settlement_status);
        $this->assertNull($attempt->cost_nok);
        $this->assertGreaterThan(0.0, (float) $attempt->reserved_cost_nok, 'What it reserved stays on the row for audit.');
        $this->assertEqualsWithDelta(0.0, (float) $this->budget($customer)->reserved_nok, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $this->budget($customer)->committed_nok, 0.0001);

        $totals = $this->ledgerTotals($customer);
        $this->assertSame(1, $totals['released_calls']);
        $this->assertSame(0.0, $totals['settled_cost_nok']);
        $this->assertSame(0.0, $totals['pending_reserved_cost_nok']);
    }

    public function test_a_failure_the_provider_reported_usage_for_is_priced_and_settled(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'error' => ['message' => 'server error'],
            'usage' => ['input_tokens' => 10_000, 'output_tokens' => 1_000, 'total_tokens' => 11_000],
        ], 500)]);

        $this->callIgnoringFailure($customer);

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::SETTLEMENT_SETTLED, $attempt->settlement_status);
        $this->assertSame('known', $attempt->cost_status);
        $this->assertGreaterThan(0.0, (float) $attempt->cost_nok);
        $this->assertEqualsWithDelta((float) $attempt->cost_nok, $this->ledgerTotals($customer)['settled_cost_nok'], 0.0001);
    }

    public function test_a_5xx_without_usage_keeps_the_reservation_and_stays_pending(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        $this->limit($customer);
        $notice = $this->notice($customer);
        Http::fake(['https://openai.test/v1/responses' => Http::response(['error' => ['message' => 'bad gateway']], 502)]);

        try {
            app(AiCallContextScope::class)->within(
                new AiCallContext(customerId: $customer->id, savedNoticeId: $notice->id, commercialCredit: true),
                fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'tender.requirement_answer'),
            );
        } catch (RuntimeException) {
        }

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::STATUS_UNCERTAIN, $attempt->status);
        $this->assertSame(AiUsageAttempt::SETTLEMENT_PENDING, $attempt->settlement_status);
        $this->assertNull($attempt->cost_nok);

        // The NOK hold is kept as possibly spent, and so is the Anbud credit hold.
        $this->assertEqualsWithDelta((float) $attempt->reserved_cost_nok, (float) $this->budget($customer)->committed_nok, 0.0001);
        $this->assertSame(CustomerAiUsageReservation::STATUS_UNCERTAIN, CustomerAiUsageReservation::query()->sole()->status);

        $totals = $this->ledgerTotals($customer);
        $this->assertSame(0.0, $totals['settled_cost_nok'], 'Never charged as settled.');
        $this->assertEqualsWithDelta((float) $attempt->reserved_cost_nok, $totals['pending_reserved_cost_nok'], 0.0001);
        $this->assertSame(1, $totals['pending_calls']);
    }

    public function test_a_timeout_stays_pending_with_its_reservation(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        Http::fake(['https://openai.test/v1/responses' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->callIgnoringFailure($customer);

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::SETTLEMENT_PENDING, $attempt->settlement_status);
        $this->assertSame('uncertain', $attempt->cost_status);
        $this->assertGreaterThan(0.0, (float) $attempt->reserved_cost_nok);
    }

    public function test_a_retry_that_succeeds_settles_once_and_keeps_the_first_attempt_open(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        Http::fakeSequence('https://openai.test/v1/responses')
            ->push(['error' => ['message' => 'unavailable']], 503)
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => 1_000, 'output_tokens' => 100, 'total_tokens' => 1_100]], 200);

        $this->callIgnoringFailure($customer);
        $this->callAi($customer);

        [$first, $second] = AiUsageAttempt::query()->orderBy('id')->get()->all();
        $this->assertSame(AiUsageAttempt::SETTLEMENT_PENDING, $first->settlement_status);
        $this->assertSame(AiUsageAttempt::SETTLEMENT_SETTLED, $second->settlement_status);

        $totals = $this->ledgerTotals($customer);
        $this->assertSame(2, $totals['calls'], 'Every attempt is tracked.');
        $this->assertEqualsWithDelta((float) $second->cost_nok, $totals['settled_cost_nok'], 0.0001, 'Settled once, at the actual cost.');
        $this->assertEqualsWithDelta((float) $first->reserved_cost_nok, $totals['pending_reserved_cost_nok'], 0.0001);
    }

    public function test_a_success_without_usage_is_unresolved_not_free(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);

        $this->callAi($customer);

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::SETTLEMENT_UNRESOLVED, $attempt->settlement_status);
        $this->assertSame(1, $this->ledgerTotals($customer)['unresolved_calls']);
        $this->assertEqualsWithDelta((float) $attempt->reserved_cost_nok, $this->ledgerTotals($customer)['unresolved_reserved_cost_nok'], 0.0001);
    }

    public function test_usage_for_a_model_without_a_price_is_unresolved(): void
    {
        $customer = $this->customer();
        $meter = app(AiUsageMeter::class);

        $meter->within(
            new AiCallContext(customerId: $customer->id, feature: 'wiki', operation: 'wiki.verify_claim'),
            fn (): array => $meter->measureResponse('model-without-price', fn (): array => ['usage' => ['input_tokens' => 10, 'output_tokens' => 5]]),
        );

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame('unknown', $attempt->cost_status);
        $this->assertSame(AiUsageAttempt::SETTLEMENT_UNRESOLVED, $attempt->settlement_status);
    }

    public function test_a_call_still_in_flight_is_pending_and_already_shows_its_reservation(): void
    {
        $customer = $this->customer();
        $meter = app(AiUsageMeter::class);
        $seen = null;

        $meter->within(
            new AiCallContext(customerId: $customer->id, feature: 'wiki', operation: 'wiki.verify_claim'),
            function () use ($meter, &$seen): array {
                return $meter->measureResponse('gpt-4.1-mini', function () use (&$seen): array {
                    $seen = AiUsageAttempt::query()->sole()->only(['status', 'settlement_status', 'reserved_cost_nok']);

                    return ['usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
                }, reservedCostNok: 0.4321);
            },
        );

        $this->assertSame(['status' => 'started', 'settlement_status' => 'pending', 'reserved_cost_nok' => '0.4321'], $seen);
    }

    // ── billing-period aggregation ─────────────────────────────────────────

    public function test_usage_is_read_per_actual_billing_period_with_settled_pending_and_legacy_apart(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        CustomerBillingPeriod::query()->create([
            'customer_id' => $customer->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_x',
            'period_start' => '2026-09-15 06:00:00', 'period_end' => '2026-10-15 06:00:00',
            'interval' => 'month', 'subscription_status' => 'active', 'synced_at' => now(),
        ]);

        $this->attempt($customer, 'wiki.verify_claim', ['cost_nok' => 1.0, 'started_at' => '2026-09-15 06:00:00']);
        $this->attempt($customer, 'tender.requirement_answer', ['cost_nok' => 2.0, 'started_at' => '2026-10-15 05:59:59']);
        $this->attempt($customer, 'tender.requirement_answer', ['cost_status' => 'uncertain', 'settlement_status' => 'pending', 'cost_nok' => null, 'reserved_cost_nok' => 0.5, 'started_at' => '2026-10-01 00:00:00']);
        // Outside the period, by one second on either side.
        $this->attempt($customer, 'wiki.verify_claim', ['cost_nok' => 100.0, 'started_at' => '2026-09-15 05:59:59']);
        $this->attempt($customer, 'wiki.verify_claim', ['cost_nok' => 100.0, 'started_at' => '2026-10-15 06:00:00']);
        // Legacy and another tenant: never counted.
        $this->attempt($customer, 'wiki.verify_claim', ['cost_nok' => 50.0, 'ledger_version' => null, 'attribution' => null, 'settlement_status' => null, 'started_at' => '2026-10-01 00:00:00']);
        $this->attempt($other, 'wiki.verify_claim', ['cost_nok' => 70.0, 'started_at' => '2026-10-01 00:00:00']);

        $usage = app(AiUsageLedger::class)->forBillingPeriod($customer->id, CarbonImmutable::parse('2026-10-08 10:00:00'));

        $this->assertSame(BillingPeriod::SOURCE_PROVIDER, $usage['period']->source);
        $this->assertSame(3, $usage['totals']['calls']);
        $this->assertSame(3.0, $usage['totals']['settled_cost_nok']);
        $this->assertSame(0.5, $usage['totals']['pending_reserved_cost_nok']);
        $this->assertSame(1, $usage['totals']['pending_calls']);
        $this->assertEqualsCanonicalizing(
            [['tender', 'tender.requirement_answer', 2.0, 0.5], ['wiki', 'wiki.verify_claim', 1.0, 0.0]],
            array_map(fn (array $row): array => [$row['feature'], $row['operation_key'], $row['settled_cost_nok'], $row['pending_reserved_cost_nok']], $usage['operations']),
        );

        // The renewal instant is counted once, in the next period.
        $next = app(AiUsageLedger::class)->forBillingPeriod($customer->id, CarbonImmutable::parse('2026-10-15 06:00:00'));
        $this->assertSame(100.0, $next['totals']['settled_cost_nok']);
    }

    public function test_the_ai_case_quota_still_uses_the_calendar_month(): void
    {
        $customer = $this->customer();
        CustomerBillingPeriod::query()->create([
            'customer_id' => $customer->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_q',
            'period_start' => '2026-09-15 00:00:00', 'period_end' => '2026-10-15 00:00:00',
            'interval' => 'month', 'subscription_status' => 'active', 'synced_at' => now(),
        ]);

        $quota = app(AiQuotaStatusService::class)->forCustomer($customer);

        $this->assertSame('2026-10-01', $quota->periodStart);
        $this->assertSame('2026-10-31', $quota->periodEnd);
    }

    // ── operations ─────────────────────────────────────────────────────────

    public function test_operations_see_open_settlements_once_they_outlive_the_configured_age(): void
    {
        config()->set('ai_operations.settlement.open_alert_after_hours', 6);
        $customer = $this->customer();
        $this->attempt($customer, 'wiki.verify_claim', ['cost_status' => 'uncertain', 'settlement_status' => 'pending', 'cost_nok' => null, 'reserved_cost_nok' => 1.25, 'started_at' => now()->subHours(7)]);
        $this->attempt($customer, 'wiki.verify_claim', ['cost_status' => 'unknown', 'settlement_status' => 'unresolved', 'cost_nok' => null, 'reserved_cost_nok' => 0.75, 'started_at' => now()->subHours(30)]);
        // Young enough to still be a normal retry.
        $this->attempt($customer, 'tender.requirement_answer', ['cost_status' => 'uncertain', 'settlement_status' => 'pending', 'cost_nok' => null, 'reserved_cost_nok' => 9.0, 'started_at' => now()->subHours(2)]);

        $open = app(AiUsageLedger::class)->openSettlements(CarbonImmutable::now()->subHours(6));
        $this->assertSame(2, $open['count']);
        $this->assertSame(2.0, $open['reserved_cost_nok']);
        $this->assertSame('2026-10-07 04:00:00', $open['oldest_started_at']);

        $this->artisan('ai:cost-control-health')->assertExitCode(0);
        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_open_settlements_ageing']);

        $check = collect(app(RuntimePreflightService::class)->run())->firstWhere('name', 'AI open settlements');
        $this->assertSame(RuntimePreflightService::STATUS_WARN, $check['status']);
        $this->assertFalse($check['critical'], 'Never a deploy blocker; the reservation already holds the money.');

        // Nothing was charged on the way.
        $this->assertSame(0, AiUsageAttempt::query()->where('settlement_status', 'settled')->count());
    }

    // ── trusted-ledger invariant ───────────────────────────────────────────

    public function test_every_trusted_customer_attempt_carries_the_full_ledger_contract(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        Http::fakeSequence('https://openai.test/v1/responses')
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]], 200)
            ->push(['error' => ['message' => 'bad gateway']], 502)
            ->push(['error' => ['message' => 'bad request']], 400)
            ->push(['status' => 'completed'], 200);

        foreach (range(1, 4) as $ignored) {
            $this->callIgnoringFailure($customer, resourceType: 'saved_notice', resourceId: 9);
        }

        $resolver = app(CustomerBillingPeriodResolver::class);
        $attempts = AiUsageAttempt::query()->trusted()->where('attribution', AiCallContext::ATTRIBUTION_CUSTOMER)->get();
        $this->assertCount(4, $attempts);

        foreach ($attempts as $attempt) {
            $this->assertSame(AiUsageAttempt::LEDGER_VERSION, $attempt->ledger_version);
            $this->assertSame($customer->id, $attempt->customer_id);
            $this->assertSame('tender', $attempt->feature);
            $this->assertTrue(in_array($attempt->operation_key, AiOperationCatalog::registeredOperations(), true));
            $this->assertNotEmpty($attempt->provider);
            $this->assertNotEmpty($attempt->model);
            $this->assertNotSame(AiUsageAttempt::STATUS_STARTED, $attempt->status);
            $this->assertSame(['saved_notice', 9], [$attempt->resource_type, $attempt->resource_id]);
            $this->assertNotNull($attempt->reserved_cost_nok);
            $this->assertContains($attempt->settlement_status, [AiUsageAttempt::SETTLEMENT_SETTLED, AiUsageAttempt::SETTLEMENT_RELEASED, AiUsageAttempt::SETTLEMENT_PENDING, AiUsageAttempt::SETTLEMENT_UNRESOLVED]);
            $this->assertSame($attempt->settlement_status === AiUsageAttempt::SETTLEMENT_SETTLED, $attempt->cost_nok !== null, 'Actual cost exactly when settled.');
            $this->assertTrue($resolver->at($customer, $attempt->started_at)->contains($attempt->started_at), 'Every attempt belongs to one billing period.');
        }

        $this->assertNotNull($attempts->firstWhere('settlement_status', AiUsageAttempt::SETTLEMENT_SETTLED)->input_tokens);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function callAi(Customer $customer, ?string $resourceType = null, ?int $resourceId = null): mixed
    {
        return app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id, resourceType: $resourceType, resourceId: $resourceId),
            fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'tender.requirement_answer'),
        );
    }

    private function callIgnoringFailure(Customer $customer, ?string $resourceType = null, ?int $resourceId = null): void
    {
        try {
            $this->callAi($customer, $resourceType, $resourceId);
        } catch (RuntimeException|ConnectionException) {
        }
    }

    /** @return array<string, int|float> */
    private function ledgerTotals(Customer $customer): array
    {
        return app(AiUsageLedger::class)->forBillingPeriod($customer->id)['totals'];
    }

    private function limit(Customer $customer): void
    {
        CustomerAiOperationalLimit::query()->create(['customer_id' => $customer->id, 'is_enabled' => true, 'daily_nok_limit' => 1000]);
    }

    private function budget(Customer $customer): AiOperationalBudgetPeriod
    {
        return AiOperationalBudgetPeriod::query()
            ->where(['scope' => AiOperationalBudgetPeriod::SCOPE_CUSTOMER, 'customer_id' => $customer->id, 'window' => AiOperationalBudgetPeriod::WINDOW_DAILY])
            ->firstOrFail();
    }

    private function notice(Customer $customer): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id, 'external_id' => 'SET-'.Str::random(8),
            'title' => 'Settlement notice', 'buyer_name' => 'Procynia', 'status' => 'ACTIVE',
        ]);
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

    /** @param array<string, mixed> $overrides */
    private function attempt(Customer $customer, string $operation, array $overrides = []): AiUsageAttempt
    {
        return AiUsageAttempt::query()->create(array_merge([
            'customer_id' => $customer->id,
            'attribution' => 'customer',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => strstr($operation, '.', true),
            'operation_key' => $operation,
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110,
            'cost_status' => 'known', 'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'started_at' => now(), 'finished_at' => now(),
        ], $overrides));
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Settlement '.Str::random(8),
            'slug' => 'settlement-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 5,
        ]);
    }
}
