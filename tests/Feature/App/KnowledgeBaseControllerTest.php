<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\OpenAi\EmbeddingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class KnowledgeBaseControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        $this->bindSuccessfulEmbeddingService();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_knowledge_base_index_lists_uploaded_documents_for_the_current_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer One AS');
        $foreignContext = $this->customerContext('Customer Two AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('company-profile.docx', 'Company profile knowledge text with enough detail to excerpt.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_COMPANY,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($foreignContext['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('foreign-profile.docx', 'Foreign customer content should stay hidden.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));
            $item = $items->first();

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter'
                && $items->count() === 1
                && $item !== null
                && $item['original_filename'] === 'company-profile.docx'
                && $item['show_url'] === route('app.ai.knowledge-base.show', ['knowledgeItem' => $item['id']])
                && $item['delete_url'] === route('app.ai.knowledge-base.destroy', ['knowledgeItem' => $item['id']])
                && $item['content_excerpt'] !== ''
                && $item['chunk_count'] > 0
                && $item['extraction_status'] === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && ! $items->contains(fn (array $candidate): bool => $candidate['original_filename'] === 'foreign-profile.docx');
        });
    }

    public function test_knowledge_base_create_page_can_be_opened(): void
    {
        $context = $this->customerContext('Customer Three AS');

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.create'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Create'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · Last opp'
                && count(data_get($page, 'props.documentTypeOptions', [])) === 6
                && data_get($page, 'props.defaultDocumentType') === KnowledgeItem::DOCUMENT_TYPE_OTHER
                && data_get($page, 'props.storeUrl') === route('app.ai.knowledge-base.store')
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index');
        });
    }

    public function test_knowledge_document_upload_extracts_text_stores_file_and_chunks_document(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four AS');
        $content = str_repeat('Reusable method text with enough length to force deterministic chunking. ', 26);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('method-description.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'method-description.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();
        $normalizedContent = $this->normalizeWhitespace($content);

        $this->assertStringStartsWith('customers/'.$context['customer']->id.'/knowledge-documents/', $document->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($document->storage_path));
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_METHOD, $document->document_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_METHOD, $document->content_type);
        $this->assertSame(KnowledgeItem::EXTRACTION_STATUS_COMPLETED, $document->extraction_status);
        $this->assertSame('', (string) $document->extraction_error);
        $this->assertSame($normalizedContent, $this->normalizeWhitespace((string) $document->extracted_text));
        $this->assertGreaterThan(0, $chunks->count());
        $this->assertSame(range(0, $chunks->count() - 1), $chunks->pluck('chunk_index')->all());
        $this->assertSame(
            array_fill(0, $chunks->count(), KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW),
            $chunks->pluck('review_status')->all(),
        );
    }

    public function test_knowledge_document_upload_generates_chunk_embeddings_when_embedding_generation_succeeds(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four B AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('embedding-success.docx', 'Embeddings should be persisted for this knowledge chunk.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'embedding-success.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        $this->assertSame([0.11, 0.22, 0.33], $chunk->embedding_vector);
        $this->assertSame('text-embedding-3-small', $chunk->embedding_model);
        $this->assertNotNull($chunk->embedding_generated_at);
        $this->assertNull($chunk->embedding_error);
    }

    public function test_knowledge_document_upload_persists_embedding_error_when_generation_fails(): void
    {
        Storage::fake('local');

        $service = Mockery::mock(EmbeddingService::class);
        $service->shouldReceive('tryEmbedText')
            ->once()
            ->andReturn([
                'ok' => false,
                'embedding' => null,
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => 'upstream_unavailable',
                'error_message' => 'OpenAI embedding request failed with HTTP status [503].',
                'upstream_status' => 503,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => '{"error":"upstream unavailable"}',
            ]);
        $this->app->instance(EmbeddingService::class, $service);

        $context = $this->customerContext('Customer Four C AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('embedding-failure.docx', 'Embedding should fail for this knowledge chunk.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'embedding-failure.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        $this->assertNull($chunk->embedding_vector);
        $this->assertNull($chunk->embedding_generated_at);
        $this->assertNull($chunk->embedding_model);
        $this->assertSame('OpenAI embedding request failed with HTTP status [503].', $chunk->embedding_error);
    }

    public function test_knowledge_document_upload_marks_failed_extraction_when_parsing_fails(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Five AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => UploadedFile::fake()->create(
                'broken.docx',
                128,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'broken.docx')
            ->firstOrFail();

        $this->assertStringStartsWith('customers/'.$context['customer']->id.'/knowledge-documents/', $document->storage_path);
        $this->assertSame(KnowledgeItem::EXTRACTION_STATUS_FAILED, $document->extraction_status);
        $this->assertSame('', (string) $document->extracted_text);
        $this->assertNotEmpty((string) $document->extraction_error);
        $this->assertSame(0, KnowledgeItemChunk::query()->where('knowledge_item_id', $document->id)->count());
    }

    public function test_knowledge_base_edit_page_can_be_opened(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six AS');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('reference-profile.docx', 'Reference project description used for AI knowledge.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'reference-profile.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · Rediger'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.original_filename') === 'reference-profile.docx'
                && data_get($page, 'props.knowledgeItem.extraction_status') === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index');
        });
    }

    public function test_knowledge_base_show_page_can_be_opened_with_chunks_and_metadata(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six B AS');
        $content = str_repeat('Chunked reference content used to power the detail page. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('detail-reference.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'detail-reference.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document): bool {
            $chunks = collect(data_get($page, 'props.knowledgeItem.chunks', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · '.$document->original_filename
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index')
                && data_get($page, 'props.editUrl') === route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id])
                && data_get($page, 'props.summaryUpdateUrl') === route('app.ai.knowledge-base.summary.update', ['knowledgeItem' => $document->id])
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.original_filename') === 'detail-reference.docx'
                && data_get($page, 'props.knowledgeItem.show_url') === route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id])
                && data_get($page, 'props.knowledgeItem.document_type_label') === KnowledgeItem::DOCUMENT_TYPE_LABELS[KnowledgeItem::DOCUMENT_TYPE_REFERENCE]
                && data_get($page, 'props.knowledgeItem.chunk_count') > 0
                && $chunks->count() > 0
                && $chunks->first()['title'] === null
                && $chunks->first()['fallback_title'] === 'Chunk 1'
                && $chunks->first()['review_status'] === KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW
                && $chunks->first()['review_status_update_url'] === route('app.ai.knowledge-base.chunks.review-status.update', [
                    'knowledgeItem' => $document->id,
                    'chunk' => $chunks->first()['id'],
                ])
                && $chunks->first()['metadata_update_url'] === route('app.ai.knowledge-base.chunks.metadata.update', [
                    'knowledgeItem' => $document->id,
                    'chunk' => $chunks->first()['id'],
                ])
                && $chunks->first()['content_preview'] !== '';
        });
    }

    public function test_knowledge_document_chunk_review_status_can_be_updated_from_the_show_page(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six D AS');
        $content = str_repeat('Chunk review flow document text that will chunk deterministically. ', 18);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('chunk-review.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'chunk-review.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->patch(route('app.ai.knowledge-base.chunks.review-status.update', [
            'knowledgeItem' => $document->id,
            'chunk' => $chunk->id,
        ]), [
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_APPROVED,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $updatedChunk = KnowledgeItemChunk::query()->whereKey($chunk->id)->firstOrFail();
        $this->assertSame(KnowledgeItemChunk::REVIEW_STATUS_APPROVED, $updatedChunk->review_status);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.chunks.0.review_status') === KnowledgeItemChunk::REVIEW_STATUS_APPROVED;
        });
    }

    public function test_knowledge_document_chunk_review_status_rejects_invalid_values(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six E AS');
        $content = str_repeat('Chunk review validation document text that will chunk deterministically. ', 18);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('chunk-review-invalid.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'chunk-review-invalid.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->patch(route('app.ai.knowledge-base.chunks.review-status.update', [
            'knowledgeItem' => $document->id,
            'chunk' => $chunk->id,
        ]), [
            'review_status' => 'invalid-status',
        ]);

        $response->assertSessionHasErrors(['review_status']);
        $this->assertSame(KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW, $chunk->fresh()->review_status);
    }

    public function test_knowledge_document_chunk_metadata_can_be_updated_from_the_show_page(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six F AS');
        $content = str_repeat('Chunk metadata flow document text that will chunk deterministically. ', 18);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('chunk-metadata.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'chunk-metadata.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->patch(route('app.ai.knowledge-base.chunks.metadata.update', [
            'knowledgeItem' => $document->id,
            'chunk' => $chunk->id,
        ]), [
            'title' => 'Leverandøravtale',
            'ai_summary' => 'Dette er en kort oppsummering av chunkens innhold.',
            'service_product_tag' => 'Kontrakt',
            'theme_tag' => 'Juridisk',
            'embedding_model' => 'ignored-by-validation',
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $updatedChunk = KnowledgeItemChunk::query()->whereKey($chunk->id)->firstOrFail();
        $this->assertSame('Leverandøravtale', $updatedChunk->title);
        $this->assertSame('Dette er en kort oppsummering av chunkens innhold.', $updatedChunk->ai_summary);
        $this->assertSame('Kontrakt', $updatedChunk->service_product_tag);
        $this->assertSame('Juridisk', $updatedChunk->theme_tag);
        $this->assertSame('text-embedding-3-small', $updatedChunk->embedding_model);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.chunks.0.title') === 'Leverandøravtale'
                && data_get($page, 'props.knowledgeItem.chunks.0.ai_summary') === 'Dette er en kort oppsummering av chunkens innhold.'
                && data_get($page, 'props.knowledgeItem.chunks.0.service_product_tag') === 'Kontrakt'
                && data_get($page, 'props.knowledgeItem.chunks.0.theme_tag') === 'Juridisk';
        });
    }

    public function test_knowledge_document_chunk_metadata_rejects_invalid_values(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six G AS');
        $content = str_repeat('Chunk metadata validation document text that will chunk deterministically. ', 18);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('chunk-metadata-invalid.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'chunk-metadata-invalid.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->patch(route('app.ai.knowledge-base.chunks.metadata.update', [
            'knowledgeItem' => $document->id,
            'chunk' => $chunk->id,
        ]), [
            'title' => str_repeat('x', 256),
            'ai_summary' => 'OK',
            'service_product_tag' => 'OK',
            'theme_tag' => 'OK',
        ]);

        $response->assertSessionHasErrors(['title']);
        $this->assertNull($chunk->fresh()->title);
    }

    public function test_knowledge_document_summary_can_be_updated_from_the_show_page(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six C AS');
        $content = str_repeat('Summary editable document text that will chunk deterministically. ', 18);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('summary-editable.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'summary-editable.docx')
            ->firstOrFail();

        $summary = 'Kort og tydelig oppsummering som kan redigeres på show-siden.';

        $response = $this->actingAs($context['user'])->patch(route('app.ai.knowledge-base.summary.update', ['knowledgeItem' => $document->id]), [
            'summary' => $summary,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $updatedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();
        $this->assertSame($summary, $updatedDocument->summary);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document, $summary): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.summary') === $summary;
        });
    }

    public function test_knowledge_document_update_allows_metadata_only_changes_and_keeps_chunks_intact(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Seven AS');
        $content = str_repeat('Boilerplate document content that will chunk deterministically. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('boilerplate.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_BOILERPLATE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'boilerplate.docx')
            ->firstOrFail();

        $initialChunkCount = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->count();

        $response = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => false,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $updatedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();
        $updatedChunkCount = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $updatedDocument->id)
            ->count();
        $normalizedContent = $this->normalizeWhitespace($content);

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $updatedDocument->document_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $updatedDocument->content_type);
        $this->assertFalse((bool) $updatedDocument->is_active);
        $this->assertSame($normalizedContent, $this->normalizeWhitespace((string) $updatedDocument->extracted_text));
        $this->assertSame($initialChunkCount, $updatedChunkCount);
    }

    public function test_knowledge_document_destroy_removes_the_database_row_chunks_and_file(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Eight AS');
        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('delete-me.docx', 'Document that will be deleted.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'delete-me.docx')
            ->firstOrFail();

        $storedPath = $document->storage_path;

        $this->actingAs($context['user'])->delete(route('app.ai.knowledge-base.destroy', ['knowledgeItem' => $document->id]))
            ->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->assertDatabaseMissing('knowledge_items', ['id' => $document->id]);
        $this->assertDatabaseMissing('knowledge_item_chunks', ['knowledge_item_id' => $document->id]);
        $this->assertTrue(Storage::disk('local')->missing($storedPath));

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));

        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $items->isEmpty()
                && ! $items->contains(fn (array $candidate): bool => $candidate['original_filename'] === $document->original_filename);
        });
    }

    public function test_knowledge_document_routes_are_scoped_to_the_current_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Nine AS');
        $foreignContext = $this->customerContext('Customer Ten AS');

        $this->actingAs($foreignContext['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('foreign.docx', 'Foreign customer knowledge.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $foreignDocument = KnowledgeItem::query()
            ->where('customer_id', $foreignContext['customer']->id)
            ->where('original_filename', 'foreign.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $foreignDocument->id]))
            ->assertNotFound();

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $foreignDocument->id]))
            ->assertNotFound();

        $this->actingAs($context['user'])
            ->delete(route('app.ai.knowledge-base.destroy', ['knowledgeItem' => $foreignDocument->id]))
            ->assertNotFound();
    }

    public function test_knowledge_document_store_rejects_invalid_file_type(): void
    {
        $context = $this->customerContext('Customer Eleven AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => UploadedFile::fake()->create('not-allowed.txt', 8, 'text/plain'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['document']);
        $this->assertDatabaseCount('knowledge_items', 0);
    }

    public function test_knowledge_document_store_rejects_unsupported_legacy_file_type(): void
    {
        $context = $this->customerContext('Customer Eleven B AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => UploadedFile::fake()->create('legacy.doc', 8, 'application/msword'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['document']);
        $this->assertDatabaseCount('knowledge_items', 0);
    }

    public function test_knowledge_document_store_rejects_invalid_document_type(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Twelve AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('invalid-type.docx', 'Valid document text.'),
            'document_type' => 'invalid',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['document_type']);
        $this->assertDatabaseMissing('knowledge_items', [
            'customer_id' => $context['customer']->id,
            'original_filename' => 'invalid-type.docx',
        ]);
    }

    private function customerContext(string $customerName): array
    {
        static $sequence = 0;
        $sequence++;
        $code = str_pad(base_convert((string) $sequence, 10, 36), 2, '0', STR_PAD_LEFT);

        $language = Language::query()->create([
            'code' => $code,
            'name_en' => 'English',
            'name_no' => 'Engelsk',
        ]);
        $nationality = Nationality::query()->create([
            'code' => $code,
            'name_en' => 'Norwegian',
            'name_no' => 'Norsk',
            'flag_emoji' => '🇳🇴',
        ]);
        $customer = Customer::query()->create([
            'name' => $customerName,
            'slug' => Str::slug($customerName).'-'.Str::lower(Str::random(6)),
            'nationality_id' => $nationality->id,
            'language_id' => $language->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => $customerName.' User',
            'email' => Str::slug($customerName).'+user@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return [
            'customer' => $customer,
            'user' => $user,
        ];
    }

    /**
     * Purpose: Create a small DOCX fixture with extractable text for upload tests.
     * Inputs: The client filename and the raw text to embed in the document body.
     * Returns: A test uploaded file backed by a real DOCX archive.
     * Side effects: Writes a temporary ZIP file to the system temp directory.
     */
    private function createDocxUpload(string $filename, string $text): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia-docx-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary DOCX file.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Unable to create a DOCX archive for testing.');
        }

        $escapedText = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .'<w:p><w:r><w:t>'.$escapedText.'</w:t></w:r></w:p>'
            .'</w:body>'
            .'</w:document>';

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }

    /**
     * Purpose: Bind a deterministic embedding service for knowledge document upload tests.
     * Inputs: None.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindSuccessfulEmbeddingService(): void
    {
        $service = Mockery::mock(EmbeddingService::class);
        $service->shouldReceive('tryEmbedText')
            ->andReturnUsing(function (string $text): array {
                return [
                    'ok' => true,
                    'embedding' => [0.11, 0.22, 0.33],
                    'model' => 'text-embedding-3-small',
                    'usage' => [],
                    'error_type' => null,
                    'error_message' => null,
                    'upstream_status' => 200,
                    'request_id' => 'test-request-id',
                    'response_body_excerpt' => null,
                ];
            });

        $this->app->instance(EmbeddingService::class, $service);
    }

    /**
     * Purpose: Normalize text so test expectations match the extractor output.
     * Inputs: Raw text with arbitrary whitespace.
     * Returns: A whitespace-collapsed string suitable for exact comparisons.
     * Side effects: None.
     */
    private function normalizeWhitespace(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));

        return is_string($normalized) ? $normalized : trim($text);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }
}
