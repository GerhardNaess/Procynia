<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\FinalizeEnterpriseWikiIngest;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
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
use App\Services\Ai\Wiki\WikiSectionAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end test for the Enterprise Wiki ingest pipeline.
 *
 * Exercises the full flow with mocked AI and no real OpenAI calls:
 *   wiki:ingest command
 *     → ProcessEnterpriseWikiIngest (orchestrator)
 *       → ProcessEnterpriseWikiSection × N (section jobs)
 *         → FinalizeEnterpriseWikiIngest (finalize)
 *
 * All jobs are driven manually (not via the queue worker) so the test
 * can assert intermediate state at each stage.
 */
class EnterpriseWikiIngestFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Full end-to-end flow: from wiki:ingest command to completed run.
     */
    public function test_full_ingest_flow_produces_completed_wiki_page(): void
    {
        // ─── Stage 0: Seed domain objects ────────────────────────────────────
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);

        // Two H2 headings → two sections → two section jobs → two claims.
        $version = $this->createVersion($item, $customer, [
            'extracted_text' => implode("\n\n", [
                "## Kompetanse\nVi leverer ISO 9001-sertifisert service til norske virksomheter.",
                "## Referanser\nVi har levert til Statsbygg, NAV og Equinor.",
            ]),
            'original_filename' => 'selskapsinfo.docx',
        ]);

        $knowledgeItemVersionCountBefore = KnowledgeItemVersion::query()->count();
        $originalExtractedText = $version->extracted_text;

        // Intercept all queue dispatches so jobs run only when we choose.
        Queue::fake();

        // ─── Stage 1: Run the wiki:ingest command ────────────────────────────
        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])->assertSuccessful();

        // Command must create exactly one queued run and dispatch the orchestrator.
        Queue::assertPushed(ProcessEnterpriseWikiIngest::class, 1);

        $run = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customer->id)
            ->where('source_id', $version->id)
            ->first();

        $this->assertNotNull($run, 'Ingest run must be created by the command.');
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_QUEUED, $run->status);
        $this->assertSame(EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION, $run->source_type);

        // ─── Stage 2: Run orchestrator ───────────────────────────────────────
        (new ProcessEnterpriseWikiIngest($run->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(EnterpriseWikiSectionParser::class),
        );

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->enterprise_wiki_page_id, 'Draft wiki page ID must be set on run.');

        // Draft page must exist with correct initial state.
        $page = EnterpriseWikiPage::query()->find($run->enterprise_wiki_page_id);
        $this->assertNotNull($page);
        $this->assertSame(EnterpriseWikiPage::STATUS_DRAFT, $page->status);
        $this->assertSame(EnterpriseWikiPage::GENERATED_BY_AI_JOB, $page->generated_by);

        // Draft page version must exist with is_current=false.
        $pageVersion = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('version_number', 1)
            ->first();
        $this->assertNotNull($pageVersion, 'Draft page version must be created.');
        $this->assertFalse($pageVersion->is_current);
        $this->assertNull($pageVersion->content_markdown);

        // Two sections must be planned (one per H2 heading).
        $sections = EnterpriseWikiIngestSection::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->orderBy('section_index')
            ->get();

        $this->assertCount(2, $sections);
        $this->assertSame('Kompetanse', $sections[0]->heading);
        $this->assertSame('Referanser', $sections[1]->heading);
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_PENDING, $sections[0]->status);
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_PENDING, $sections[1]->status);

        // Orchestrator must have dispatched one section job per section.
        Queue::assertPushed(ProcessEnterpriseWikiSection::class, 2);

        // ─── Stage 3: Run section jobs with mocked AI ─────────────────────
        // WikiSectionAiClient::fetchClaims() throws in production — the mock
        // proves no real OpenAI call is made.
        /** @var WikiSectionAiClient&MockInterface $aiMock */
        $aiMock = $this->mock(WikiSectionAiClient::class);
        $aiMock->shouldReceive('fetchClaims')
            ->twice()
            ->andReturn(['claims' => [
                [
                    'text' => 'Vi er ISO 9001-sertifisert.',
                    'confidence' => 'high',
                    'excerpt' => 'ISO 9001-sertifisert service til norske virksomheter.',
                ],
            ]]);

        foreach ($sections as $section) {
            (new ProcessEnterpriseWikiSection($section->id))->handle(
                app(EnterpriseWikiIngestService::class),
                app(EnterpriseWikiSectionParser::class),
                $aiMock,
            );
        }

        // Both sections must be completed.
        $sections->each(fn ($s) => $s->refresh());
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_COMPLETED, $sections[0]->status);
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_COMPLETED, $sections[1]->status);

        // Two claims (one per section) and two source references must exist.
        $this->assertDatabaseCount('enterprise_wiki_claims', 2);
        $this->assertDatabaseCount('enterprise_wiki_source_references', 2);

        // All claims must be in pending state — not auto-approved.
        $claims = EnterpriseWikiClaim::query()->orderBy('id')->get();
        foreach ($claims as $claim) {
            $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $claim->approval_status);
        }

        // Source references must point to the correct knowledge item version.
        $refs = EnterpriseWikiSourceReference::query()->orderBy('id')->get();
        foreach ($refs as $ref) {
            $this->assertSame(EnterpriseWikiSourceReference::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION, $ref->source_type);
            $this->assertSame($version->id, $ref->source_id);
            $this->assertSame('selskapsinfo.docx', $ref->source_label);
            $this->assertNotEmpty($ref->excerpt);
        }

        // Section jobs must have dispatched finalize.
        Queue::assertPushed(FinalizeEnterpriseWikiIngest::class);

        // ─── Stage 4: Run finalize ────────────────────────────────────────────
        (new FinalizeEnterpriseWikiIngest($run->id))->handle(
            app(EnterpriseWikiIngestService::class),
        );

        // ─── Stage 5: Assert final state ─────────────────────────────────────
        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->finished_at);

        $pageVersion->refresh();
        $this->assertTrue($pageVersion->is_current, 'Page version must become is_current after finalize.');
        $this->assertNotNull($pageVersion->content_markdown, 'content_markdown must be assembled.');
        $this->assertStringContainsString('Vi er ISO 9001-sertifisert.', $pageVersion->content_markdown);
        $this->assertStringContainsString('selskapsinfo.docx', $pageVersion->content_markdown);

        $page->refresh();
        $this->assertSame(
            EnterpriseWikiPage::STATUS_PENDING_REVIEW,
            $page->status,
            'Page must advance to pending_review — not approved.',
        );

        // Claims must still be pending after finalize (human approval step is separate).
        $claims->each(fn ($c) => $c->refresh());
        foreach ($claims as $claim) {
            $this->assertSame(EnterpriseWikiClaim::APPROVAL_STATUS_PENDING, $claim->approval_status);
        }
    }

    /**
     * Verifies that the ingest pipeline is completely isolated from the
     * KnowledgeBase/RAG layer: no KnowledgeItemVersion rows are created,
     * modified, or deleted; no KnowledgeItemChunk rows are created.
     */
    public function test_ingest_flow_does_not_modify_rag_tables(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);

        $originalText = $version->extracted_text;
        $originalApproval = $version->approval_status;
        $countBefore = KnowledgeItemVersion::query()->count();

        Queue::fake();

        $this->artisan('wiki:ingest', [
            '--customer' => (string) $customer->id,
            '--version-id' => (string) $version->id,
        ])->assertSuccessful();

        $run = EnterpriseWikiIngestRun::query()->where('source_id', $version->id)->first();

        // Orchestrator
        (new ProcessEnterpriseWikiIngest($run->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(EnterpriseWikiSectionParser::class),
        );

        // Section jobs
        /** @var WikiSectionAiClient&MockInterface $aiMock */
        $aiMock = $this->mock(WikiSectionAiClient::class);
        $aiMock->shouldReceive('fetchClaims')->andReturn(['claims' => [
            ['text' => 'Test krav.', 'confidence' => 'medium', 'excerpt' => 'Kildesetning.'],
        ]]);

        $sections = EnterpriseWikiIngestSection::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->get();

        foreach ($sections as $section) {
            (new ProcessEnterpriseWikiSection($section->id))->handle(
                app(EnterpriseWikiIngestService::class),
                app(EnterpriseWikiSectionParser::class),
                $aiMock,
            );
        }

        // Finalize
        (new FinalizeEnterpriseWikiIngest($run->id))->handle(
            app(EnterpriseWikiIngestService::class),
        );

        // KnowledgeItemVersion unchanged
        $version->refresh();
        $this->assertSame($originalText, $version->extracted_text);
        $this->assertSame($originalApproval, $version->approval_status);

        // No KnowledgeItemVersion rows added or removed
        $this->assertSame($countBefore, KnowledgeItemVersion::query()->count());

        // No KnowledgeItemChunk rows created
        $this->assertDatabaseCount('knowledge_item_chunks', 0);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Testbedrift AS'): Customer
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
            'title' => 'Selskapsinfo',
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
            'extracted_text' => "## Kompetanse\nVi leverer sertifisert service.",
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'),
            'original_filename' => 'selskapsinfo.docx',
        ], $overrides));
    }
}
