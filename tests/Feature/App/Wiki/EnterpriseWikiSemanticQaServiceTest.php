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
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiSemanticQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for semantic QA (8G-4):
 * - EnterpriseWikiSemanticQaService (direct)
 * - Integration via EnterpriseWikiPostIngestQaService
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
    // Integration via EnterpriseWikiPostIngestQaService
    // =========================================================================

    public function test_structural_failure_does_not_invoke_semantic_qa(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        // No pages → article missing → structural failure.

        // Semantic QA client must NOT be called.
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->never();

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_semantic_pass_gives_qa_status_passed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')->andReturn($this->passingAiResult());

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_repair_action_targeted_revision_triggers_semantic_repair_and_passes(): void
    {
        // With 8G-5, targeted_revision triggers automatic repair — repair_required never lands in DB.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->andReturn("# Article\n\nRevised content covering all missing topics.");

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(
                    action: 'targeted_revision',
                    missingTopics: ['Section A', 'Section B'],
                    unsupportedClaims: [],
                ),
                $this->passingAiResult(),
            );

        $result = $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertTrue($result['semantic_repair_attempted']);
        $this->assertTrue($result['semantic_repair_result']['success']);
    }

    public function test_unsupported_claims_trigger_semantic_repair_and_pass(): void
    {
        // With 8G-5, unsupported claims with targeted_revision action trigger automatic repair.
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->once()
            ->andReturn("# Article\n\nRevised content with unsupported claims removed.");

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')
            ->twice()
            ->andReturn(
                $this->failingAiResult(
                    action: 'targeted_revision',
                    missingTopics: [],
                    unsupportedClaims: ['The system costs €1 000 per user.'],
                ),
                $this->passingAiResult(),
            );

        $result = $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertTrue($result['semantic_repair_attempted']);
    }

    public function test_escalate_action_gives_escalated_status(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')->andReturn(
                $this->failingAiResult(action: 'escalate', missingTopics: [], unsupportedClaims: [])
            );

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_structured_diagnosis_stored_under_semantic_qa_key(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $aiResult = $this->passingAiResult();

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')->andReturn($aiResult);

        $result = $this->orchestrator()->runForRun($run);

        $this->assertArrayHasKey('semantic_qa', $result);
        $semanticQa = $result['semantic_qa'];

        // All structured fields must be present.
        $this->assertTrue($semanticQa['pass']);
        $this->assertIsFloat($semanticQa['quality_score']);
        $this->assertIsFloat($semanticQa['coverage_score']);
        $this->assertIsFloat($semanticQa['factual_consistency_score']);
        $this->assertIsArray($semanticQa['unsupported_claims']);
        $this->assertIsArray($semanticQa['missing_topics']);
        $this->assertIsArray($semanticQa['missing_key_facts']);
        $this->assertIsString($semanticQa['critique']);
        $this->assertIsString($semanticQa['recommended_repair_action']);
        $this->assertIsFloat($semanticQa['confidence']);
        $this->assertArrayHasKey('model', $semanticQa);
        $this->assertArrayHasKey('source_hash', $semanticQa);
        $this->assertArrayHasKey('page_version_id', $semanticQa);
        $this->assertNotNull($semanticQa['source_hash']);
        $this->assertNotNull($semanticQa['page_version_id']);

        // Verify it is also persisted to the DB.
        $run->refresh();
        $persisted = $run->qa_result['semantic_qa'] ?? null;
        $this->assertNotNull($persisted);
        $this->assertTrue($persisted['pass']);
    }

    public function test_ai_not_enabled_gives_failed_not_passed(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // AI client must not be called at all.
        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->never();

        $this->orchestrator()->runForRun($run);

        $run->refresh();
        // Semantic QA is required — disabled AI must not yield 'passed'.
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        $this->assertNotEmpty($run->qa_last_error);
        $this->assertStringContainsString('Semantic QA', $run->qa_last_error);
        $this->assertNull($run->qa_result['semantic_qa'] ?? null);
    }

    public function test_passed_requires_semantic_qa_result_in_qa_result(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')->andReturn($this->passingAiResult());

        $result = $this->orchestrator()->runForRun($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        // 'passed' requires a stored semantic_qa entry.
        $this->assertNotNull($result['semantic_qa'] ?? null);
        $this->assertNotNull($run->qa_result['semantic_qa'] ?? null);
        $this->assertTrue($run->qa_result['semantic_qa']['pass']);
    }

    public function test_retry_flag_still_processes_escalated_run_with_semantic_qa_enabled(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('isAvailable')->andReturn(true)
            ->getMock()
            ->shouldReceive('review')->andReturn($this->passingAiResult());

        $result = $this->orchestrator()->runForRun($run, retry: true);

        $this->assertNotNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_escalated_run_without_retry_is_skipped(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $result = $this->orchestrator()->runForRun($run, retry: false);

        $this->assertNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function orchestrator(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

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

    private function failingAiResult(
        string $action,
        array $missingTopics,
        array $unsupportedClaims,
    ): array {
        return [
            'pass' => false,
            'quality_score' => 0.45,
            'coverage_score' => 0.40,
            'factual_consistency_score' => 0.80,
            'unsupported_claims' => $unsupportedClaims,
            'missing_topics' => $missingTopics,
            'missing_key_facts' => [],
            'critique' => 'Several key topics are missing from the generated content.',
            'recommended_repair_action' => $action,
            'confidence' => 0.85,
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
