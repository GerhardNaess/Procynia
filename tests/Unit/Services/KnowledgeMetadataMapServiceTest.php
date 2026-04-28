<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
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

        return KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => $content,
            'original_filename' => $filename,
            'storage_path' => $overrides['storage_path'] ?? 'customers/'.$customer->id.'/knowledge-items/metadata-document.docx',
            'mime_type' => $overrides['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => $overrides['file_size_bytes'] ?? 1024,
            'content_type' => $overrides['content_type'] ?? KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => $overrides['document_type'] ?? KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => $overrides['extracted_text'] ?? $content,
            'summary' => $overrides['summary'] ?? 'Oppsummering',
            'extraction_status' => $overrides['extraction_status'] ?? KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => $overrides['extraction_error'] ?? null,
            'uploaded_by_user_id' => $overrides['uploaded_by_user_id'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ], $overrides));
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
