<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Knowledge\KnowledgeChunkVocabularyCandidateService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class KnowledgeChunkVocabularyCandidateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_pending_candidates_from_persisted_chunk_metadata_when_approved_vocab_is_empty(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $this->bindOpenAiClient([
            $this->enrichmentResponse('Tema A', ['Tema A', 'Tema A alternativ'], 'Beskrivelse av Tema A.', 'Foreslått fra chunk-metadata basert på innhold under Kapittel 1.'),
            $this->enrichmentResponse('Underemne A', ['Underemne A', 'Underemne A alternativ'], 'Beskrivelse av Underemne A.', 'Foreslått fra chunk-metadata basert på innhold under Kapittel 1.'),
        ]);

        $service = app(KnowledgeChunkVocabularyCandidateService::class);
        $result = $service->syncForChunk($document, $chunk);

        $this->assertSame(2, $result['created_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(2, KnowledgeMetadataTermSuggestion::query()->count());

        $rows = KnowledgeMetadataTermSuggestion::query()
            ->orderBy('id')
            ->get([
                'source_chunk_id',
                'suggested_term',
                'suggested_type',
                'suggested_canonical_name',
                'suggested_synonyms',
                'suggested_description',
                'reason',
                'status',
            ]);

        $this->assertSame($chunk->id, $rows->first()->source_chunk_id);
        $this->assertSame([
            ['term' => 'Tema A', 'type' => 'topic'],
            ['term' => 'Underemne A', 'type' => 'sub_topic'],
        ], $rows->map(static fn (KnowledgeMetadataTermSuggestion $suggestion): array => [
            'term' => $suggestion->suggested_term,
            'type' => $suggestion->suggested_type,
        ])->all());
        $this->assertSame(0, KnowledgeMetadataTerm::query()->count());
        $this->assertNotContains('Tema A', $rows->first()->suggested_synonyms);
        $this->assertNotEmpty($rows->first()->suggested_description);
        $this->assertNotEmpty($rows->first()->reason);
        $this->assertSame(0, $rows->where('suggested_type', 'keywords')->count());
    }

    public function test_it_skips_existing_approved_and_pending_terms_without_normalizing_domain_values(): void
    {
        [$document, $chunk] = $this->fixtureBundle([
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
        ]);

        $this->bindOpenAiClient([]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $document->customer_id,
            'type' => 'topic',
            'canonical_name' => 'Tema A',
            'synonyms' => [],
            'description' => null,
            'approved' => true,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $document->customer_id,
            'source_chunk_id' => $chunk->id,
            'suggested_term' => 'Underemne A',
            'suggested_canonical_name' => 'Underemne A',
            'suggested_type' => 'sub_topic',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Existing pending term.',
            'confidence_score' => null,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        KnowledgeMetadataTermSuggestion::query()->create([
            'customer_id' => $document->customer_id,
            'source_chunk_id' => $chunk->id,
            'suggested_term' => 'Stikkord A',
            'suggested_canonical_name' => 'Stikkord A',
            'suggested_type' => 'keywords',
            'suggested_synonyms' => [],
            'suggested_description' => null,
            'suggested_canonical_parent' => null,
            'related_existing_term_id' => null,
            'reason' => 'Existing pending keyword.',
            'confidence_score' => null,
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);

        $service = app(KnowledgeChunkVocabularyCandidateService::class);
        $result = $service->syncForChunk($document, $chunk);

        $this->assertSame(0, $result['created_count']);
        $this->assertSame(2, $result['skipped_count']);
        $this->assertSame(2, KnowledgeMetadataTermSuggestion::query()->count());
    }

    public function test_it_uses_fallback_reason_when_enrichment_fails_and_keeps_candidate_creation_running(): void
    {
        [$document, $chunk] = $this->fixtureBundle([
            'sub_topic' => null,
            'keywords' => [],
            'heading_path' => '2.1 Sammendrag og grunnlag',
            'section_title' => '2.1 Sammendrag og grunnlag',
            'summary_for_retrieval' => 'Kort oppsummering for gjenfinning.',
        ]);

        $this->bindOpenAiClient([], true);

        $service = app(KnowledgeChunkVocabularyCandidateService::class);
        $result = $service->syncForChunk($document, $chunk);

        $this->assertSame(1, $result['created_count']);
        $this->assertSame(0, $result['skipped_count']);

        $suggestion = KnowledgeMetadataTermSuggestion::query()->firstOrFail();

        $this->assertSame('Tema A', $suggestion->suggested_canonical_name);
        $this->assertSame([], $suggestion->suggested_synonyms);
        $this->assertNotEmpty($suggestion->suggested_description);
        $this->assertStringContainsString('Foreslått fra chunk-metadata', $suggestion->reason);
        $this->assertSame(0, KnowledgeMetadataTerm::query()->count());
    }

    public function test_it_removes_the_canonical_name_from_synonyms_before_persisting(): void
    {
        [$document, $chunk] = $this->fixtureBundle([
            'sub_topic' => null,
            'keywords' => [],
            'heading_path' => '2.2 Strategisk partnerskap',
            'section_title' => '2.2 Strategisk partnerskap',
            'summary_for_retrieval' => 'Kort oppsummering for gjenfinning.',
        ]);

        $this->bindOpenAiClient([
            $this->enrichmentResponse('Tema A', ['Tema A', 'Tema A alternativ', 'Tema A'], 'Beskrivelse av Tema A.', 'Foreslått fra chunk-metadata basert på innhold under 2.2 Strategisk partnerskap.'),
        ]);

        $service = app(KnowledgeChunkVocabularyCandidateService::class);
        $service->syncForChunk($document, $chunk);

        $suggestion = KnowledgeMetadataTermSuggestion::query()->firstOrFail();

        $this->assertSame('Tema A', $suggestion->suggested_canonical_name);
        $this->assertSame(['Tema A alternativ'], $suggestion->suggested_synonyms);
    }

    /**
     * Purpose: Build one document and chunk for candidate service tests.
     * Inputs: Optional overrides for chunk metadata.
     * Returns: The persisted document and chunk pair.
     * Side effects: Creates knowledge records in the test database.
     *
     * @return array{0: KnowledgeItem, 1: KnowledgeItemChunk}
     */
    private function fixtureBundle(array $chunkOverrides = []): array
    {
        $customer = $this->createCustomer('Candidate Customer AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Candidate document',
            'content' => 'Tema A, Underemne A og tre stikkord.',
            'original_filename' => 'candidate-document.docx',
            'storage_path' => 'customers/'.$customer->id.'/knowledge-documents/candidate-document.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'content_type' => KnowledgeItem::CONTENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => 'Tema A, Underemne A og tre stikkord.',
            'summary' => 'Oppsummering',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'uploaded_by_user_id' => null,
            'is_active' => true,
        ]);

        $chunk = KnowledgeItemChunk::query()->create(array_merge([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Tema A, Underemne A og tre stikkord.',
            'start_offset' => 0,
            'end_offset' => 100,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Stikkord A', 'Stikkord B', 'Stikkord C'],
        ], $chunkOverrides));

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

    /**
     * Purpose: Bind a deterministic OpenAI client for suggestion enrichment tests.
     * Inputs: The list of responses to return, and whether the first call should fail.
     * Returns: None.
     * Side effects: Replaces the OpenAI client binding in the container.
     */
    private function bindOpenAiClient(array $responses, bool $shouldFail = false): void
    {
        $client = Mockery::mock(OpenAiClient::class);

        if ($shouldFail) {
            $client->shouldReceive('createResponse')
                ->andThrow(new RuntimeException('OpenAI suggestion enrichment failed.'));
        } elseif ($responses === []) {
            $client->shouldReceive('createResponse')
                ->never();
        } else {
            $client->shouldReceive('createResponse')
                ->times(count($responses))
                ->andReturnUsing(function () use (&$responses): array {
                    return array_shift($responses) ?? [];
                });
        }

        $this->app->instance(OpenAiClient::class, $client);
    }

    /**
     * Purpose: Build one OpenAI response payload for suggestion enrichment tests.
     * Inputs: The canonical name, synonyms, description, and reason.
     * Returns: A deterministic OpenAI-style response array.
     * Side effects: None.
     */
    private function enrichmentResponse(string $canonicalName, array $synonyms, string $description, string $reason): array
    {
        return [
            'id' => 'resp_enrichment_'.Str::slug($canonicalName).'-'.Str::random(6),
            'output_text' => json_encode([
                'canonical_name' => $canonicalName,
                'synonyms' => $synonyms,
                'description' => $description,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}
