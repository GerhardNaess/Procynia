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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeChunkVocabularyCandidateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_pending_candidates_from_persisted_chunk_metadata_when_approved_vocab_is_empty(): void
    {
        [$document, $chunk] = $this->fixtureBundle();

        $service = app(KnowledgeChunkVocabularyCandidateService::class);
        $result = $service->syncForChunk($document, $chunk);

        $this->assertSame(5, $result['created_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(5, KnowledgeMetadataTermSuggestion::query()->count());

        $rows = KnowledgeMetadataTermSuggestion::query()
            ->orderBy('id')
            ->get([
                'source_chunk_id',
                'suggested_term',
                'suggested_type',
                'reason',
                'status',
            ]);

        $this->assertSame($chunk->id, $rows->first()->source_chunk_id);
        $this->assertSame([
            ['term' => 'Tema A', 'type' => 'topic'],
            ['term' => 'Underemne A', 'type' => 'sub_topic'],
            ['term' => 'Stikkord A', 'type' => 'keywords'],
            ['term' => 'Stikkord B', 'type' => 'keywords'],
            ['term' => 'Stikkord C', 'type' => 'keywords'],
        ], $rows->map(static fn (KnowledgeMetadataTermSuggestion $suggestion): array => [
            'term' => $suggestion->suggested_term,
            'type' => $suggestion->suggested_type,
        ])->all());
    }

    public function test_it_skips_existing_approved_and_pending_terms_without_normalizing_domain_values(): void
    {
        [$document, $chunk] = $this->fixtureBundle([
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Stikkord A', 'Stikkord B'],
        ]);

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

        $this->assertSame(1, $result['created_count']);
        $this->assertSame(3, $result['skipped_count']);
        $this->assertSame(3, KnowledgeMetadataTermSuggestion::query()->count());
        $this->assertDatabaseHas('knowledge_metadata_term_suggestions', [
            'customer_id' => $document->customer_id,
            'source_chunk_id' => $chunk->id,
            'suggested_term' => 'Stikkord B',
            'suggested_type' => 'keywords',
            'status' => KnowledgeMetadataTermSuggestion::STATUS_PENDING,
        ]);
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
}
