<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Retrieval\MetadataCandidateRetrievalService;
use App\Support\PgVector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetadataCandidateRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_retrieves_chunks_matching_topic(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Metadata Retrieval AS');
        $document = $this->createKnowledgeItem($customer);
        $match = $this->createChunk($document, 0, [
            'topic' => 'Tema A',
            'content' => 'Innhold om tema a.',
        ]);
        $this->createChunk($document, 1, [
            'topic' => 'Tema B',
            'content' => 'Innhold om tema b.',
        ]);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => [
                'topic' => ['Tema A'],
            ],
            'search_text' => 'tema a',
            'intent_summary' => 'Leter etter tema a.',
            'confidence' => 0.91,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($match->id, $result->first()['chunk_id']);
        $this->assertSame('Tema A', $result->first()['topic']);
        $this->assertGreaterThan(0, $result->first()['metadata_score']);
    }

    public function test_it_retrieves_chunks_matching_sub_topic(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Metadata Retrieval AS');
        $document = $this->createKnowledgeItem($customer);
        $match = $this->createChunk($document, 0, [
            'sub_topic' => 'Underemne A',
            'content' => 'Innhold om underemne a.',
        ]);
        $this->createChunk($document, 1, [
            'sub_topic' => 'Underemne B',
            'content' => 'Innhold om underemne b.',
        ]);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => [
                'sub_topic' => ['Underemne A'],
            ],
            'search_text' => 'underemne a',
            'intent_summary' => 'Leter etter underemne a.',
            'confidence' => 0.84,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($match->id, $result->first()['chunk_id']);
        $this->assertSame('Underemne A', $result->first()['sub_topic']);
    }

    public function test_it_retrieves_chunks_matching_keyword_overlap(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Metadata Retrieval AS');
        $document = $this->createKnowledgeItem($customer);
        $match = $this->createChunk($document, 0, [
            'keywords' => ['Nøkkelord A', 'Nøkkelord B'],
            'content' => 'Innhold med nøkkelord a.',
        ]);
        $this->createChunk($document, 1, [
            'keywords' => ['Nøkkelord C'],
            'content' => 'Innhold med nøkkelord c.',
        ]);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => [
                'keywords' => ['Nøkkelord A'],
            ],
            'search_text' => 'nøkkelord a',
            'intent_summary' => 'Leter etter nøkkelord a.',
            'confidence' => 0.77,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($match->id, $result->first()['chunk_id']);
        $this->assertSame(['Nøkkelord A', 'Nøkkelord B'], $result->first()['keywords']);
    }

    public function test_it_ranks_chunks_with_more_metadata_hits_above_single_field_matches(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Metadata Retrieval AS');
        $document = $this->createKnowledgeItem($customer);
        $strongMatch = $this->createChunk($document, 0, [
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne A',
            'keywords' => ['Nøkkelord A'],
            'content' => 'Sterk metadata-match.',
        ]);
        $weakMatch = $this->createChunk($document, 1, [
            'topic' => 'Tema A',
            'sub_topic' => 'Underemne B',
            'keywords' => ['Nøkkelord Z'],
            'content' => 'Svakere metadata-match.',
        ]);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => [
                'topic' => ['Tema A'],
                'sub_topic' => ['Underemne A', 'Underemne B'],
                'keywords' => ['Nøkkelord A'],
            ],
            'search_text' => 'tema a underemne a nøkkelord a',
            'intent_summary' => 'Leter etter kombinasjonen av metadata.',
            'confidence' => 0.93,
        ]);

        $this->assertCount(2, $result);
        $this->assertSame($strongMatch->id, $result->first()['chunk_id']);
        $this->assertSame($weakMatch->id, $result->get(1)['chunk_id']);
        $this->assertGreaterThan($result->get(1)['metadata_score'], $result->first()['metadata_score']);
    }

    public function test_it_returns_an_empty_collection_when_no_metadata_is_selected(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Metadata Retrieval AS');
        $this->createKnowledgeItem($customer);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => [],
            'search_text' => 'ingen metadata',
            'intent_summary' => 'Tom plan.',
            'confidence' => 0.0,
        ]);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_it_uses_pgvector_similarity_and_exposes_it_when_requirement_embedding_is_1536_dimensions(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Pgvector Scope AS');
        $foreignCustomer = $this->createCustomer('Pgvector Foreign AS');
        $document = $this->createKnowledgeItem($customer, ['original_filename' => 'local-pgvector.docx']);
        $foreignDocument = $this->createKnowledgeItem($foreignCustomer, ['original_filename' => 'foreign-pgvector.docx']);
        $requirementEmbedding = $this->embeddingVector(1536, 0);

        $localChunk = $this->createChunk($document, 0, [
            'topic' => 'Tema Pgvector',
            'content' => 'Innhold om pgvector tema.',
            'embedding_vector_pgvector' => PgVector::literal($requirementEmbedding),
        ]);
        $this->createChunk($foreignDocument, 0, [
            'topic' => 'Tema Pgvector',
            'content' => 'Fremmed innhold som skal filtreres bort.',
            'embedding_vector_pgvector' => PgVector::literal($this->embeddingVector(1536, 1)),
        ]);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => ['topic' => ['Tema Pgvector']],
            'search_text' => 'pgvector tema',
            'intent_summary' => 'Leter etter pgvector tema.',
            'confidence' => 0.91,
        ], $requirementEmbedding);

        $this->assertCount(1, $result);
        $this->assertSame($localChunk->id, $result->first()['chunk_id']);
        $this->assertNotNull($result->first()['embedding_similarity']);
        $this->assertSame(1.0, $result->first()['embedding_similarity']);
    }

    public function test_it_excludes_non_company_documents_even_when_they_match_metadata_and_embedding(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Company Scope AS');
        $companyDocument = $this->createKnowledgeItem($customer, ['original_filename' => 'company-scope.docx']);
        $personalDocument = $this->createKnowledgeItem($customer, [
            'original_filename' => 'personal-scope.docx',
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_PERSONAL,
        ]);
        $requirementEmbedding = $this->embeddingVector(1536, 0);

        $companyChunk = $this->createChunk($companyDocument, 0, [
            'topic' => 'Løsning',
            'sub_topic' => 'Dokumentasjon',
            'keywords' => ['løsningen'],
            'content' => 'Leverandøren skal beskrive løsningen.',
            'embedding_vector_pgvector' => PgVector::literal($requirementEmbedding),
        ]);
        $this->createChunk($personalDocument, 0, [
            'topic' => 'Løsning',
            'sub_topic' => 'Dokumentasjon',
            'keywords' => ['løsningen'],
            'content' => 'Leverandøren skal beskrive løsningen.',
            'embedding_vector_pgvector' => PgVector::literal($requirementEmbedding),
        ]);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => [
                'topic' => ['Løsning'],
                'sub_topic' => ['Dokumentasjon'],
                'keywords' => ['løsningen'],
            ],
            'search_text' => 'leverandøren skal beskrive løsningen',
            'intent_summary' => 'Tester company scope.',
            'confidence' => 0.95,
        ], $requirementEmbedding);

        $this->assertCount(1, $result);
        $this->assertSame($companyChunk->id, $result->first()['chunk_id']);
        $this->assertSame($companyDocument->id, $result->first()['knowledge_item_id']);
    }

    public function test_it_falls_back_to_default_ordering_when_requirement_embedding_has_wrong_dimension(): void
    {
        $service = app(MetadataCandidateRetrievalService::class);
        $customer = $this->createCustomer('Fallback Dim AS');
        $document = $this->createKnowledgeItem($customer, ['original_filename' => 'dim-fallback.docx']);
        $this->createChunk($document, 0, [
            'topic' => 'Tema Dim',
            'content' => 'Innhold om dim-fallback tema.',
        ]);

        $result = $service->retrieveForCustomer($customer->id, [
            'selected_metadata' => ['topic' => ['Tema Dim']],
            'search_text' => 'dim fallback',
            'intent_summary' => 'Leter etter dim tema.',
            'confidence' => 0.9,
        ], [0.5, 0.5, 0.0]);

        $this->assertCount(1, $result);
        $this->assertNull($result->first()['embedding_similarity']);
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
        $pgvector = isset($overrides['embedding_vector_pgvector']) ? $overrides['embedding_vector_pgvector'] : null;
        unset($overrides['embedding_vector_pgvector']);

        $chunk = KnowledgeItemChunk::query()->create(array_merge([
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

        if ($pgvector !== null) {
            DB::table('knowledge_item_chunks')
                ->where('id', $chunk->id)
                ->update(['embedding_vector_pgvector' => $pgvector]);
        }

        return $chunk;
    }

    /**
     * @return array<int, float>
     */
    private function embeddingVector(int $dimension, int $oneIndex): array
    {
        $vector = array_fill(0, $dimension, 0.0);
        $vector[$oneIndex] = 1.0;

        return $vector;
    }
}
