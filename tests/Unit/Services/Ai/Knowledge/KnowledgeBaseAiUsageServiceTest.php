<?php

namespace Tests\Unit\Services\Ai\Knowledge;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiEvidence;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Knowledge\KnowledgeBaseAiUsageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeBaseAiUsageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        app()->setLocale('no');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // documentAggregate()
    // ------------------------------------------------------------------

    public function test_document_aggregate_returns_rows_for_the_given_customer(): void
    {
        $customer = $this->createCustomer('Testbedrift AS');
        [$notice, $requirement] = $this->scaffoldCase($customer, 'DOC-001');
        [$item, $version, $chunk] = $this->scaffoldKnowledge($customer);

        $this->createEvidence($requirement, $item, $chunk, $version);

        $rows = app(KnowledgeBaseAiUsageService::class)->documentAggregate($customer->id);

        $this->assertCount(1, $rows);
        $this->assertSame($item->id, (int) $rows->first()->knowledge_item_id);
    }

    public function test_document_aggregate_excludes_evidence_from_another_customer(): void
    {
        $customerA = $this->createCustomer('Kunde A AS');
        $customerB = $this->createCustomer('Kunde B AS');

        [$noticeA, $reqA] = $this->scaffoldCase($customerA, 'DOC-002A');
        [$itemA, $versionA, $chunkA] = $this->scaffoldKnowledge($customerA);
        $this->createEvidence($reqA, $itemA, $chunkA, $versionA);

        [$noticeB, $reqB] = $this->scaffoldCase($customerB, 'DOC-002B');
        [$itemB, $versionB, $chunkB] = $this->scaffoldKnowledge($customerB);
        $this->createEvidence($reqB, $itemB, $chunkB, $versionB);

        $service = app(KnowledgeBaseAiUsageService::class);

        $rowsA = $service->documentAggregate($customerA->id);
        $this->assertCount(1, $rowsA);
        $this->assertSame($itemA->id, (int) $rowsA->first()->knowledge_item_id);

        $rowsB = $service->documentAggregate($customerB->id);
        $this->assertCount(1, $rowsB);
        $this->assertSame($itemB->id, (int) $rowsB->first()->knowledge_item_id);
    }

    public function test_document_aggregate_counts_are_correct(): void
    {
        $customer = $this->createCustomer('Teller AS');

        [$noticeA, $reqA] = $this->scaffoldCase($customer, 'CNT-001A');
        [$noticeB, $reqB] = $this->scaffoldCase($customer, 'CNT-001B');

        [$item, $version, $chunk] = $this->scaffoldKnowledge($customer);
        $chunk2 = $this->createChunk($item, $version, 1);

        // (reqA, chunk): primary
        $this->createEvidence($reqA, $item, $chunk, $version, ['is_primary' => true]);
        // (reqA, chunk2): not primary — same requirement, different chunk
        $this->createEvidence($reqA, $item, $chunk2, $version, ['is_primary' => false]);
        // (reqB, chunk): not primary — different requirement, same chunk as first
        $this->createEvidence($reqB, $item, $chunk, $version, ['is_primary' => false]);

        $rows = app(KnowledgeBaseAiUsageService::class)->documentAggregate($customer->id);

        $this->assertCount(1, $rows, 'All evidence points to the same knowledge item.');
        $row = $rows->first();

        $this->assertSame(2, (int) $row->case_count, 'Two distinct saved notices.');
        $this->assertSame(2, (int) $row->requirement_count, 'Two distinct requirements.');
        $this->assertSame(3, (int) $row->evidence_count, 'Three evidence rows total.');
        $this->assertSame(1, (int) $row->primary_count, 'One primary evidence row.');
    }

    public function test_document_aggregate_excludes_rejected_evidence(): void
    {
        $customer = $this->createCustomer('Avvisning AS');
        [$notice, $requirement] = $this->scaffoldCase($customer, 'REJ-001');
        [$item, $version, $chunk] = $this->scaffoldKnowledge($customer);

        $this->createEvidence($requirement, $item, $chunk, $version, [
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_REJECTED,
        ]);

        $rows = app(KnowledgeBaseAiUsageService::class)->documentAggregate($customer->id);

        $this->assertCount(0, $rows, 'Rejected evidence must not appear in the aggregate.');
    }

    public function test_document_aggregate_reflects_current_version_and_approval_status(): void
    {
        $customer = $this->createCustomer('Versjon AS');
        [$notice, $requirement] = $this->scaffoldCase($customer, 'VER-001');
        [$item, $version, $chunk] = $this->scaffoldKnowledge(
            $customer,
            versionNo: 3,
            isCurrent: true,
            approvalStatus: KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        );

        $this->createEvidence($requirement, $item, $chunk, $version);

        $row = app(KnowledgeBaseAiUsageService::class)->documentAggregate($customer->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->current_version_no);
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $row->current_version_approval_status);
    }

    public function test_document_aggregate_counts_evidence_on_superseded_version(): void
    {
        $customer = $this->createCustomer('Erstattet AS');
        [$notice, $requirement] = $this->scaffoldCase($customer, 'SUP-001');

        $item = $this->createKnowledgeItem($customer, 'Superseded Document');

        $v1 = $this->createVersion($item, isCurrent: false, versionNo: 1);
        $chunk1a = $this->createChunk($item, $v1, 0);
        $chunk1b = $this->createChunk($item, $v1, 1);

        $v2 = $this->createVersion($item, isCurrent: true, versionNo: 2);
        $chunk2a = $this->createChunk($item, $v2, 0);

        // 2 evidence rows on v1 (superseded), 1 on v2 (current)
        $this->createEvidence($requirement, $item, $chunk1a, $v1);
        $this->createEvidence($requirement, $item, $chunk1b, $v1);
        $this->createEvidence($requirement, $item, $chunk2a, $v2);

        $row = app(KnowledgeBaseAiUsageService::class)->documentAggregate($customer->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->evidence_on_superseded_version_count);
    }

    // ------------------------------------------------------------------
    // chunkAggregate()
    // ------------------------------------------------------------------

    public function test_chunk_aggregate_returns_rows_for_the_given_customer(): void
    {
        $customer = $this->createCustomer('Chunk Kunde AS');
        [$notice, $requirement] = $this->scaffoldCase($customer, 'CHK-001');
        [$item, $version, $chunk] = $this->scaffoldKnowledge($customer);

        $this->createEvidence($requirement, $item, $chunk, $version);

        $rows = app(KnowledgeBaseAiUsageService::class)->chunkAggregate($customer->id);

        $this->assertCount(1, $rows);
        $this->assertSame($chunk->id, (int) $rows->first()->knowledge_item_chunk_id);
    }

    public function test_chunk_aggregate_counts_are_correct(): void
    {
        $customer = $this->createCustomer('Chunk Teller AS');

        [$noticeA, $reqA] = $this->scaffoldCase($customer, 'CCHK-001A');
        [$noticeB, $reqB] = $this->scaffoldCase($customer, 'CCHK-001B');

        [$item, $version, $chunk] = $this->scaffoldKnowledge($customer);

        // (reqA, chunk): primary, score 80
        $this->createEvidence($reqA, $item, $chunk, $version, ['is_primary' => true, 'match_score' => 80]);
        // (reqB, chunk): not primary, score 60
        $this->createEvidence($reqB, $item, $chunk, $version, ['is_primary' => false, 'match_score' => 60]);

        $rows = app(KnowledgeBaseAiUsageService::class)->chunkAggregate($customer->id);

        $this->assertCount(1, $rows);
        $row = $rows->first();

        $this->assertSame(2, (int) $row->case_count);
        $this->assertSame(2, (int) $row->requirement_count);
        $this->assertSame(2, (int) $row->evidence_count);
        $this->assertSame(1, (int) $row->primary_count);
        $this->assertSame(80, (int) $row->max_match_score);
        $this->assertSame(70, (int) $row->avg_match_score, 'AVG(80, 60) = 70.');
    }

    // ------------------------------------------------------------------
    // Empty state
    // ------------------------------------------------------------------

    public function test_both_aggregates_return_empty_collection_when_no_evidence_exists(): void
    {
        $customer = $this->createCustomer('Tom Kunde AS');

        $service = app(KnowledgeBaseAiUsageService::class);

        $docRows = $service->documentAggregate($customer->id);
        $chunkRows = $service->chunkAggregate($customer->id);

        $this->assertInstanceOf(Collection::class, $docRows);
        $this->assertInstanceOf(Collection::class, $chunkRows);
        $this->assertTrue($docRows->isEmpty(), 'documentAggregate must return an empty Collection.');
        $this->assertTrue($chunkRows->isEmpty(), 'chunkAggregate must return an empty Collection.');
    }

    // ------------------------------------------------------------------
    // Isolation: SavedNoticeAiDocument must not affect aggregates
    // ------------------------------------------------------------------

    public function test_saved_notice_ai_document_does_not_affect_aggregates(): void
    {
        $customer = $this->createCustomer('Dokument Isolasjon AS');
        $notice = $this->createSavedNotice($customer, 'ISO-001');

        // Create a case document (SavedNoticeAiDocument). The service must not query this table.
        $aiDocument = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $notice->id,
            'original_filename' => 'kravspesifikasjon.docx',
            'stored_path' => 'saved-notices/'.$notice->id.'/documents/kravspesifikasjon.docx',
        ]);
        $this->assertNotNull($aiDocument->id);

        $service = app(KnowledgeBaseAiUsageService::class);

        $this->assertTrue($service->documentAggregate($customer->id)->isEmpty(),
            'SavedNoticeAiDocument must not appear in documentAggregate.');
        $this->assertTrue($service->chunkAggregate($customer->id)->isEmpty(),
            'SavedNoticeAiDocument must not appear in chunkAggregate.');
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_document_aggregate_version_status_current_excludes_superseded_and_null_version_rows(): void
    {
        $customer = $this->createCustomer('Versjon Filter AS');
        [$notice, $requirement] = $this->scaffoldCase($customer, 'VF-001');

        $item = $this->createKnowledgeItem($customer, 'Filter Document');

        $vSuperseded = $this->createVersion($item, isCurrent: false, versionNo: 1);
        $chunkOld = $this->createChunk($item, $vSuperseded, 0);

        $vCurrent = $this->createVersion($item, isCurrent: true, versionNo: 2);
        $chunkNew = $this->createChunk($item, $vCurrent, 1);

        $chunkNoVersion = $this->createChunk($item, null, 2);

        $this->createEvidence($requirement, $item, $chunkOld, $vSuperseded);
        $this->createEvidence($requirement, $item, $chunkNew, $vCurrent);
        $this->createEvidence($requirement, $item, $chunkNoVersion, null);

        $rows = app(KnowledgeBaseAiUsageService::class)
            ->documentAggregate($customer->id, ['version_status' => 'current']);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows->first()->evidence_count,
            'Only the evidence row referencing the current version should be counted.');
    }

    public function test_chunk_aggregate_primary_only_filter_counts_only_primary_rows(): void
    {
        $customer = $this->createCustomer('Primary Filter AS');

        [$noticeA, $reqA] = $this->scaffoldCase($customer, 'PF-001A');
        [$noticeB, $reqB] = $this->scaffoldCase($customer, 'PF-001B');

        [$item, $version, $chunk] = $this->scaffoldKnowledge($customer);

        // (reqA, chunk): primary=true
        $this->createEvidence($reqA, $item, $chunk, $version, ['is_primary' => true]);
        // (reqB, chunk): primary=false
        $this->createEvidence($reqB, $item, $chunk, $version, ['is_primary' => false]);

        $rows = app(KnowledgeBaseAiUsageService::class)
            ->chunkAggregate($customer->id, ['primary_only' => true]);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows->first()->evidence_count,
            'primary_only filter must count only is_primary evidence rows.');
    }

    // ------------------------------------------------------------------
    // Private fixture helpers
    // ------------------------------------------------------------------

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.port' => 5432,
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.url' => null,
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    /**
     * Creates a Customer with prerequisite language and nationality rows.
     */
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
            'is_active' => true,
        ]);
    }

    private function createSavedNotice(Customer $customer, string $externalId = 'NOTICE-001'): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => $externalId,
            'title' => 'Test anbudsutlysning '.$externalId,
        ]);
    }

    /**
     * Creates a SavedNotice with all required FK infrastructure for creating requirements.
     * Returns [$savedNotice, $requirement].
     */
    private function scaffoldCase(Customer $customer, string $externalId = 'NOTICE-001'): array
    {
        $notice = $this->createSavedNotice($customer, $externalId);

        $aiDocument = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $notice->id,
            'original_filename' => 'krav-'.$externalId.'.docx',
            'stored_path' => 'saved-notices/'.$notice->id.'/documents/krav.docx',
        ]);

        $aiDocumentChunk = SavedNoticeAiDocumentChunk::query()->create([
            'saved_notice_ai_document_id' => $aiDocument->id,
            'content' => 'Kravtekst for '.$externalId.'.',
            'chunk_index' => 0,
            'char_start' => 0,
            'char_end' => 40,
        ]);

        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'saved_notice_ai_document_id' => $aiDocument->id,
            'saved_notice_ai_document_chunk_id' => $aiDocumentChunk->id,
            'requirement_text' => 'Leverandøren skal beskrive løsningen.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_AI_PHASE_1,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_STAGED,
        ]);

        return [$notice, $requirement];
    }

    /**
     * Creates a KnowledgeItem with one version and one chunk.
     * Returns [$item, $version, $chunk].
     */
    private function scaffoldKnowledge(
        Customer $customer,
        int $versionNo = 1,
        bool $isCurrent = true,
        string $approvalStatus = KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
    ): array {
        $item = $this->createKnowledgeItem($customer);
        $version = $this->createVersion($item, $isCurrent, $versionNo, $approvalStatus);
        $chunk = $this->createChunk($item, $version, 0);

        return [$item, $version, $chunk];
    }

    private function createKnowledgeItem(Customer $customer, string $title = 'Testdokument'): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => 'Testinnhold for '.$title.'.',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'content_type' => KnowledgeItem::CONTENT_TYPE_COMPANY,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
        ]);
    }

    private function createVersion(
        KnowledgeItem $item,
        bool $isCurrent = true,
        int $versionNo = 1,
        string $approvalStatus = KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
    ): KnowledgeItemVersion {
        return KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $item->customer_id,
            'version_no' => $versionNo,
            'is_current' => $isCurrent,
            'storage_path' => 'customers/'.$item->customer_id.'/knowledge-items/'.$item->id.'/v'.$versionNo.'.docx',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'approval_status' => $approvalStatus,
        ]);
    }

    private function createChunk(KnowledgeItem $item, ?KnowledgeItemVersion $version, int $index = 0): KnowledgeItemChunk
    {
        $offset = $index * 100;

        return KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $item->id,
            'knowledge_item_version_id' => $version?->id,
            'chunk_index' => $index,
            'content' => 'Testinnhold for chunk '.$index.'.',
            'start_offset' => $offset,
            'end_offset' => $offset + 40,
        ]);
    }

    private function createEvidence(
        SavedNoticeAiRequirement $requirement,
        KnowledgeItem $item,
        KnowledgeItemChunk $chunk,
        ?KnowledgeItemVersion $version,
        array $overrides = [],
    ): SavedNoticeAiEvidence {
        return SavedNoticeAiEvidence::query()->create(array_merge([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'knowledge_item_id' => $item->id,
            'knowledge_item_chunk_id' => $chunk->id,
            'knowledge_item_version_id' => $version?->id,
            'match_type' => SavedNoticeAiEvidence::MATCH_TYPE_AUTO_MATCH,
            'match_score' => 75,
            'match_rank' => 1,
            'selection_status' => SavedNoticeAiEvidence::SELECTION_STATUS_SUGGESTED,
            'is_primary' => false,
        ], $overrides));
    }
}
