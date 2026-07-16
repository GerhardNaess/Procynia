<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\FinalizeEnterpriseWikiIngest;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiSection;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use App\Services\Ai\Wiki\WikiArticleAiClient;
use App\Services\Ai\Wiki\WikiSectionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentWikiAnswerStalenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class FinalizeEnterpriseWikiIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // -------------------------------------------------------------------------
    // Test 1: Returns without side effect when sections are still pending/running
    // -------------------------------------------------------------------------

    public function test_finalize_returns_without_side_effect_when_sections_still_pending(): void
    {
        ['run' => $run, 'pageVersion' => $pageVersion] = $this->createScaffold(['pending', 'completed']);

        $this->runFinalize($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED, $run->status);
        $pageVersion->refresh();
        $this->assertNull($pageVersion->content_markdown);
        $this->assertFalse($pageVersion->is_current);
    }

    public function test_finalize_returns_without_side_effect_when_section_still_running(): void
    {
        ['run' => $run] = $this->createScaffold(['running']);

        $this->runFinalize($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED, $run->status);
    }

    // -------------------------------------------------------------------------
    // Test 2: Marks run failed when at least one section is failed
    // -------------------------------------------------------------------------

    public function test_finalize_marks_run_failed_when_section_is_failed(): void
    {
        ['run' => $run] = $this->createScaffold(['failed', 'completed']);

        $this->runFinalize($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('1 of 2', (string) $run->error_message);
    }

    // -------------------------------------------------------------------------
    // Test 3: Marks run failed when all sections completed but no claims stored
    // -------------------------------------------------------------------------

    public function test_finalize_marks_run_failed_when_no_claims_exist(): void
    {
        ['run' => $run] = $this->createScaffold(['completed']);
        // No claims created.

        $this->runFinalize($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('No claims', (string) $run->error_message);
    }

    // -------------------------------------------------------------------------
    // Test 4: Writes article markdown (from WikiArticleAiClient) to content_markdown
    // -------------------------------------------------------------------------

    public function test_finalize_writes_article_markdown_to_content_markdown(): void
    {
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);

        $this->createClaim($page, $pageVersion, $run, 'Vi er ISO 9001-sertifisert.', 'ISO 9001 siden 2015.');
        $this->createClaim($page, $pageVersion, $run, 'Vi har 50 ansatte.', 'Antall ansatte: 50.', 1);

        $expectedMarkdown = "## Kvalitet\n\nVi er ISO 9001-sertifisert og har 50 ansatte.";
        $this->mockArticleClient($expectedMarkdown);

        $this->runFinalize($run);

        $pageVersion->refresh();
        $this->assertSame($expectedMarkdown, $pageVersion->content_markdown);
    }

    // -------------------------------------------------------------------------
    // Test 5: Sets run to completed with finished_at
    // -------------------------------------------------------------------------

    public function test_finalize_sets_run_completed_with_finished_at(): void
    {
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);
        $this->createClaim($page, $pageVersion, $run);
        $this->mockArticleClient();

        $this->runFinalize($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_finalize_sets_page_version_is_current_and_page_pending_review(): void
    {
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);
        $this->createClaim($page, $pageVersion, $run);
        $this->mockArticleClient();

        $this->runFinalize($run);

        $pageVersion->refresh();
        $this->assertTrue($pageVersion->is_current);

        $page->refresh();
        $this->assertSame(EnterpriseWikiPage::STATUS_PENDING_REVIEW, $page->status);
    }

    // -------------------------------------------------------------------------
    // Test 6: Idempotent on re-run (returns early if already completed)
    // -------------------------------------------------------------------------

    public function test_finalize_is_idempotent_when_run_already_completed(): void
    {
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);
        $this->createClaim($page, $pageVersion, $run, 'Første krav.', 'Kildesetning.');
        $this->mockArticleClient("## Første\n\nInnhold.");

        $this->runFinalize($run);
        $run->refresh();
        $pageVersion->refresh();

        $firstMarkdown = $pageVersion->content_markdown;
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);

        // Run a second time — must return early without changes.
        $this->runFinalize($run);
        $run->refresh();
        $pageVersion->refresh();

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertSame($firstMarkdown, $pageVersion->content_markdown);
    }

    // -------------------------------------------------------------------------
    // Test 7: Section job dispatches finalize after completed section
    // -------------------------------------------------------------------------

    public function test_section_job_dispatches_finalize_after_completed_section(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);
        $run = $this->createRun($customer, $version);
        $this->createDraftPage($customer, $run);

        $section = EnterpriseWikiIngestSection::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'section_index' => 0,
            'heading' => 'Kompetanse',
            'status' => EnterpriseWikiIngestSection::STATUS_PENDING,
        ]);

        /** @var WikiSectionAiClient&MockInterface $mock */
        $mock = $this->mock(WikiSectionAiClient::class);
        $mock->shouldReceive('fetchClaims')->andReturn(['claims' => [
            ['text' => 'Vi er sertifisert.', 'confidence' => 'high', 'excerpt' => 'Sertifisert siden 2015.'],
        ]]);

        (new ProcessEnterpriseWikiSection($section->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(EnterpriseWikiSectionParser::class),
            $mock,
        );

        Queue::assertPushed(FinalizeEnterpriseWikiIngest::class);
    }

    // -------------------------------------------------------------------------
    // Test 8: Section job dispatches finalize after failed section
    // -------------------------------------------------------------------------

    public function test_section_job_dispatches_finalize_after_failed_section(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);
        $run = $this->createRun($customer, $version);
        $this->createDraftPage($customer, $run);

        $section = EnterpriseWikiIngestSection::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'section_index' => 0,
            'heading' => 'Kompetanse',
            'status' => EnterpriseWikiIngestSection::STATUS_PENDING,
        ]);

        /** @var WikiSectionAiClient&MockInterface $mock */
        $mock = $this->mock(WikiSectionAiClient::class);
        $mock->shouldReceive('fetchClaims')->andThrow(new \RuntimeException('AI utilgjengelig.'));

        try {
            (new ProcessEnterpriseWikiSection($section->id))->handle(
                app(EnterpriseWikiIngestService::class),
                app(EnterpriseWikiSectionParser::class),
                $mock,
            );
        } catch (\RuntimeException) {
            // Expected — Throwable catch in the section job rethrows.
        }

        Queue::assertPushed(FinalizeEnterpriseWikiIngest::class);
    }

    // -------------------------------------------------------------------------
    // Test 9: Finalize calls WikiArticleAiClient, not WikiSectionAiClient
    // -------------------------------------------------------------------------

    public function test_finalize_calls_article_client_not_section_client(): void
    {
        // FinalizeEnterpriseWikiIngest uses WikiArticleAiClient for article synthesis.
        // WikiSectionAiClient (per-section extraction) must not be called by finalize.
        // We mock WikiArticleAiClient and leave WikiSectionAiClient unmocked —
        // any unexpected call to it would cause the test to fail.
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);
        $this->createClaim($page, $pageVersion, $run);
        $this->mockArticleClient();

        $this->runFinalize($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
    }

    // -------------------------------------------------------------------------
    // Test 10: Finalize does not modify KnowledgeBase/RAG tables
    // -------------------------------------------------------------------------

    public function test_finalize_does_not_modify_rag_tables(): void
    {
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion, 'version' => $version]
            = $this->createScaffold(['completed']);
        $this->createClaim($page, $pageVersion, $run);
        $this->mockArticleClient();

        $originalText = $version->extracted_text;
        $originalApproval = $version->approval_status;
        $countBefore = KnowledgeItemVersion::query()->count();

        $this->runFinalize($run);

        $version->refresh();
        $this->assertSame($originalText, $version->extracted_text);
        $this->assertSame($originalApproval, $version->approval_status);
        $this->assertSame($countBefore, KnowledgeItemVersion::query()->count());
        $this->assertDatabaseCount('knowledge_item_chunks', 0);
    }

    // -------------------------------------------------------------------------
    // Test 11: Disabled AI flag → run fails cleanly, no external call, claims intact
    // -------------------------------------------------------------------------

    public function test_finalize_marks_run_failed_when_wiki_ai_is_disabled(): void
    {
        config(['services.enterprise_wiki.ai_enabled' => false]);

        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);
        $this->createClaim($page, $pageVersion, $run);

        // No mockArticleClient() — the real client is injected and the guard
        // in finalize must prevent it from making any external call.
        $this->runFinalize($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('disabled', (string) $run->error_message);
        $this->assertNotNull($run->finished_at);

        // content_markdown must not be touched — no article was generated.
        $pageVersion->refresh();
        $this->assertNull($pageVersion->content_markdown);

        // Claims are intact.
        $this->assertDatabaseCount('enterprise_wiki_claims', 1);
        $this->assertDatabaseCount('enterprise_wiki_source_references', 1);
    }

    // -------------------------------------------------------------------------
    // Test 12: Claims and source references survive article generation
    // -------------------------------------------------------------------------

    public function test_finalize_claims_are_unchanged_after_article_generation(): void
    {
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);
        $claim = $this->createClaim($page, $pageVersion, $run, 'Vi leverer kvalitet.', 'Kildesetning.');
        $this->mockArticleClient();

        $this->runFinalize($run);

        $claim->refresh();
        $this->assertSame('Vi leverer kvalitet.', $claim->claim_text);
        $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $claim->approval_status);
        $this->assertDatabaseCount('enterprise_wiki_claims', 1);
        $this->assertDatabaseCount('enterprise_wiki_source_references', 1);
    }

    // -------------------------------------------------------------------------
    // Test 13: content_markdown not stored when article client throws (validation failure)
    // -------------------------------------------------------------------------

    public function test_finalize_does_not_store_content_markdown_when_article_client_throws(): void
    {
        ['run' => $run, 'page' => $page, 'pageVersion' => $pageVersion] = $this->createScaffold(['completed']);
        $this->createClaim($page, $pageVersion, $run);

        $mock = $this->mock(WikiArticleAiClient::class);
        $mock->shouldReceive('generateArticle')
            ->andThrow(new \RuntimeException('WikiArticleAiClient: generated article contains HTML comments — rejected.'));

        try {
            $this->runFinalize($run);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTML comments', $e->getMessage());
        }

        // Transaction was rolled back — content_markdown must not be set.
        $pageVersion->refresh();
        $this->assertNull($pageVersion->content_markdown);

        // Claims are intact.
        $this->assertDatabaseCount('enterprise_wiki_claims', 1);
        $this->assertDatabaseCount('enterprise_wiki_source_references', 1);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function runFinalize(EnterpriseWikiIngestRun $run): void
    {
        (new FinalizeEnterpriseWikiIngest($run->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(WikiArticleAiClient::class),
            app(EnterpriseWikiDocumentWikiAnswerStalenessService::class),
        );
    }

    private function mockArticleClient(string $markdown = "## Oversikt\n\nGenerert testinnhold."): WikiArticleAiClient
    {
        /** @var WikiArticleAiClient&MockInterface $mock */
        $mock = $this->mock(WikiArticleAiClient::class);
        $mock->shouldReceive('generateArticle')->andReturn($markdown);

        return $mock;
    }

    /**
     * @param  string[]  $sectionStatuses  Status for each section to create (determines which sections exist and their status)
     * @return array{customer: Customer, item: KnowledgeItem, version: KnowledgeItemVersion, run: EnterpriseWikiIngestRun, page: EnterpriseWikiPage, pageVersion: EnterpriseWikiPageVersion, sections: EnterpriseWikiIngestSection[]}
     */
    private function createScaffold(array $sectionStatuses = ['completed']): array
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);
        $run = $this->createRun($customer, $version);
        [$page, $pageVersion] = $this->createDraftPage($customer, $run);

        $sections = [];

        foreach ($sectionStatuses as $i => $status) {
            $sections[] = EnterpriseWikiIngestSection::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'section_index' => $i,
                'heading' => "Seksjon {$i}",
                'status' => $status,
            ]);
        }

        return compact('customer', 'item', 'version', 'run', 'page', 'pageVersion', 'sections');
    }

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $pageVersion,
        EnterpriseWikiIngestRun $run,
        string $claimText = 'Vi leverer kvalitet.',
        string $excerpt = 'Kildesetning.',
        int $order = 0,
    ): EnterpriseWikiClaim {
        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $pageVersion->id,
            'claim_text' => $claimText,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'position_order' => $order,
        ]);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $run->source_id,
            'source_label' => 'kompetanse.docx',
            'source_hash' => $run->source_hash ?? '',
            'excerpt' => $excerpt,
        ]);

        return $claim;
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
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createKnowledgeItem(Customer $customer): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Test Document',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'ai_usage_enabled' => true,
        ]);
    }

    private function createVersion(KnowledgeItem $item, Customer $customer, array $overrides = []): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create(array_merge([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'extracted_text' => "## Kompetanse\nVi leverer ISO 9001-sertifisert service.",
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'),
            'original_filename' => 'kompetanse.docx',
        ], $overrides));
    }

    private function createRun(Customer $customer, KnowledgeItemVersion $version): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id,
            'source_hash' => str_pad('hash', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED,
        ]);
    }

    /**
     * @return array{EnterpriseWikiPage, EnterpriseWikiPageVersion}
     */
    private function createDraftPage(Customer $customer, EnterpriseWikiIngestRun $run): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'wiki-draft-' . $run->id,
            'title' => 'Test Document',
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => $run->source_hash,
        ]);

        $run->update(['enterprise_wiki_page_id' => $page->id]);

        $pageVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
        ]);

        return [$page, $pageVersion];
    }
}
