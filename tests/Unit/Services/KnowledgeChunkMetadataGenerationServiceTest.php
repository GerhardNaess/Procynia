<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataGenerationService;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class KnowledgeChunkMetadataGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_builds_embedding_input_without_persisting_descriptive_field_suggestions(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'id' => 'resp_metadata_1',
                'output_text' => json_encode([
                    'service_product_tag' => 'samhandling',
                    'theme_tag' => 'driftsmodell',
                    'topic' => 'møtestruktur',
                    'sub_topic' => 'operativ oppfølging',
                    'keywords' => ['SLA', 'Nytt begrep'],
                    'matched_terms' => ['samhandling', 'SLA'],
                    'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
                    'new_term_suggestions' => [],
                    'confidence_score' => 0.93,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        $this->app->instance(OpenAiClient::class, $client);

        $service = app(KnowledgeChunkMetadataGenerationService::class);
        $result = $service->generateForChunk($document, $chunk);

        $this->assertSame('Governance', $result['service_product_tag']);
        $this->assertSame('Drift', $result['theme_tag']);
        $this->assertSame('møtestruktur', $result['topic']);
        $this->assertSame('operativ oppfølging', $result['sub_topic']);
        $this->assertSame(['SLA', 'Nytt begrep'], $result['keywords']);
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED, $result['metadata_status']);
        $this->assertStringContainsString('Summary: Beskriver faste møtefora for oppfølging.', $result['embedding_input']);
        $this->assertStringContainsString('Service/product tag: Governance', $result['embedding_input']);
        $this->assertStringContainsString('Keywords: SLA, Nytt begrep', $result['embedding_input']);
        $this->assertSame([], $result['new_term_suggestions']);
        $this->assertSame(0, KnowledgeMetadataTermSuggestion::query()->count());
    }

    public function test_it_skips_persisting_suggestions_for_approved_canonical_names_and_synonyms(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'id' => 'resp_metadata_2',
                'output_text' => json_encode([
                    'service_product_tag' => 'samhandling',
                    'theme_tag' => 'driftsmodell',
                    'topic' => 'møtestruktur',
                    'sub_topic' => 'operativ oppfølging',
                    'keywords' => ['SLA', 'KPI'],
                    'matched_terms' => ['samhandling', 'SLA'],
                    'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
                    'new_term_suggestions' => [
                        [
                            'suggested_term' => 'Governance',
                            'suggested_type' => 'service_product_tag',
                            'suggested_canonical_parent' => null,
                            'reason' => 'Allerede godkjent kanonisk verdi.',
                        ],
                        [
                            'suggested_term' => 'samhandling',
                            'suggested_type' => 'service_product_tag',
                            'suggested_canonical_parent' => null,
                            'reason' => 'Allerede godkjent synonym.',
                        ],
                        [
                            'suggested_term' => 'Nytt begrep',
                            'suggested_type' => 'service_product_tag',
                            'suggested_canonical_parent' => null,
                            'reason' => 'Nytt forslag.',
                        ],
                    ],
                    'confidence_score' => 0.93,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        $this->app->instance(OpenAiClient::class, $client);

        $service = app(KnowledgeChunkMetadataGenerationService::class);
        $result = $service->generateForChunk($document, $chunk);

        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW, $result['metadata_status']);
        $this->assertCount(1, $result['new_term_suggestions']);
        $this->assertSame('Nytt begrep', $result['new_term_suggestions'][0]['suggested_term']);
        $this->assertSame(1, KnowledgeMetadataTermSuggestion::query()->count());
        $this->assertSame('Nytt begrep', KnowledgeMetadataTermSuggestion::query()->firstOrFail()->suggested_term);
    }

    public function test_it_fills_blank_topic_from_heading_path_and_keeps_the_chunk_content_unchanged(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $chunk->forceFill([
            'heading_path' => '2.1 Sammendrag og helhetlig løsningsforslag',
            'section_title' => '2.1 Sammendrag og helhetlig løsningsforslag',
        ])->save();

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'id' => 'resp_metadata_topic_fallback',
                'output_text' => json_encode([
                    'service_product_tag' => 'samhandling',
                    'theme_tag' => 'driftsmodell',
                    'topic' => '',
                    'sub_topic' => '',
                    'keywords' => ['SLA', 'KPI'],
                    'matched_terms' => ['samhandling', 'SLA'],
                    'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
                    'new_term_suggestions' => [],
                    'confidence_score' => 0.93,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        $this->app->instance(OpenAiClient::class, $client);

        $service = app(KnowledgeChunkMetadataGenerationService::class);
        $result = $service->generateForChunk($document, $chunk);

        $chunk->refresh();

        $this->assertSame('2.1 Sammendrag og helhetlig løsningsforslag', $result['topic']);
        $this->assertSame('Beskriver faste møtefora for oppfølging.', $result['sub_topic']);
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED, $result['metadata_status']);
        $this->assertStringContainsString('Topic: 2.1 Sammendrag og helhetlig løsningsforslag', $result['embedding_input']);
        $this->assertStringContainsString('Sub-topic: Beskriver faste møtefora for oppfølging.', $result['embedding_input']);
        $this->assertSame('Samhandling og møtestruktur for oppfølging av leveransen. SLA og KPI beskrives her.', $chunk->content);
        $this->assertSame(0, $chunk->start_offset);
        $this->assertSame(100, $chunk->end_offset);
    }

    public function test_it_fills_blank_sub_topic_from_keywords_when_summary_is_missing(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $chunk->forceFill([
            'heading_path' => '2.2 Strategisk partnerskap, veikart og måloppnåelse',
            'section_title' => '2.2 Strategisk partnerskap, veikart og måloppnåelse',
        ])->save();

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'id' => 'resp_metadata_keyword_fallback',
                'output_text' => json_encode([
                    'service_product_tag' => 'samhandling',
                    'theme_tag' => 'driftsmodell',
                    'topic' => '',
                    'sub_topic' => '',
                    'keywords' => ['SLA', 'KPI', 'Møtereferat'],
                    'matched_terms' => ['samhandling'],
                    'summary_for_retrieval' => '',
                    'new_term_suggestions' => [],
                    'confidence_score' => 0.93,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        $this->app->instance(OpenAiClient::class, $client);

        $service = app(KnowledgeChunkMetadataGenerationService::class);
        $result = $service->generateForChunk($document, $chunk);

        $this->assertSame('2.2 Strategisk partnerskap, veikart og måloppnåelse', $result['topic']);
        $this->assertSame('SLA, KPI, Møtereferat', $result['sub_topic']);
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED, $result['metadata_status']);
        $this->assertStringContainsString('Sub-topic: SLA, KPI, Møtereferat', $result['embedding_input']);
    }

    public function test_it_uses_section_title_as_a_last_resort_topic_fallback_without_modifying_chunk_bounds(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $chunk->forceFill([
            'heading_path' => null,
            'section_title' => 'Generell seksjonstitel',
        ])->save();

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andReturn([
                'id' => 'resp_metadata_section_title_fallback',
                'output_text' => json_encode([
                    'service_product_tag' => 'samhandling',
                    'theme_tag' => 'driftsmodell',
                    'topic' => '',
                    'sub_topic' => '',
                    'keywords' => [],
                    'matched_terms' => [],
                    'summary_for_retrieval' => '',
                    'new_term_suggestions' => [],
                    'confidence_score' => 0.93,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        $this->app->instance(OpenAiClient::class, $client);

        $service = app(KnowledgeChunkMetadataGenerationService::class);
        $result = $service->generateForChunk($document, $chunk);

        $chunk->refresh();

        $this->assertSame('Generell seksjonstitel', $result['topic']);
        $this->assertSame('Generell seksjonstitel', $result['sub_topic']);
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW, $result['metadata_status']);
        $this->assertSame('Samhandling og møtestruktur for oppfølging av leveransen. SLA og KPI beskrives her.', $chunk->content);
        $this->assertSame(0, $chunk->start_offset);
        $this->assertSame(100, $chunk->end_offset);
    }

    public function test_it_returns_failed_metadata_when_openai_throws_and_preserves_chunk_content_for_embedding(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')
            ->once()
            ->andThrow(new RuntimeException('OpenAI metadata request failed.'));
        $this->app->instance(OpenAiClient::class, $client);

        $service = app(KnowledgeChunkMetadataGenerationService::class);
        $result = $service->generateForChunk($document, $chunk);

        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_FAILED, $result['metadata_status']);
        $this->assertNull($result['service_product_tag']);
        $this->assertNull($result['theme_tag']);
        $this->assertNull($result['topic']);
        $this->assertNull($result['sub_topic']);
        $this->assertSame([], $result['keywords']);
        $this->assertSame([], $result['new_term_suggestions']);
        $this->assertStringContainsString('Content: Samhandling og møtestruktur for oppfølging av leveransen. SLA og KPI beskrives her.', $result['embedding_input']);
    }

    private function fixtureBundle(): array
    {
        $customer = $this->createCustomer('Generation Customer AS');

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'service_product_tag',
            'canonical_name' => 'Governance',
            'synonyms' => ['samhandling'],
            'description' => 'Styringsmodell.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'theme_tag',
            'canonical_name' => 'Drift',
            'synonyms' => ['driftsmodell'],
            'description' => 'Driftsrelatert tema.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'topic',
            'canonical_name' => 'Samhandlingsfora og møtestruktur',
            'synonyms' => ['møtestruktur'],
            'description' => 'Møtefora.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'sub_topic',
            'canonical_name' => 'Strategisk, taktisk og operativ oppfølging',
            'synonyms' => ['operativ oppfølging'],
            'description' => 'Oppfølging.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'keyword',
            'canonical_name' => 'SLA',
            'synonyms' => ['service level agreement'],
            'description' => 'Nøkkelord.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => 'keyword',
            'canonical_name' => 'KPI',
            'synonyms' => ['målepunkt'],
            'description' => 'Nøkkelord.',
            'approved' => true,
        ]);

        $document = KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Metadata document',
            'content' => 'Samhandling og møtestruktur for oppfølging av leveransen.',
            'original_filename' => 'metadata-document.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-documents/metadata-document.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => 'Samhandling og møtestruktur for oppfølging av leveransen.',
            'summary' => 'Oppsummering',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'uploaded_by_user_id' => null,
            'is_active' => true,
        ]);

        $chunk = KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Samhandling og møtestruktur for oppfølging av leveransen. SLA og KPI beskrives her.',
            'start_offset' => 0,
            'end_offset' => 100,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
        ]);

        return [$document, $chunk];
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
