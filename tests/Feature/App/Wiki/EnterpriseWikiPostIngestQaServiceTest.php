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
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiPostIngestQaServiceTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_MARKDOWN = "# Article\n\nGenerated content.";

    protected function setUp(): void
    {
        parent::setUp();

        // Semantic QA (8G-4) is required for 'passed'. Enable AI and provide a
        // default passing result so 8G-3 structural QA tests are unaffected.
        config(['services.enterprise_wiki.ai_enabled' => true]);

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn([
                'pass'                      => true,
                'quality_score'             => 0.9,
                'coverage_score'            => 0.88,
                'factual_consistency_score' => 0.95,
                'unsupported_claims'        => [],
                'missing_topics'            => [],
                'missing_key_facts'         => [],
                'critique'                  => 'Default passing result for structural QA tests.',
                'recommended_repair_action' => 'none',
                'confidence'                => 0.92,
                'model'                     => 'gpt-4.1-mini/1.0',
                'prompt_version'            => '1.0',
            ])
            ->byDefault();

        // Prevent any real AI calls during repair attempts.
        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generateFromSource')
            ->andReturn(self::FAKE_MARKDOWN)
            ->byDefault();
    }

    // =========================================================================
    // Guard: run must be applied
    // =========================================================================

    public function test_throws_when_run_is_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createRun($customer, maintainerStatus: EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/only 'applied'/");

        $this->service()->runForRun($run);
    }

    // =========================================================================
    // Idempotency: skip when already running or passed
    // =========================================================================

    public function test_returns_null_when_already_passed(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $result = $this->service()->runForRun($run);

        $this->assertNull($result);
    }

    public function test_returns_null_when_already_running(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING);

        $result = $this->service()->runForRun($run);

        $this->assertNull($result);
    }

    // =========================================================================
    // Happy path: passed
    // =========================================================================

    public function test_sets_passed_when_article_and_summary_have_content(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $this->assertTrue($result['checks']['article_exists']);
        $this->assertTrue($result['checks']['summary_exists']);
        $this->assertTrue($result['checks']['article_has_content']);
        $this->assertTrue($result['checks']['summary_has_content']);
        $this->assertFalse($result['repair_attempted']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
        $this->assertNotNull($run->qa_completed_at);
        $this->assertSame(1, $run->qa_attempt_count);
    }

    public function test_stores_qa_result_json(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->service()->runForRun($run);

        $run->refresh();

        $this->assertIsArray($run->qa_result);
        $this->assertArrayHasKey('checks', $run->qa_result);
        $this->assertArrayHasKey('coverage_summary', $run->qa_result);
        $this->assertArrayHasKey('lint_summary', $run->qa_result);
    }

    // =========================================================================
    // Escalated: critical gap, repair fails to fix it
    // =========================================================================

    public function test_escalated_when_article_page_missing(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        // Only summary — no article page in run_pages.
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $this->assertFalse($result['checks']['article_exists']);
        $this->assertTrue($result['repair_attempted']);

        $run->refresh();
        // Repair cannot create a new page in run_pages → still escalated.
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_escalated_when_summary_page_missing(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        // No summary in run_pages.

        $result = $this->service()->runForRun($run);

        $this->assertFalse($result['checks']['summary_exists']);
        $this->assertTrue($result['repair_attempted']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_escalated_when_article_has_empty_content(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->addPageToRun($run, $article);
        // Version with empty content.
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => '',
            'generated_by_model'      => 'gpt-5',
        ]);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = $this->service()->runForRun($run);

        // Article page exists structurally, but content is empty.
        $this->assertTrue($result['checks']['article_exists']);
        $this->assertFalse($result['checks']['article_has_content']);
        $this->assertTrue($result['repair_attempted']);

        $run->refresh();
        // Generate service skips pages that already have a version → repair cannot fix this.
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    // =========================================================================
    // Repair succeeds: article missing, generate creates it, re-check passes
    // =========================================================================

    public function test_passed_after_repair_fills_missing_version(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        // Article page is in run_pages but has NO version (generate will create one).
        $article = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->addPageToRun($run, $article);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // After repair, article will have a version with content → should pass.
        $result = $this->service()->runForRun($run);

        $this->assertTrue($result['repair_attempted']);
        $this->assertTrue($result['repair_result']['success'] ?? false);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // Attempt count increments on re-run
    // =========================================================================

    public function test_increments_attempt_count_on_each_run(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->service()->runForRun($run);

        $run->refresh();
        $this->assertSame(1, $run->qa_attempt_count);
    }

    // =========================================================================
    // Customer scope: other customer's runs not affected
    // =========================================================================

    public function test_does_not_affect_other_customers_runs(): void
    {
        $customerA = $this->createCustomer('A');
        $customerB = $this->createCustomer('B');

        $runA = $this->createAppliedRun($customerA);
        $runB = $this->createAppliedRun($customerB);

        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'A Article');
        $this->createVersionedPage($customerA, $runA, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'A Summary');

        $this->service()->runForRun($runA);

        $runB->refresh();
        $this->assertNull($runB->qa_status, 'Run for other customer must not be touched.');
    }

    // =========================================================================
    // findPendingRuns
    // =========================================================================

    public function test_find_pending_runs_returns_null_qa_runs(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: null);

        $pending = $this->service()->findPendingRuns();

        $this->assertTrue($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_passed(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_running(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_RUNNING);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_failed(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    public function test_find_pending_runs_excludes_escalated(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $pending = $this->service()->findPendingRuns();

        $this->assertFalse($pending->contains('id', $run->id));
    }

    // =========================================================================
    // Retry mode: failed and escalated require explicit $retry = true
    // =========================================================================

    public function test_default_mode_skips_failed_run(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = $this->service()->runForRun($run);

        $this->assertNull($result, 'Failed run must not be claimed without --retry.');
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    public function test_default_mode_skips_escalated_run(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = $this->service()->runForRun($run);

        $this->assertNull($result, 'Escalated run must not be claimed without --retry.');
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_retry_mode_can_run_failed_run(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = $this->service()->runForRun($run, retry: true);

        $this->assertNotNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_retry_mode_can_run_escalated_run(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = $this->service()->runForRun($run, retry: true);

        $this->assertNotNull($result);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    public function test_find_retryable_runs_includes_failed_and_escalated(): void
    {
        $customer = $this->createCustomer();
        $runF     = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED);
        $runE     = $this->createAppliedRun($customer, qaStatus: EnterpriseWikiIngestRun::QA_STATUS_ESCALATED);

        $retryable = $this->service()->findRetryableRuns();

        $this->assertTrue($retryable->contains('id', $runF->id));
        $this->assertTrue($retryable->contains('id', $runE->id));
    }

    // =========================================================================
    // Lint errors block passed; warnings do not
    // =========================================================================

    public function test_escalated_when_lint_error_blocks_passed(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        // Article with content + a claim whose source reference points to a non-existent document.
        // This produces a source_reference_without_document ERROR during lint.
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $version = $article->versions()->where('is_current', true)->first();
        $claim   = \App\Models\EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id'         => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text'                      => 'Test claim.',
            'position_order'                  => 1,
            'confidence'                      => \App\Models\EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status'                 => \App\Models\EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        \App\Models\EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type'              => \App\Models\EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                => 999999, // Non-existent document.
            'source_label'             => 'Missing doc',
            'excerpt'                  => 'some excerpt',
        ]);

        $result = $this->service()->runForRun($run);

        // Content checks pass, but lint error blocks passed.
        $this->assertNotNull($result);
        $this->assertTrue($result['checks']['article_has_content']);
        $this->assertTrue($result['checks']['summary_has_content']);
        $this->assertTrue($result['open_lint_errors']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_ESCALATED, $run->qa_status);
    }

    public function test_passed_when_only_lint_warnings_exist(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);

        // Article with content + a claim with no source reference → warning, not error.
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $version = $article->versions()->where('is_current', true)->first();
        \App\Models\EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id'         => $article->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text'                      => 'Test claim with no source.',
            'position_order'                  => 1,
            'confidence'                      => \App\Models\EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'approval_status'                 => \App\Models\EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
        // No source references on the claim → claim_missing_source WARNING is created.

        $result = $this->service()->runForRun($run);

        $this->assertNotNull($result);
        $this->assertFalse($result['open_lint_errors'], 'Warnings must not block passed.');

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // ProcessEnterpriseWikiIngest is untouched
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_is_not_modified(): void
    {
        $path = app_path('Jobs/Ai/Wiki/ProcessEnterpriseWikiIngest.php');
        $hash = md5_file($path);

        $customer = $this->createCustomer();
        $run      = $this->createAppliedRun($customer);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'A');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'S');

        $this->service()->runForRun($run);

        $this->assertSame($hash, md5_file($path), 'ProcessEnterpriseWikiIngest must not be modified.');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiPostIngestQaService
    {
        return app(EnterpriseWikiPostIngestQaService::class);
    }

    private function createCustomer(string $name = 'Test AS'): Customer
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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id'       => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path'         => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'extracted_text'    => 'Source document text for QA tests.',
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRun(Customer $customer, string $maintainerStatus): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => $maintainerStatus,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createAppliedRun(Customer $customer, ?string $qaStatus = null): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json'         => ['pages' => []],
            'qa_status'                        => $qaStatus,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'title'            => $title,
            'page_type'        => $pageType,
            'status'           => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    private function createVersionedPage(
        Customer $customer,
        EnterpriseWikiIngestRun $run,
        string $pageType,
        string $title,
    ): EnterpriseWikiPage {
        $page = $this->createPage($customer, $pageType, $title);
        $this->addPageToRun($run, $page);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => "# {$title}\n\nContent.",
            'generated_by_model'      => 'gpt-5',
        ]);

        return $page;
    }
}
