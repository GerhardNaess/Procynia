<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Ai\Usage\AiUsagePeriod;
use App\Models\AiModelPrice;
use App\Models\AiOperationalBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiOperationalLimit;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiDocumentOrigin;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Services\Ai\Commercial\AiQuotaStatusService;
use App\Services\Ai\Operational\AiOperationalPricingService;
use App\Services\Ai\Pricing\AiModelPriceReadiness;
use App\Services\Ai\Usage\AiUsageLedger;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionClaimSyncService;
use App\Services\EnterpriseWiki\EnterpriseWikiQaRegressionPolicy;
use App\Services\EnterpriseWiki\EnterpriseWikiQaRegressionService;
use App\Services\OpenAi\OpenAiClient;
use App\Services\Operations\RuntimePreflightService;
use App\Support\Ai\AiCallContextScope;
use App\Support\Ai\AiOperationCatalog;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * `ai_usage_attempts` as the economic source of truth: every trusted row has an owner, an
 * operation and an actual cost kept apart from its reservation; legacy and unattributed rows are
 * never counted; and the environment refuses to call itself AI-ready while a model in use has no
 * synced price.
 */
class AiUsageLedgerTest extends TestCase
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

    // ── attribution: the scheduler paths that used to reach the provider without a customer ──

    public function test_the_maintenance_cycle_repairs_claim_content_as_the_run_customer_and_keeps_module_provenance(): void
    {
        $customer = $this->customer();
        $run = $this->wikiRun($customer, [
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ], origin: ['risk', 42]);
        $seen = null;

        $this->mock(EnterpriseWikiClaimContentRepairService::class, function ($mock) use (&$seen): void {
            $mock->shouldReceive('attempt')->once()->andReturnUsing(function () use (&$seen): array {
                $seen = app(AiCallContextScope::class)->current();

                return [];
            });
        });

        app(EnterpriseWikiMaintenanceCycleService::class)->run();

        $this->assertSame($customer->id, $seen?->customerId);
        $this->assertSame(AiCallContext::ATTRIBUTION_CUSTOMER, $seen->attribution());
        $this->assertSame($run->id, $seen->runId);
        // A risk handed over to the Wiki keeps attributing its maintenance spend to the risk.
        $this->assertSame('risk', $seen->resourceType);
        $this->assertSame(42, $seen->resourceId);
    }

    public function test_the_qa_regression_scan_runs_each_snapshot_as_its_run_customer(): void
    {
        $customer = $this->customer();
        $run = $this->wikiRun($customer, ['status' => EnterpriseWikiIngestRun::STATUS_COMPLETED]);
        $snapshot = EnterpriseWikiQaSnapshot::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id, 'customer_id' => $customer->id,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_PASSED, 'qa_attempt_count' => 0, 'snapshotted_at' => now(),
        ]);
        $seen = null;

        $this->mock(EnterpriseWikiQaRegressionPolicy::class, function ($mock) use (&$seen): void {
            $mock->shouldReceive('evaluate')->once()->andReturnUsing(function () use (&$seen): never {
                $seen = app(AiCallContextScope::class)->current();

                throw new RuntimeException('stop after capturing the context');
            });
        });

        try {
            app(EnterpriseWikiQaRegressionService::class)->processSnapshot($snapshot);
        } catch (RuntimeException) {
        }

        $this->assertSame($customer->id, $seen?->customerId);
        $this->assertSame($run->id, $seen->runId);
    }

    public function test_claim_resync_from_an_operator_repair_command_runs_each_run_as_its_own_customer(): void
    {
        $first = $this->customer();
        $second = $this->customer();
        $runs = [
            $this->wikiRun($first, ['status' => EnterpriseWikiIngestRun::STATUS_RUNNING]),
            $this->wikiRun($second, ['status' => EnterpriseWikiIngestRun::STATUS_RUNNING]),
        ];
        $seen = [];

        $service = $this->partialMock(EnterpriseWikiPageVersionClaimSyncService::class, function ($mock) use (&$seen): void {
            $mock->shouldReceive('syncRun')->twice()->andReturnUsing(function () use (&$seen): array {
                $seen[] = app(AiCallContextScope::class)->current()->customerId;

                return [];
            });
        });

        $service->syncRuns(array_map(fn (EnterpriseWikiIngestRun $run): int => $run->id, $runs));

        $this->assertSame([$first->id, $second->id], $seen);
    }

    // ── measuring unattributed usage and strict readiness ───────────────────

    public function test_operations_can_ask_whether_any_ai_call_was_unattributed(): void
    {
        $this->artisan('ai:usage-integrity')->assertExitCode(0);

        $this->fakeProvider();
        app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'wiki.verify_claim');

        $summary = app(AiUsageLedger::class)->unattributed();
        $this->assertSame(1, $summary['count']);
        $this->assertSame('wiki.verify_claim', $summary['operations'][0]['operation_key']);
        $this->assertNotNull($summary['last_at']);

        $this->artisan('ai:usage-integrity')->expectsOutputToContain('1 unattributed AI call(s)')->assertExitCode(1);
        $this->artisan('ai:cost-control-health')->assertExitCode(0);
        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_unattributed_attempts']);
    }

    public function test_a_misspelt_enforcement_mode_is_a_deploy_blocker_not_a_silent_warn(): void
    {
        config()->set('ai_operations.context_enforcement', 'Strict');
        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $this->preflight('AI context enforcement')['status']);

        config()->set('ai_operations.context_enforcement', 'strict');
        $this->assertSame(RuntimePreflightService::STATUS_PASS, $this->preflight('AI context enforcement')['status']);
    }

    // ── what a trusted row promises ─────────────────────────────────────────

    public function test_a_trusted_customer_attempt_answers_every_ledger_question(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        $this->fakeProvider(['input_tokens' => 1_000, 'input_tokens_details' => ['cached_tokens' => 200], 'output_tokens' => 100, 'output_tokens_details' => ['reasoning_tokens' => 40], 'total_tokens' => 1_100]);

        $this->asCustomer($customer, fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'tender.requirement_answer'), resourceType: 'saved_notice_ai_requirement', resourceId: 9);

        $attempt = AiUsageAttempt::query()->trusted()->sole();
        $this->assertSame(AiUsageAttempt::LEDGER_VERSION, $attempt->ledger_version);
        $this->assertSame($customer->id, $attempt->customer_id);
        $this->assertSame('tender', $attempt->feature);
        $this->assertSame('tender.requirement_answer', $attempt->operation_key);
        $this->assertSame('gpt-4.1-mini', $attempt->model);
        $this->assertSame('openai', $attempt->provider);
        $this->assertSame([1_000, 200, 100, 40], [$attempt->input_tokens, $attempt->cached_input_tokens, $attempt->output_tokens, $attempt->reasoning_tokens]);
        $this->assertSame(['saved_notice_ai_requirement', 9], [$attempt->resource_type, $attempt->resource_id]);
        $this->assertNotNull($attempt->started_at);
        $this->assertSame(AiUsageAttempt::STATUS_SUCCESS, $attempt->status);
        // 800 × 0.40 + 200 × 0.10 + 100 × 1.60 per 1M = 0.0005 USD, × 10 NOK/USD.
        $this->assertSame('known', $attempt->cost_status);
        $this->assertEqualsWithDelta(0.0005, (float) $attempt->cost_usd, 0.0000001);
        $this->assertEqualsWithDelta(0.005, (float) $attempt->cost_nok, 0.00001);
    }

    public function test_the_reservation_estimate_is_recorded_beside_the_actual_cost_never_instead_of_it(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        $this->fakeProvider();

        $this->asCustomer($customer, fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'wiki.verify_claim'));

        $attempt = AiUsageAttempt::query()->sole();
        $expected = app(AiOperationalPricingService::class)
            ->estimateMaxCostNok('openai', 'gpt-4.1-mini', null, null, 'wiki.verify_claim');

        $this->assertEqualsWithDelta($expected, (float) $attempt->reserved_cost_nok, 0.0001);
        $this->assertLessThan((float) $attempt->reserved_cost_nok, (float) $attempt->cost_nok);
    }

    public function test_a_call_with_no_reported_usage_has_unknown_cost_not_zero_and_is_not_a_pricing_alarm(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['error' => ['message' => 'bad request']], 400)]);

        try {
            $this->asCustomer($customer, fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'wiki.verify_claim'));
        } catch (RuntimeException) {
        }

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('unknown', $attempt->cost_status);
        $this->assertNull($attempt->cost_nok);
        $this->assertNull($attempt->price_state);

        $this->artisan('ai:cost-control-health')->assertExitCode(0);
        $this->assertDatabaseMissing('admin_notifications', ['type' => 'ai_unpriced_attempts']);
    }

    public function test_a_failed_call_the_provider_still_reported_usage_for_is_priced_and_charged_to_the_budget(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        CustomerAiOperationalLimit::query()->create(['customer_id' => $customer->id, 'is_enabled' => true, 'daily_nok_limit' => 1000]);
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'error' => ['message' => 'content filtered'],
            'usage' => ['input_tokens' => 10_000, 'output_tokens' => 1_000, 'total_tokens' => 11_000],
        ], 400)]);

        $this->asCustomer($customer, fn () => app(OpenAiClient::class)->post('responses', ['model' => 'gpt-4.1-mini', 'input' => []], operation: 'wiki.verify_claim'));

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(AiUsageAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('known', $attempt->cost_status);
        $this->assertGreaterThan(0.0, (float) $attempt->cost_nok);

        $period = $this->budgetPeriod($customer);
        $this->assertEqualsWithDelta((float) $attempt->cost_nok, (float) $period->committed_nok, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $period->reserved_nok, 0.0001);
    }

    public function test_a_failure_before_the_provider_did_work_releases_the_reservation(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        CustomerAiOperationalLimit::query()->create(['customer_id' => $customer->id, 'is_enabled' => true, 'daily_nok_limit' => 1000]);
        Http::fake(['https://openai.test/v1/responses' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $this->asCustomer($customer, fn () => app(OpenAiClient::class)->post('responses', ['model' => 'gpt-4.1-mini', 'input' => []], operation: 'wiki.verify_claim'));

        $period = $this->budgetPeriod($customer);
        $this->assertEqualsWithDelta(0.0, (float) $period->committed_nok, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $period->reserved_nok, 0.0001);
    }

    public function test_a_success_without_a_usage_block_settles_the_budget_at_its_reservation(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        CustomerAiOperationalLimit::query()->create(['customer_id' => $customer->id, 'is_enabled' => true, 'daily_nok_limit' => 1000]);
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);

        $this->asCustomer($customer, fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'wiki.verify_claim'));

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame('unknown', $attempt->cost_status);
        $this->assertEqualsWithDelta((float) $attempt->reserved_cost_nok, (float) $this->budgetPeriod($customer)->committed_nok, 0.0001);
    }

    // ── Anbud: AI-saker unchanged, attempts beside them ─────────────────────

    public function test_a_retried_tender_case_commits_one_ai_case_and_records_every_provider_attempt(): void
    {
        $customer = $this->customer();
        $this->seedPrices();
        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id, 'external_id' => 'LED-'.Str::random(8),
            'title' => 'Ledger notice', 'buyer_name' => 'Procynia', 'status' => 'ACTIVE',
        ]);
        $this->fakeProvider();

        // A job and its retry: the same case, two provider calls.
        foreach ([1, 2] as $attemptNumber) {
            app(AiCallContextScope::class)->within(
                new AiCallContext(customerId: $customer->id, savedNoticeId: $notice->id, commercialCredit: true, resourceType: 'requirement_extraction_run', resourceId: 5),
                fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: 'tender.requirement_extraction.segment'),
            );
        }

        $this->assertSame(1, CustomerAiCaseUsage::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, app(AiQuotaStatusService::class)->forCustomer($customer)->used);

        $totals = app(AiUsageLedger::class)->totals(new AiUsageFilter(AiUsagePeriod::calendarMonth(), customerId: $customer->id, feature: 'tender'));
        $this->assertSame(2, $totals['calls']);
        $this->assertSame(30, $totals['total_tokens']);
        $this->assertGreaterThan(0.0, $totals['cost_nok']);
        $this->assertSame(2, AiUsageAttempt::query()->whereNotNull('reserved_cost_nok')->count(), 'Each attempt reserves and settles on its own.');
    }

    public function test_the_usage_period_is_the_same_calendar_month_the_ai_case_quota_uses(): void
    {
        $customer = $this->customer();
        $status = app(AiQuotaStatusService::class)->forCustomer($customer, CarbonImmutable::parse('2026-10-31 23:30:00'));
        $period = AiUsagePeriod::calendarMonth(CarbonImmutable::parse('2026-10-31 23:30:00'));

        $this->assertSame($status->periodStart, $period->start->toDateString());
        $this->assertSame($status->periodEnd, $period->end->subDay()->toDateString());
    }

    // ── the ledger reader ───────────────────────────────────────────────────

    public function test_the_ledger_counts_trusted_rows_only_and_groups_by_customer_feature_and_operation(): void
    {
        $alpha = $this->customer();
        $beta = $this->customer();
        $this->attempt($alpha, 'tender.requirement_answer', 1.00);
        $this->attempt($alpha, 'tender.requirement_answer', 2.00);
        $this->attempt($alpha, 'wiki.generate_page', 4.00);
        $this->attempt($beta, 'quality.interpret_process', 8.00);
        $this->attempt(null, 'system.price_probe', 16.00, ['attribution' => 'system']);
        $this->attempt(null, 'wiki.verify_claim', 32.00, ['attribution' => 'unattributed']);
        $this->attempt($alpha, 'saved_notice.requirement_answer', 64.00, ['ledger_version' => null, 'attribution' => null]);
        $this->attempt($alpha, 'tender.requirement_answer', 128.00, ['started_at' => '2026-09-30 23:59:59']);

        $ledger = app(AiUsageLedger::class);
        $october = AiUsagePeriod::calendarMonth();

        $this->assertEqualsWithDelta(31.0, $ledger->totals(new AiUsageFilter($october))['cost_nok'], 0.0001);
        $this->assertSame(1, $ledger->totals(new AiUsageFilter($october))['system_calls']);
        $this->assertEqualsWithDelta(7.0, $ledger->totals(new AiUsageFilter($october, customerId: $alpha->id))['cost_nok'], 0.0001);
        $this->assertEqualsWithDelta(3.0, $ledger->totals(new AiUsageFilter($october, customerId: $alpha->id, operation: 'tender.requirement_answer'))['cost_nok'], 0.0001);
        $this->assertSame(['wiki', 'tender'], array_column($ledger->breakdown(new AiUsageFilter($october, customerId: $alpha->id), ['feature']), 'feature'));
        $this->assertSame(['2026-10-08' => 5], array_map(fn (array $row): int => $row['calls'], $ledger->trend(new AiUsageFilter($october))));
        $this->assertSame(['trusted' => 6, 'unattributed' => 1, 'legacy' => 1], $ledger->integrity());
    }

    // ── model pricing readiness ─────────────────────────────────────────────

    public function test_every_active_model_has_a_reviewed_source_price(): void
    {
        $sources = array_column(config('ai_model_prices.providers.openai'), 'model');

        $this->assertContains('gpt-5', AiOperationCatalog::activeModels());
        $this->assertContains('gpt-4.1-mini', AiOperationCatalog::activeModels());

        foreach (AiOperationCatalog::activeModels() as $model) {
            $this->assertContains($model, $sources, "Active model [{$model}] has no price in config/ai_model_prices.php.");
        }
    }

    public function test_an_environment_is_not_ai_ready_until_every_active_model_has_its_synced_price(): void
    {
        // Missing entirely.
        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $this->preflight('AI active model prices')['status']);

        // The pre-correction gpt-5 price still in the database: priced, but wrong.
        Artisan::call('ai:sync-model-prices');
        AiModelPrice::query()->where('model', 'gpt-5')->update([
            'input_price_per_1m_tokens' => 15.00, 'cached_input_price_per_1m_tokens' => 3.75, 'output_price_per_1m_tokens' => 75.00,
            'source_hash' => 'hash-of-the-config-before-the-correction',
        ]);

        $this->assertSame([['model' => 'gpt-5', 'state' => AiModelPriceReadiness::UNSYNCED]], app(AiModelPriceReadiness::class)->problems());
        $check = $this->preflight('AI active model prices');
        $this->assertSame(RuntimePreflightService::STATUS_FAIL, $check['status']);
        $this->assertTrue($check['critical']);

        $this->artisan('ai:cost-control-health')->assertExitCode(0);
        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_active_model_price_not_ready']);

        // The documented deploy step fixes it.
        Artisan::call('ai:sync-model-prices');
        $this->assertSame([], app(AiModelPriceReadiness::class)->problems());
        $this->assertSame(RuntimePreflightService::STATUS_PASS, $this->preflight('AI active model prices')['status']);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function asCustomer(Customer $customer, \Closure $call, ?string $resourceType = null, ?int $resourceId = null): mixed
    {
        return app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id, resourceType: $resourceType, resourceId: $resourceId),
            $call,
        );
    }

    private function fakeProvider(?array $usage = null): void
    {
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'status' => 'completed',
            'usage' => $usage ?? ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ], 200)]);
    }

    private function seedPrices(): void
    {
        foreach ([['gpt-4.1-mini', 0.40, 0.10, 1.60], ['gpt-5', 1.25, 0.125, 10.00]] as [$model, $input, $cached, $output]) {
            AiModelPrice::query()->create([
                'provider' => 'openai', 'model' => $model, 'currency' => 'usd',
                'input_price_per_1m_tokens' => $input, 'cached_input_price_per_1m_tokens' => $cached,
                'output_price_per_1m_tokens' => $output, 'valid_from' => '2026-10-01',
                'is_active' => true, 'last_verified_at' => now(),
            ]);
        }

        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate' => 10.0,
            'rate_date' => '2026-10-08', 'source' => ExchangeRate::SOURCE_NORGES_BANK, 'fetched_at' => now(),
        ]);
    }

    private function budgetPeriod(Customer $customer): AiOperationalBudgetPeriod
    {
        return AiOperationalBudgetPeriod::query()
            ->where(['scope' => AiOperationalBudgetPeriod::SCOPE_CUSTOMER, 'customer_id' => $customer->id, 'window' => AiOperationalBudgetPeriod::WINDOW_DAILY])
            ->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function attempt(?Customer $customer, string $operation, float $costNok, array $overrides = []): AiUsageAttempt
    {
        return AiUsageAttempt::query()->create(array_merge([
            'customer_id' => $customer?->id,
            'attribution' => $customer !== null ? 'customer' : 'system',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => strstr($operation, '.', true),
            'operation_key' => $operation,
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110,
            'cost_status' => 'known', 'cost_nok' => $costNok,
            'started_at' => now(), 'finished_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{0: string, 1: int}|null  $origin
     */
    private function wikiRun(Customer $customer, array $attributes, ?array $origin = null): EnterpriseWikiIngestRun
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'Kilde.docx',
            'file_path' => 'ledger-test/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Innhold.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        if ($origin !== null) {
            EnterpriseWikiDocumentOrigin::query()->create([
                'customer_id' => $customer->id, 'enterprise_wiki_document_id' => $document->id,
                'source_module' => $origin[0], 'source_type' => $origin[0], 'source_id' => $origin[1],
            ]);
        }

        return EnterpriseWikiIngestRun::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
        ], $attributes));
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function preflight(string $name): array
    {
        return collect(app(RuntimePreflightService::class)->run())->firstWhere('name', $name);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Ledger '.Str::random(8),
            'slug' => 'ledger-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 5,
        ]);
    }
}
