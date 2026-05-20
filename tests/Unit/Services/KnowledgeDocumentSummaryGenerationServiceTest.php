<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Knowledge\KnowledgeDocumentSummaryGenerationService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class KnowledgeDocumentSummaryGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_summary_context_from_multiple_chunks_before_requesting_a_summary(): void
    {
        [$document, $firstChunk, $secondChunk] = $this->fixtureBundle();

        $expectedSummary = 'Dokumentet beskriver koordinering, risiko og kostnadsstyring.';

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($document, $firstChunk, $secondChunk): bool {
                $userPrompt = (string) data_get($payload, 'input.1.content.0.text', '');
                $decoded = json_decode($userPrompt, true);

                return is_array($decoded)
                    && data_get($decoded, 'document.id') === $document->id
                    && data_get($decoded, 'document_context.source_type') === 'chunks'
                    && str_contains((string) data_get($decoded, 'document_context.chunks.0.content', ''), $firstChunk->content)
                    && str_contains((string) data_get($decoded, 'document_context.chunks.1.content', ''), $secondChunk->content)
                    && data_get($decoded, 'instructions.0') !== null;
            }))
            ->andReturn([
                'output_text' => json_encode([
                    'summary' => $expectedSummary,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        $this->app->instance(OpenAiClient::class, $client);

        $service = app(KnowledgeDocumentSummaryGenerationService::class);
        $summary = $service->generateForDocument($document);

        $this->assertSame($expectedSummary, $summary);
    }

    /**
     * Purpose: Build one document and two content chunks for document summary service tests.
     * Inputs: None.
     * Returns: The persisted document and its ordered chunks.
     * Side effects: Creates rows in the test database.
     *
     * @return array{0: KnowledgeItem, 1: KnowledgeItemChunk, 2: KnowledgeItemChunk}
     */
    private function fixtureBundle(): array
    {
        $customer = $this->createCustomer('Summary Customer AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'summary-source.pdf',
            'content' => 'Første del beskriver koordinering. Andre del beskriver risiko og kostnadsstyring.',
            'original_filename' => 'summary-source.pdf',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-documents/summary-source.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'extracted_text' => 'Første del beskriver koordinering. Andre del beskriver risiko og kostnadsstyring.',
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'is_active' => true,
        ]);

        $firstChunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Første del beskriver koordinering og samhandling i etableringsprosjektet.',
            'start_offset' => 0,
            'end_offset' => 74,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        $secondChunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Andre del beskriver risiko, oppfølging og kostnadsstyring i dokumentet.',
            'start_offset' => 75,
            'end_offset' => 146,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        return [$document, $firstChunk, $secondChunk];
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
}
