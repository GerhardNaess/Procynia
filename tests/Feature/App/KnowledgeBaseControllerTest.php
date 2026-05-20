<?php

namespace Tests\Feature\App;

use App\Http\Controllers\App\KnowledgeBaseController;
use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeMetadataTerm;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Knowledge\AiKnowledgeChunkBoundaryService;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataGenerationService;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataValidator;
use App\Services\Ai\Knowledge\KnowledgeVocabularySuggestionEnrichmentService;
use App\Services\OpenAi\EmbeddingService;
use App\Services\OpenAi\OpenAiClient;
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
        $this->bindKnowledgeChunkBoundaryService();
        $this->bindSuccessfulBillingEntitlementService();
        $this->bindSuccessfulKnowledgeMetadataGenerationService();
        $this->bindSuccessfulKnowledgeVocabularySuggestionEnrichmentService();
        $this->bindSuccessfulEmbeddingService();
    }

    protected function tearDown(): void
    {
        Mockery::close();

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

    public function test_knowledge_document_upload_uses_structural_chunking_and_persists_heading_paths_and_offsets(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true);

        $context = $this->customerContext('Customer Four Structured AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-pipeline.docx', [
                ['text' => 'Intro before first heading.', 'style' => 'Normal'],
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Første avsnitt under hovedseksjonen.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Mer tekst i underseksjonen.', 'style' => 'Normal'],
                ['text' => 'Andre hovedseksjon', 'style' => 'Heading1'],
                ['text' => 'Avsluttende avsnitt.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'structured-pipeline.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $expectedSourceText = implode("\n\n", [
            'Intro before first heading.',
            'Første avsnitt under hovedseksjonen.',
            'Underseksjon A',
            'Mer tekst i underseksjonen.',
            'Avsluttende avsnitt.',
        ]);

        // This fixture is intentionally short, so the controller should fall back to one document chunk.
        $this->assertSame(1, $chunks->count());
        $this->assertSame(0, (int) $chunks[0]->start_offset);
        $this->assertSame(mb_strlen($expectedSourceText, 'UTF-8'), (int) $chunks[0]->end_offset);
        $this->assertSame(null, $chunks[0]->heading_path);
        $this->assertSame('document', $chunks[0]->chunk_type);
        $this->assertSame($expectedSourceText, (string) $chunks[0]->content);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.chunks.0.heading_path') === null
                && data_get($page, 'props.knowledgeItem.chunks.0.chunk_type') === 'document'
                && data_get($page, 'props.knowledgeItem.chunks.0.start_offset') === 0;
        });
    }

    public function test_knowledge_document_upload_persists_image_chunks_separately_from_text_chunks(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true);

        $context = $this->customerContext('Customer Image AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('image-pipeline.docx', [
                ['type' => 'paragraph', 'text' => '1 Overskrift test', 'style' => 'Heading1'],
                ['type' => 'paragraph', 'text' => 'Tekst før bilde.', 'style' => 'Normal'],
                [
                    'type' => 'image',
                    'alt_text' => 'Arkitekturdiagram med integrasjoner',
                    'title' => 'Figure 1',
                    'relationship_id' => 'rId1',
                    'media_filename' => 'image1.png',
                    'media_bytes' => $this->docxSampleImageBytes(),
                ],
                ['type' => 'paragraph', 'text' => 'Figur 1: Overordnet arkitektur', 'style' => 'Caption'],
                ['type' => 'paragraph', 'text' => '1.1 Dokumentasjonskrav for drift', 'style' => 'Heading2'],
                ['type' => 'paragraph', 'text' => 'Systemet skal oppdateres regelmessig for å sikre stabil og pålitelig drift av alle komponenter. Alle kritiske oppdateringer skal testes i et isolert testmiljø før de rulles ut i produksjon. Vedlikeholdsvinduet er klart definert i driftsavtalen og gjelder for alle planlagte nedetider.', 'style' => 'Normal'],
                ['type' => 'paragraph', 'text' => 'Tekst etter H2.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'image-pipeline.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $imageChunk = $chunks->firstWhere('chunk_type', 'image');

        $this->assertNotNull($imageChunk);
        $this->assertNotEmpty($imageChunk->image_path);
        $this->assertSame('local', $imageChunk->image_disk);
        $this->assertSame('image/png', $imageChunk->image_mime_type);
        $this->assertNotEmpty($imageChunk->image_hash);
        $this->assertIsArray($imageChunk->image_metadata);
        $this->assertSame('png', $imageChunk->image_metadata['extension'] ?? null);
        $this->assertSame('unknown', $imageChunk->image_metadata['image_kind'] ?? null);
        $this->assertNotEmpty($imageChunk->content);
        $this->assertStringContainsString('Grafikk i seksjon: 1 Overskrift test', (string) $imageChunk->content);
        $this->assertStringContainsString('Arkitekturdiagram med integrasjoner', (string) $imageChunk->content);
        $this->assertStringContainsString('Figur 1: Overordnet arkitektur', (string) $imageChunk->content);
        $this->assertSame('1 Overskrift test', $imageChunk->heading_path);
        $this->assertSame('1 Overskrift test', $imageChunk->section_path);
        $this->assertTrue(Storage::disk('local')->exists($imageChunk->image_path));

        $semanticChunks = $chunks->where('chunk_type', 'semantic');
        $this->assertGreaterThan(0, $semanticChunks->count());

        foreach ($semanticChunks as $semanticChunk) {
            $this->assertStringNotContainsString('Figur 1: Overordnet arkitektur', (string) $semanticChunk->content);
            $this->assertStringNotContainsString('Arkitekturdiagram med integrasjoner', (string) $semanticChunk->content);
        }

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $chunks = collect(data_get($page, 'props.knowledgeItem.chunks', []));
            $imageChunk = $chunks->firstWhere('chunk_type', 'image');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && $imageChunk !== null
                && $imageChunk['image_url'] === route('app.ai.knowledge-base.chunks.image', [
                    'knowledgeItem' => $document->id,
                    'chunk' => $imageChunk['id'],
                    'v' => $imageChunk['image_hash'],
                ])
                && ! empty($imageChunk['image_metadata'])
                && data_get($imageChunk, 'image_metadata.extension') === 'png'
                && data_get($imageChunk, 'image_metadata.image_kind') === 'unknown'
                && $imageChunk['image_caption'] === 'Figur 1: Overordnet arkitektur'
                && $imageChunk['image_alt_text'] === 'Arkitekturdiagram med integrasjoner';
        });

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $document->id,
                'chunk' => $imageChunk->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_knowledge_document_upload_keeps_images_before_h2_under_the_previous_h1_context_even_when_tables_precede_them(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true);

        $context = $this->customerContext('Customer Image H2 Context AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('image-h2-context.docx', [
                ['text' => '1 Overskrift test', 'style' => 'Heading1'],
                ['text' => 'Tekst før tabell.', 'style' => 'Normal'],
                ['text' => '', 'style' => 'Table'],
                [
                    'type' => 'image',
                    'alt_text' => 'Arkitekturdiagram med integrasjoner',
                    'title' => 'Figure 1',
                    'relationship_id' => 'rId1',
                    'media_filename' => 'image1.png',
                    'media_bytes' => $this->docxSampleImageBytes(),
                ],
                ['text' => '1.1 Dokumentasjonskrav for drift', 'style' => 'Heading2'],
                ['text' => 'Systemet skal oppdateres regelmessig for å sikre stabil og pålitelig drift av alle komponenter. Alle kritiske oppdateringer skal testes i et isolert testmiljø før de rulles ut i produksjon. Vedlikeholdsvinduet er klart definert i driftsavtalen og gjelder for alle planlagte nedetider.', 'style' => 'Normal'],
                ['text' => 'Tekst etter H2.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'image-h2-context.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $imageChunk = $chunks->firstWhere('chunk_type', 'image');
        $tableChunk = $chunks->firstWhere('chunk_type', 'table');
        $postH2Chunk = $chunks->firstWhere('heading_path', '1.1 Dokumentasjonskrav for drift');

        $this->assertNotNull($imageChunk);
        $this->assertNotNull($tableChunk);
        $this->assertNotNull($postH2Chunk);

        $this->assertSame('1 Overskrift test', $imageChunk->heading_path);
        $this->assertSame('1 Overskrift test', $imageChunk->section_path);
        $this->assertStringContainsString('Grafikk i seksjon: 1 Overskrift test', (string) $imageChunk->content);
        $this->assertStringNotContainsString('1.1 Dokumentasjonskrav for drift', (string) $imageChunk->content);

        $this->assertSame('1 Overskrift test', $tableChunk->heading_path);
        $this->assertSame('1 Overskrift test', $tableChunk->section_path);
        $this->assertStringNotContainsString('1.1 Dokumentasjonskrav for drift', (string) $tableChunk->content);

        $this->assertSame('1.1 Dokumentasjonskrav for drift', $postH2Chunk->heading_path);
        $this->assertStringContainsString('Tekst etter H2.', (string) $postH2Chunk->content);
        $this->assertStringNotContainsString('Grafikk i seksjon: 1 Overskrift test', (string) $postH2Chunk->content);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            $chunks = collect(data_get($page, 'props.knowledgeItem.chunks', []));
            $imageChunk = $chunks->firstWhere('chunk_type', 'image');
            $postH2Chunk = $chunks->firstWhere('heading_path', '1.1 Dokumentasjonskrav for drift');

            return $imageChunk !== null
                && data_get($imageChunk, 'section_path') === '1 Overskrift test'
                && str_contains((string) data_get($imageChunk, 'content', ''), 'Grafikk i seksjon: 1 Overskrift test')
                && ! str_contains((string) data_get($imageChunk, 'content', ''), '1.1 Dokumentasjonskrav for drift')
                && $postH2Chunk !== null
                && data_get($postH2Chunk, 'heading_path') === '1.1 Dokumentasjonskrav for drift'
                && str_contains((string) data_get($postH2Chunk, 'content', ''), 'Tekst etter H2.');
        });
    }

    public function test_knowledge_document_upload_preserves_unpreviewable_image_extensions_without_skipping_image_chunks(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true);

        $context = $this->customerContext('Customer Four B AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('image-unpreviewable.docx', [
                ['type' => 'paragraph', 'text' => '1 Overskrift test', 'style' => 'Heading1'],
                ['type' => 'paragraph', 'text' => 'Tekst før bilde.', 'style' => 'Normal'],
                [
                    'type' => 'image',
                    'media_filename' => 'image1.svg',
                    'media_bytes' => $this->docxSampleImageBytes(),
                    'title' => 'Figure 2',
                    'alt_text' => 'Illustrasjon av prosessflyt',
                ],
                ['type' => 'paragraph', 'text' => 'Figur 2: Prosessflyt', 'style' => 'Caption'],
                ['type' => 'paragraph', 'text' => '1.1 Dokumentasjonskrav for drift', 'style' => 'Heading2'],
                ['type' => 'paragraph', 'text' => 'Tekst etter bilde.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'image-unpreviewable.docx')
            ->firstOrFail();

        $imageChunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->where('chunk_type', 'image')
            ->firstOrFail();

        $this->assertSame('svg', $imageChunk->image_metadata['extension'] ?? null);
        $this->assertSame('unknown', $imageChunk->image_metadata['image_kind'] ?? null);
        $this->assertStringEndsWith('.svg', (string) $imageChunk->image_path);
        $this->assertTrue(Storage::disk('local')->exists($imageChunk->image_path));

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            $chunks = collect(data_get($page, 'props.knowledgeItem.chunks', []));
            $imageChunk = $chunks->firstWhere('chunk_type', 'image');

            return $imageChunk !== null
                && $imageChunk['image_url'] === route('app.ai.knowledge-base.chunks.image', [
                    'knowledgeItem' => data_get($page, 'props.knowledgeItem.id'),
                    'chunk' => $imageChunk['id'],
                    'v' => $imageChunk['image_hash'],
                ])
                && data_get($imageChunk, 'image_metadata.extension') === 'svg'
                && data_get($imageChunk, 'image_metadata.image_kind') === 'unknown'
                && ! empty($imageChunk['image_url']);
        });

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $document->id,
                'chunk' => $imageChunk->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_knowledge_document_image_route_returns_not_found_when_file_is_missing(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true);

        $context = $this->customerContext('Customer Four C AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('image-missing.docx', [
                ['type' => 'paragraph', 'text' => '1 Overskrift test', 'style' => 'Heading1'],
                ['type' => 'image', 'title' => 'Figure 3', 'alt_text' => 'Diagram', 'relationship_id' => 'rId1', 'media_filename' => 'image1.png', 'media_bytes' => $this->docxSampleImageBytes()],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'image-missing.docx')
            ->firstOrFail();

        $imageChunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->where('chunk_type', 'image')
            ->firstOrFail();

        Storage::disk('local')->delete($imageChunk->image_path);

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $document->id,
                'chunk' => $imageChunk->id,
            ]))
            ->assertNotFound();
    }

    public function test_knowledge_document_image_route_returns_forbidden_for_foreign_documents_and_rejects_non_image_chunks(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true);

        $context = $this->customerContext('Customer Four D AS');
        $foreignContext = $this->customerContext('Customer Four E AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('image-access.docx', [
                ['type' => 'paragraph', 'text' => '1 Overskrift test', 'style' => 'Heading1'],
                ['type' => 'paragraph', 'text' => 'Tekst før bilde.', 'style' => 'Normal'],
                [
                    'type' => 'image',
                    'media_filename' => 'image1.png',
                    'media_bytes' => $this->docxSampleImageBytes(),
                    'title' => 'Figure 4',
                    'alt_text' => 'Arkitekturdiagram med integrasjoner',
                ],
                ['type' => 'paragraph', 'text' => '1.1 Dokumentasjonskrav for drift', 'style' => 'Heading2'],
                ['type' => 'paragraph', 'text' => 'Systemet skal oppdateres regelmessig for å sikre stabil og pålitelig drift av alle komponenter. Alle kritiske oppdateringer skal testes i et isolert testmiljø før de rulles ut i produksjon. Vedlikeholdsvinduet er klart definert i driftsavtalen og gjelder for alle planlagte nedetider.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($foreignContext['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('image-access-foreign.docx', [
                ['type' => 'paragraph', 'text' => '1 Overskrift test', 'style' => 'Heading1'],
                [
                    'type' => 'image',
                    'media_filename' => 'image1.png',
                    'media_bytes' => $this->docxSampleImageBytes(),
                    'title' => 'Figure 5',
                    'alt_text' => 'Eksempelbilde',
                ],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'image-access.docx')
            ->firstOrFail();

        $imageChunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->where('chunk_type', 'image')
            ->firstOrFail();

        $textChunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->where('chunk_type', 'semantic')
            ->firstOrFail();

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $document->id,
                'chunk' => $textChunk->id,
            ]))
            ->assertNotFound();

        $foreignDocument = KnowledgeItem::query()
            ->where('customer_id', $foreignContext['customer']->id)
            ->where('original_filename', 'image-access-foreign.docx')
            ->firstOrFail();

        $foreignImageChunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $foreignDocument->id)
            ->where('chunk_type', 'image')
            ->firstOrFail();

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $foreignDocument->id,
                'chunk' => $foreignImageChunk->id,
            ]))
            ->assertForbidden();
    }

    public function test_rule_based_h2_chunk_payload_builder_emits_dedicated_table_chunks_with_metadata(): void
    {
        $structure = $this->ruleBasedTableChunkStructureFixture();
        $payloads = $this->invokeBuildRuleBasedH2ChunkPayloads($structure);

        $this->assertCount(4, $payloads);
        $this->assertSame(
            ['semantic', 'semantic', 'table', 'semantic'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['chunk_type'] ?? null,
                $payloads,
            )),
        );

        $tablePayload = $payloads[2];

        $this->assertSame('table', $tablePayload['chunk_type']);
        $this->assertSame('docx_table', $tablePayload['table_metadata']['source'] ?? null);
        $this->assertStringContainsString('| Tabell A | Tabell B |', $tablePayload['table_markdown']);
        $this->assertStringContainsString('| --- | --- |', $tablePayload['table_markdown']);
        $this->assertSame("Tabell A | Tabell B\nRad 1 | Rad 2", $tablePayload['table_text']);
        $this->assertStringContainsString('<table', $tablePayload['table_html']);
        $this->assertSame('simple', $tablePayload['table_complexity']);
        $this->assertSame([], $tablePayload['table_warnings']);
        $this->assertNotContains('markdown_is_simplified', $tablePayload['table_warnings']);
        $this->assertSame('docx_table', $tablePayload['table_json']['source_type'] ?? null);
        $this->assertSame('simple', $tablePayload['table_json']['complexity'] ?? null);
        $this->assertSame([], $tablePayload['table_json']['warnings'] ?? []);
        $this->assertSame('header', $tablePayload['table_json']['rows'][0]['row_type'] ?? null);
        $this->assertStringContainsString('Kapittel 2 > Underseksjon A', $tablePayload['content']);
        $this->assertStringContainsString('Tabell A | Tabell B', $tablePayload['content']);

        $this->assertStringNotContainsString('Tabell A | Tabell B', $payloads[0]['content']);
        $this->assertStringNotContainsString('Tabell A | Tabell B', $payloads[1]['content']);
        $this->assertStringNotContainsString('Tabell A | Tabell B', $payloads[3]['content']);
        $this->assertStringContainsString('Innledning før tabell.', $payloads[1]['content']);
        $this->assertStringContainsString('Etter tabell.', $payloads[3]['content']);
    }

    public function test_rule_based_h2_chunk_payload_builder_preserves_figure_like_gap_text_as_an_image_chunk(): void
    {
        $structure = $this->ruleBasedFigureGapStructureFixture();
        $payloads = $this->invokeBuildRuleBasedH2ChunkPayloads($structure);

        $chunkTypes = array_values(array_map(
            static fn (array $payload): ?string => $payload['chunk_type'] ?? null,
            $payloads,
        ));

        $imageIndex = array_search('image', $chunkTypes, true);

        $this->assertNotFalse(
            $imageIndex,
            'Expected the figure-like gap to be preserved as a dedicated image chunk.',
        );

        $imagePayload = $payloads[$imageIndex];
        $previousPayload = $payloads[$imageIndex - 1] ?? null;
        $nextPayload = $payloads[$imageIndex + 1] ?? null;

        $this->assertSame('image', $imagePayload['chunk_type']);
        $this->assertStringContainsString('1.12', (string) ($imagePayload['heading_path'] ?? ''));
        $this->assertStringContainsString('1.12', (string) ($imagePayload['section_path'] ?? ''));
        $this->assertNotNull($previousPayload);
        $this->assertNotNull($nextPayload);
        $this->assertSame('semantic', $previousPayload['chunk_type'] ?? null);
        $this->assertSame('semantic', $nextPayload['chunk_type'] ?? null);
        $this->assertStringContainsString('1.12', (string) ($previousPayload['heading_path'] ?? ''));
        $this->assertStringContainsString('1.13', (string) ($nextPayload['heading_path'] ?? ''));
        $this->assertStringContainsString('Advania Risk Management', (string) ($imagePayload['content'] ?? ''));
        $this->assertStringContainsString('Kontroll', (string) ($imagePayload['content'] ?? ''));
        $this->assertSame('pdf_figure_gap', data_get($imagePayload, 'image_metadata.source'));
        $this->assertTrue((bool) data_get($imagePayload, 'image_metadata.derived_from_text'));
        $this->assertSame('Advania Risk Management', $imagePayload['image_caption'] ?? null);
        foreach ([
            'Identifisere',
            'Beskrivelse',
            'Analyse',
            'Planlegge',
            'Oppfølging',
            'Kontroll',
        ] as $term) {
            $this->assertStringContainsString($term, (string) ($imagePayload['image_description'] ?? ''));
        }
        $this->assertNotEmpty($imagePayload['image_caption'] ?? null);
        $this->assertNotEmpty($imagePayload['ocr_text'] ?? null);
        $this->assertNotEmpty($imagePayload['image_description'] ?? null);
    }

    public function test_real_pdf_import_keeps_existing_pdf_graphics_and_drops_competing_pdf_figure_gaps(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Real Pdf AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->realKnowledgePdfUpload(),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'Masterdata Prosjekt_pdf.pdf')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $pageNineGraphic = $chunks->first(static function (KnowledgeItemChunk $chunk): bool {
            return data_get($chunk, 'chunk_type') === 'image'
                && data_get($chunk, 'image_metadata.page_number') === 9
                && data_get($chunk, 'image_metadata.source_type') === 'pdf_graphic';
        });

        $pageEighteenGraphic = $chunks->first(static function (KnowledgeItemChunk $chunk): bool {
            return data_get($chunk, 'chunk_type') === 'image'
                && data_get($chunk, 'image_metadata.page_number') === 18
                && data_get($chunk, 'image_metadata.source_type') === 'pdf_graphic';
        });

        $pageTwentyThreeFigure = $chunks->first(static function (KnowledgeItemChunk $chunk): bool {
            return data_get($chunk, 'chunk_type') === 'image'
                && data_get($chunk, 'image_metadata.source') === 'pdf_figure_gap'
                && data_get($chunk, 'image_metadata.derived_from_text') === true
                && data_get($chunk, 'image_caption') === 'Advania Risk Management'
                && str_contains((string) $chunk->heading_path, '1.12');
        });

        $competingFigureGapInSectionOneFour = $chunks->first(static function (KnowledgeItemChunk $chunk): bool {
            return data_get($chunk, 'chunk_type') === 'image'
                && data_get($chunk, 'image_metadata.source') === 'pdf_figure_gap'
                && str_contains((string) $chunk->heading_path, '1.4');
        });

        $this->assertNotNull($pageNineGraphic);
        $this->assertNotNull($pageEighteenGraphic);
        $this->assertNotNull($pageTwentyThreeFigure);
        $this->assertNull($competingFigureGapInSectionOneFour, 'Existing 1.4 graphic must not be replaced by a derived pdf_figure_gap chunk.');

        $this->assertSame('pdf_graphic', data_get($pageNineGraphic, 'image_metadata.source_type'));
        $this->assertSame(9, data_get($pageNineGraphic, 'image_metadata.page_number'));
        $this->assertNotEmpty($pageNineGraphic->image_path);
        $this->assertNotEmpty($pageNineGraphic->image_metadata['source_image_path'] ?? null);

        $this->assertSame('pdf_graphic', data_get($pageEighteenGraphic, 'image_metadata.source_type'));
        $this->assertSame(18, data_get($pageEighteenGraphic, 'image_metadata.page_number'));
        $this->assertNotEmpty($pageEighteenGraphic->image_path);

        $this->assertSame('pdf_figure_gap', data_get($pageTwentyThreeFigure, 'image_metadata.source'));
        $this->assertTrue((bool) data_get($pageTwentyThreeFigure, 'image_metadata.derived_from_text'));
        $this->assertSame('Advania Risk Management', $pageTwentyThreeFigure->image_caption);
        $this->assertNotEmpty($pageTwentyThreeFigure->image_description ?? null);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $chunks = collect(data_get($page, 'props.knowledgeItem.chunks', []));

            $pageNineGraphic = $chunks->first(static function (array $chunk): bool {
                return data_get($chunk, 'chunk_type') === 'image'
                    && data_get($chunk, 'image_metadata.page_number') === 9
                    && data_get($chunk, 'image_metadata.source_type') === 'pdf_graphic';
            });

            $pageTwentyThreeFigure = $chunks->first(static function (array $chunk): bool {
                return data_get($chunk, 'chunk_type') === 'image'
                    && data_get($chunk, 'image_metadata.source') === 'pdf_figure_gap'
                    && data_get($chunk, 'image_metadata.derived_from_text') === true
                    && data_get($chunk, 'image_caption') === 'Advania Risk Management';
            });

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && $pageNineGraphic !== null
                && ! empty($pageNineGraphic['image_url'])
                && $pageTwentyThreeFigure !== null
                && data_get($pageTwentyThreeFigure, 'image_caption') === 'Advania Risk Management';
        });

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $document->id,
                'chunk' => $pageNineGraphic->id,
            ]))
            ->assertOk();
    }

    public function test_knowledge_document_upload_persists_table_chunks_separately_from_text_chunks(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Table AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('table-pipeline.docx', [
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Innledning før tabell.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Tekst før tabell.', 'style' => 'Normal'],
                ['text' => '', 'style' => 'Table'],
                ['text' => 'Tekst etter tabell.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'table-pipeline.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $tableChunks = $chunks->filter(static fn (KnowledgeItemChunk $chunk): bool => $chunk->chunk_type === 'table');
        $semanticChunks = $chunks->filter(static fn (KnowledgeItemChunk $chunk): bool => $chunk->chunk_type === 'semantic');

        $this->assertGreaterThanOrEqual(1, $tableChunks->count());
        $this->assertGreaterThanOrEqual(2, $semanticChunks->count());

        $tableChunk = $tableChunks->first();

        $this->assertNotNull($tableChunk);
        $this->assertSame('table', $tableChunk->chunk_type);
        $this->assertSame('docx_table', $tableChunk->table_metadata['source'] ?? null);
        $this->assertSame(2, $tableChunk->table_metadata['row_count'] ?? null);
        $this->assertSame(2, $tableChunk->table_metadata['column_count'] ?? null);
        $this->assertSame('simple', $tableChunk->table_complexity);
        $this->assertSame([], $tableChunk->table_warnings);
        $this->assertIsArray($tableChunk->table_json);
        $this->assertSame('docx_table', $tableChunk->table_json['source_type'] ?? null);
        $this->assertNotEmpty($tableChunk->table_markdown);
        $this->assertNotEmpty($tableChunk->table_html);
        $this->assertNotEmpty($tableChunk->table_text);
        $this->assertNotContains('markdown_is_simplified', $tableChunk->table_warnings);
        $this->assertStringContainsString('Tabell A', (string) $tableChunk->content);
        $this->assertStringContainsString('Rad 1', (string) $tableChunk->content);
        $this->assertStringContainsString('| Tabell A | Tabell B |', (string) $tableChunk->table_markdown);
        $this->assertStringContainsString('Tabell A | Tabell B', (string) $tableChunk->table_text);
        $this->assertStringContainsString('<table', (string) $tableChunk->table_html);

        $semanticContents = $semanticChunks->map(
            static fn (KnowledgeItemChunk $chunk): string => (string) $chunk->content,
        )->implode("\n\n");

        $this->assertStringContainsString('Innledning før tabell.', $semanticContents);
        $this->assertStringContainsString('Tekst før tabell.', $semanticContents);
        $this->assertStringContainsString('Tekst etter tabell.', $semanticContents);
        $this->assertStringNotContainsString('Tabell A | Tabell B', $semanticContents);
        $this->assertStringNotContainsString('Rad 1 | Rad 2', $semanticContents);

        foreach ($semanticChunks as $semanticChunk) {
            $this->assertStringNotContainsString('Tabell A | Tabell B', (string) $semanticChunk->content);
            $this->assertStringNotContainsString('Rad 1 | Rad 2', (string) $semanticChunk->content);
        }

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $chunks = collect(data_get($page, 'props.knowledgeItem.chunks', []));
            $tableChunk = $chunks->firstWhere('chunk_type', 'table');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && $tableChunk !== null
                && data_get($tableChunk, 'table_markdown') !== null
                && data_get($tableChunk, 'table_text') !== null
                && data_get($tableChunk, 'table_html') !== null
                && data_get($tableChunk, 'table_complexity') === 'simple'
                && data_get($tableChunk, 'table_warnings') === []
                && is_array(data_get($tableChunk, 'table_json'))
                && data_get($tableChunk, 'table_metadata.source') === 'docx_table';
        });
    }

    public function test_rule_based_h2_chunk_payload_builder_keeps_pre_h2_text_and_tables_under_the_previous_h1_context(): void
    {
        $structure = $this->ruleBasedPreH2TableStructureFixture();
        $payloads = $this->invokeBuildRuleBasedH2ChunkPayloads($structure);

        $this->assertCount(3, $payloads);
        $this->assertSame(
            ['1 Overskrift test', '1 Overskrift test', '1.1 Dokumentasjonskrav for drift'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['heading_path'] ?? null,
                $payloads,
            )),
        );
        $this->assertSame(
            ['semantic', 'table', 'semantic'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['chunk_type'] ?? null,
                $payloads,
            )),
        );

        $this->assertSame('1 Overskrift test', $payloads[0]['section_path']);
        $this->assertStringContainsString('Tekst før tabell.', (string) $payloads[0]['content']);
        $this->assertStringNotContainsString('1.1 Dokumentasjonskrav for drift', (string) $payloads[0]['content']);

        $this->assertSame('table', $payloads[1]['chunk_type']);
        $this->assertSame('1 Overskrift test', $payloads[1]['heading_path']);
        $this->assertSame('1 Overskrift test', $payloads[1]['section_path']);
        $this->assertStringContainsString('Tabell A | Tabell B', (string) $payloads[1]['content']);
        $this->assertStringNotContainsString('1.1 Dokumentasjonskrav for drift', (string) $payloads[1]['content']);

        $this->assertSame('1 Overskrift test > 1.1 Dokumentasjonskrav for drift', $payloads[2]['section_path']);
        $this->assertSame('1.1 Dokumentasjonskrav for drift', $payloads[2]['heading_path']);
        $this->assertStringContainsString('Tekst etter H2.', (string) $payloads[2]['content']);
        $this->assertStringNotContainsString('Tekst før tabell.', (string) $payloads[2]['content']);
    }

    public function test_rule_based_h2_chunk_payload_builder_includes_h1_only_sections_without_duplication(): void
    {
        $structure = $this->ruleBasedChunkStructureFixture();
        $payloads = $this->invokeBuildRuleBasedH2ChunkPayloads($structure);

        $this->assertCount(4, $payloads);
        $this->assertSame(
            [
                'Kapittel 1',
                '2.1 Sammendrag og helhetlig løsningsforslag',
                '2.2 Strategisk partnerskap, veikart og måloppnåelse',
                'Kapittel 3',
            ],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['heading_path'] ?? null,
                $payloads,
            )),
        );
        $this->assertSame(
            ['semantic', 'semantic', 'semantic', 'semantic'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['chunk_type'] ?? null,
                $payloads,
            )),
        );
        $this->assertStringContainsString('Kapittel 1', (string) $payloads[0]['content']);
        $this->assertStringContainsString('Kapittel 1 tekst.', (string) $payloads[0]['content']);
        $this->assertStringContainsString('Sammendrag og helhetlig løsningsforslag.', (string) $payloads[1]['content']);
        $this->assertStringContainsString('Strategisk partnerskap, veikart og måloppnåelse.', (string) $payloads[2]['content']);
        $this->assertStringContainsString('Kapittel 3', (string) $payloads[3]['content']);
        $this->assertStringContainsString('Kapittel 3 tekst.', (string) $payloads[3]['content']);
        $this->assertSame('Kapittel 1', $payloads[0]['section_path']);
        $this->assertSame('Kapittel 2 > 2.1 Sammendrag og helhetlig løsningsforslag', $payloads[1]['section_path']);
        $this->assertSame('Kapittel 2 > 2.2 Strategisk partnerskap, veikart og måloppnåelse', $payloads[2]['section_path']);
        $this->assertSame('Kapittel 3', $payloads[3]['section_path']);
        $this->assertNull($payloads[0]['part_index']);
        $this->assertNull($payloads[1]['part_index']);
        $this->assertNull($payloads[2]['part_index']);
        $this->assertNull($payloads[3]['part_index']);
    }

    public function test_rule_based_h2_chunk_payload_builder_splits_oversized_h2_sections_on_block_boundaries(): void
    {
        $structure = $this->ruleBasedOversizedChunkStructureFixture();
        $payloads = $this->invokeBuildRuleBasedH2ChunkPayloads($structure);

        $this->assertCount(5, $payloads);
        $this->assertSame(
            [
                'Kapittel 1',
                '2.1 Sammendrag og grunnlag',
                '2.1 Sammendrag og grunnlag',
                '2.2 Kort oppsummering',
                'Kapittel 3',
            ],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['heading_path'] ?? null,
                $payloads,
            )),
        );
        $this->assertSame(
            [null, 1, 2, null, null],
            array_values(array_map(
                static fn (array $payload): mixed => $payload['part_index'] ?? null,
                $payloads,
            )),
        );
        $this->assertSame('Kapittel 2 > 2.1 Sammendrag og grunnlag', $payloads[1]['section_path']);
        $this->assertSame('Kapittel 2 > 2.2 Kort oppsummering', $payloads[3]['section_path']);
        $startOffsets = array_values(array_map(
            static fn (array $payload): int => (int) $payload['start_offset'],
            $payloads,
        ));
        $sortedStartOffsets = $startOffsets;
        sort($sortedStartOffsets);

        $this->assertSame($sortedStartOffsets, $startOffsets);

        foreach ($payloads as $payload) {
            $this->assertNotSame('', trim((string) $payload['content']));
        }
    }

    public function test_rule_based_h2_chunk_payload_builder_only_splits_when_word_count_exceeds_the_threshold(): void
    {
        $structureAtThreshold = $this->ruleBasedThresholdBoundaryStructureFixture(400, 400);
        $payloadsAtThreshold = $this->invokeBuildRuleBasedH2ChunkPayloads($structureAtThreshold);

        $this->assertCount(1, $payloadsAtThreshold);
        $this->assertSame(
            ['2.1 Grensetest'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['heading_path'] ?? null,
                $payloadsAtThreshold,
            )),
        );
        $this->assertSame([null], array_values(array_map(
            static fn (array $payload): mixed => $payload['part_index'] ?? null,
            $payloadsAtThreshold,
        )));
        $this->assertSame(800, array_sum(array_map(
            static fn (array $payload): int => count(preg_split('/\s+/u', trim((string) $payload['content']), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            $payloadsAtThreshold,
        )));

        $structureAboveThreshold = $this->ruleBasedThresholdBoundaryStructureFixture(400, 401);
        $payloadsAboveThreshold = $this->invokeBuildRuleBasedH2ChunkPayloads($structureAboveThreshold);

        $this->assertCount(2, $payloadsAboveThreshold);
        $this->assertSame(
            ['2.1 Grensetest', '2.1 Grensetest'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['heading_path'] ?? null,
                $payloadsAboveThreshold,
            )),
        );
        $this->assertSame([1, 2], array_values(array_map(
            static fn (array $payload): mixed => $payload['part_index'] ?? null,
            $payloadsAboveThreshold,
        )));
        $this->assertSame(801, array_sum(array_map(
            static fn (array $payload): int => count(preg_split('/\s+/u', trim((string) $payload['content']), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            $payloadsAboveThreshold,
        )));
    }

    public function test_rule_based_h2_chunk_payload_builder_splits_oversized_h1_sections_on_block_boundaries(): void
    {
        $structure = $this->ruleBasedOversizedH1StructureFixture();
        $payloads = $this->invokeBuildRuleBasedH2ChunkPayloads($structure);

        $this->assertCount(2, $payloads);
        $this->assertSame(
            ['Kapittel A', 'Kapittel A'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['heading_path'] ?? null,
                $payloads,
            )),
        );
        $this->assertSame([1, 2], array_values(array_map(
            static fn (array $payload): mixed => $payload['part_index'] ?? null,
            $payloads,
        )));
        $this->assertSame(
            ['Kapittel A', 'Kapittel A'],
            array_values(array_map(
                static fn (array $payload): ?string => $payload['section_path'] ?? null,
                $payloads,
            )),
        );
        foreach ($payloads as $payload) {
            $this->assertNotSame('', trim((string) $payload['content']));
        }
    }

    public function test_knowledge_document_upload_persists_boundary_metadata_even_when_approved_vocabulary_is_empty(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true, true);

        $metadataService = Mockery::mock(KnowledgeChunkMetadataGenerationService::class);
        $metadataService->shouldReceive('generateForChunk')
            ->twice()
            ->andReturnUsing(function (KnowledgeItem $document, KnowledgeItemChunk $chunk): array {
                return [
                    'service_product_tag' => 'Produkt A',
                    'theme_tag' => 'Tema A',
                    'topic' => 'Tema '.($chunk->chunk_index + 1),
                    'sub_topic' => 'Underemne '.($chunk->chunk_index + 1),
                    'keywords' => [
                        'stikkord-'.($chunk->chunk_index + 1).'-a',
                        'stikkord-'.($chunk->chunk_index + 1).'-b',
                        'stikkord-'.($chunk->chunk_index + 1).'-c',
                    ],
                    'matched_terms' => [],
                    'summary_for_retrieval' => 'Kort oppsummering for gjenfinning.',
                    'confidence_score' => 0.25,
                    'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
                    'new_term_suggestions' => [],
                    'embedding_input' => implode("\n", array_filter([
                        'Title: '.trim((string) ($chunk->title ?: $chunk->section_title ?: $document->title)),
                        'Service/product tag: Produkt A',
                        'Theme tag: Tema A',
                        'Topic: Tema '.($chunk->chunk_index + 1),
                        'Sub-topic: Underemne '.($chunk->chunk_index + 1),
                        'Keywords: '.implode(', ', [
                            'stikkord-'.($chunk->chunk_index + 1).'-a',
                            'stikkord-'.($chunk->chunk_index + 1).'-b',
                            'stikkord-'.($chunk->chunk_index + 1).'-c',
                        ]),
                        'Summary: Kort oppsummering for gjenfinning.',
                        'Content: '.trim((string) $chunk->content),
                    ])),
                ];
            });
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $context = $this->customerContext('Customer Four Structured Metadata AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-metadata.docx', [
                ['text' => 'Intro before first heading.', 'style' => 'Normal'],
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Første avsnitt under hovedseksjonen.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Mer tekst i underseksjonen.', 'style' => 'Normal'],
                ['text' => 'Andre hovedseksjon', 'style' => 'Heading1'],
                ['text' => 'Avsluttende avsnitt.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'structured-metadata.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $this->assertSame(2, $chunks->count());
        $this->assertSame('Tema 1', $chunks[0]->topic);
        $this->assertSame('Underemne 1', $chunks[0]->sub_topic);
        $this->assertSame(['stikkord-1-a', 'stikkord-1-b', 'stikkord-1-c'], $chunks[0]->keywords);
        $this->assertSame('Tema 2', $chunks[1]->topic);
        $this->assertSame('Underemne 2', $chunks[1]->sub_topic);
        $this->assertSame(['stikkord-2-a', 'stikkord-2-b', 'stikkord-2-c'], $chunks[1]->keywords);
        $this->assertSame(0, KnowledgeMetadataTerm::query()->count());
    }

    public function test_knowledge_document_upload_generates_metadata_for_each_chunk_and_keeps_chunk_content_unchanged(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four Structured Metadata Calls AS');

        $metadataService = Mockery::mock(KnowledgeChunkMetadataGenerationService::class);
        $metadataService->shouldReceive('generateForChunk')
            ->twice()
            ->andReturnUsing(function (KnowledgeItem $document, KnowledgeItemChunk $chunk): array {
                $chunkNumber = $chunk->chunk_index + 1;
                $summary = 'Kort oppsummering '.$chunkNumber;
                $keywords = ['stikkord-'.$chunkNumber.'-a', 'stikkord-'.$chunkNumber.'-b'];

                return [
                    'service_product_tag' => 'Produkt A',
                    'theme_tag' => 'Tema A',
                    'topic' => 'Emne '.$chunkNumber,
                    'sub_topic' => 'Underemne '.$chunkNumber,
                    'keywords' => $keywords,
                    'matched_terms' => ['term '.$chunkNumber],
                    'summary_for_retrieval' => $summary,
                    'confidence_score' => 0.91,
                    'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED,
                    'new_term_suggestions' => [],
                    'embedding_input' => implode("\n", array_filter([
                        'Title: '.trim((string) ($chunk->title ?: $chunk->section_title ?: $document->title)),
                        'Service/product tag: Produkt A',
                        'Theme tag: Tema A',
                        'Topic: Emne '.$chunkNumber,
                        'Sub-topic: Underemne '.$chunkNumber,
                        'Keywords: '.implode(', ', $keywords),
                        'Matched terms: term '.$chunkNumber,
                        'Summary: '.$summary,
                        'Content: '.trim((string) $chunk->content),
                    ])),
                ];
            });
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-metadata-calls.docx', [
                ['text' => 'Intro before first heading.', 'style' => 'Normal'],
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Første avsnitt under hovedseksjonen.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Mer tekst i underseksjonen.', 'style' => 'Normal'],
                ['text' => 'Andre hovedseksjon', 'style' => 'Heading1'],
                ['text' => 'Avsluttende avsnitt.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'structured-metadata-calls.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $this->assertSame(2, $chunks->count());
        $this->assertSame('Emne 1', $chunks[0]->topic);
        $this->assertSame('Underemne 1', $chunks[0]->sub_topic);
        $this->assertSame(['stikkord-1-a', 'stikkord-1-b'], $chunks[0]->keywords);
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED, $chunks[0]->metadata_status);
        $this->assertStringContainsString('Intro before first heading.', (string) $chunks[0]->content);
        $this->assertStringContainsString('Underseksjon A', (string) $chunks[0]->content);
        $this->assertSame('Emne 2', $chunks[1]->topic);
        $this->assertSame('Underemne 2', $chunks[1]->sub_topic);
        $this->assertSame(['stikkord-2-a', 'stikkord-2-b'], $chunks[1]->keywords);
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED, $chunks[1]->metadata_status);
        $this->assertStringContainsString('Andre hovedseksjon', (string) $chunks[1]->content);
    }

    public function test_knowledge_document_upload_generates_metadata_for_each_final_chunk_in_a_six_chunk_document(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four Structured Six Chunk Metadata AS');

        $metadataService = Mockery::mock(KnowledgeChunkMetadataGenerationService::class);
        $metadataService->shouldReceive('generateForChunk')
            ->times(6)
            ->andReturnUsing(function (KnowledgeItem $document, KnowledgeItemChunk $chunk): array {
                $chunkNumber = $chunk->chunk_index + 1;

                return [
                    'service_product_tag' => 'Produkt A',
                    'theme_tag' => 'Tema A',
                    'topic' => 'Emne '.$chunkNumber,
                    'sub_topic' => 'Underemne '.$chunkNumber,
                    'keywords' => ['stikkord-'.$chunkNumber.'-a', 'stikkord-'.$chunkNumber.'-b'],
                    'matched_terms' => ['term '.$chunkNumber],
                    'summary_for_retrieval' => 'Kort oppsummering '.$chunkNumber,
                    'confidence_score' => 0.91,
                    'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED,
                    'new_term_suggestions' => [],
                    'embedding_input' => implode("\n", array_filter([
                        'Title: '.trim((string) ($chunk->title ?: $chunk->section_title ?: $document->title)),
                        'Service/product tag: Produkt A',
                        'Theme tag: Tema A',
                        'Topic: Emne '.$chunkNumber,
                        'Sub-topic: Underemne '.$chunkNumber,
                        'Keywords: stikkord-'.$chunkNumber.'-a, stikkord-'.$chunkNumber.'-b',
                        'Matched terms: term '.$chunkNumber,
                        'Summary: Kort oppsummering '.$chunkNumber,
                        'Content: '.trim((string) $chunk->content),
                    ])),
                ];
            });
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-metadata-six.docx', [
                ['text' => 'Kapittel 1', 'style' => 'Heading1'],
                ['text' => 'Tekst 1.', 'style' => 'Normal'],
                ['text' => 'Kapittel 2', 'style' => 'Heading1'],
                ['text' => 'Tekst 2.', 'style' => 'Normal'],
                ['text' => 'Kapittel 3', 'style' => 'Heading1'],
                ['text' => 'Tekst 3.', 'style' => 'Normal'],
                ['text' => 'Kapittel 4', 'style' => 'Heading1'],
                ['text' => 'Tekst 4.', 'style' => 'Normal'],
                ['text' => 'Kapittel 5', 'style' => 'Heading1'],
                ['text' => 'Tekst 5.', 'style' => 'Normal'],
                ['text' => 'Kapittel 6', 'style' => 'Heading1'],
                ['text' => 'Tekst 6.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'structured-metadata-six.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $this->assertSame(6, $chunks->count());
        $this->assertSame(range(0, 5), $chunks->pluck('chunk_index')->all());
        $this->assertSame('Emne 1', $chunks[0]->topic);
        $this->assertSame('Emne 6', $chunks[5]->topic);
        $this->assertStringContainsString('Kapittel 1', (string) $chunks[0]->content);
        $this->assertStringContainsString('Tekst 6.', (string) $chunks[5]->content);
    }

    public function test_knowledge_document_upload_fills_blank_topic_and_sub_topic_from_chunk_context_and_creates_vocabulary_candidates(): void
    {
        Storage::fake('local');

        $this->bindKnowledgeChunkBoundaryService(true);

        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function (): array {
                static $chunkNumber = 0;

                $chunkNumber++;
                $summary = 'Kort oppsummering '.$chunkNumber;

                return [
                    'id' => 'resp_blank_metadata_'.$chunkNumber,
                    'output_text' => json_encode([
                        'service_product_tag' => '',
                        'theme_tag' => '',
                        'topic' => '',
                        'sub_topic' => '',
                        'keywords' => ['stikkord-'.$chunkNumber.'-a', 'stikkord-'.$chunkNumber.'-b'],
                        'matched_terms' => [],
                        'summary_for_retrieval' => $summary,
                        'new_term_suggestions' => [],
                        'confidence_score' => 0.42,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });
        $this->app->instance(OpenAiClient::class, $openAiClient);

        $metadataService = new KnowledgeChunkMetadataGenerationService(
            $openAiClient,
            app(KnowledgeMetadataVocabularyService::class),
            app(KnowledgeChunkMetadataValidator::class),
        );
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $context = $this->customerContext('Customer Four Blank Metadata AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-metadata-blanks.docx', [
                ['text' => 'Intro before first heading.', 'style' => 'Normal'],
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Første avsnitt under hovedseksjonen.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Mer tekst i underseksjonen.', 'style' => 'Normal'],
                ['text' => 'Andre hovedseksjon', 'style' => 'Heading1'],
                ['text' => 'Avsluttende avsnitt.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'structured-metadata-blanks.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $this->assertSame(2, $chunks->count());
        $this->assertSame('Underseksjon A', $chunks[0]->heading_path);
        $this->assertSame('Underseksjon A', $chunks[0]->topic);
        $this->assertSame('Kort oppsummering 1', $chunks[0]->sub_topic);
        $this->assertSame(['stikkord-1-a', 'stikkord-1-b'], $chunks[0]->keywords);
        $this->assertStringContainsString('Intro before first heading.', (string) $chunks[0]->content);
        $this->assertStringContainsString('Underseksjon A', (string) $chunks[0]->content);
        $this->assertSame('Andre hovedseksjon', $chunks[1]->heading_path);
        $this->assertSame('Andre hovedseksjon', $chunks[1]->topic);
        $this->assertSame('Kort oppsummering 2', $chunks[1]->sub_topic);
        $this->assertSame(['stikkord-2-a', 'stikkord-2-b'], $chunks[1]->keywords);
        $this->assertStringContainsString('Andre hovedseksjon', (string) $chunks[1]->content);

        $suggestions = KnowledgeMetadataTermSuggestion::query()
            ->whereIn('source_chunk_id', $chunks->pluck('id'))
            ->get();

        $this->assertSame(2, $suggestions->where('suggested_type', 'topic')->count());
        $this->assertSame(2, $suggestions->where('suggested_type', 'sub_topic')->count());
        $this->assertSame(0, $suggestions->where('suggested_type', 'keywords')->count());
        $this->assertNotEmpty($suggestions->firstWhere('suggested_type', 'topic')->suggested_synonyms);
        $this->assertNotEmpty($suggestions->firstWhere('suggested_type', 'topic')->suggested_description);
        $this->assertNotEmpty($suggestions->firstWhere('suggested_type', 'topic')->reason);
    }

    public function test_knowledge_document_upload_continues_when_metadata_generation_ai_fails(): void
    {
        Storage::fake('local');

        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->once()
            ->andThrow(new RuntimeException('OpenAI metadata request failed with HTTP status [500].'));

        $metadataService = new KnowledgeChunkMetadataGenerationService(
            $openAiClient,
            app(KnowledgeMetadataVocabularyService::class),
            app(KnowledgeChunkMetadataValidator::class),
        );
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $context = $this->customerContext('Customer Four Metadata Failure AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('metadata-failure.docx', 'Metadata generation failure should not block upload.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'metadata-failure.docx')
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();

        $this->assertSame(1, $chunks->count());
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_FAILED, $chunks[0]->metadata_status);
        $this->assertNull($chunks[0]->topic);
        $this->assertNull($chunks[0]->sub_topic);
        $this->assertTrue($chunks[0]->keywords === null || $chunks[0]->keywords === []);
        $this->assertNotNull($chunks[0]->embedding_vector);
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

    public function test_knowledge_document_upload_generates_metadata_before_embedding_and_persists_metadata_fields(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four D AS');

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('tryEmbedText')
            ->once()
            ->with(Mockery::on(function (string $text): bool {
                return str_contains($text, 'Summary: Kort oppsummering for gjenfinning.')
                    && str_contains($text, 'Service/product tag: Produkt A')
                    && str_contains($text, 'Theme tag: Tema A')
                    && str_contains($text, 'Topic: Emne A')
                    && str_contains($text, 'Sub-topic: Underemne A')
                    && str_contains($text, 'Content: Metadata generation test content');
            }))
            ->andReturn([
                'ok' => true,
                'embedding' => [0.11, 0.22, 0.33],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ]);
        $this->app->instance(EmbeddingService::class, $embeddingService);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('metadata-generation.docx', 'Metadata generation test content that should be chunked and embedded.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'metadata-generation.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->firstOrFail();

        $this->assertSame('Produkt A', $chunk->service_product_tag);
        $this->assertSame('Tema A', $chunk->theme_tag);
        $this->assertSame('Emne A', $chunk->topic);
        $this->assertSame('Underemne A', $chunk->sub_topic);
        $this->assertSame(['stikkord a', 'stikkord b'], $chunk->keywords);
        $this->assertSame(['term a'], $chunk->matched_terms);
        $this->assertSame('Kort oppsummering for gjenfinning.', $chunk->summary_for_retrieval);
        $this->assertSame('Kort oppsummering for gjenfinning.', $chunk->ai_summary);
        $this->assertSame(0.91, $chunk->confidence_score);
        $this->assertSame(KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED, $chunk->metadata_status);
        $this->assertSame([0.11, 0.22, 0.33], $chunk->embedding_vector);
        $this->assertSame('text-embedding-3-small', $chunk->embedding_model);
    }

    public function test_knowledge_document_upload_generates_ai_summary_for_table_chunks_from_table_text(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four E AS');

        $openAiClient = Mockery::mock(OpenAiClient::class);
        $openAiClient->shouldReceive('createResponse')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                $promptText = (string) data_get($payload, 'input.1.content.0.text', '');

                return str_contains($promptText, '"chunk_type":"table"')
                    && str_contains($promptText, 'Tabell A | Tabell B')
                    && str_contains($promptText, 'source_text');
            }))
            ->andReturn([
                'id' => 'resp_table_summary',
                'output_text' => json_encode([
                    'service_product_tag' => 'samhandling',
                    'theme_tag' => 'driftsmodell',
                    'topic' => 'sikkerhetsparametere',
                    'sub_topic' => 'SOC-tjeneste',
                    'keywords' => ['SOC', 'sikkerhetsparametere'],
                    'matched_terms' => ['SOC'],
                    'summary_for_retrieval' => 'Tabellen beskriver sikkerhetsparametere for SOC-tjenesten og viser loggovervåking, hendelseshåndtering og eskalering.',
                    'new_term_suggestions' => [],
                    'confidence_score' => 0.92,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        $this->app->instance(OpenAiClient::class, $openAiClient);

        $metadataService = new KnowledgeChunkMetadataGenerationService(
            $openAiClient,
            app(KnowledgeMetadataVocabularyService::class),
            app(KnowledgeChunkMetadataValidator::class),
        );
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('tryEmbedText')
            ->once()
            ->andReturn([
                'ok' => true,
                'embedding' => [0.11, 0.22, 0.33],
                'model' => 'text-embedding-3-small',
                'usage' => [],
                'error_type' => null,
                'error_message' => null,
                'upstream_status' => 200,
                'request_id' => 'test-request-id',
                'response_body_excerpt' => null,
            ]);
        $this->app->instance(EmbeddingService::class, $embeddingService);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('table-summary.docx', [
                ['text' => 'Tabelloppsummering', 'style' => 'Heading1'],
                ['text' => '', 'style' => 'Table'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('original_filename', 'table-summary.docx')
            ->firstOrFail();

        $tableChunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->where('chunk_type', 'table')
            ->firstOrFail();

        $this->assertSame('Tabellen beskriver sikkerhetsparametere for SOC-tjenesten og viser loggovervåking, hendelseshåndtering og eskalering.', $tableChunk->summary_for_retrieval);
        $this->assertSame($tableChunk->summary_for_retrieval, $tableChunk->ai_summary);
        $this->assertSame('docx_table', $tableChunk->table_metadata['source'] ?? null);
        $this->assertIsArray($tableChunk->table_json);
        $this->assertStringContainsString('<table', (string) $tableChunk->table_html);
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

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->firstOrFail();

        $chunk->forceFill([
            'section_title' => 'Bemanning og roller',
            'section_path' => 'SOC-tjenester > Bemanning og roller',
        ])->save();

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
                && array_key_exists('topic', $chunks->first())
                && array_key_exists('sub_topic', $chunks->first())
                && array_key_exists('keywords', $chunks->first())
                && $chunks->first()['section_title'] === 'Bemanning og roller'
                && $chunks->first()['section_path'] === 'SOC-tjenester > Bemanning og roller'
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

        $chunk->forceFill([
            'section_title' => 'Bemanning og roller',
            'section_path' => 'SOC-tjenester > Bemanning og roller',
        ])->save();

        $response = $this->actingAs($context['user'])->patch(route('app.ai.knowledge-base.chunks.metadata.update', [
            'knowledgeItem' => $document->id,
            'chunk' => $chunk->id,
        ]), [
            'title' => 'Leverandøravtale',
            'ai_summary' => 'Dette er en kort oppsummering av chunkens innhold.',
            'service_product_tag' => 'Kontrakt',
            'theme_tag' => 'Juridisk',
            'topic' => 'Servicedesk',
            'sub_topic' => 'Lærlingordning',
            'keywords' => 'lærling, fagbrev, lærling, IKT-servicefaget',
            'embedding_model' => 'ignored-by-validation',
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $updatedChunk = KnowledgeItemChunk::query()->whereKey($chunk->id)->firstOrFail();
        $this->assertSame('Leverandøravtale', $updatedChunk->title);
        $this->assertSame('Dette er en kort oppsummering av chunkens innhold.', $updatedChunk->ai_summary);
        $this->assertSame('Kontrakt', $updatedChunk->service_product_tag);
        $this->assertSame('Juridisk', $updatedChunk->theme_tag);
        $this->assertSame('Servicedesk', $updatedChunk->topic);
        $this->assertSame('Lærlingordning', $updatedChunk->sub_topic);
        $this->assertSame(['lærling', 'fagbrev', 'IKT-servicefaget'], $updatedChunk->keywords);
        $this->assertSame('Bemanning og roller', $updatedChunk->section_title);
        $this->assertSame('SOC-tjenester > Bemanning og roller', $updatedChunk->section_path);
        $this->assertSame('text-embedding-3-small', $updatedChunk->embedding_model);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.chunks.0.title') === 'Leverandøravtale'
                && data_get($page, 'props.knowledgeItem.chunks.0.ai_summary') === 'Dette er en kort oppsummering av chunkens innhold.'
                && data_get($page, 'props.knowledgeItem.chunks.0.service_product_tag') === 'Kontrakt'
                && data_get($page, 'props.knowledgeItem.chunks.0.theme_tag') === 'Juridisk'
                && data_get($page, 'props.knowledgeItem.chunks.0.topic') === 'Servicedesk'
                && data_get($page, 'props.knowledgeItem.chunks.0.sub_topic') === 'Lærlingordning'
                && data_get($page, 'props.knowledgeItem.chunks.0.keywords.0') === 'lærling'
                && data_get($page, 'props.knowledgeItem.chunks.0.section_title') === 'Bemanning og roller'
                && data_get($page, 'props.knowledgeItem.chunks.0.section_path') === 'SOC-tjenester > Bemanning og roller';
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
        $originalTitle = $chunk->fresh()->title;

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
        $this->assertSame($originalTitle, $chunk->fresh()->title);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $chunk->fresh()->title, 'UTF-8'));
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
        return $this->createDocxUploadWithBlocks($filename, [
            [
                'text' => $text,
                'style' => null,
            ],
        ]);
    }

    /**
     * Purpose: Create a DOCX fixture with explicit structured blocks for upload tests.
     * Inputs: The client filename and ordered paragraph/table blocks.
     * Returns: A test uploaded file backed by a real DOCX archive.
     * Side effects: Writes a temporary ZIP file to the system temp directory.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    private function createDocxUploadWithBlocks(string $filename, array $blocks): UploadedFile
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

        $bodyXml = [];
        $relationshipsXml = [];
        $mediaFiles = [];
        $hasImages = false;

        foreach ($blocks as $block) {
            $type = (string) data_get($block, 'type', 'paragraph');

            if ($type === 'image') {
                $hasImages = true;
                $relationshipId = (string) data_get($block, 'relationship_id', 'rId'.(count($relationshipsXml) + 1));
                $mediaFilename = (string) data_get($block, 'media_filename', 'image'.(count($relationshipsXml) + 1).'.png');
                $mediaPath = 'word/media/'.$mediaFilename;
                $mediaBytes = (string) data_get($block, 'media_bytes', $this->docxSampleImageBytes());

                $relationshipsXml[] = $this->docxRelationshipXml($relationshipId, 'media/'.$mediaFilename);
                $mediaFiles[$mediaPath] = $mediaBytes;
                $bodyXml[] = $this->docxImageBodyXml(
                    $relationshipId,
                    (string) data_get($block, 'title', 'Figure 1'),
                    (string) data_get($block, 'alt_text', 'Image alt text'),
                );

                continue;
            }

            $text = (string) data_get($block, 'text', '');
            $style = data_get($block, 'style');

            $bodyXml[] = $this->docxBodyBlockXml($text, is_string($style) ? $style : null);
        }

        $namespaceTail = $hasImages
            ? ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            : '';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'.$namespaceTail.'>'
            .'<w:body>'
            .implode("\n", $bodyXml)
            .'</w:body>'
            .'</w:document>';

        $zip->addFromString('word/document.xml', $xml);

        if ($hasImages) {
            $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .implode("\n", $relationshipsXml)
                .'</Relationships>';

            $zip->addFromString('word/_rels/document.xml.rels', $relsXml);

            foreach ($mediaFiles as $mediaPath => $mediaBytes) {
                $zip->addFromString($mediaPath, $mediaBytes);
            }
        }

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
     * Purpose: Reuse the real PDF fixture that previously exposed the figure-gap regression.
     * Inputs: None.
     * Returns: A test uploaded file backed by the actual PDF used during manual diagnosis.
     * Side effects: None.
     */
    private function realKnowledgePdfUpload(): UploadedFile
    {
        $path = storage_path('app/private/customers/2162/knowledge-documents/01KS21B3M5YKSPAYKQWTZ1HC33.pdf');

        if (! is_file($path)) {
            throw new RuntimeException('Unable to locate the real PDF fixture used for regression testing.');
        }

        return new UploadedFile(
            $path,
            'Masterdata Prosjekt_pdf.pdf',
            'application/pdf',
            null,
            true,
        );
    }

    /**
     * Purpose: Build a synthetic structure fixture for the rule-based H1/H2 chunk payload builder.
     * Inputs: None.
     * Returns: A parsed-structure shaped array with one H1-only section, one H1 with H2 subsections, and another H1-only section.
     * Side effects: None.
     *
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function ruleBasedChunkStructureFixture(): array
    {
        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel 1',
                'text' => 'Kapittel 1 tekst.',
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > 2.1 Sammendrag og helhetlig løsningsforslag',
                'text' => 'Sammendrag og helhetlig løsningsforslag.',
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > 2.2 Strategisk partnerskap, veikart og måloppnåelse',
                'text' => 'Strategisk partnerskap, veikart og måloppnåelse.',
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel 3',
                'text' => 'Kapittel 3 tekst.',
                'heading_level' => null,
                'relation_hint' => null,
            ],
        ]);
    }

    /**
     * Purpose: Build a synthetic structure fixture with one oversized H2 section that must be split on block boundaries.
     * Inputs: None.
     * Returns: A parsed-structure shaped array with one H1-only section, one oversized H2 section, one small H2 section, and another H1-only section.
     * Side effects: None.
     *
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function ruleBasedOversizedChunkStructureFixture(): array
    {
        $paragraphs = [];

        for ($index = 1; $index <= 8; $index++) {
            $paragraphs[] = $this->repeatedWords(sprintf('blok-%d', $index), 100);
        }

        $oversizedSectionText = implode("\n\n", array_merge(
            ['2.1 Sammendrag og grunnlag'],
            $paragraphs,
        ));

        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel 1',
                'text' => 'Kapittel 1 tekst.',
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > 2.1 Sammendrag og grunnlag',
                'text' => $oversizedSectionText,
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > 2.2 Kort oppsummering',
                'text' => 'Kort oppsummering.',
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel 3',
                'text' => 'Kapittel 3 tekst.',
                'heading_level' => null,
                'relation_hint' => null,
            ],
        ]);
    }

    /**
     * Purpose: Build a synthetic structure fixture with one oversized H1-only section that must be split on block boundaries.
     * Inputs: None.
     * Returns: A parsed-structure shaped array with one oversized H1-only section.
     * Side effects: None.
     *
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function ruleBasedOversizedH1StructureFixture(): array
    {
        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel A',
                'text' => $this->repeatedWords('kapittel-a-del-1', 201),
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel A',
                'text' => $this->repeatedWords('kapittel-a-del-2', 201),
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel A',
                'text' => $this->repeatedWords('kapittel-a-del-3', 201),
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel A',
                'text' => $this->repeatedWords('kapittel-a-del-4', 201),
                'heading_level' => null,
                'relation_hint' => null,
            ],
        ]);
    }

    /**
     * Purpose: Build a synthetic fixture where a table appears before the first H2 section.
     * Inputs: None.
     * Returns: A parsed-structure shaped array that keeps the table under the parent H1 context.
     * Side effects: None.
     *
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function ruleBasedPreH2TableStructureFixture(): array
    {
        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'paragraph',
                'heading_path' => '1 Overskrift test',
                'text' => 'Tekst før tabell.',
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'table',
                'heading_path' => '1 Overskrift test',
                'heading_context' => '1 Overskrift test',
                'text' => "Tabell A | Tabell B\nRad 1 | Rad 2",
                'table_json' => [
                    'source_type' => 'docx_table',
                    'complexity' => 'simple',
                    'warnings' => [],
                    'row_count' => 2,
                    'column_count' => 2,
                    'title_row_index' => null,
                    'header_row_indices' => [0],
                    'table_index_in_document' => 0,
                    'rows' => [
                        [
                            'row_index' => 0,
                            'row_type' => 'header',
                            'is_title' => false,
                            'is_header' => true,
                            'is_empty' => false,
                            'explicit_header' => true,
                            'cells' => [
                                [
                                    'row_index' => 0,
                                    'cell_index' => 0,
                                    'column_index' => 0,
                                    'text' => 'Tabell A',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => true,
                                    'is_title' => false,
                                    'style_hints' => ['header_row'],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 0,
                                        'cell_index' => 0,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                                [
                                    'row_index' => 0,
                                    'cell_index' => 1,
                                    'column_index' => 1,
                                    'text' => 'Tabell B',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => true,
                                    'is_title' => false,
                                    'style_hints' => ['header_row'],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 0,
                                        'cell_index' => 1,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                            ],
                            'source_metadata' => [
                                'row_index' => 0,
                                'explicit_header' => true,
                                'column_count' => 2,
                                'row_count' => 2,
                                'detected_header_rows' => [0],
                            ],
                        ],
                        [
                            'row_index' => 1,
                            'row_type' => 'data',
                            'is_title' => false,
                            'is_header' => false,
                            'is_empty' => false,
                            'explicit_header' => false,
                            'cells' => [
                                [
                                    'row_index' => 1,
                                    'cell_index' => 0,
                                    'column_index' => 0,
                                    'text' => 'Rad 1',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => false,
                                    'is_title' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 1,
                                        'cell_index' => 0,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                                [
                                    'row_index' => 1,
                                    'cell_index' => 1,
                                    'column_index' => 1,
                                    'text' => 'Rad 2',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => false,
                                    'is_title' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 1,
                                        'cell_index' => 1,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                            ],
                            'source_metadata' => [
                                'row_index' => 1,
                                'explicit_header' => false,
                                'column_count' => 2,
                                'row_count' => 2,
                            ],
                        ],
                    ],
                    'cells' => [],
                    'source_metadata' => [
                        'source_type' => 'docx_table',
                        'row_count' => 2,
                        'column_count' => 2,
                        'title_row_index' => null,
                        'header_row_indices' => [0],
                        'table_index_in_document' => 0,
                        'has_merged_cells' => false,
                        'has_vertical_merges' => false,
                        'has_group_rows' => false,
                    ],
                ],
                'table_html' => '<table><thead><tr><th scope="col">Tabell A</th><th scope="col">Tabell B</th></tr></thead><tbody><tr><td>Rad 1</td><td>Rad 2</td></tr></tbody></table>',
                'row_count' => 2,
                'column_count' => 2,
                'table_markdown' => "| Tabell A | Tabell B |\n| --- | --- |\n| Rad 1 | Rad 2 |",
                'table_text' => "Tabell A | Tabell B\nRad 1 | Rad 2",
                'table_complexity' => 'simple',
                'table_warnings' => [],
                'table_index_in_document' => 0,
                'heading_level' => null,
                'relation_hint' => 'table_group',
            ],
            [
                'type' => 'h2_section',
                'heading_path' => '1 Overskrift test > 1.1 Dokumentasjonskrav for drift',
                'text' => "1.1 Dokumentasjonskrav for drift\n\nTekst etter H2.",
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
        ]);
    }

    /**
     * Purpose: Build a synthetic fixture with one H2 section that contains a table between text blocks.
     * Inputs: None.
     * Returns: A parsed-structure shaped array with a dedicated table element.
     * Side effects: None.
     *
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function ruleBasedTableChunkStructureFixture(): array
    {
        $h1Text = 'Kapittel 2 tekst. ' . $this->repeatedWords('kapitteltekst', 40);
        $preTableText = 'Innledning før tabell. ' . $this->repeatedWords('innledningsord', 40);
        $postTableText = 'Etter tabell. ' . $this->repeatedWords('avslutningsord', 40);

        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel 2',
                'text' => $h1Text,
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > Underseksjon A',
                'text' => $preTableText,
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'table',
                'heading_path' => 'Kapittel 2 > Underseksjon A',
                'heading_context' => 'Kapittel 2 > Underseksjon A',
                'text' => "Tabell A | Tabell B\nRad 1 | Rad 2",
                'table_json' => [
                    'source_type' => 'docx_table',
                    'complexity' => 'simple',
                    'warnings' => [],
                    'row_count' => 2,
                    'column_count' => 2,
                    'title_row_index' => null,
                    'header_row_indices' => [0],
                    'table_index_in_document' => 0,
                    'rows' => [
                        [
                            'row_index' => 0,
                            'row_type' => 'header',
                            'is_title' => false,
                            'is_header' => true,
                            'is_empty' => false,
                            'explicit_header' => true,
                            'cells' => [
                                [
                                    'row_index' => 0,
                                    'cell_index' => 0,
                                    'column_index' => 0,
                                    'text' => 'Tabell A',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => true,
                                    'is_title' => false,
                                    'style_hints' => ['header_row'],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 0,
                                        'cell_index' => 0,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                                [
                                    'row_index' => 0,
                                    'cell_index' => 1,
                                    'column_index' => 1,
                                    'text' => 'Tabell B',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => true,
                                    'is_title' => false,
                                    'style_hints' => ['header_row'],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 0,
                                        'cell_index' => 1,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                            ],
                            'source_metadata' => [
                                'row_index' => 0,
                                'explicit_header' => true,
                                'column_count' => 2,
                                'row_count' => 2,
                                'detected_header_rows' => [0],
                            ],
                        ],
                        [
                            'row_index' => 1,
                            'row_type' => 'data',
                            'is_title' => false,
                            'is_header' => false,
                            'is_empty' => false,
                            'explicit_header' => false,
                            'cells' => [
                                [
                                    'row_index' => 1,
                                    'cell_index' => 0,
                                    'column_index' => 0,
                                    'text' => 'Rad 1',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => false,
                                    'is_title' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 1,
                                        'cell_index' => 0,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                                [
                                    'row_index' => 1,
                                    'cell_index' => 1,
                                    'column_index' => 1,
                                    'text' => 'Rad 2',
                                    'is_empty' => false,
                                    'rowspan' => 1,
                                    'colspan' => 1,
                                    'is_header' => false,
                                    'is_title' => false,
                                    'style_hints' => [],
                                    'source_metadata' => [
                                        'grid_span' => 1,
                                        'v_merge' => null,
                                        'row_index' => 1,
                                        'cell_index' => 1,
                                        'detected_title_row' => false,
                                        'detected_header_rows' => [0],
                                        'column_count' => 2,
                                        'row_count' => 2,
                                    ],
                                ],
                            ],
                            'source_metadata' => [
                                'row_index' => 1,
                                'explicit_header' => false,
                                'column_count' => 2,
                                'row_count' => 2,
                            ],
                        ],
                    ],
                    'cells' => [],
                    'source_metadata' => [
                        'source_type' => 'docx_table',
                        'row_count' => 2,
                        'column_count' => 2,
                        'title_row_index' => null,
                        'header_row_indices' => [0],
                        'table_index_in_document' => 0,
                        'has_merged_cells' => false,
                        'has_vertical_merges' => false,
                        'has_group_rows' => false,
                    ],
                ],
                'table_html' => '<table><thead><tr><th scope="col">Tabell A</th><th scope="col">Tabell B</th></tr></thead><tbody><tr><td>Rad 1</td><td>Rad 2</td></tr></tbody></table>',
                'row_count' => 2,
                'column_count' => 2,
                'table_markdown' => "| Tabell A | Tabell B |\n| --- | --- |\n| Rad 1 | Rad 2 |",
                'table_text' => "Tabell A | Tabell B\nRad 1 | Rad 2",
                'table_complexity' => 'simple',
                'table_warnings' => [],
                'table_index_in_document' => 0,
                'heading_level' => null,
                'relation_hint' => 'table_group',
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > Underseksjon A',
                'text' => $postTableText,
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
        ]);
    }

    /**
     * Purpose: Build a synthetic fixture that mirrors the real PDF figure gap between 1.12 and 1.13.
     * Inputs: None.
     * Returns: A parsed-structure shaped array with one H2 section, a figure-like text cluster, and the next H2 section.
     * Side effects: None.
     *
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function ruleBasedFigureGapStructureFixture(): array
    {
        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'h2_section',
                'heading_path' => 'B ILAG 1-11 > 1.12 R ISIKOSTYRING AV PROSJEKTER',
                'text' => 'Prosess for risikohåndtering. Leverandøren er ansvarlig for nødvendig risikostyring i etableringsprosjektet. Risikostyring er en integrert og kontinuerlig del av Leverandørens prosjektstyring og må følges opp gjennom hele prosjektperioden. Risikoer skal identifiseres, vurderes, prioriteres og håndteres fortløpende. Tiltak skal dokumenteres, følges opp og revideres når forutsetningene endrer seg.',
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'B ILAG 1-11',
                'text' => 'Advania Risk Management',
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'list',
                'heading_path' => 'B ILAG 1-11',
                'text' => "0. Identifisere\n1. Beskrivelse\n2. Analyse",
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'B ILAG 1-11',
                'text' => "Risiko register\nRisiko register",
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'list',
                'heading_path' => 'B ILAG 1-11',
                'text' => "3. Planlegge\n4. Oppfølging\n5. Kontroll",
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'B ILAG 1-11',
                'text' => "Løste risikoer\nÅpne risikoer",
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'B ILAG 1-11',
                'text' => 'Kontinuerlig risk prosess i dynamisk risiko analyse',
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'h2_section',
                'heading_path' => '4. Oppfølging > 1.13 K OSTNADSSTYRING I PROSJEKTER',
                'text' => "1.13 K OSTNADSSTYRING I PROSJEKTER\n\nProsess for kostnadsstyring og kostnadskontroll i prosjektfasen. Kostnadsstyring i prosjektet skal gjennomføres gjennom en strukturert prosess som sikrer løpende kontroll med prosjektet, tydelig oppfølging av avvik og god styring av budsjett, prognoser og beslutningsgrunnlag. Leverandøren skal kunne rapportere avvik, følge opp tiltak og gi Kunden et oppdatert grunnlag for styring og beslutninger.",
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
        ]);
    }

    /**
     * Purpose: Build a synthetic H2 section fixture that straddles the chunk threshold with two paragraph blocks.
     * Inputs: Word counts for the first and second paragraph blocks.
     * Returns: A parsed-structure shaped array with one H2 section.
     * Side effects: None.
     *
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function ruleBasedThresholdBoundaryStructureFixture(int $firstBlockWords, int $secondBlockWords): array
    {
        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel Grense > 2.1 Grensetest',
                'text' => $this->repeatedWords('grenseord-a', $firstBlockWords)
                    ."\n\n"
                    .$this->repeatedWords('grenseord-b', $secondBlockWords),
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
        ]);
    }

    /**
     * Purpose: Build a parsed-structure shaped fixture from ordered document sections.
     * Inputs: Ordered section descriptors with text and heading metadata.
     * Returns: A source_text plus offset-aware elements array.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $sections
     * @return array{
     *     source_text: string,
     *     elements: array<int, array<string, mixed>>
     * }
     */
    private function buildRuleBasedStructureFixture(array $sections): array
    {
        $sourceTextParts = [];
        $elements = [];
        $cursor = 0;
        $lastIndex = array_key_last($sections);

        foreach ($sections as $index => $section) {
            $text = (string) $section['text'];
            $startOffset = $cursor;
            $sourceTextParts[] = $text;
            $cursor += mb_strlen($text, 'UTF-8');

            $elements[] = [
                'id' => sprintf('element-%04d', $index + 1),
                'type' => $section['type'],
                'heading_path' => $section['heading_path'],
                'text' => $text,
                'start_offset' => $startOffset,
                'end_offset' => $cursor,
                'order_index' => $index,
                'heading_level' => $section['heading_level'],
                'relation_hint' => $section['relation_hint'],
            ];

            foreach ([
                'heading_context',
                'table_json',
                'table_html',
                'table_complexity',
                'table_warnings',
                'table_markdown',
                'table_text',
                'rows',
                'row_count',
                'column_count',
                'table_index_in_document',
            ] as $optionalKey) {
                if (array_key_exists($optionalKey, $section)) {
                    $elements[$index][$optionalKey] = $section[$optionalKey];
                }
            }

            if ($index < $lastIndex) {
                $cursor += 2;
            }
        }

        return [
            'source_text' => implode("\n\n", $sourceTextParts),
            'elements' => $elements,
        ];
    }

    /**
     * Purpose: Repeat a phrase a fixed number of times to create predictable test content length.
     * Inputs: The token or phrase to repeat and the number of repetitions.
     * Returns: A whitespace-separated string with a stable word count.
     * Side effects: None.
     */
    private function repeatedWords(string $phrase, int $count): string
    {
        return implode(' ', array_fill(0, $count, $phrase));
    }

    /**
     * Purpose: Invoke the private rule-based chunk payload builder.
     * Inputs: The parsed structure array.
     * Returns: The generated chunk payloads.
     * Side effects: None.
     *
     * @param array<string, mixed> $structure
     * @return array<int, array<string, mixed>>
     */
    private function invokeBuildRuleBasedH2ChunkPayloads(array $structure): array
    {
        $controller = app(KnowledgeBaseController::class);
        $method = new \ReflectionMethod($controller, 'buildRuleBasedH2ChunkPayloads');
        $method->setAccessible(true);

        return $method->invoke($controller, $structure);
    }

    /**
     * Purpose: Build one DOCX body block for a structured fixture.
     * Inputs: The visible text and optional paragraph style name.
     * Returns: A WordprocessingML paragraph or table block.
     * Side effects: None.
     */
    private function docxBodyBlockXml(string $text, ?string $style = null): string
    {
        if ($style === 'Table') {
            return <<<'XML'
<w:tbl>
    <w:tblGrid>
        <w:gridCol w:w="2400"/>
        <w:gridCol w:w="2400"/>
    </w:tblGrid>
    <w:tr>
        <w:trPr><w:tblHeader/></w:trPr>
        <w:tc><w:p><w:r><w:t>Tabell A</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Tabell B</w:t></w:r></w:p></w:tc>
    </w:tr>
    <w:tr>
        <w:tc><w:p><w:r><w:t>Rad 1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Rad 2</w:t></w:r></w:p></w:tc>
    </w:tr>
</w:tbl>
XML;
        }

        $escapedText = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $styleXml = $style !== null && trim($style) !== ''
            ? '<w:pPr><w:pStyle w:val="'.htmlspecialchars($style, ENT_XML1 | ENT_COMPAT, 'UTF-8').'"/></w:pPr>'
            : '';

        return '<w:p>'.$styleXml.'<w:r><w:t>'.$escapedText.'</w:t></w:r></w:p>';
    }

    /**
     * Purpose: Build one DOCX image paragraph for a structured fixture.
     * Inputs: The relationship id, image title, and alt text.
     * Returns: A WordprocessingML paragraph with an embedded drawing reference.
     * Side effects: None.
     */
    private function docxImageBodyXml(string $relationshipId, string $title, string $altText): string
    {
        $relationshipId = htmlspecialchars($relationshipId, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $title = htmlspecialchars($title, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $altText = htmlspecialchars($altText, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return <<<XML
<w:p>
    <w:r>
        <w:drawing>
            <wp:inline>
                <wp:extent cx="952500" cy="952500"/>
                <wp:docPr id="1" name="{$title}" title="{$title}" descr="{$altText}"/>
                <a:graphic>
                    <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                        <pic:pic>
                            <pic:blipFill>
                                <a:blip r:embed="{$relationshipId}"/>
                            </pic:blipFill>
                        </pic:pic>
                    </a:graphicData>
                </a:graphic>
            </wp:inline>
        </w:drawing>
    </w:r>
</w:p>
XML;
    }

    /**
     * Purpose: Build one DOCX image relationship entry for a structured fixture.
     * Inputs: The relationship id and the media target path.
     * Returns: A WordprocessingML relationship entry.
     * Side effects: None.
     */
    private function docxRelationshipXml(string $relationshipId, string $targetPath): string
    {
        $relationshipId = htmlspecialchars($relationshipId, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $targetPath = htmlspecialchars($targetPath, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return '<Relationship Id="'.$relationshipId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="'.$targetPath.'"/>';
    }

    /**
     * Purpose: Return a compact PNG image fixture for embedded image uploads.
     * Inputs: None.
     * Returns: Binary PNG bytes suitable for a DOCX media entry.
     * Side effects: None.
     */
    private function docxSampleImageBytes(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2X3b8AAAAASUVORK5CYII=', true);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to build a DOCX image fixture.');
        }

        return $bytes;
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
     * Purpose: Bind a deterministic billing entitlement service for knowledge base tests.
     * Inputs: None.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindSuccessfulBillingEntitlementService(): void
    {
        $service = Mockery::mock(BillingEntitlementService::class);
        $service->shouldReceive('canUseAiOffer')
            ->andReturnTrue();

        $this->app->instance(BillingEntitlementService::class, $service);
    }

    /**
     * Purpose: Bind a deterministic metadata generation service for knowledge document upload tests.
     * Inputs: None.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindSuccessfulKnowledgeMetadataGenerationService(): void
    {
        $service = Mockery::mock(KnowledgeChunkMetadataGenerationService::class);
        $service->shouldReceive('generateForChunk')
            ->andReturnUsing(function (KnowledgeItem $document, KnowledgeItemChunk $chunk): array {
                $summary = 'Kort oppsummering for gjenfinning.';
                $keywords = ['stikkord a', 'stikkord b'];
                $matchedTerms = ['term a'];
                $embeddingInput = implode("\n", array_filter([
                    'Title: '.trim((string) ($chunk->title ?: $chunk->section_title ?: $document->title)),
                    'Service/product tag: Produkt A',
                    'Theme tag: Tema A',
                    'Topic: Emne A',
                    'Sub-topic: Underemne A',
                    'Keywords: '.implode(', ', $keywords),
                    'Matched terms: '.implode(', ', $matchedTerms),
                    'Summary: '.$summary,
                    'Content: '.trim((string) $chunk->content),
                ]));

                return [
                    'service_product_tag' => 'Produkt A',
                    'theme_tag' => 'Tema A',
                    'topic' => 'Emne A',
                    'sub_topic' => 'Underemne A',
                    'keywords' => $keywords,
                    'matched_terms' => $matchedTerms,
                    'summary_for_retrieval' => $summary,
                    'confidence_score' => 0.91,
                    'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED,
                    'new_term_suggestions' => [],
                    'embedding_input' => $embeddingInput,
                ];
            });

        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $service);
    }

    /**
     * Purpose: Bind a deterministic suggestion enrichment service for upload tests.
     * Inputs: None.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindSuccessfulKnowledgeVocabularySuggestionEnrichmentService(): void
    {
        $service = Mockery::mock(KnowledgeVocabularySuggestionEnrichmentService::class);
        $service->shouldReceive('enrichSuggestion')
            ->andReturnUsing(function (KnowledgeItem $document, KnowledgeItemChunk $chunk, string $field, string $term): array {
                $heading = trim((string) ($chunk->heading_path ?: $chunk->section_title ?: 'seksjonen'));
                $baseDescription = trim((string) ($chunk->summary_for_retrieval ?? ''));

                return [
                    'canonical_name' => $term,
                    'synonyms' => [
                        $term.' alternativ',
                    ],
                    'description' => $baseDescription !== '' ? $baseDescription : 'Beskrivelse av '.$field.' under '.$heading,
                    'reason' => 'Foreslått fra chunk-metadata basert på innhold under '.$heading.'.',
                ];
            });

        $this->app->instance(KnowledgeVocabularySuggestionEnrichmentService::class, $service);
    }

    /**
     * Purpose: Bind a deterministic knowledge chunk boundary service for upload tests.
     * Inputs: Optional flag to group parsed elements by primary heading.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindKnowledgeChunkBoundaryService(bool $groupByPrimaryHeading = false, bool $includeMetadataSuggestions = false): void
    {
        $service = Mockery::mock(AiKnowledgeChunkBoundaryService::class);
        $service->shouldReceive('suggestBoundaries')
            ->andReturnUsing(function (int $customerId, array $documentContext, array $structure) use ($groupByPrimaryHeading, $includeMetadataSuggestions): array {
                $sourceText = trim((string) data_get($structure, 'source_text', ''));
                $elements = array_values(array_filter(
                    (array) data_get($structure, 'elements', []),
                    static fn ($element): bool => is_array($element),
                ));

                if ($sourceText === '' || $elements === []) {
                    return [
                        'model' => 'deterministic-boundary',
                        'analysis_groups' => [],
                    ];
                }

                $groups = $groupByPrimaryHeading
                    ? $this->groupKnowledgeElementsByPrimaryHeading($elements)
                    : [$elements];

                $analysisGroups = [];

                foreach ($groups as $groupIndex => $groupElements) {
                    if (! is_array($groupElements) || $groupElements === []) {
                        continue;
                    }

                    $groupStart = (int) data_get($groupElements[0], 'start_offset', 0);
                    $groupEnd = (int) data_get($groupElements[count($groupElements) - 1], 'end_offset', $groupStart);
                    $groupText = trim((string) mb_substr($sourceText, $groupStart, max(0, $groupEnd - $groupStart), 'UTF-8'));

                    $analysisGroups[] = [
                        'group_index' => $groupIndex,
                        'start_offset' => $groupStart,
                        'end_offset' => $groupEnd,
                        'text' => $groupText,
                        'word_count' => $this->wordCount($groupText),
                        'elements' => $groupElements,
                        'previous_group_tail' => null,
                        'next_group_head' => null,
                        'suggested_chunks' => $includeMetadataSuggestions ? [[
                            'start_offset_relative' => 0,
                            'end_offset_relative' => mb_strlen($groupText, 'UTF-8'),
                            'short_reason' => 'Boundary chunk '.$groupIndex,
                            'topic' => 'Tema '.($groupIndex + 1),
                            'sub_topic' => 'Underemne '.($groupIndex + 1),
                            'keywords' => [
                                'stikkord-'.($groupIndex + 1).'-a',
                                'stikkord-'.($groupIndex + 1).'-b',
                                'stikkord-'.($groupIndex + 1).'-c',
                            ],
                        ]] : [],
                        'request_id' => null,
                        'response_id' => null,
                    ];
                }

                $groupCount = count($analysisGroups);

                for ($index = 0; $index < $groupCount; $index++) {
                    $analysisGroups[$index]['previous_group_tail'] = $index > 0
                        ? Str::limit(trim((string) data_get($analysisGroups[$index - 1], 'text', '')), 180, '')
                        : null;
                    $analysisGroups[$index]['next_group_head'] = $index < $groupCount - 1
                        ? Str::limit(trim((string) data_get($analysisGroups[$index + 1], 'text', '')), 180, '')
                        : null;
                }

                return [
                    'model' => 'deterministic-boundary',
                    'analysis_groups' => $analysisGroups,
                ];
            });

        $this->app->instance(AiKnowledgeChunkBoundaryService::class, $service);
    }

    /**
     * Purpose: Group parsed elements by their top-level heading path.
     * Inputs: The ordered structural elements from the knowledge document parser.
     * Returns: Ordered element groups where H2 content stays inside the enclosing H1 group.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function groupKnowledgeElementsByPrimaryHeading(array $elements): array
    {
        $groups = [];
        $currentGroup = [];
        $currentPrimaryHeading = null;

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $text = trim((string) data_get($element, 'text', ''));

            if ($text === '') {
                continue;
            }

            $elementPrimaryHeading = $this->primaryHeadingFromPath(data_get($element, 'heading_path'));

            if ($currentGroup !== [] && $currentPrimaryHeading !== null && $elementPrimaryHeading !== null && $elementPrimaryHeading !== $currentPrimaryHeading) {
                $groups[] = $currentGroup;
                $currentGroup = [];
                $currentPrimaryHeading = null;
            }

            if ($currentPrimaryHeading === null && $elementPrimaryHeading !== null) {
                $currentPrimaryHeading = $elementPrimaryHeading;
            }

            $currentGroup[] = $element;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /**
     * Purpose: Resolve the primary heading segment from a heading path.
     * Inputs: A heading path string or null.
     * Returns: The top-level heading segment or null when no heading exists.
     * Side effects: None.
     */
    private function primaryHeadingFromPath(mixed $headingPath): ?string
    {
        $text = trim((string) ($headingPath ?? ''));

        if ($text === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(' > ', $text),
        ), static fn (string $part): bool => $part !== ''));

        return $parts !== [] ? $parts[0] : null;
    }

    /**
     * Purpose: Count the approximate number of words in a text block.
     * Inputs: Raw text.
     * Returns: The word count.
     * Side effects: None.
     */
    private function wordCount(string $text): int
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($normalized === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
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
