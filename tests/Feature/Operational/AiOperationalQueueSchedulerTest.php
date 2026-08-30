<?php

namespace Tests\Feature\Operational;

use App\Exceptions\Ai\AiCostControlException;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiSection;
use App\Models\AiModelPrice;
use App\Models\AiRuntimeControl;
use App\Models\Customer;
use App\Models\CustomerAiOperationalLimit;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\ExchangeRate;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use App\Services\Ai\Wiki\WikiSectionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\OpenAi\OpenAiClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Queue and scheduler run the same guard as everything else, immediately before the provider call.
 * A budget or payment state that changed after dispatch has to be respected, not the snapshot the
 * job was queued with.
 */
class AiOperationalQueueSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
        config()->set('services.enterprise_wiki.ai_enabled', true);
        $this->seedPricing();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_queue_job_stops_at_a_budget_that_filled_up_after_dispatch(): void
    {
        Queue::fake();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        ['customer' => $customer, 'section' => $section] = $this->wikiSectionScaffold();

        // The job was queued while there was budget; the ceiling was reached before it ran.
        $this->customerLimit($customer, daily: 0.01);

        try {
            $this->runSectionJob($section);
            $this->fail('The job must refuse to call the provider.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::CUSTOMER_DAILY_BUDGET_EXHAUSTED, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_a_queue_job_stops_at_a_global_budget_reached_after_dispatch(): void
    {
        Queue::fake();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        ['section' => $section] = $this->wikiSectionScaffold();

        $this->globalLimit(daily: 0.01);

        try {
            $this->runSectionJob($section);
            $this->fail('The job must refuse to call the provider.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::GLOBAL_DAILY_BUDGET_EXHAUSTED, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_the_same_job_reaches_the_provider_when_the_budget_allows_it(): void
    {
        Queue::fake();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        ['customer' => $customer, 'section' => $section] = $this->wikiSectionScaffold();
        $this->customerLimit($customer, daily: 100000.0);

        try {
            $this->runSectionJob($section);
        } catch (\Throwable) {
            // The faked envelope is not a usable claims response; only the outbound call matters.
        }

        Http::assertSentCount(1);
    }

    public function test_scheduled_wiki_maintenance_stops_at_a_global_budget(): void
    {
        Queue::fake();
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        $customer = $this->customer();
        $run = $this->escalatedRun($customer);

        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('runForRun')
            ->andReturnUsing(fn (): mixed => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]));

        $enabled = app(EnterpriseWikiMaintenanceCycleService::class)->run();
        $this->assertSame(1, $enabled['retried']);
        Http::assertSentCount(1);

        $this->globalLimit(daily: 0.01);
        $run->update(['maintenance_source_hash' => null, 'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED]);

        $blocked = app(EnterpriseWikiMaintenanceCycleService::class)->run();

        $this->assertSame(1, $blocked['failed']);
        Http::assertSentCount(1);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function runSectionJob(EnterpriseWikiIngestSection $section): void
    {
        (new ProcessEnterpriseWikiSection($section->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(EnterpriseWikiSectionParser::class),
            app(WikiSectionAiClient::class),
        );
    }

    /** @return array{customer: Customer, section: EnterpriseWikiIngestSection} */
    private function wikiSectionScaffold(): array
    {
        $customer = $this->customer();
        $item = KnowledgeItem::query()->create([
            'customer_id' => $customer->id, 'title' => 'Test Document',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY, 'ai_usage_enabled' => true,
        ]);
        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id, 'customer_id' => $customer->id, 'version_no' => 1,
            'is_current' => true, 'extracted_text' => "## Kompetanse\nVi leverer ISO 9001-sertifisert service.",
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'), 'original_filename' => 'kompetanse.docx',
        ]);
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id, 'source_hash' => str_pad('hash', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED,
        ]);
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id, 'slug' => 'wiki-draft-'.$run->id, 'title' => 'Test Document',
            'status' => EnterpriseWikiPage::STATUS_DRAFT, 'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => $run->source_hash,
        ]);
        $run->update(['enterprise_wiki_page_id' => $page->id]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id, 'version_number' => 1, 'is_current' => false,
        ]);
        $section = EnterpriseWikiIngestSection::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id, 'section_index' => 0, 'heading' => 'Kompetanse',
            'status' => EnterpriseWikiIngestSection::STATUS_PENDING,
        ]);

        return ['customer' => $customer, 'section' => $section];
    }

    private function escalatedRun(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id, 'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text.', 'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(), 'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id, 'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(), 'maintainer_decision_json' => ['pages' => []],
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ]);
    }

    private function customerLimit(Customer $customer, float $daily): void
    {
        CustomerAiOperationalLimit::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            ['is_enabled' => true, 'daily_nok_limit' => $daily],
        );
    }

    private function globalLimit(float $daily): void
    {
        AiRuntimeControl::query()->orderBy('id')->firstOrFail()->forceFill([
            'operational_budget_enabled' => true, 'global_daily_nok_limit' => $daily,
        ])->save();
    }

    private function seedPricing(): void
    {
        // Every model these flows can reach needs a price, or the unknown-price stop fires first
        // and the budget guard under test is never exercised.
        foreach ([
            'gpt-5',
            (string) config('services.openai.model', 'gpt-4.1-mini'),
            (string) config('services.openai.embedding_model', 'text-embedding-3-small'),
        ] as $model) {
            AiModelPrice::query()->firstOrCreate(
                ['provider' => 'openai', 'model' => $model],
                [
                    'currency' => 'USD',
                    'input_price_per_1m_tokens' => 10.0, 'output_price_per_1m_tokens' => 30.0,
                    'valid_from' => '2026-01-01', 'is_active' => true, 'last_verified_at' => '2026-09-14 00:00:00',
                ],
            );
        }
        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate' => 10.0,
            'rate_date' => '2026-09-15', 'source' => ExchangeRate::SOURCE_NORGES_BANK, 'fetched_at' => now(),
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'QueueBudget '.Str::random(8),
            'slug' => 'queuebudget-'.Str::lower(Str::random(10)),
            'language_id' => $language->id, 'nationality_id' => $nationality->id, 'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 10,
        ]);
    }
}
