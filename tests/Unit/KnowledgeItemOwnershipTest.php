<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemVersion;
use App\Models\KnowledgeMetadataTerm;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeItemOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_company_ownership_and_exposes_company_state_helpers(): void
    {
        $customer = $this->createCustomer('Ownership Default Customer AS');

        $document = $this->createKnowledgeItem($customer);

        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_COMPANY, $document->ownership_type);
        $this->assertTrue($document->isCompanyOwned());
        $this->assertFalse($document->isPersonalOwned());
        $this->assertFalse($document->isCaseOwned());
        $this->assertFalse($document->hasDocumentTheme());
        $this->assertNull($document->owner);
        $this->assertNull($document->owningSavedNotice);
        $this->assertNull($document->documentThemeTerm);
    }

    public function test_it_resolves_personal_owner_and_case_notice_relations(): void
    {
        $customer = $this->createCustomer('Ownership Relation Customer AS');
        $owner = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => 'OWNERSHIP-001',
            'title' => 'Ownership case',
        ]);

        $personalDocument = $this->createKnowledgeItem($customer, [
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_PERSONAL,
            'owner_user_id' => $owner->id,
        ]);

        $caseDocument = $this->createKnowledgeItem($customer, [
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_CASE,
            'owner_user_id' => $owner->id,
            'owning_saved_notice_id' => $savedNotice->id,
        ]);

        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_PERSONAL, $personalDocument->ownership_type);
        $this->assertTrue($personalDocument->isPersonalOwned());
        $this->assertSame($owner->id, $personalDocument->owner?->id);
        $this->assertNull($personalDocument->owningSavedNotice);

        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_CASE, $caseDocument->ownership_type);
        $this->assertTrue($caseDocument->isCaseOwned());
        $this->assertSame($owner->id, $caseDocument->owner?->id);
        $this->assertSame($savedNotice->id, $caseDocument->owningSavedNotice?->id);
    }

    public function test_it_resolves_document_theme_term_and_nulls_the_reference_when_the_term_is_deleted(): void
    {
        $customer = $this->createCustomer('Ownership Theme Customer AS');
        $term = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => KnowledgeMetadataTerm::TYPE_THEME_TAG,
            'canonical_name' => 'Strategi',
            'synonyms' => ['Strategi'],
            'description' => 'Tema for dokumentet',
            'approved' => true,
        ]);

        $document = $this->createKnowledgeItem($customer, [
            'document_theme_term_id' => $term->id,
        ]);

        $this->assertSame($term->id, $document->document_theme_term_id);
        $this->assertTrue($document->hasDocumentTheme());
        $this->assertSame($term->id, $document->documentThemeTerm?->id);

        $term->delete();

        $freshDocument = $document->fresh();

        $this->assertNotNull($freshDocument);
        $this->assertNull($freshDocument->document_theme_term_id);
        $this->assertFalse($freshDocument->hasDocumentTheme());
        $this->assertNull($freshDocument->documentThemeTerm);
    }

    public function test_chunk_can_belong_to_a_version(): void
    {
        $customer = $this->createCustomer('Chunk Version Customer AS');
        $document = $this->createKnowledgeItem($customer);

        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
        ]);

        $chunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'knowledge_item_version_id' => $version->id,
            'chunk_index' => 0,
            'content' => 'Chunk content for version test.',
            'start_offset' => 0,
            'end_offset' => 30,
        ]);

        $this->assertSame($version->id, $chunk->version?->id);
        $this->assertSame($version->version_no, $chunk->version?->version_no);
    }

    public function test_knowledge_item_version_has_chunks_relation(): void
    {
        $customer = $this->createCustomer('Version Chunks Customer AS');
        $document = $this->createKnowledgeItem($customer);

        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
        ]);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'knowledge_item_version_id' => $version->id,
            'chunk_index' => 0,
            'content' => 'First chunk.',
            'start_offset' => 0,
            'end_offset' => 12,
        ]);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'knowledge_item_version_id' => $version->id,
            'chunk_index' => 1,
            'content' => 'Second chunk.',
            'start_offset' => 12,
            'end_offset' => 25,
        ]);

        $this->assertCount(2, $version->chunks);
    }

    public function test_backfill_assigns_existing_chunk_to_version(): void
    {
        $customer = $this->createCustomer('Backfill Chunk Customer AS');
        $document = $this->createKnowledgeItem($customer);

        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
        ]);

        // Simulate a chunk that existed before backfill (no version_id set).
        $chunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'knowledge_item_version_id' => null,
            'chunk_index' => 0,
            'content' => 'Pre-backfill chunk content.',
            'start_offset' => 0,
            'end_offset' => 26,
        ]);

        $this->assertNull($chunk->knowledge_item_version_id);

        // Apply the same backfill logic used in the migration.
        \Illuminate\Support\Facades\DB::statement("
            UPDATE knowledge_item_chunks kic
            SET knowledge_item_version_id = kiv.id
            FROM knowledge_item_versions kiv
            WHERE kiv.knowledge_item_id = kic.knowledge_item_id
              AND kiv.version_no = 1
              AND kic.knowledge_item_version_id IS NULL
        ");

        $chunk->refresh();

        $this->assertSame($version->id, $chunk->knowledge_item_version_id);
        $this->assertSame($version->id, $chunk->version?->id);
    }

    public function test_knowledge_item_versions_relation_returns_all_versions(): void
    {
        $customer = $this->createCustomer('Version Relation Customer AS');
        $document = $this->createKnowledgeItem($customer);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => false,
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 2,
            'is_current' => true,
        ]);

        $this->assertCount(2, $document->versions);
    }

    public function test_knowledge_item_current_version_returns_the_is_current_version(): void
    {
        $customer = $this->createCustomer('Current Version Customer AS');
        $document = $this->createKnowledgeItem($customer);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => false,
        ]);

        $v2 = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 2,
            'is_current' => true,
        ]);

        $current = $document->currentVersion;

        $this->assertNotNull($current);
        $this->assertSame($v2->id, $current->id);
        $this->assertSame(2, $current->version_no);
    }

    public function test_knowledge_item_version_belongs_to_knowledge_item_and_customer(): void
    {
        $customer = $this->createCustomer('Version BelongsTo Customer AS');
        $document = $this->createKnowledgeItem($customer);

        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
        ]);

        $this->assertSame($document->id, $version->knowledgeItem->id);
        $this->assertSame($customer->id, $version->customer->id);
    }

    public function test_knowledge_item_version_is_backfilled_with_file_fields(): void
    {
        $customer = $this->createCustomer('Backfill Test Customer AS');

        $document = $this->createKnowledgeItem($customer, [
            'extracted_text' => 'Extracted content from company profile.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
        ]);

        $storagePath = 'customers/'.$customer->id.'/knowledge-items/company-profile.docx';

        $version = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $document->customer_id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'company-profile.docx',
            'storage_path' => $storagePath,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Extracted content from company profile.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'uploaded_by_user_id' => $document->uploaded_by_user_id,
            'uploaded_at' => $document->created_at,
        ]);

        $this->assertSame(1, $version->version_no);
        $this->assertTrue($version->is_current);
        $this->assertSame('company-profile.docx', $version->original_filename);
        $this->assertSame($storagePath, $version->storage_path);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $version->mime_type);
        $this->assertSame(2048, $version->file_size_bytes);
        $this->assertSame('Extracted content from company profile.', $version->extracted_text);
        $this->assertSame(KnowledgeItem::EXTRACTION_STATUS_COMPLETED, $version->extraction_status);
        $this->assertNull($version->extraction_error);
        $this->assertSame($document->id, $version->knowledgeItem->id);
    }

    private function createCustomer(string $name): Customer
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

    private function createKnowledgeItem(Customer $customer, array $overrides = []): KnowledgeItem
    {
        $content = $overrides['content'] ?? 'Ownership test content.';
        $kiOverrides = array_diff_key($overrides, array_flip(['original_filename', 'storage_path', 'mime_type', 'file_size_bytes']));

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'ownership_type' => $overrides['ownership_type'] ?? KnowledgeItem::OWNERSHIP_TYPE_COMPANY,
            'title' => $overrides['title'] ?? 'Ownership test document',
            'content' => $content,
            'content_type' => $overrides['content_type'] ?? KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => $overrides['document_type'] ?? KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_theme_term_id' => $overrides['document_theme_term_id'] ?? null,
            'extracted_text' => $overrides['extracted_text'] ?? $content,
            'summary' => $overrides['summary'] ?? 'Oppsummering',
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'owner_user_id' => $overrides['owner_user_id'] ?? null,
            'owning_saved_notice_id' => $overrides['owning_saved_notice_id'] ?? null,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ], $kiOverrides));
    }

    public function test_file_resolvers_return_version_values(): void
    {
        $customer = $this->createCustomer('File Resolver Version AS');
        $document = $this->createKnowledgeItem($customer);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'version-name.docx',
            'storage_path' => 'customers/'.$customer->id.'/version-path.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
        ]);

        $document->load('currentVersion');

        $this->assertSame('version-name.docx', $document->resolvedOriginalFilename());
        $this->assertSame('customers/'.$customer->id.'/version-path.docx', $document->resolvedStoragePath());
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $document->resolvedMimeType());
        $this->assertSame(4096, $document->resolvedFileSizeBytes());
    }

    public function test_file_resolvers_return_null_when_no_current_version(): void
    {
        $customer = $this->createCustomer('File Resolver Null AS');
        $document = $this->createKnowledgeItem($customer);

        // No version created — resolvers must return null, not fall back to KI fields.
        $this->assertNull($document->resolvedOriginalFilename());
        $this->assertNull($document->resolvedStoragePath());
        $this->assertNull($document->resolvedMimeType());
        $this->assertNull($document->resolvedFileSizeBytes());
    }

    public function test_file_resolvers_ignore_legacy_ki_fields_when_version_exists(): void
    {
        $customer = $this->createCustomer('File Resolver Ignore Legacy AS');
        $document = $this->createKnowledgeItem($customer);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'version-name.docx',
            'storage_path' => 'customers/'.$customer->id.'/version-path.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 4096,
        ]);

        $document->load('currentVersion');

        // Version values must win; legacy KI fields must not appear in resolver output.
        $this->assertNotSame($document->original_filename, $document->resolvedOriginalFilename());
        $this->assertNotSame($document->storage_path, $document->resolvedStoragePath());
        $this->assertNotSame($document->mime_type, $document->resolvedMimeType());
        $this->assertNotSame($document->file_size_bytes, $document->resolvedFileSizeBytes());
    }

    public function test_text_for_knowledge_processing_prefers_current_version_extracted_text(): void
    {
        // currentVersion.extracted_text must win over legacy KnowledgeItem fields.
        $customer = $this->createCustomer('Text Processing Priority AS');
        $document = $this->createKnowledgeItem($customer, [
            'extracted_text' => 'Stale document extracted text',
            'content' => 'Even older document content',
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => 'ownership-test-document.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/ownership-test-document.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'extracted_text' => 'Current version extracted text',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'uploaded_by_user_id' => null,
            'uploaded_at' => now(),
            'file_hash_sha256' => hash('sha256', 'current-version-content'),
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);

        $document->load('currentVersion');

        $this->assertSame('Current version extracted text', $document->textForKnowledgeProcessing());
    }

    public function test_text_for_knowledge_processing_returns_content_when_no_version(): void
    {
        // Without a currentVersion, resolvedExtractedText() returns null; falls back to KnowledgeItem.content.
        $customer = $this->createCustomer('Text Processing Fallback AS');
        $document = $this->createKnowledgeItem($customer, [
            'extracted_text' => 'Legacy extracted text',
            'content' => 'Legacy content',
        ]);

        $this->assertSame('Legacy content', $document->textForKnowledgeProcessing());
    }

    public function test_text_for_knowledge_processing_falls_back_to_content_when_extracted_text_is_empty(): void
    {
        // Without extracted_text, falls back to KnowledgeItem.content.
        $customer = $this->createCustomer('Text Processing Content Fallback AS');
        $document = $this->createKnowledgeItem($customer, [
            'extracted_text' => '',
            'content' => 'Only content is available',
        ]);

        $this->assertSame('Only content is available', $document->textForKnowledgeProcessing());
    }

    public function test_text_for_knowledge_processing_returns_null_when_all_sources_are_empty(): void
    {
        $customer = $this->createCustomer('Text Processing Null AS');
        $document = $this->createKnowledgeItem($customer, [
            'extracted_text' => '',
            'content' => '',
        ]);

        $this->assertNull($document->textForKnowledgeProcessing());
    }
}
