<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiTimeoutPlan;
use App\Exceptions\Ai\AiCallContextException;
use App\Models\AiModelPrice;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Quality\ProcessFlowInterpretationAiClient;
use App\Services\Ai\Requirements\Excel\WorkbookStructureDiscoveryAiClient;
use App\Services\Ai\Requirements\FullDocumentRequirementExtractionPrompt;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\Ai\Wiki\WikiQuestionAnswerAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityRetryExecutor;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiCallContextScope;
use App\Support\Ai\AiOperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * What `ai_usage_attempts` can now promise about every row, and the provider-boundary rules that
 * make it true: a named operation always, a customer unless the call says it is system work, a
 * nested scope that narrows its owner instead of erasing it, and a cost that prices cached input
 * at the cached rate.
 */
class AiPlatformIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        config()->set('ai_operations.context_enforcement', 'warn');
    }

    // ── context propagation ─────────────────────────────────────────────────

    public function test_a_nested_empty_context_inherits_the_job_customer_instead_of_hiding_it(): void
    {
        $customer = $this->customer();
        $this->fakeProvider();

        // The Wiki run-context loss: a job establishes the customer, and a client deep below pushes
        // AiCallContext::none() naming only its operation. The call must still be the customer's.
        app(AiCallContextScope::class)->within(
            new AiCallContext(runId: null, customerId: $customer->id, feature: 'wiki', operation: 'wiki.generate_page', resourceType: 'enterprise_wiki_document', resourceId: 77),
            fn () => app(AiCallContextScope::class)->within(
                AiCallContext::none()->withOperation(WikiPageContentAiClient::OPERATION_REPAIR_SECTIONS),
                fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]),
            ),
        );

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame($customer->id, $attempt->customer_id);
        $this->assertSame('customer', $attempt->attribution);
        $this->assertSame('wiki', $attempt->feature);
        $this->assertSame('wiki.repair_page_sections', $attempt->operation_key);
        $this->assertSame('enterprise_wiki_document', $attempt->resource_type);
        $this->assertSame(77, $attempt->resource_id);
    }

    public function test_the_wiki_retry_executor_no_longer_hides_the_job_context_when_given_none(): void
    {
        $customer = $this->customer();
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ], 200)]);
        $plan = new AiCapacityPlan('enterprise_wiki_maintainer_decision', 'gpt-5', 1000, 10, 10, 5000, false, 'test', 0);

        // Exactly what FinalizeEnterpriseWikiMaintainerDecisionBatches did: the job context is set by
        // RunsInAiCallContext, and the executor is called without a context of its own.
        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id, feature: 'wiki', operation: 'wiki.finalize_maintainer_batches'),
            fn (): array => app(EnterpriseWikiAiCapacityRetryExecutor::class)->execute(
                'test_operation',
                100,
                fn (int $retry): AiCapacityPlan => $plan,
                fn (int $maxTokens): array => ['model' => 'gpt-5', 'input' => [], 'max_output_tokens' => $maxTokens],
                fn (): AiTimeoutPlan => new AiTimeoutPlan('enterprise_wiki_maintainer_decision', 30, 20, 120, false, false, 'test'),
                null,
            ),
        );

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame($customer->id, $attempt->customer_id);
        $this->assertSame('wiki.finalize_maintainer_batches', $attempt->operation_key);
        $this->assertSame('customer', $attempt->attribution);
    }

    public function test_the_client_operation_narrows_the_entry_context_and_keeps_its_commercial_credit(): void
    {
        $context = (new AiCallContext(customerId: 5, feature: 'tender', operation: 'tender.requirement_answer', savedNoticeId: 9, commercialCredit: true))
            ->forProviderCall('gpt-4.1-mini', 'responses');
        $inner = AiCallContext::none()->withOperation('wiki.navigation_plan')->inheritFrom($context);

        $this->assertSame('wiki.navigation_plan', $inner->operation);
        $this->assertSame('wiki', $inner->feature);
        $this->assertSame(5, $inner->customerId);
        $this->assertSame(9, $inner->savedNoticeId);
        $this->assertTrue($inner->commercialCredit);
    }

    public function test_a_nested_context_for_another_customer_is_refused_before_any_call(): void
    {
        Http::fake();

        $this->expectException(LogicException::class);

        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: 1, feature: 'wiki', operation: 'wiki.verify_claim'),
            fn () => app(AiCallContextScope::class)->within(
                new AiCallContext(customerId: 2, feature: 'wiki', operation: 'wiki.verify_claim'),
                fn () => null,
            ),
        );
    }

    // ── attribution rules at the provider boundary ──────────────────────────

    public function test_a_call_without_an_operation_is_refused_before_the_provider(): void
    {
        $customer = $this->customer();
        $this->fakeProvider();

        try {
            app(AiCallContextScope::class)->within(
                new AiCallContext(customerId: $customer->id),
                fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]),
            );
            $this->fail('A call that names no operation must not reach the provider.');
        } catch (AiCallContextException) {
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiUsageAttempt::query()->count());
    }

    public function test_an_unknown_feature_is_refused(): void
    {
        $customer = $this->customer();
        $this->fakeProvider();

        $this->expectException(AiCallContextException::class);

        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id),
            fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []], operation: 'saved_notice.requirement_answer'),
        );
    }

    public function test_a_customerless_call_is_recorded_as_unattributed_and_alerted_in_warn_mode(): void
    {
        $this->fakeProvider();

        app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []], operation: 'wiki.verify_claim');

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertNull($attempt->customer_id);
        $this->assertSame('unattributed', $attempt->attribution);
        $this->assertSame('wiki.verify_claim', $attempt->operation_key);
        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_call_unattributed']);
    }

    public function test_a_customerless_call_is_refused_in_strict_mode(): void
    {
        config()->set('ai_operations.context_enforcement', 'strict');
        $this->fakeProvider();

        try {
            app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []], operation: 'wiki.verify_claim');
            $this->fail('Strict mode must refuse a customer-driven call without a customer.');
        } catch (AiCallContextException) {
        }

        Http::assertNothingSent();
    }

    public function test_explicit_system_work_passes_strict_mode_and_is_recorded_as_system(): void
    {
        config()->set('ai_operations.context_enforcement', 'strict');
        $this->fakeProvider();

        app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []], operation: 'system.health_probe');

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame('system', $attempt->attribution);
        $this->assertSame('system', $attempt->feature);
    }

    // ── tokens and cost ─────────────────────────────────────────────────────

    public function test_cached_and_reasoning_tokens_are_stored_and_cached_input_is_priced_at_its_own_rate(): void
    {
        $customer = $this->customer();
        $this->price('gpt-5', input: 1.25, cached: 0.125, output: 10.00);
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'status' => 'completed',
            'usage' => [
                'input_tokens' => 1_000_000,
                'input_tokens_details' => ['cached_tokens' => 400_000],
                'output_tokens' => 100_000,
                'output_tokens_details' => ['reasoning_tokens' => 60_000],
                'total_tokens' => 1_100_000,
            ],
        ], 200)]);

        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id),
            fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []], operation: 'wiki.generate_page'),
        );

        $attempt = AiUsageAttempt::query()->sole();
        $this->assertSame(1_000_000, $attempt->input_tokens);
        $this->assertSame(400_000, $attempt->cached_input_tokens);
        $this->assertSame(100_000, $attempt->output_tokens);
        $this->assertSame(60_000, $attempt->reasoning_tokens);
        // 600k uncached × 1.25 + 400k cached × 0.125 + 100k output × 10.00 = 0.75 + 0.05 + 1.00
        $this->assertEqualsWithDelta(1.80, (float) $attempt->cost_usd, 0.000001);
        $this->assertEqualsWithDelta(0.125, (float) $attempt->price_cached_input_per_1m, 0.000001);
    }

    public function test_a_model_without_a_cached_rate_bills_cached_tokens_as_input(): void
    {
        $customer = $this->customer();
        $this->price('gpt-5', input: 2.00, cached: null, output: 0.00);
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'status' => 'completed',
            'usage' => ['input_tokens' => 1_000_000, 'input_tokens_details' => ['cached_tokens' => 500_000], 'output_tokens' => 0],
        ], 200)]);

        app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id),
            fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []], operation: 'wiki.generate_page'),
        );

        $this->assertEqualsWithDelta(2.00, (float) AiUsageAttempt::query()->sole()->cost_usd, 0.000001);
    }

    public function test_the_gpt5_price_matches_the_verified_provider_price(): void
    {
        $gpt5 = collect(config('ai_model_prices.providers.openai'))->firstWhere('model', 'gpt-5');

        $this->assertSame(1.25, $gpt5['input_price_per_1m_tokens']);
        $this->assertSame(0.125, $gpt5['cached_input_price_per_1m_tokens']);
        $this->assertSame(10.00, $gpt5['output_price_per_1m_tokens']);
    }

    // ── central model configuration ─────────────────────────────────────────

    public function test_every_client_resolves_its_model_from_the_catalog_with_the_previous_defaults(): void
    {
        // The registry moved the choice; it must not have changed it.
        $this->assertSame('gpt-5', WikiPageContentAiClient::model());
        $this->assertSame('gpt-5', AiOperationCatalog::model(WikiPageContentAiClient::OPERATION_REPAIR_FIGURES));
        $this->assertSame('gpt-5', AiOperationCatalog::model(EnterpriseWikiMaintainerDecisionAiClient::OPERATION));
        $this->assertSame('gpt-4.1-mini', WikiClaimVerificationAiClient::model());
        $this->assertSame('gpt-4.1-mini', WikiQuestionAnswerAiClient::model());
        $this->assertSame('gpt-4.1-mini', RequirementWikiAnswerAiClient::model());
        $this->assertSame('gpt-4.1-mini', WorkbookStructureDiscoveryAiClient::model());
        $this->assertSame('gpt-4.1-mini', FullDocumentRequirementExtractionPrompt::model());
        $this->assertSame('gpt-4.1-mini', ProcessFlowInterpretationAiClient::model());
    }

    public function test_an_operation_variant_falls_back_to_its_parent_model_and_an_unknown_one_is_an_error(): void
    {
        config()->set('ai_operations.operations', array_merge(config('ai_operations.operations'), [
            'tender.requirement_extraction' => ['model' => 'parent-model'],
        ]));

        $this->assertSame('parent-model', AiOperationCatalog::model('tender.requirement_extraction.segment'));

        $this->expectException(\InvalidArgumentException::class);
        AiOperationCatalog::model('wiki.not_an_operation');
    }

    public function test_historical_names_translate_to_the_standard(): void
    {
        $this->assertSame('tender', AiOperationCatalog::canonicalFeature('saved_notice'));
        $this->assertSame('wiki', AiOperationCatalog::canonicalFeature('enterprise_wiki'));
        $this->assertNull(AiOperationCatalog::canonicalFeature('unclassified'));
        $this->assertSame('tender.requirement_extraction.segment', AiOperationCatalog::canonicalOperation('saved_notice.requirement_extraction.segment'));
        $this->assertSame('wiki.operator.recover_document_flow', AiOperationCatalog::canonicalOperation('operator.wiki.recover_document_flow'));
        $this->assertSame('wiki.verify_claim', AiOperationCatalog::canonicalOperation('enterprise_wiki.verify_claim'));
        $this->assertSame('quality.interpret_process', AiOperationCatalog::canonicalOperation('process_flow_interpretation'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function fakeProvider(): void
    {
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]], 200)]);
    }

    private function price(string $model, float $input, ?float $cached, float $output): void
    {
        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => $model, 'currency' => 'usd',
            'input_price_per_1m_tokens' => $input, 'cached_input_price_per_1m_tokens' => $cached,
            'output_price_per_1m_tokens' => $output, 'valid_from' => now()->subDay()->toDateString(),
            'is_active' => true, 'last_verified_at' => now(),
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Integrity '.Str::random(8),
            'slug' => 'integrity-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 5,
        ]);
    }
}
