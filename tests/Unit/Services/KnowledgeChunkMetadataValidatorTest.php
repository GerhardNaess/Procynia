<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTerm;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataValidator;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeChunkMetadataValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_auto_approves_clean_high_confidence_metadata(): void
    {
        [$document, $chunk, $vocabularyMap] = $this->fixtureBundle();

        $validator = app(KnowledgeChunkMetadataValidator::class);
        $result = $validator->validate($document, $chunk, [
            'service_product_tag' => 'samhandling',
            'theme_tag' => 'driftsmodell',
            'topic' => 'møtestruktur',
            'sub_topic' => 'operativ oppfølging',
            'keywords' => ['SLA', 'KPI'],
            'matched_terms' => ['samhandling', 'SLA'],
            'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
            'confidence_score' => 0.94,
            'new_term_suggestions' => [],
        ], $vocabularyMap);

        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED, $result['metadata_status']);
        $this->assertSame('Governance', $result['service_product_tag']);
        $this->assertSame('Drift', $result['theme_tag']);
        $this->assertSame('møtestruktur', $result['topic']);
        $this->assertSame('operativ oppfølging', $result['sub_topic']);
        $this->assertSame(['SLA', 'KPI'], $result['keywords']);
        $this->assertSame(['Governance', 'SLA'], $result['matched_terms']);
        $this->assertSame('Beskriver faste møtefora for oppfølging.', $result['summary_for_retrieval']);
        $this->assertSame(0.94, $result['confidence_score']);
        $this->assertSame([], $result['new_term_suggestions']);
    }

    public function test_it_marks_low_confidence_metadata_as_pending_review(): void
    {
        [$document, $chunk, $vocabularyMap] = $this->fixtureBundle();

        $validator = app(KnowledgeChunkMetadataValidator::class);
        $result = $validator->validate($document, $chunk, [
            'service_product_tag' => 'samhandling',
            'theme_tag' => 'driftsmodell',
            'topic' => 'møtestruktur',
            'sub_topic' => 'operativ oppfølging',
            'keywords' => ['SLA', 'KPI'],
            'matched_terms' => ['samhandling', 'SLA'],
            'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
            'confidence_score' => 0.41,
            'new_term_suggestions' => [],
        ], $vocabularyMap);

        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW, $result['metadata_status']);
        $this->assertTrue($result['pending_review']);
    }

    public function test_it_marks_unknown_values_as_pending_review_and_keeps_them_for_review(): void
    {
        [$document, $chunk, $vocabularyMap] = $this->fixtureBundle();

        $validator = app(KnowledgeChunkMetadataValidator::class);
        $result = $validator->validate($document, $chunk, [
            'service_product_tag' => 'Ukjent produkt',
            'theme_tag' => 'driftsmodell',
            'topic' => 'møtestruktur',
            'sub_topic' => 'operativ oppfølging',
            'keywords' => ['SLA', 'Nytt begrep'],
            'matched_terms' => ['samhandling', 'SLA'],
            'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
            'confidence_score' => 0.92,
            'new_term_suggestions' => [],
        ], $vocabularyMap);

        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW, $result['metadata_status']);
        $this->assertSame('Ukjent produkt', $result['service_product_tag']);
        $this->assertSame(['SLA', 'Nytt begrep'], $result['keywords']);
        $this->assertNotEmpty($result['new_term_suggestions']);
        $this->assertSame('Ukjent produkt', $result['new_term_suggestions'][0]['suggested_term']);
    }

    public function test_it_ignores_suggestions_that_match_approved_canonical_names_or_synonyms(): void
    {
        [$document, $chunk, $vocabularyMap] = $this->fixtureBundle();

        $validator = app(KnowledgeChunkMetadataValidator::class);
        $result = $validator->validate($document, $chunk, [
            'service_product_tag' => 'samhandling',
            'theme_tag' => 'driftsmodell',
            'topic' => 'møtestruktur',
            'sub_topic' => 'operativ oppfølging',
            'keywords' => ['SLA', 'KPI'],
            'matched_terms' => ['samhandling', 'SLA'],
            'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
            'confidence_score' => 0.94,
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
        ], $vocabularyMap);

        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW, $result['metadata_status']);
        $this->assertCount(1, $result['new_term_suggestions']);
        $this->assertSame('Nytt begrep', $result['new_term_suggestions'][0]['suggested_term']);
    }

    public function test_it_preserves_persisted_descriptive_metadata_when_ai_payload_is_empty(): void
    {
        [$document, $chunk, $vocabularyMap] = $this->fixtureBundle();

        $chunk->forceFill([
            'topic' => 'Persisted topic',
            'sub_topic' => 'Persisted sub-topic',
            'keywords' => ['alpha', 'beta'],
        ])->save();

        $validator = app(KnowledgeChunkMetadataValidator::class);
        $result = $validator->validate($document, $chunk, [
            'service_product_tag' => 'samhandling',
            'theme_tag' => 'driftsmodell',
            'topic' => null,
            'sub_topic' => null,
            'keywords' => [],
            'matched_terms' => [],
            'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
            'confidence_score' => 0.94,
            'new_term_suggestions' => [],
        ], $vocabularyMap);

        $this->assertSame('Persisted topic', $result['topic']);
        $this->assertSame('Persisted sub-topic', $result['sub_topic']);
        $this->assertSame(['alpha', 'beta'], $result['keywords']);
    }

    public function test_it_trims_and_limits_descriptive_scalar_fields(): void
    {
        [$document, $chunk, $vocabularyMap] = $this->fixtureBundle();

        $validator = app(KnowledgeChunkMetadataValidator::class);
        $longTopic = '  '.str_repeat('Tema ', 60).'  ';
        $longSubTopic = " \n".str_repeat('Undertema ', 60)."\n ";

        $result = $validator->validate($document, $chunk, [
            'service_product_tag' => 'samhandling',
            'theme_tag' => 'driftsmodell',
            'topic' => $longTopic,
            'sub_topic' => $longSubTopic,
            'keywords' => ['SLA', 'KPI'],
            'matched_terms' => ['samhandling', 'SLA'],
            'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
            'confidence_score' => 0.94,
            'new_term_suggestions' => [],
        ], $vocabularyMap);

        $this->assertSame(Str::limit(Str::squish($longTopic), 191, ''), $result['topic']);
        $this->assertSame(Str::limit(Str::squish($longSubTopic), 191, ''), $result['sub_topic']);
        $this->assertTrue(mb_strlen((string) $result['topic'], 'UTF-8') <= 191);
        $this->assertTrue(mb_strlen((string) $result['sub_topic'], 'UTF-8') <= 191);
    }

    public function test_it_normalizes_blank_descriptive_scalar_fields_to_null_without_fallbacks(): void
    {
        [$document, $chunk, $vocabularyMap] = $this->fixtureBundle();

        $validator = app(KnowledgeChunkMetadataValidator::class);
        $result = $validator->validate($document, $chunk, [
            'service_product_tag' => 'samhandling',
            'theme_tag' => 'driftsmodell',
            'topic' => '   ',
            'sub_topic' => "\n\t",
            'keywords' => ['SLA', 'KPI'],
            'matched_terms' => ['samhandling', 'SLA'],
            'summary_for_retrieval' => 'Beskriver faste møtefora for oppfølging.',
            'confidence_score' => 0.94,
            'new_term_suggestions' => [],
        ], $vocabularyMap);

        $this->assertNull($result['topic']);
        $this->assertNull($result['sub_topic']);
    }

    private function fixtureBundle(): array
    {
        $customer = $this->createCustomer('Validator Customer AS');

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

        $document = $this->createKnowledgeItem($customer, [
            'title' => 'Metadata document',
            'original_filename' => 'metadata-document.docx',
            'content' => 'Samhandling og møtestruktur for oppfølging av leveransen.',
            'summary' => 'Dokumentsammendrag.',
        ]);

        $chunk = $this->createChunk($document, 0, [
            'content' => 'Samhandling og møtestruktur for oppfølging av leveransen. SLA og KPI beskrives her.',
        ]);

        $vocabularyMap = app(KnowledgeMetadataVocabularyService::class)->buildForCustomer($customer->id);

        return [$document, $chunk, $vocabularyMap];
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
            'storage_path' => $overrides['storage_path'] ?? 'customers/'.$customer->id.'/knowledge-documents/metadata-document.docx',
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
