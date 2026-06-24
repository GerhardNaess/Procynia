<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Retrieval\KnowledgeMetadataMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeMetadataMapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_distinct_chunk_metadata_values_from_the_database(): void
    {
        $service = app(KnowledgeMetadataMapService::class);
        $customer = $this->createCustomer('Metadata Customer AS');
        $document = $this->createKnowledgeItem($customer, [
            'title' => 'Metadata document',
            'original_filename' => 'metadata-document.docx',
            'summary' => 'Dokumentoppsummering',
        ]);

        $this->createChunk($document, 0, [
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Nøkkelord A', 'Nøkkelord B'],
            'service_product_tag' => 'Tag A',
            'theme_tag' => 'Tag B',
            'section_title' => 'Del 1',
            'section_path' => 'Kapittel > Del 1',
        ]);
        $this->createChunk($document, 1, [
            'topic' => 'Tema A',
            'sub_topic' => null,
            'keywords' => ['Nøkkelord B', 'Nøkkelord C'],
            'service_product_tag' => 'Tag A',
            'theme_tag' => 'Tag C',
            'section_title' => 'Del 1',
            'section_path' => 'Kapittel > Del 1',
        ]);

        $map = $service->buildForCustomer($customer->id);

        $this->assertSame($customer->id, $map['customer_id']);
        $this->assertSame(2, $map['chunk_count']);
        $this->assertSame(['Tema A'], $map['fields']['topic']);
        $this->assertSame(['Underemne A'], $map['fields']['sub_topic']);
        $this->assertSame(['Nøkkelord A', 'Nøkkelord B', 'Nøkkelord C'], $map['fields']['keywords']);
        $this->assertSame(['Tag A'], $map['fields']['service_product_tag']);
        $this->assertSame(['Tag B', 'Tag C'], $map['fields']['theme_tag']);
        $this->assertSame(['Del 1'], $map['fields']['section_title']);
        $this->assertSame(['Kapittel > Del 1'], $map['fields']['section_path']);
        $this->assertSame(1, $map['field_counts']['topic']);
        $this->assertSame(3, $map['field_counts']['keywords']);
        $this->assertSame(1, $map['field_counts']['service_product_tag']);
        $this->assertSame(2, $map['field_counts']['theme_tag']);
    }

    public function test_it_ignores_non_company_documents_when_building_the_active_chunk_inventory(): void
    {
        $service = app(KnowledgeMetadataMapService::class);
        $customer = $this->createCustomer('Metadata Company Scope AS');
        $companyDocument = $this->createKnowledgeItem($customer, [
            'title' => 'Company document',
            'original_filename' => 'company-document.docx',
            'summary' => 'Selskapsoppsummering',
        ]);
        $personalDocument = $this->createKnowledgeItem($customer, [
            'title' => 'Personal document',
            'original_filename' => 'personal-document.docx',
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_PERSONAL,
            'summary' => 'Personlig oppsummering',
        ]);

        $this->createChunk($companyDocument, 0, [
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Nøkkelord A'],
            'service_product_tag' => 'Tag A',
            'theme_tag' => 'Tag B',
            'section_title' => 'Del 1',
            'section_path' => 'Kapittel > Del 1',
        ]);
        $this->createChunk($personalDocument, 0, [
            'topic' => 'Tema Privat',
            'sub_topic' => 'Underemne Privat',
            'keywords' => ['Privat nøkkelord'],
            'service_product_tag' => 'Tag Privat',
            'theme_tag' => 'Tag Privat',
            'section_title' => 'Privat del',
            'section_path' => 'Kapittel > Privat del',
        ]);

        $map = $service->buildForCustomer($customer->id);

        $this->assertSame(1, $map['chunk_count']);
        $this->assertSame(['Tema A'], $map['fields']['topic']);
        $this->assertSame(['Underemne A'], $map['fields']['sub_topic']);
        $this->assertSame(['Nøkkelord A'], $map['fields']['keywords']);
        $this->assertSame(['Tag A'], $map['fields']['service_product_tag']);
        $this->assertSame(['Tag B'], $map['fields']['theme_tag']);
        $this->assertSame(['Del 1'], $map['fields']['section_title']);
        $this->assertSame(['Kapittel > Del 1'], $map['fields']['section_path']);
    }

    public function test_it_includes_document_when_is_active_false_and_document_status_active(): void
    {
        // document_status is authoritative; is_active alone does not exclude a document.
        $service = app(KnowledgeMetadataMapService::class);
        $customer = $this->createCustomer('Mismatch Include Map AS');
        $document = $this->createKnowledgeItem($customer, [
            'is_active' => false,
            // document_status defaults to DOCUMENT_STATUS_ACTIVE — intentional mismatch
        ]);

        $this->createChunk($document, 0, [
            'topic' => 'Mismatch tema',
            'sub_topic' => 'Underemne',
            'keywords' => ['nøkkelord'],
            'service_product_tag' => 'Tag',
            'theme_tag' => 'Tema-tag',
            'section_title' => 'Del 1',
            'section_path' => 'Del 1',
        ]);

        $map = $service->buildForCustomer($customer->id);

        $this->assertSame(1, $map['chunk_count']);
    }

    public function test_it_excludes_document_when_document_status_archived_and_is_active_true(): void
    {
        // Reverse mismatch: is_active=true does not override an archived document_status.
        $service = app(KnowledgeMetadataMapService::class);
        $customer = $this->createCustomer('Mismatch Exclude Map AS');
        $document = $this->createKnowledgeItem($customer, [
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ARCHIVED,
        ]);

        $this->createChunk($document, 0, [
            'topic' => 'Arkivert tema',
            'sub_topic' => null,
            'keywords' => [],
            'service_product_tag' => null,
            'theme_tag' => null,
            'section_title' => null,
            'section_path' => null,
        ]);

        $map = $service->buildForCustomer($customer->id);

        $this->assertSame(0, $map['chunk_count']);
    }

    public function test_metadata_map_includes_chunk_when_version_has_storage_path_but_item_field_is_null(): void
    {
        // Scenario A: item has null storage_path but the current version is complete — map must include the chunk.
        $service = app(KnowledgeMetadataMapService::class);
        $customer = $this->createCustomer('Map Version Win AS');

        $item = KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Map Version Win',
            'original_filename' => 'map-version-win.docx',
            'content' => 'Stale item.',
            'storage_path' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_PENDING,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'summary' => 'Summary',
            'is_active' => true,
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'storage_path' => 'customers/'.$customer->id.'/map-version-win.docx',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
        ]);

        $this->createChunk($item, 0, [
            'topic' => 'Versjonskontrollert tema',
            'sub_topic' => 'Underemne',
            'keywords' => ['nøkkelord'],
            'service_product_tag' => 'Tag',
            'theme_tag' => 'Tema-tag',
            'section_title' => 'Del 1',
            'section_path' => 'Del 1',
        ]);

        $map = $service->buildForCustomer($customer->id);

        $this->assertSame(1, $map['chunk_count']);
        $this->assertSame(['Versjonskontrollert tema'], $map['fields']['topic']);
    }

    public function test_metadata_map_excludes_chunk_when_version_lacks_storage_path_despite_valid_item_fields(): void
    {
        // Scenario B: item has valid storage_path and completed extraction but the current version
        // has null storage_path — map must exclude the chunk even though legacy document fields look eligible.
        $service = app(KnowledgeMetadataMapService::class);
        $customer = $this->createCustomer('Map Version Block AS');

        $item = KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Map Version Block',
            'original_filename' => 'map-version-block.docx',
            'content' => 'Valid item.',
            'storage_path' => 'customers/'.$customer->id.'/map-version-block.docx',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'summary' => 'Summary',
            'is_active' => true,
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'storage_path' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_PENDING,
        ]);

        $this->createChunk($item, 0, [
            'topic' => 'Blokkert tema',
            'sub_topic' => null,
            'keywords' => [],
            'service_product_tag' => null,
            'theme_tag' => null,
            'section_title' => null,
            'section_path' => null,
        ]);

        $map = $service->buildForCustomer($customer->id);

        $this->assertSame(0, $map['chunk_count']);
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
        $title = $overrides['title'] ?? 'Metadata document';
        $filename = $overrides['original_filename'] ?? 'metadata-document.docx';
        $content = $overrides['content'] ?? 'Metadata document content.';
        $storagePath = $overrides['storage_path'] ?? 'customers/'.$customer->id.'/knowledge-items/metadata-document.docx';
        $extractionStatus = $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED;

        $item = KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => $content,
            'original_filename' => $filename,
            'storage_path' => $storagePath,
            'mime_type' => $overrides['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => $overrides['file_size_bytes'] ?? 1024,
            'content_type' => $overrides['content_type'] ?? KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => $overrides['document_type'] ?? KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => $overrides['extracted_text'] ?? $content,
            'summary' => $overrides['summary'] ?? 'Oppsummering',
            'extraction_status' => $extractionStatus,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ], $overrides));

        // Every knowledge item needs a current version so retrieval guards can use version fields.
        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'storage_path' => $storagePath,
            'extraction_status' => $extractionStatus,
        ]);

        return $item;
    }

    private function createChunk(KnowledgeItem $knowledgeItem, int $chunkIndex, array $overrides = []): KnowledgeItemChunk
    {
        $content = $overrides['content'] ?? sprintf('Chunk %d content.', $chunkIndex + 1);

        return KnowledgeItemChunk::query()->create(array_merge([
            'knowledge_item_id' => $knowledgeItem->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'start_offset' => $overrides['start_offset'] ?? ($chunkIndex * 100),
            'end_offset' => $overrides['end_offset'] ?? ($chunkIndex * 100 + mb_strlen($content, 'UTF-8')),
            'review_status' => $overrides['review_status'] ?? KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
            'title' => $overrides['title'] ?? null,
            'ai_summary' => $overrides['ai_summary'] ?? null,
            'service_product_tag' => $overrides['service_product_tag'] ?? null,
            'theme_tag' => $overrides['theme_tag'] ?? null,
            'topic' => $overrides['topic'] ?? null,
            'sub_topic' => $overrides['sub_topic'] ?? null,
            'keywords' => $overrides['keywords'] ?? null,
            'section_title' => $overrides['section_title'] ?? null,
            'section_path' => $overrides['section_path'] ?? null,
        ], $overrides));
    }
}
