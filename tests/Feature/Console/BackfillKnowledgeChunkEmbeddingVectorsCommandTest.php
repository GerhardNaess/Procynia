<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillKnowledgeChunkEmbeddingVectorsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_backfills_vector_embeddings_from_json_and_skips_invalid_dimensions(): void
    {
        $customer = $this->createCustomer('Embedding Backfill AS');
        $document = $this->createKnowledgeItem($customer);

        $goodVector = $this->embeddingVector(1536, 0.125);
        $sameVector = $this->embeddingVector(1536, 0.25);
        $wrongVector = $this->embeddingVector(1535, 0.5);

        $goodChunk = $this->createChunk($document, 0, [
            'content' => 'Chunk with a JSON embedding only.',
            'embedding_vector' => $goodVector,
            'embedding_vector_pgvector' => null,
        ]);
        $alreadyBackfilledChunk = $this->createChunk($document, 1, [
            'content' => 'Chunk with both embedding columns already set.',
            'embedding_vector' => $sameVector,
            'embedding_vector_pgvector' => $sameVector,
        ]);
        $wrongDimensionChunk = $this->createChunk($document, 2, [
            'content' => 'Chunk with the wrong vector dimension.',
            'embedding_vector' => $wrongVector,
            'embedding_vector_pgvector' => null,
        ]);

        $this->artisan('embeddings:backfill-vectors')
            ->expectsOutputToContain('[PROCYNIA][EMBEDDINGS][BACKFILL_VECTORS] found=3 updated=1 skipped=1 wrong_dimension=1')
            ->assertSuccessful();

        $this->assertSame($goodVector, $goodChunk->refresh()->embedding_vector_pgvector);
        $this->assertSame($sameVector, $alreadyBackfilledChunk->refresh()->embedding_vector_pgvector);
        $this->assertNull($wrongDimensionChunk->refresh()->embedding_vector_pgvector);
        $this->assertSame($goodVector, $goodChunk->refresh()->embedding_vector);
        $this->assertSame($sameVector, $alreadyBackfilledChunk->refresh()->embedding_vector);
        $this->assertSame($wrongVector, $wrongDimensionChunk->refresh()->embedding_vector);
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

    private function createKnowledgeItem(Customer $customer): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Embedding document',
            'content' => 'Embedding document content.',
            'original_filename' => 'embedding-document.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-documents/embedding-document.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => 'Embedding document content.',
            'summary' => 'Embedding document summary.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'uploaded_by_user_id' => null,
            'is_active' => true,
        ]);
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
            'embedding_vector' => $overrides['embedding_vector'] ?? null,
            'embedding_vector_pgvector' => $overrides['embedding_vector_pgvector'] ?? null,
        ], $overrides));
    }

    /**
     * @return array<int, float>
     */
    private function embeddingVector(int $dimension, float $value): array
    {
        return array_fill(0, $dimension, $value);
    }
}
