<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\Ai\Wiki\WikiSemanticQaAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiSemanticQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for semantic QA (8G-4):
 * - EnterpriseWikiSemanticQaService (direct)
 *
 * EnterpriseWikiPostIngestQaService was redesigned into a minimal deterministic end check and
 * no longer integrates with this service at all — semantic QA is now only exercised via
 * EnterpriseWikiQaRegressionService (see EnterpriseWikiPostIngestQaServiceTest for the
 * orchestrator's own deterministic-logic tests).
 */
class EnterpriseWikiSemanticQaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable AI so semantic QA layer is active by default in these tests.
        config(['services.enterprise_wiki.ai_enabled' => true]);

        // Suppress any accidental calls to the real page content generator.
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generateFromSource')
            ->andReturn("# Generated\n\nContent.")
            ->byDefault();
    }

    // =========================================================================
    // EnterpriseWikiSemanticQaService — direct tests
    // =========================================================================

    public function test_escalates_when_source_document_not_found(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, sourceId: 999999);

        $result = $this->semanticQaService()->review($run);

        $this->assertFalse($result['pass']);
        $this->assertTrue($result['escalated'] ?? false);
        $this->assertSame('source_document_not_found', $result['reason']);
        $this->assertSame('escalate', $result['recommended_repair_action']);
    }

    public function test_escalates_when_source_text_is_empty(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, extractedText: '');

        $result = $this->semanticQaService()->review($run);

        $this->assertFalse($result['pass']);
        $this->assertTrue($result['escalated'] ?? false);
        $this->assertSame('source_text_empty', $result['reason']);
    }

    public function test_escalates_when_article_version_not_found(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        // No pages added to the run → article version cannot be found.

        $result = $this->semanticQaService()->review($run);

        $this->assertFalse($result['pass']);
        $this->assertTrue($result['escalated'] ?? false);
        $this->assertSame('article_version_not_found', $result['reason']);
    }

    public function test_skips_for_knowledge_item_version_source_type(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, sourceType: EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION);

        $result = $this->semanticQaService()->review($run);

        // Skipped — not escalated; the run can still pass on tech/structural grounds.
        $this->assertTrue($result['pass']);
        $this->assertTrue($result['skipped'] ?? false);
        $this->assertSame('source_type_not_supported', $result['reason']);
        $this->assertSame('none', $result['recommended_repair_action']);
    }

    public function test_review_calls_ai_with_source_and_generated_content(): void
    {
        $customer = $this->createCustomer();
        $extractedText = 'This is the authoritative source document text.';
        $articleContent = "# Article\n\nGenerated article content here.";

        $run = $this->createAppliedRun($customer, extractedText: $extractedText);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article', $articleContent);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $capturedSource = null;
        $capturedContent = null;

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')
            ->andReturn(true)
            ->byDefault()
            ->getMock()
            ->shouldReceive('review')
            ->once()
            ->withArgs(function (string $source, string $content) use (&$capturedSource, &$capturedContent): bool {
                $capturedSource = $source;
                $capturedContent = $content;

                return true;
            })
            ->andReturn($this->passingAiResult());

        $this->semanticQaService()->review($run);

        $this->assertSame($extractedText, $capturedSource);
        $this->assertSame($articleContent, $capturedContent);
    }

    public function test_review_stores_source_hash_and_page_version_id(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $page = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $expectedVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->first();

        $document = EnterpriseWikiDocument::find($run->source_id);
        $expectedHash = $document->file_hash_sha256;

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')->andReturn($this->passingAiResult());

        $result = $this->semanticQaService()->review($run);

        $this->assertSame($expectedHash, $result['source_hash']);
        $this->assertSame($expectedVersion->id, $result['page_version_id']);
    }

    public function test_review_does_not_create_or_modify_page_version(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $versionCountBefore = EnterpriseWikiPageVersion::query()->count();

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')->andReturn($this->passingAiResult());

        $this->semanticQaService()->review($run);

        $this->assertSame($versionCountBefore, EnterpriseWikiPageVersion::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function semanticQaService(): EnterpriseWikiSemanticQaService
    {
        return app(EnterpriseWikiSemanticQaService::class);
    }

    private function passingAiResult(): array
    {
        return [
            'pass' => true,
            'quality_score' => 0.9,
            'coverage_score' => 0.88,
            'factual_consistency_score' => 0.95,
            'unsupported_claims' => [],
            'missing_topics' => [],
            'missing_key_facts' => [],
            'critique' => 'Content accurately represents the source.',
            'recommended_repair_action' => 'none',
            'confidence' => 0.92,
            'model' => 'gpt-4.1-mini/1.0',
            'prompt_version' => '1.0',
        ];
    }

    private function createCustomer(string $name = 'SemanticQA Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(
        Customer $customer,
        string $extractedText = 'Authoritative source document text for semantic QA.',
    ): EnterpriseWikiDocument {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $extractedText,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(
        Customer $customer,
        ?string $qaStatus = null,
        ?int $sourceId = null,
        ?string $extractedText = null,
        string $sourceType = EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
    ): EnterpriseWikiIngestRun {
        if ($sourceId === null) {
            $document = $this->createDocument($customer, $extractedText ?? 'Authoritative source document text for semantic QA.');
            $sourceId = $document->id;
        }

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => $qaStatus,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
        string $content = '',
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content !== '' ? $content : "# {$title}\n\nContent.",
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }
}
