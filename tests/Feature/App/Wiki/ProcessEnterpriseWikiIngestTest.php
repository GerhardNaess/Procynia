<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiIngest;
use App\Jobs\Ai\Wiki\ProcessEnterpriseWikiSection;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestSection;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\EnterpriseWikiIngestService;
use App\Services\Ai\Wiki\EnterpriseWikiSectionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessEnterpriseWikiIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent section jobs from running synchronously in these orchestrator tests.
        Queue::fake();
    }

    // --- Happy path ---

    public function test_job_transitions_run_from_queued_to_sections_planned(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, [
            'extracted_text' => "## Seksjon 1\nNoe innhold.\n\n## Seksjon 2\nMer innhold.",
        ]);
        $run = $this->createQueuedRun($customer, $version);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->enterprise_wiki_page_id);
    }

    public function test_job_creates_section_rows_matching_text_headings(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, [
            'extracted_text' => "## Kompetanse\nVi har bred kompetanse.\n\n## Referanser\nSe vedlegg.",
        ]);
        $run = $this->createQueuedRun($customer, $version);

        $this->runJob($run);

        $sections = EnterpriseWikiIngestSection::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->orderBy('section_index')
            ->get();

        $this->assertCount(2, $sections);
        $this->assertSame(0, $sections[0]->section_index);
        $this->assertSame('Kompetanse', $sections[0]->heading);
        $this->assertSame(EnterpriseWikiIngestSection::STATUS_PENDING, $sections[0]->status);
        $this->assertSame(1, $sections[1]->section_index);
        $this->assertSame('Referanser', $sections[1]->heading);
    }

    public function test_job_dispatches_one_section_job_per_section(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, [
            'extracted_text' => "## Seksjon A\nInnhold A.\n\n## Seksjon B\nInnhold B.",
        ]);
        $run = $this->createQueuedRun($customer, $version);

        $this->runJob($run);

        Queue::assertPushed(ProcessEnterpriseWikiSection::class, 2);
    }

    public function test_job_creates_draft_wiki_page_and_version(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);
        $run = $this->createQueuedRun($customer, $version);

        $this->runJob($run);

        $run->refresh();
        $this->assertNotNull($run->enterprise_wiki_page_id);
        $this->assertDatabaseCount('enterprise_wiki_pages', 1);
        $this->assertDatabaseCount('enterprise_wiki_page_versions', 1);
        $this->assertDatabaseHas('enterprise_wiki_page_versions', ['version_number' => 1, 'is_current' => false]);
    }

    public function test_job_creates_sections_for_text_without_headings(): void
    {
        $text = str_repeat('Tekst uten overskrift. ', 200);

        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, ['extracted_text' => $text]);
        $run = $this->createQueuedRun($customer, $version);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED, $run->status);

        $sectionCount = EnterpriseWikiIngestSection::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->count();

        $this->assertGreaterThan(0, $sectionCount);
        $this->assertLessThanOrEqual(EnterpriseWikiSectionParser::MAX_SECTIONS, $sectionCount);
    }

    // --- Idempotency ---

    public function test_job_returns_early_when_run_is_not_queued(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);
        $run = $this->createQueuedRun($customer, $version);

        $run->update(['status' => EnterpriseWikiIngestRun::STATUS_RUNNING]);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_RUNNING, $run->status);
        $this->assertDatabaseCount('enterprise_wiki_ingest_sections', 0);
    }

    // --- Failure paths ---

    public function test_job_marks_run_failed_when_version_not_found_for_customer(): void
    {
        $customer = $this->createCustomer();
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => 99999,
            'source_hash' => 'nonexistent',
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotEmpty($run->error_message);
        $this->assertDatabaseCount('enterprise_wiki_ingest_sections', 0);
    }

    public function test_job_marks_run_failed_when_version_has_no_extracted_text(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, ['extracted_text' => null]);
        $run = $this->createQueuedRun($customer, $version);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotEmpty($run->error_message);
    }

    public function test_job_marks_run_failed_when_version_is_not_approved(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer, [
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW,
        ]);
        $run = $this->createQueuedRun($customer, $version);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
    }

    // --- RAG read-only ---

    public function test_job_does_not_modify_knowledge_item_versions(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);
        $run = $this->createQueuedRun($customer, $version);

        $originalText = $version->extracted_text;
        $originalApprovalStatus = $version->approval_status;

        $this->runJob($run);

        $version->refresh();
        $this->assertSame($originalText, $version->extracted_text);
        $this->assertSame($originalApprovalStatus, $version->approval_status);
    }

    public function test_job_does_not_create_or_delete_knowledge_item_versions(): void
    {
        $customer = $this->createCustomer();
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $customer);
        $run = $this->createQueuedRun($customer, $version);

        $countBefore = KnowledgeItemVersion::query()->count();

        $this->runJob($run);

        $this->assertSame($countBefore, KnowledgeItemVersion::query()->count());
    }

    // --- Helpers ---

    private function runJob(EnterpriseWikiIngestRun $run): void
    {
        (new ProcessEnterpriseWikiIngest($run->id))->handle(
            app(EnterpriseWikiIngestService::class),
            app(EnterpriseWikiSectionParser::class),
        );
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createKnowledgeItem(Customer $customer, bool $aiUsageEnabled = true): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Test Document',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'ai_usage_enabled' => $aiUsageEnabled,
        ]);
    }

    private function createVersion(KnowledgeItem $item, Customer $customer, array $overrides = []): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create(array_merge([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'extracted_text' => "## Seksjon A\nNoe innhold.\n\n## Seksjon B\nMer innhold.",
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
            'file_hash_sha256' => str_pad('abc123', 64, '0'),
        ], $overrides));
    }

    private function createQueuedRun(Customer $customer, KnowledgeItemVersion $version): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id,
            'source_hash' => str_pad('hash', 64, '0'),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);
    }

    // =========================================================================
    // enterprise_wiki_document path
    // =========================================================================

    public function test_job_handles_enterprise_wiki_document_source_type_and_plans_sections(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer, [
            'extracted_text' => "## Kompetanse\nVi leverer ISO 9001.\n\n## Referanser\nSe vedlegg.",
        ]);
        $run = $this->createQueuedRunForDocument($customer, $document);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_SECTIONS_PLANNED, $run->status);
        $this->assertNotNull($run->enterprise_wiki_page_id);

        $sections = EnterpriseWikiIngestSection::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->orderBy('section_index')
            ->get();

        $this->assertCount(2, $sections);
        $this->assertSame('Kompetanse', $sections[0]->heading);
        $this->assertSame('Referanser', $sections[1]->heading);

        Queue::assertPushed(ProcessEnterpriseWikiSection::class, 2);
    }

    public function test_job_uses_original_filename_as_page_title_for_document_source(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer, [
            'original_filename' => 'selskapsinfo.pdf',
            'extracted_text'    => "## Om oss\nVi er et selskap.",
        ]);
        $run = $this->createQueuedRunForDocument($customer, $document);

        $this->runJob($run);

        $run->refresh();
        $page = \App\Models\EnterpriseWikiPage::query()->find($run->enterprise_wiki_page_id);

        $this->assertNotNull($page);
        $this->assertSame('selskapsinfo.pdf', $page->title);
    }

    public function test_job_marks_run_failed_when_document_not_found_for_source_type(): void
    {
        $customer = $this->createCustomer();
        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid'         => (string) Str::uuid(),
            'customer_id'  => $customer->id,
            'source_type'  => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'    => 99999,
            'source_hash'  => 'nonexistent',
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status'       => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotEmpty($run->error_message);
        $this->assertDatabaseCount('enterprise_wiki_ingest_sections', 0);
    }

    public function test_job_marks_run_failed_when_document_is_not_extracted(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createExtractedDocument($customer, [
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_PENDING,
            'extracted_text'  => null,
        ]);
        $run = $this->createQueuedRunForDocument($customer, $document);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(EnterpriseWikiIngestRun::STATUS_FAILED, $run->status);
        $this->assertNotEmpty($run->error_message);
    }

    // ─── Document helpers ─────────────────────────────────────────────────────

    private function createExtractedDocument(Customer $customer, array $overrides = []): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create(array_merge([
            'customer_id'       => $customer->id,
            'original_filename' => 'test.pdf',
            'file_path'         => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
            'extracted_text'    => "## Seksjon A\nNoe innhold.\n\n## Seksjon B\nMer innhold.",
        ], $overrides));
    }

    private function createQueuedRunForDocument(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid'         => (string) Str::uuid(),
            'customer_id'  => $customer->id,
            'source_type'  => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'    => $document->id,
            'source_hash'  => hash('sha256', "enterprise_wiki_document:{$document->id}:{$document->file_hash_sha256}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status'       => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);
    }
}
