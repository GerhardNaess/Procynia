<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\OpenAi\EmbeddingService;
use App\Services\OpenAi\OpenAiClient;
use App\Support\PgVector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class BackfillKnowledgeChunkEmbeddingVectorsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_backfills_json_embeddings_to_pgvector_and_is_idempotent_without_calling_ai(): void
    {
        $embedding = $this->deterministicEmbeddingVector();
        $wrongDimensionEmbedding = $this->deterministicEmbeddingVector(1535, 0.002);

        $this->bindAiClientsAsStrictMocks();

        $customer = $this->createCustomer();
        $knowledgeItem = $this->createKnowledgeItem($customer);

        $goodChunk = $this->createChunk($knowledgeItem, 0, [
            'embedding_vector' => $embedding,
            'embedding_vector_pgvector' => null,
        ]);
        $alreadyBackfilledChunk = $this->createChunk($knowledgeItem, 1, [
            'embedding_vector' => $embedding,
            'embedding_vector_pgvector' => PgVector::literal($embedding),
        ]);
        $missingEmbeddingChunk = $this->createChunk($knowledgeItem, 2, [
            'embedding_vector' => null,
            'embedding_vector_pgvector' => null,
        ]);
        $invalidDimensionChunk = $this->createChunk($knowledgeItem, 3, [
            'embedding_vector' => $wrongDimensionEmbedding,
            'embedding_vector_pgvector' => null,
        ]);

        $this->artisan('knowledge:backfill-pgvector-embeddings')
            ->expectsOutputToContain('[PROCYNIA][EMBEDDINGS][PGVECTOR_BACKFILL] processed=4 updated=1 skipped_missing_embedding=1 skipped_invalid_dimensions=1 skipped_already_backfilled=1')
            ->assertSuccessful();

        $this->assertSame($embedding, $goodChunk->refresh()->embedding_vector);
        $this->assertSame($embedding, $alreadyBackfilledChunk->refresh()->embedding_vector);
        $this->assertNull($missingEmbeddingChunk->refresh()->embedding_vector);
        $this->assertSame($wrongDimensionEmbedding, $invalidDimensionChunk->refresh()->embedding_vector);

        $goodChunkLiteralRow = DB::selectOne(
            'select embedding_vector_pgvector::text as literal from knowledge_item_chunks where id = ?',
            [$goodChunk->id],
        );
        $alreadyBackfilledChunkLiteralRow = DB::selectOne(
            'select embedding_vector_pgvector::text as literal from knowledge_item_chunks where id = ?',
            [$alreadyBackfilledChunk->id],
        );
        $missingEmbeddingChunkLiteralRow = DB::selectOne(
            'select embedding_vector_pgvector::text as literal from knowledge_item_chunks where id = ?',
            [$missingEmbeddingChunk->id],
        );
        $invalidDimensionChunkLiteralRow = DB::selectOne(
            'select embedding_vector_pgvector::text as literal from knowledge_item_chunks where id = ?',
            [$invalidDimensionChunk->id],
        );

        $this->assertSame(PgVector::literal($embedding), $goodChunkLiteralRow->literal);
        $this->assertSame(PgVector::literal($embedding), $alreadyBackfilledChunkLiteralRow->literal);
        $this->assertNull($missingEmbeddingChunkLiteralRow->literal);
        $this->assertNull($invalidDimensionChunkLiteralRow->literal);

        $literalDistanceRow = DB::selectOne(
            'select embedding_vector_pgvector::text as literal, embedding_vector_pgvector <=> ?::vector as distance from knowledge_item_chunks where id = ?',
            [PgVector::literal($embedding), $goodChunk->id],
        );

        $this->assertSame(PgVector::literal($embedding), $literalDistanceRow->literal);
        $this->assertSame(0.0, (float) $literalDistanceRow->distance);

        $this->artisan('knowledge:backfill-pgvector-embeddings')
            ->expectsOutputToContain('[PROCYNIA][EMBEDDINGS][PGVECTOR_BACKFILL] processed=4 updated=0 skipped_missing_embedding=1 skipped_invalid_dimensions=1 skipped_already_backfilled=2')
            ->assertSuccessful();

        $this->assertSame($embedding, $goodChunk->refresh()->embedding_vector);
        $this->assertSame($embedding, $alreadyBackfilledChunk->refresh()->embedding_vector);
        $this->assertNull($missingEmbeddingChunk->refresh()->embedding_vector_pgvector);
        $this->assertNull($invalidDimensionChunk->refresh()->embedding_vector_pgvector);
    }

    private function bindAiClientsAsStrictMocks(): void
    {
        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldNotReceive('get');
        $openAiClient->shouldNotReceive('createResponse');
        $openAiClient->shouldNotReceive('createEmbedding');
        $openAiClient->shouldNotReceive('post');

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldNotReceive('embedText');
        $embeddingService->shouldNotReceive('tryEmbedText');

        $this->app->instance(OpenAiClient::class, $openAiClient);
        $this->app->instance(EmbeddingService::class, $embeddingService);
    }

    private function createCustomer(): Customer
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
            'name' => 'Backfill Test Customer',
            'slug' => 'backfill-test-customer',
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function createKnowledgeItem(Customer $customer): KnowledgeItem
    {
        return KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Backfill test document',
            'content' => 'Backfill test content.',
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
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
            'embedding_vector' => $overrides['embedding_vector'] ?? null,
            'embedding_vector_pgvector' => $overrides['embedding_vector_pgvector'] ?? null,
        ], $overrides));
    }

    /**
     * @return array<int, float>
     */
    private function deterministicEmbeddingVector(int $dimensions = 1536, float $value = 0.001): array
    {
        return array_fill(0, $dimensions, $value);
    }
}
