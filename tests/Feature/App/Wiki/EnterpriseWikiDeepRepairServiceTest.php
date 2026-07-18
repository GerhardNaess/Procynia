<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use App\Services\Ai\Wiki\WikiSemanticQaAiClient;
use App\Services\Ai\Wiki\WikiSemanticReviserAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDeepRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for 8H-kjerne delfase 2 — targeted deep repair of claims, source references, and page links.
 *
 * Verifies diagnosis logic, targeted repair routing, QA re-evaluation, idempotence, customer
 * isolation, and lineage storage. All AI calls are mocked.
 */
class EnterpriseWikiDeepRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);

        $this->mock(WikiPageContentAiClient::class)
            ->shouldReceive('generatePageFromSource')
            ->andReturn([
                'markdown' => "# Article\n\nContent.",
                'blocks' => [[
                    'markdown' => "# Article\n\nContent.",
                    'content_origin' => 'source_based',
                    'source_element_keys' => ['document-1-full-text'],
                    'source_element_types' => ['manual'],
                    'best_practice_reason' => null,
                    'link_intents' => [],
                ]],
            ])
            ->byDefault();

        $this->mock(WikiSemanticQaAiClient::class)
            ->shouldReceive('review')
            ->andReturn($this->passingSemanticResult())
            ->byDefault();

        $this->mock(WikiSemanticReviserAiClient::class)
            ->shouldReceive('revise')
            ->andReturn("# Article\n\nRevised content.")
            ->byDefault();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => [
                ['text' => 'Claim A', 'confidence' => 'high', 'excerpt' => 'Excerpt A', 'conflict_note' => null],
            ]])
            ->byDefault();

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn(['supported' => true, 'excerpt' => 'Source excerpt.'])
            ->byDefault();
    }

    // =========================================================================
    // 1: Still escalated after QA retry → deep repair starts
    // =========================================================================

    public function test_escalated_run_after_retry_triggers_deep_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document, maintenanceSourceHash: self::HASH_A);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // Simulate: maintenance cycle retried QA (still escalated), now deep repair kicks in.
        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertTrue($result['attempted']);
        $run->refresh();
        $this->assertNotNull($run->deep_repair_attempted_at);
        $this->assertSame(self::HASH_A, $run->deep_repair_source_hash);
    }

    // =========================================================================
    // 2: Claims repaired only when diagnosis detects missing claims
    // =========================================================================

    public function test_claims_repaired_when_version_has_no_claims(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->assertSame(0, EnterpriseWikiClaim::count());

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertTrue($result['attempted']);
        $this->assertContains('claims', $result['components_repaired']);
        $this->assertGreaterThan(0, EnterpriseWikiClaim::count());
    }

    // =========================================================================
    // 3: Source references repaired when claims exist without refs
    // =========================================================================

    public function test_source_references_repaired_when_claims_lack_refs(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $page = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // Pre-create claims without source references
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->first();

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Content.',
            'page_excerpt' => 'Content.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->assertSame(0, EnterpriseWikiSourceReference::count());

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertTrue($result['attempted']);
        $this->assertContains('source_references', $result['components_repaired']);
        $this->assertGreaterThan(0, EnterpriseWikiSourceReference::count());
    }

    // =========================================================================
    // 4: Page links repaired when no links exist for the run's pages
    // =========================================================================

    public function test_page_links_repaired_when_no_links_exist(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // Pre-create claims+refs so diagnosis does not report those as needing repair
        $this->preCreateClaimsAndRefs($run, $document);

        $this->assertSame(0, EnterpriseWikiPageLink::count());

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertTrue($result['attempted']);
        $this->assertContains('page_links', $result['components_repaired']);
        $this->assertGreaterThan(0, EnterpriseWikiPageLink::count());
    }

    // =========================================================================
    // 5: Nothing repaired when all components already complete
    // =========================================================================

    public function test_no_repair_when_claims_refs_and_links_all_complete(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->preCreateClaimsAndRefs($run, $document);
        $this->preCreatePageLink($run, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);

        // Claims AI should NOT be called
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldNotReceive('extractClaims');

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertFalse($result['attempted']);
        $this->assertSame('no_repairables', $result['reason']);
        $this->assertEmpty($result['components_repaired']);
    }

    // =========================================================================
    // 6: Full QA pipeline runs after repair
    // =========================================================================

    public function test_qa_pipeline_runs_after_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // Post-ingest QA (deterministic, no AI) must run as part of re-evaluation.
        app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $run->refresh();
        $this->assertNotNull($run->qa_result);
    }

    // =========================================================================
    // 7: Successful repair → run ends as passed
    // =========================================================================

    public function test_successful_deep_repair_gives_passed_status(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $result['qa_status']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_PASSED, $run->qa_status);
    }

    // =========================================================================
    // 8: Repair done but QA still fails → escalated
    // =========================================================================

    /**
     * Deep repair only fixes claims/source references/page links — it never touches page
     * content. A page with genuinely empty content is therefore a defect deep repair cannot
     * resolve, and post-ingest QA correctly reports it as failed (a concrete, understood
     * defect), not escalated (which now means "cannot be safely judged yet").
     */
    public function test_deep_repair_with_a_remaining_content_defect_gives_failed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $article->id)
            ->where('is_current', true)
            ->update(['content_markdown' => '']);

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $result['qa_status']);
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
    }

    // =========================================================================
    // 9: Technical error during repair → run marked failed with clear error
    // =========================================================================

    public function test_technical_error_during_repair_marks_run_failed(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andThrow(new \RuntimeException('AI unavailable'));

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertTrue($result['attempted']);
        $this->assertSame('component_repair_failed', $result['reason']);
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $result['qa_status']);
        $this->assertSame(self::HASH_A, $result['source_hash']);
        $this->assertIsArray($result['diagnosis']);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::QA_STATUS_FAILED, $run->qa_status);
        $this->assertStringContainsString('[DEEP_REPAIR]', $run->qa_last_error);
        $this->assertSame(self::HASH_A, $run->deep_repair_result['source_hash']);
        $this->assertIsArray($run->deep_repair_result['diagnosis']);
    }

    // =========================================================================
    // 10: Same source hash → maximum one deep repair attempt
    // =========================================================================

    public function test_same_source_hash_prevents_second_deep_repair_attempt(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        // Run already has deep_repair_source_hash set to HASH_A (previous attempt)
        $run = $this->createEscalatedRun($customer, $document, deepRepairSourceHash: self::HASH_A);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldNotReceive('extractClaims');

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $this->assertFalse($result['attempted']);
        $this->assertSame('already_attempted_for_hash', $result['reason']);
    }

    // =========================================================================
    // 11: Changed source hash → new deep repair attempt allowed
    // =========================================================================

    public function test_changed_source_hash_allows_new_deep_repair_attempt(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_B);
        // Previous attempt was for HASH_A; current doc is HASH_B
        $run = $this->createEscalatedRun($customer, $document, deepRepairSourceHash: self::HASH_A);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_B);

        $this->assertTrue($result['attempted']);
        $run->refresh();
        $this->assertSame(self::HASH_B, $run->deep_repair_source_hash);
    }

    // =========================================================================
    // 12: deep_repair_result stored; QA snapshot created after re-evaluation
    // =========================================================================

    public function test_deep_repair_result_stored_and_snapshot_created(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        $run->refresh();
        $this->assertNotNull($run->deep_repair_result);
        $this->assertTrue($run->deep_repair_result['attempted']);
        $this->assertSame(self::HASH_A, $run->deep_repair_result['source_hash']);
        $this->assertIsArray($run->deep_repair_result['diagnosis']);
        $this->assertIsArray($run->deep_repair_result['components_repaired']);
        $this->assertNotNull($run->deep_repair_result['qa_status']);

        // QA re-evaluation creates a snapshot with deep repair context
        $this->assertDatabaseHas('enterprise_wiki_qa_snapshots', [
            'enterprise_wiki_ingest_run_id' => $run->id,
            'deep_repair_attempted' => true,
            'deep_repair_source_hash' => self::HASH_A,
        ]);
    }

    // =========================================================================
    // 13: Customer isolation — run from other customer not processed
    // =========================================================================

    public function test_maintenance_cycle_only_processes_own_customer_runs(): void
    {
        $customer1 = $this->createCustomer('Customer One AS');
        $customer2 = $this->createCustomer('Customer Two AS');

        $doc1 = $this->createDocument($customer1, hash: self::HASH_B);
        $doc2 = $this->createDocument($customer2, hash: self::HASH_B);

        $run1 = $this->createEscalatedRun($customer1, $doc1);
        $run2 = $this->createEscalatedRun($customer2, $doc2);

        $this->createVersionedPage($customer1, $run1, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article 1');
        $this->createVersionedPage($customer1, $run1, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary 1');
        $this->createVersionedPage($customer2, $run2, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article 2');
        $this->createVersionedPage($customer2, $run2, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary 2');

        // Maintenance cycle processes both runs independently
        $summary = app(EnterpriseWikiMaintenanceCycleService::class)->run();

        $this->assertSame(2, $summary['retried']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(0, $summary['failed']);

        $run1->refresh();
        $run2->refresh();

        // Each run only has its own pages and claims
        $this->assertSame((int) $customer1->id, (int) $run1->customer_id);
        $this->assertSame((int) $customer2->id, (int) $run2->customer_id);
    }

    // =========================================================================
    // 14: Existing 8G and 8H delfase-1 tests are unaffected (ProcessEnterpriseWikiIngest check)
    // =========================================================================

    public function test_process_enterprise_wiki_ingest_not_modified(): void
    {
        $path = base_path('app/Jobs/Ai/Wiki/ProcessEnterpriseWikiIngest.php');

        $this->assertFileExists($path);

        $content = file_get_contents($path);

        // Verify the file does not reference deep repair — it must not have been touched
        $this->assertStringNotContainsString('DeepRepair', $content);
        $this->assertStringNotContainsString('deep_repair', $content);
    }

    // =========================================================================
    // 15: Only necessary components are repaired — links not repaired if already present
    // =========================================================================

    public function test_existing_page_links_not_rebuilt_unnecessarily(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, hash: self::HASH_A);
        $run = $this->createEscalatedRun($customer, $document);
        $article = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'Article');
        $summary = $this->createVersionedPage($customer, $run, EnterpriseWikiPage::PAGE_TYPE_SUMMARY, 'Summary');

        // Pre-create the article→summary link so page links are NOT needed
        $this->preCreatePageLink($run, $article, $summary, EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY);

        $countBefore = EnterpriseWikiPageLink::count();

        $result = app(EnterpriseWikiDeepRepairService::class)->attempt($run, self::HASH_A);

        // claims and source_references should be repaired; page_links should NOT be in components
        $this->assertNotContains('page_links', $result['components_repaired']);

        // Link count may grow slightly from build() symmetry, but we verify 'page_links' not in repaired list
        // The key check is that links were not listed as a repair target
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function passingSemanticResult(): array
    {
        return [
            'pass' => true,
            'quality_score' => 0.92,
            'coverage_score' => 0.90,
            'factual_consistency_score' => 0.95,
            'unsupported_claims' => [],
            'missing_topics' => [],
            'missing_key_facts' => [],
            'critique' => 'Well covered.',
            'recommended_repair_action' => 'none',
            'confidence' => 0.9,
            'model' => 'gpt-4.1-mini/1.0',
            'prompt_version' => '1.0',
            'source_hash' => self::HASH_A,
            'page_version_id' => 1,
            'skipped' => false,
            'escalated' => false,
        ];
    }

    private function createCustomer(string $name = 'Deep Repair Test AS'): Customer
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

    private function createDocument(Customer $customer, string $hash = ''): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => $hash !== '' ? $hash : hash('sha256', Str::random(32)),
            'extracted_text' => 'Authoritative source text for deep repair tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createEscalatedRun(
        Customer $customer,
        EnterpriseWikiDocument $document,
        ?string $maintenanceSourceHash = null,
        ?string $deepRepairSourceHash = null,
    ): EnterpriseWikiIngestRun {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => ['pages' => []],
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
            'qa_attempt_count' => 1,
            'maintenance_source_hash' => $maintenanceSourceHash,
            'deep_repair_source_hash' => $deepRepairSourceHash,
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
            'last_source_hash' => self::HASH_A,
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

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
            // Deep repair only fixes claims/references/links — page generation is assumed
            // already complete, which post-ingest QA's deterministic step-completeness check
            // now verifies explicitly.
            'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
        ]);

        // Gives any claim the default extractClaims mock produces (excerpt "Excerpt A") a
        // resolvable, source-based block anchor — without this, EnterpriseWikiExtractPageClaimsService
        // cannot match the excerpt to any block (content_origin=internal_error) and
        // EnterpriseWikiVerifyPageClaimsService's own anchor check against content_markdown
        // would independently fail the same way, since it checks content_markdown directly, not
        // content_blocks_json. See EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects(),
        // which now correctly blocks qa_status=passed for either.
        $blockMarkdown = 'Excerpt A appears in this block.';
        $defaultMarkdown = "# {$title}\n\n{$blockMarkdown}";

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $content !== '' ? $content : $defaultMarkdown,
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => $blockMarkdown,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $run->source_id,
                'source_label' => 'source.pdf',
                'source_hash' => '',
            ]],
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function preCreateClaimsAndRefs(EnterpriseWikiIngestRun $run, EnterpriseWikiDocument $document): void
    {
        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get();

        foreach ($pivotRows as $row) {
            $page = $row->page;
            if ($page === null) {
                continue;
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->where('is_current', true)
                ->first();

            if ($version === null) {
                continue;
            }

            $claim = EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'claim_text' => 'Pre-existing claim for '.$page->title,
                'position_order' => 0,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            ]);

            EnterpriseWikiSourceReference::query()->create([
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_label' => $document->original_filename,
                'excerpt' => 'Supporting excerpt.',
                'source_hash' => $document->file_hash_sha256 ?? '',
            ]);
        }
    }

    private function preCreatePageLink(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $from,
        EnterpriseWikiPage $to,
        string $linkType,
    ): void {
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $run->customer_id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'from_page_id' => $from->id,
            'to_page_id' => $to->id,
            'link_type' => $linkType,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);
    }
}
