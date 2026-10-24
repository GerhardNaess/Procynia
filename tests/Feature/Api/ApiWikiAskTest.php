<?php

namespace Tests\Feature\Api;

use App\Data\Ai\AiCallContext;
use App\Models\AiModelPrice;
use App\Models\AiUsageAttempt;
use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Ai\Wiki\EnterpriseWikiSemanticSearchPlanAiClient;
use App\Services\Ai\Wiki\WikiQuestionAnswerAiClient;
use App\Services\EnterpriseWiki\WikiAskRateLimiter;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiCallContextScope;
use App\Support\ApiOperationCatalog;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * «Spør Wiki» through the API (WIK-03, D18–D21): the Wiki's own retrieval core, answered from
 * published versions only, with citations naming the published version read; the access rule in
 * the service (wiki.view, the AI offer, and the grant's `wiki.ask`); its own rate limit; and the
 * customer's AI capacity as a hard stop on this channel.
 *
 * The answer tests mock the two Wiki AI clients, as the app's own Spør Wiki tests do. The capacity
 * tests leave them real, so the provider boundary (OpenAiClient → cost control) decides.
 */
class ApiWikiAskTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use DatabaseTransactions;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    protected bool $customersHoldTenderPackage = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->fakeEntraSigningKeys();
        config()->set('services.enterprise_wiki.ai_enabled', true);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        config()->set('ai_operations.context_enforcement', 'warn');
        config()->set('ai_customer_capacity.nok_per_unit', 0.10);
    }

    public function test_the_answer_reads_published_versions_only_and_cites_the_published_version(): void
    {
        $client = $this->apiClient();
        $customer = $this->askingCustomer();
        // Published version 1 answers; the working version 2 in review must never reach the model.
        $page = $this->page($customer, 'svartid', 'Svartid', "# Svartid\n\n## Kritiske hendelser\n\nSvartiden er fire timer.", EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->workingVersion($page, "# Svartid\n\nARBEIDSVERSJON: svartiden er én time.");
        $draft = $this->page($customer, 'svartid-utkast', 'Svartid utkast', null, EnterpriseWikiPage::STATUS_DRAFT);
        $this->workingVersion($draft, 'UTKAST: svartid fem minutter.');
        $published = EnterpriseWikiPageVersion::query()->findOrFail($page->published_version_id);
        $this->grantAsker($client, $customer);
        $seen = [];
        $this->mockWikiAi(function (array $context) use (&$seen, $page): array {
            $seen = $context;

            return [
                'answer_status' => WikiQuestionAnswerAiClient::STATUS_ANSWERED,
                'answer' => 'Svartiden er fire timer.',
                'citations' => [
                    ['page_id' => $page->id, 'heading' => 'Kritiske hendelser', 'excerpt' => 'Svartiden er fire timer.'],
                    // A page the model was never given is not a citation.
                    ['page_id' => 999999, 'heading' => null, 'excerpt' => 'Oppdiktet.'],
                ],
            ];
        });
        $before = $this->wikiState($customer);

        // A read-only token: asking writes nothing, so no write role is needed.
        $response = $this->postJson('/api/v1/wiki/ask', ['question' => 'Hva er svartiden for kritiske hendelser?'], $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJsonStructure([
            'data' => ['answer_status', 'answer', 'citations' => ['*' => ['page_id', 'title', 'slug', 'version_number', 'heading', 'excerpt']], 'pages_used'],
            'meta' => ['as_of', 'request_id'],
        ]);
        $response->assertJsonPath('data.answer_status', 'answered');
        $response->assertJsonPath('data.citations', [[
            'page_id' => $page->id, 'title' => 'Svartid', 'slug' => 'svartid', 'version_number' => 1,
            'heading' => 'Kritiske hendelser', 'excerpt' => 'Svartiden er fire timer.',
        ]]);
        $response->assertJsonPath('data.pages_used', 1);

        $this->assertSame([$page->id], array_column($seen, 'page_id'));
        $this->assertSame((int) $published->id, $seen[0]['page_version_id'], 'The context names the published version it read.');
        $this->assertStringContainsString('fire timer', $seen[0]['content_markdown']);

        foreach (['ARBEIDSVERSJON', 'UTKAST'] as $absent) {
            $this->assertStringNotContainsString($absent, json_encode($seen, JSON_UNESCAPED_UNICODE) ?: '');
        }

        foreach (['ARBEIDSVERSJON', 'UTKAST', 'page_version_id', 'ranking', 'retrieval', 'Oppdiktet', 'pending_review'] as $absent) {
            $this->assertStringNotContainsString($absent, (string) $response->getContent());
        }

        $this->assertSame($before, $this->wikiState($customer), 'Asking writes nothing to the Wiki.');
    }

    public function test_insufficient_evidence_is_a_valid_answer(): void
    {
        $client = $this->apiClient();
        $customer = $this->askingCustomer();
        $this->page($customer, 'svartid', 'Svartid', 'Svartiden er fire timer.');
        $this->grantAsker($client, $customer);
        $this->mockWikiAi(fn (array $context): array => [
            'answer_status' => WikiQuestionAnswerAiClient::STATUS_INSUFFICIENT_EVIDENCE,
            'answer' => '',
            'citations' => [],
        ]);

        $this->postJson('/api/v1/wiki/ask', ['question' => 'Hva er svartiden?'], $this->asCustomer($this->accessToken(), $customer))
            ->assertOk()
            ->assertJsonPath('data', ['answer_status' => 'insufficient_evidence', 'answer' => '', 'citations' => [], 'pages_used' => 1]);
    }

    public function test_the_operation_wiki_view_and_the_ai_offer_are_each_required_before_any_ai(): void
    {
        $client = $this->apiClient();
        $this->forbidWikiAi();

        // wiki.view, but «Spør Wiki» not switched on for the grant.
        $customer = $this->askingCustomer();
        $this->page($customer, 'svartid', 'Svartid', 'Svartiden er fire timer.');
        $this->grantAsker($client, $customer, operations: []);
        $this->ask($customer)->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        // The operation, but no wiki.view.
        $customer = $this->askingCustomer('Uten wiki.view');
        $this->grantAsker($client, $customer, permissions: [CustomerPermissionCatalog::QUALITY_VIEW]);
        $this->ask($customer)->assertForbidden();

        // The operation and wiki.view, but no AI offer for the customer.
        $customer = $this->askingCustomer('Uten AI', ['included_ai_credits' => 0]);
        $this->grantAsker($client, $customer);
        $this->ask($customer)->assertForbidden();

        // Refused before the request counts against the limit.
        $this->assertSame(0, RateLimiter::attempts(WikiAskRateLimiter::customerKey($customer->id, WikiAskRateLimiter::CHANNEL_API)));
    }

    public function test_questions_have_their_own_rate_limit_separate_from_the_web_application(): void
    {
        config()->set('procynia.ai.wiki_ask.user_attempts', 1);
        $client = $this->apiClient();
        $customer = $this->askingCustomer();
        $this->page($customer, 'svartid', 'Svartid', 'Svartiden er fire timer.');
        $identity = $this->grantAsker($client, $customer);
        $this->mockWikiAi(fn (array $context): array => ['answer_status' => 'answered', 'answer' => 'Fire timer.', 'citations' => []]);

        $this->ask($customer)->assertOk();
        $this->ask($customer)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        $this->assertSame(1, RateLimiter::attempts(WikiAskRateLimiter::userKey($customer->id, $identity->id, WikiAskRateLimiter::CHANNEL_API)));
        $this->assertSame(0, RateLimiter::attempts(WikiAskRateLimiter::customerKey($customer->id)), 'The people\'s bucket is untouched.');
    }

    public function test_the_question_is_validated(): void
    {
        $client = $this->apiClient();
        $customer = $this->askingCustomer();
        $this->grantAsker($client, $customer);
        $this->forbidWikiAi();
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->postJson('/api/v1/wiki/ask', [], $headers)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson('/api/v1/wiki/ask', ['question' => str_repeat('a', 501)], $headers)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['question']]]);
    }

    public function test_used_up_capacity_stops_the_question_before_the_provider_even_in_observe_mode(): void
    {
        $this->assertSame('observe', config('ai_customer_capacity.enforcement'), 'The people\'s channel only observes.');
        $client = $this->apiClient();
        $customer = $this->askingCustomer('Brukt opp', ['included_ai_units' => 100]);
        $this->page($customer, 'svartid', 'Svartid', 'Svartiden er fire timer.');
        $this->grantAsker($client, $customer);
        $this->seedPrices();
        $this->settledAttempt($customer, 10.0);

        $response = $this->ask($customer);

        $response->assertStatus(429)->assertJsonPath('error.code', 'ai_capacity_exhausted');
        $this->assertStringNotContainsString('AI_CAPACITY', (string) $response->getContent());
        $this->assertNoProviderCall();
        $this->assertSame(1, AiUsageAttempt::query()->where('customer_id', $customer->id)->count(), 'A refused call opens no attempt.');
    }

    public function test_capacity_held_by_calls_in_flight_stops_the_question(): void
    {
        $client = $this->apiClient();
        $customer = $this->askingCustomer('Reservert', ['included_ai_units' => 100]);
        $this->page($customer, 'svartid', 'Svartid', 'Svartiden er fire timer.');
        $this->grantAsker($client, $customer);
        $this->seedPrices();
        $this->settledAttempt($customer, 5.0);
        // A concurrent call has reserved what is left; it has used nothing yet.
        $this->settledAttempt($customer, null, ['settlement_status' => 'pending', 'cost_status' => 'uncertain', 'reserved_cost_nok' => 4.99]);

        $this->ask($customer)->assertStatus(429)->assertJsonPath('error.code', 'ai_capacity_exhausted');
        $this->assertNoProviderCall();
    }

    public function test_a_call_that_fits_is_admitted_metered_and_attributed_to_the_integration(): void
    {
        $customer = $this->askingCustomer('Plass', ['included_ai_units' => 1000]);
        $identity = $this->integrationIdentity($customer);
        $this->seedPrices();
        Http::fake(['https://openai.test/v1/responses' => Http::response([
            'status' => 'completed',
            'usage' => ['input_tokens' => 1_000, 'output_tokens' => 100, 'total_tokens' => 1_100],
        ], 200)]);
        $this->asWikiAskRoute($customer, $identity, 'wiki.ask.answer');

        $this->get('/api/_test/wiki-ask-provider')->assertOk();

        $attempt = AiUsageAttempt::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame((int) $identity->id, (int) $attempt->user_id);
        $this->assertSame('wiki.ask.answer', $attempt->operation_key);
        $this->assertSame('customer', $attempt->attribution);
        $this->assertSame(AiUsageAttempt::SETTLEMENT_SETTLED, $attempt->settlement_status);
        $this->assertNotNull($attempt->reserved_cost_nok, 'The estimate was reserved against the capacity.');
    }

    public function test_a_customer_without_a_defined_capacity_is_refused_on_this_channel(): void
    {
        config()->set('ai_customer_capacity.tiers', []);
        config()->set('ai_customer_capacity.default_tier', null);
        $customer = $this->askingCustomer('Uten kapasitet');
        $this->assertFalse(app(CustomerAiCapacityService::class)->forCustomer($customer)->isConfigured());
        $this->seedPrices();
        $this->asWikiAskRoute($customer, $this->integrationIdentity($customer), 'wiki.ask.answer');

        $this->get('/api/_test/wiki-ask-provider')->assertStatus(429)->assertJsonPath('error.code', 'ai_capacity_exhausted');
        $this->assertNoProviderCall();
    }

    /**
     * A route under /api named like «Spør Wiki», which makes one real provider call through
     * OpenAiClient in the customer's context — the provider boundary without the retrieval.
     */
    private function asWikiAskRoute(Customer $customer, User $identity, string $operation): void
    {
        Route::middleware('api')->get('api/_test/wiki-ask-provider', fn () => app(AiCallContextScope::class)->within(
            new AiCallContext(customerId: $customer->id, userId: $identity->id),
            fn () => app(OpenAiClient::class)->createResponse(['model' => 'gpt-4.1-mini', 'input' => []], operation: $operation),
        ))->name('api.v1.wiki.ask');
    }

    /** Http also carries the Entra signing keys; only a call to the AI provider counts here. */
    private function assertNoProviderCall(): void
    {
        Http::assertNotSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://openai.test'));
    }

    private function ask(Customer $customer): TestResponse
    {
        return $this->postJson('/api/v1/wiki/ask', ['question' => 'Hva er svartiden?'], $this->asCustomer($this->accessToken(), $customer));
    }

    /** @param array<string, mixed> $attributes */
    private function askingCustomer(string $name = 'Kunde', array $attributes = []): Customer
    {
        $customer = $this->apiCustomer($name.' '.uniqid());
        $customer->forceFill(array_merge(['billing_interval' => Customer::BILLING_MONTHLY, 'included_ai_credits' => 3], $attributes))->save();

        return $customer->fresh();
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $operations
     */
    private function grantAsker(ApiClient $client, Customer $customer, array $permissions = [CustomerPermissionCatalog::WIKI_VIEW], array $operations = [ApiOperationCatalog::WIKI_ASK]): User
    {
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, $permissions);
        $this->grantTo($client, $customer, $identity, ['allowed_operations' => $operations]);

        return $identity;
    }

    /** Both Wiki AI clients, mocked: navigation reads every indexed page, ranking keeps them. */
    private function mockWikiAi(callable $answer): void
    {
        $this->mock(EnterpriseWikiSemanticSearchPlanAiClient::class, fn ($mock) => $mock
            ->shouldReceive('planWikiReading')
            ->andReturnUsing(fn (string $input, array $index): array => [
                'query_understanding' => ['topic' => 'unknown', 'intent' => 'find documented knowledge', 'explicit_entities' => [], 'explicit_services_or_systems' => [], 'scope' => 'unknown'],
                'selected_pages' => array_map(static fn (array $page): array => ['page_id' => $page['page_id'], 'intended_use' => 'primary_evidence', 'reason' => 'Test.'], $index),
                'model' => 'stub/1.0',
            ]));
        $this->mock(WikiQuestionAnswerAiClient::class, fn ($mock) => $mock
            ->shouldReceive('planRetrieval')
            ->andReturnUsing(fn (string $question, array $candidates): array => [
                'question_understanding' => ['topic' => 'unknown', 'question_scope' => 'unknown', 'explicit_entities' => [], 'explicit_services_or_systems' => [], 'question_intent' => 'unknown'],
                'ranked_pages' => array_map(static fn (array $candidate): array => [
                    'page_id' => (int) $candidate['page_id'], 'page_scope' => 'unknown', 'entities' => [], 'services_or_systems' => [],
                    'is_general' => false, 'is_specific' => false, 'retrieval_fit' => 'background', 'reason' => 'Test.',
                ], $candidates),
                'model' => 'stub/1.0',
            ])
            ->getMock()
            ->shouldReceive('answer')
            ->andReturnUsing(fn (string $question, array $context): array => array_merge(['model' => 'stub/1.0'], $answer($context))));
    }

    private function forbidWikiAi(): void
    {
        $this->mock(EnterpriseWikiSemanticSearchPlanAiClient::class)->shouldNotReceive('planWikiReading');
        $this->mock(WikiQuestionAnswerAiClient::class, fn ($mock) => $mock->shouldNotReceive('planRetrieval')->getMock()->shouldNotReceive('answer'));
    }

    private function page(Customer $customer, string $slug, string $title, ?string $publishedMarkdown, string $status = EnterpriseWikiPage::STATUS_APPROVED): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id, 'slug' => $slug, 'title' => $title, 'scope' => EnterpriseWikiPage::SCOPE_COMPANY,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB, 'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        if ($publishedMarkdown !== null) {
            $version = EnterpriseWikiPageVersion::query()->create([
                'enterprise_wiki_page_id' => $page->id, 'version_number' => 1, 'is_current' => true,
                'content_markdown' => $publishedMarkdown, 'generated_by_model' => 'gpt-5',
            ]);
            $page->forceFill(['published_version_id' => $version->id])->save();
        }

        return $page->refresh();
    }

    private function workingVersion(EnterpriseWikiPage $page, string $markdown): void
    {
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => (int) EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->max('version_number') + 1,
            'is_current' => true, 'content_markdown' => $markdown, 'generated_by_model' => 'gpt-5',
        ]);
    }

    private function seedPrices(): void
    {
        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'currency' => 'usd',
            'input_price_per_1m_tokens' => 0.40, 'cached_input_price_per_1m_tokens' => 0.10,
            'output_price_per_1m_tokens' => 1.60, 'valid_from' => now()->subMonth()->toDateString(),
            'is_active' => true, 'last_verified_at' => now(),
        ]);
        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate' => 10.0,
            'rate_date' => now()->subDay()->toDateString(), 'source' => ExchangeRate::SOURCE_NORGES_BANK, 'fetched_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function settledAttempt(Customer $customer, ?float $costNok, array $overrides = []): AiUsageAttempt
    {
        return AiUsageAttempt::query()->create(array_merge([
            'customer_id' => $customer->id, 'attribution' => 'customer', 'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => 'wiki', 'operation_key' => 'wiki.verify_claim', 'provider' => 'openai', 'endpoint' => 'responses',
            'model' => 'gpt-4.1-mini', 'status' => AiUsageAttempt::STATUS_SUCCESS, 'input_tokens' => 100, 'output_tokens' => 10,
            'total_tokens' => 110, 'cost_status' => 'known', 'cost_nok' => $costNok, 'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'started_at' => now(), 'finished_at' => now(),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function wikiState(Customer $customer): array
    {
        $pageIds = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->pluck('id');

        return [
            'pages' => EnterpriseWikiPage::query()->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(fn (EnterpriseWikiPage $page): array => [$page->id, $page->status, $page->published_version_id, $page->updated_at?->toIso8601String()])
                ->all(),
            'versions' => EnterpriseWikiPageVersion::query()->whereIn('enterprise_wiki_page_id', $pageIds)->count(),
        ];
    }
}
