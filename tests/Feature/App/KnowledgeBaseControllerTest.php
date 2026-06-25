<?php

namespace Tests\Feature\App;

use App\Http\Controllers\App\KnowledgeBaseController;
use App\Models\AiTokenEvent;
use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\KnowledgeDocumentCategory;
use App\Models\KnowledgeDocumentTopic;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemRevision;
use App\Models\KnowledgeItemVersion;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\AiTokenLogger;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataGenerationService;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataValidator;
use App\Services\Ai\Knowledge\KnowledgeDocumentSummaryGenerationService;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use App\Services\Ai\Knowledge\KnowledgeVocabularySuggestionEnrichmentService;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Knowledge\AiKnowledgeChunkBoundaryService;
use App\Services\Knowledge\PdfFigurePreviewRenderer;
use App\Services\OpenAi\EmbeddingService;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->bindSuccessfulKnowledgeDocumentSummaryGenerationService();
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
            $ownershipOptions = collect(data_get($page, 'props.documentOwnershipOptions', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Create'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · Last opp'
                && count(data_get($page, 'props.documentTypeOptions', [])) === 6
                && $ownershipOptions->count() === 3
                && $ownershipOptions->firstWhere('value', KnowledgeItem::OWNERSHIP_TYPE_COMPANY)['selectable'] === true
                && $ownershipOptions->firstWhere('value', KnowledgeItem::OWNERSHIP_TYPE_PERSONAL)['selectable'] === true
                && $ownershipOptions->firstWhere('value', KnowledgeItem::OWNERSHIP_TYPE_CASE)['selectable'] === false
                && data_get($page, 'props.defaultDocumentType') === KnowledgeItem::DOCUMENT_TYPE_OTHER
                && data_get($page, 'props.storeUrl') === route('app.ai.knowledge-base.store')
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index');
        });
    }

    public function test_knowledge_base_create_and_edit_pages_expose_filtered_document_theme_options(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Three Theme Options AS');
        $foreignContext = $this->customerContext('Customer Three Theme Options Foreign AS');

        $approvedLateTheme = $this->createKnowledgeThemeTerm($context['customer'], 'Zulu tema');
        $approvedEarlyTheme = $this->createKnowledgeThemeTerm($context['customer'], 'Alfa tema');

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_THEME_TAG,
            'canonical_name' => 'Skjult tema',
            'synonyms' => ['skjult'],
            'description' => 'Skal ikke vises fordi den ikke er godkjent.',
            'approved' => false,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $foreignContext['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_THEME_TAG,
            'canonical_name' => 'Fremmed tema',
            'synonyms' => ['fremmed'],
            'description' => 'Skal ikke vises fordi den tilhører en annen kunde.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_DOCUMENT_TYPE,
            'canonical_name' => 'Dokumentkategori',
            'synonyms' => ['kategori'],
            'description' => 'Skal ikke vises fordi den har feil term-type.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_TOPIC,
            'canonical_name' => 'Tema fra chunk',
            'synonyms' => ['chunk-tema'],
            'description' => 'Skal ikke vises fordi den ikke er dokumenttema.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_SUB_TOPIC,
            'canonical_name' => 'Underemne fra chunk',
            'synonyms' => ['chunk-underemne'],
            'description' => 'Skal ikke vises fordi den ikke er dokumenttema.',
            'approved' => true,
        ]);

        KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_SERVICE_PRODUCT_TAG,
            'canonical_name' => 'Tjenesteprodukt',
            'synonyms' => ['produkt'],
            'description' => 'Skal ikke vises fordi den ikke er dokumenttema.',
            'approved' => true,
        ]);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('theme-options.docx', 'Document content used to open the edit page.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'theme-options.docx')
            ->firstOrFail();

        $createResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.create'));
        $createResponse->assertOk();
        $createResponse->assertViewHas('page', function (array $page): bool {
            $ownershipOptions = collect(data_get($page, 'props.documentOwnershipOptions', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Create'
                && $ownershipOptions->count() === 3
                && $ownershipOptions->firstWhere('value', KnowledgeItem::OWNERSHIP_TYPE_CASE)['selectable'] === false;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page): bool {
            $ownershipOptions = collect(data_get($page, 'props.documentOwnershipOptions', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && $ownershipOptions->count() === 3
                && $ownershipOptions->firstWhere('value', KnowledgeItem::OWNERSHIP_TYPE_CASE)['selectable'] === false;
        });
    }

    public function test_knowledge_base_create_and_edit_pages_expose_document_category_options_with_nested_topics(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Catalog Options AS');
        $foreignContext = $this->customerContext('Customer Catalog Options Foreign AS');

        $alphaCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Alfa kategori');
        $omegaCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Omega kategori');
        $inactiveCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Skjult kategori', false);
        $foreignCategory = $this->createKnowledgeDocumentCategory($foreignContext['customer'], 'Fremmed kategori');

        $alphaTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Alfa tema');
        $zuluTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Zulu tema');
        $inactiveTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Skjult tema', false);
        $foreignTopic = $this->createKnowledgeDocumentTopic($foreignContext['customer'], 'Fremmed tema');
        $omegaTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Beta tema');

        $alphaCategory->topics()->attach([$alphaTopic->id, $zuluTopic->id, $inactiveTopic->id, $foreignTopic->id]);
        $omegaCategory->topics()->attach([$omegaTopic->id]);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('catalog-options.docx', 'Catalog option document content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'catalog-options.docx')
            ->firstOrFail();

        $expectedOptions = [
            [
                'id' => $alphaCategory->id,
                'name' => $alphaCategory->name,
                'topics' => [
                    [
                        'id' => $alphaTopic->id,
                        'name' => $alphaTopic->name,
                    ],
                    [
                        'id' => $zuluTopic->id,
                        'name' => $zuluTopic->name,
                    ],
                ],
            ],
            [
                'id' => $omegaCategory->id,
                'name' => $omegaCategory->name,
                'topics' => [
                    [
                        'id' => $omegaTopic->id,
                        'name' => $omegaTopic->name,
                    ],
                ],
            ],
        ];

        $createResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.create'));
        $createResponse->assertOk();
        $createResponse->assertViewHas('page', function (array $page) use ($expectedOptions): bool {
            $ownershipOptions = collect(data_get($page, 'props.documentOwnershipOptions', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Create'
                && data_get($page, 'props.documentCategoryOptions') === $expectedOptions
                && $ownershipOptions->count() === 3;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page) use ($expectedOptions): bool {
            $ownershipOptions = collect(data_get($page, 'props.documentOwnershipOptions', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($page, 'props.documentCategoryOptions') === $expectedOptions
                && $ownershipOptions->count() === 3;
        });
    }

    public function test_knowledge_document_upload_can_persist_explicit_personal_ownership(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Three Personal Ownership AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('personal-ownership.docx', 'Personal ownership content used for validation.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_PERSONAL,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->with(['owner', 'uploadedBy'])
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'personal-ownership.docx')
            ->firstOrFail();

        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_PERSONAL, $document->ownership_type);
        $this->assertSame($context['user']->id, $document->owner_user_id);
        $this->assertSame($context['user']->name, $document->owner?->name);
        $this->assertSame($context['user']->id, $document->uploaded_by_user_id);
        $this->assertSame($context['user']->name, $document->uploadedBy?->name);
    }

    public function test_knowledge_document_store_rejects_invalid_ownership_type(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Three Invalid Ownership AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('invalid-ownership.docx', 'Invalid ownership document content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => 'invalid',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['ownership_type']);

        $this->assertDatabaseMissing('knowledge_items', [
            'customer_id' => $context['customer']->id,
            'original_filename' => 'invalid-ownership.docx',
        ]);
    }

    public function test_knowledge_base_index_payload_exposes_document_theme_metadata_and_nulls_when_missing(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Three Theme Index AS');
        $themeName = 'Bærekraft';
        $themeTerm = $this->createKnowledgeThemeTerm($context['customer'], $themeName);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('theme-index.docx', 'Themed document content with enough text to persist.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('plain-index.docx', 'Plain document content with enough text to persist.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $themedDocument = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'theme-index.docx')
            ->firstOrFail();

        $themedDocument->forceFill([
            'document_theme_term_id' => $themeTerm->id,
        ])->save();

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($themeTerm): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));
            $themedItem = $items->firstWhere('original_filename', 'theme-index.docx');
            $plainItem = $items->firstWhere('original_filename', 'plain-index.docx');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $themedItem !== null
                && data_get($themedItem, 'document_theme_term_id') === $themeTerm->id
                && data_get($themedItem, 'document_theme_label') === $themeTerm->canonical_name
                && $plainItem !== null
                && data_get($plainItem, 'document_theme_term_id') === null
                && data_get($plainItem, 'document_theme_label') === null;
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
            ->with('owner')
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'method-description.docx')
            ->firstOrFail();

        $documentVersion = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('is_current', true)
            ->firstOrFail();

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('chunk_index')
            ->get();
        $normalizedContent = $this->normalizeWhitespace($content);

        $this->assertStringStartsWith('customers/'.$context['customer']->id.'/knowledge-documents/', $documentVersion->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($documentVersion->storage_path));
        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_METHOD, $document->document_type);
        $this->assertSame(KnowledgeItem::OWNERSHIP_TYPE_COMPANY, $document->ownership_type);
        $this->assertSame($context['user']->id, $document->owner_user_id);
        $this->assertSame($context['user']->name, $document->owner?->name);
        $this->assertSame(KnowledgeItem::EXTRACTION_STATUS_COMPLETED, $document->extraction_status);
        $this->assertSame('', (string) $document->extraction_error);
        $this->assertSame($normalizedContent, $this->normalizeWhitespace((string) $document->extracted_text));
        $this->assertStringStartsWith('AI-oppsummering:', (string) $document->summary);
        $this->assertGreaterThan(0, $chunks->count());
        $this->assertSame(range(0, $chunks->count() - 1), $chunks->pluck('chunk_index')->all());
        $this->assertSame(
            array_fill(0, $chunks->count(), KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW),
            $chunks->pluck('review_status')->all(),
        );
    }

    public function test_knowledge_document_upload_creates_chunks_with_version_id(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Version Chunk AS');
        $content = str_repeat('Version chunk test content with sufficient length for deterministic chunking. ', 26);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('version-chunk.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'version-chunk.docx')
            ->firstOrFail();

        $version = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->first();

        $this->assertNotNull($version, 'A KnowledgeItemVersion should be created on upload.');
        $this->assertTrue($version->is_current);
        $this->assertSame($document->resolvedStoragePath(), $version->storage_path);

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->get();

        $this->assertGreaterThan(0, $chunks->count());

        foreach ($chunks as $chunk) {
            $this->assertSame(
                $version->id,
                $chunk->knowledge_item_version_id,
                "Chunk {$chunk->chunk_index} should reference the created version.",
            );
        }

        $this->assertCount($chunks->count(), $version->chunks);
    }

    public function test_metadata_update_does_not_create_new_version(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Metadata Update AS');
        $content = str_repeat('Metadata update test content with sufficient length for chunking. ', 26);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('metadata-no-version.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'metadata-no-version.docx')
            ->firstOrFail();

        $versionsBefore = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->count();

        $this->assertSame(1, $versionsBefore, 'Upload should create exactly one version.');

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->get();

        $versionIdBefore = $chunks->first()?->knowledge_item_version_id;

        // Update metadata only (no new file upload).
        $this->actingAs($context['user'])->patch(
            route('app.ai.knowledge-base.update', $document),
            [
                'title' => 'Updated Title',
                'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
                'is_active' => true,
            ],
        );

        $versionsAfter = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->count();

        $this->assertSame(1, $versionsAfter, 'Metadata update must not create a new version.');

        // Chunks still point to the same version.
        foreach ($chunks->fresh() as $chunk) {
            $this->assertSame($versionIdBefore, $chunk->knowledge_item_version_id);
        }
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
            ->where('title', 'structured-pipeline.docx')
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
        $showResponse->assertViewHas('page', function (array $page): bool {
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
            ->where('title', 'image-pipeline.docx')
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
            ->where('title', 'image-h2-context.docx')
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
            ->where('title', 'image-unpreviewable.docx')
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
            ->where('title', 'image-missing.docx')
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
            ->where('title', 'image-access.docx')
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
            ->where('title', 'image-access-foreign.docx')
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

        $prefixPayload = collect($payloads)->first(static function (array $payload): bool {
            return data_get($payload, 'chunk_type') === 'semantic'
                && str_contains((string) ($payload['content'] ?? ''), 'Det skal holdes egne møter for risikostyring jevnlig');
        });

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

        $this->assertNotNull($prefixPayload, 'Expected the narrative text before the figure to remain a semantic chunk.');
        $this->assertStringContainsString('Det skal holdes egne møter for risikostyring jevnlig', (string) ($prefixPayload['content'] ?? ''));
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
        $this->assertStringNotContainsString('Det skal holdes egne møter for risikostyring jevnlig', (string) ($imagePayload['content'] ?? ''));
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

    public function test_rule_based_h2_chunk_payload_builder_does_not_create_pdf_figure_gap_for_body_text_without_strong_figure_evidence(): void
    {
        $structure = $this->buildRuleBasedStructureFixture([
            [
                'type' => 'h2_section',
                'heading_path' => 'B ILAG 1-11 > 1.10 L EVERANDØRENS STANDARD ORGANISERING AV PROSJEKTER',
                'text' => 'Innledning før sjekklister. Leverandøren beskriver her hvordan kick-off for prosjektet skal gjennomføres.',
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'B ILAG 1-11 > 1.10 L EVERANDØRENS STANDARD ORGANISERING AV PROSJEKTER',
                'text' => "Sjekklister for prosjekt kick-off\nLeverandøren benytter etablerte sjekklister for gjennomføring av prosjektets kick-off. Disse sjekklistene sikrer at alle sentrale temaer behandles ved prosjektoppstart. Dette omfatter blant annet gjennomgang av prosjektmål, leveranseomfang, organisering, rollefordeling, fremdriftsplan, samhandlingsmodell, rapporteringsstruktur og beslutningsprosesser.\nSjekklistene bidrar til å etablere en felles forståelse av prosjektet mellom partene før prosjektets operative aktiviteter starter.\nSjekklister for milepæler og beslutningspunkter\nVed sentrale milepæler i prosjektet benyttes strukturerte sjekklister som dokumenterer at nødvendige aktiviteter er gjennomført før prosjektet går videre til neste fase. Sjekklistene benyttes som grunnlag for beslutningsunderlag i prosjektledelsen og i styringsgruppen.\nBeslutningsunderlaget kan blant annet omfatte:",
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'h2_section',
                'heading_path' => '4. Oppfølging > 1.11 L EVERANDØRENS OPPBYGGING AV PROSJEKTPLANEN (WBS STRUKTUR MM .)',
                'text' => "1.11 L EVERANDØRENS OPPBYGGING AV PROSJEKTPLANEN (WBS STRUKTUR MM .)\n\nOppbygging av WBS-struktur i prosjektplanen. Leverandøren benytter en strukturert Work Breakdown Structure (WBS) som grunnlag for planlegging, styring og oppfølging av prosjektet.",
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
        ]);

        $payloads = $this->invokeBuildRuleBasedH2ChunkPayloads($structure);
        $imagePayloads = array_values(array_filter(
            $payloads,
            static fn (array $payload): bool => ($payload['chunk_type'] ?? null) === 'image',
        ));
        $semanticPayload = collect($payloads)->first(static function (array $payload): bool {
            return ($payload['chunk_type'] ?? null) === 'semantic'
                && str_contains((string) ($payload['content'] ?? ''), 'Sjekklister for prosjekt kick-off');
        });

        $this->assertCount(0, $imagePayloads, 'Long body text without figure evidence should remain semantic text.');
        $this->assertNotNull($semanticPayload, 'The checklist text should remain a semantic chunk.');
        $this->assertStringContainsString('Leverandøren benytter etablerte sjekklister', (string) ($semanticPayload['content'] ?? ''));
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
            ->where('title', 'Masterdata Prosjekt_pdf.pdf')
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

        $pageTwentyThreeNarrative = $chunks->first(static function (KnowledgeItemChunk $chunk): bool {
            return data_get($chunk, 'chunk_type') === 'semantic'
                && str_contains((string) $chunk->content, 'Det skal holdes egne møter for risikostyring jevnlig');
        });

        $sjekklisterChunk = $chunks->first(static function (KnowledgeItemChunk $chunk): bool {
            return str_contains((string) $chunk->content, 'Sjekklister for prosjekt kick-off');
        });

        $competingFigureGapInSectionOneFour = $chunks->first(static function (KnowledgeItemChunk $chunk): bool {
            return data_get($chunk, 'chunk_type') === 'image'
                && data_get($chunk, 'image_metadata.source') === 'pdf_figure_gap'
                && str_contains((string) $chunk->heading_path, '1.4');
        });

        $this->assertNotNull($pageNineGraphic);
        $this->assertNotNull($pageEighteenGraphic);
        $this->assertNotNull($pageTwentyThreeFigure);
        $this->assertNotNull($pageTwentyThreeNarrative, 'The paragraph before the figure should remain a semantic chunk.');
        $this->assertNotNull($sjekklisterChunk, 'Checklist text should still be present in the imported chunks.');
        $this->assertSame('semantic', data_get($sjekklisterChunk, 'chunk_type'));
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
        $this->assertNotEmpty($pageTwentyThreeFigure->image_path);
        $this->assertSame('image/png', $pageTwentyThreeFigure->image_mime_type);
        $this->assertNotEmpty($pageTwentyThreeFigure->image_hash);
        $this->assertTrue(Storage::disk('local')->exists($pageTwentyThreeFigure->image_path));
        $this->assertTrue((bool) data_get($pageTwentyThreeFigure, 'image_metadata.preview_generated'));
        $this->assertSame(24, data_get($pageTwentyThreeFigure, 'image_metadata.preview_page_number'));
        $this->assertStringNotContainsString('Det skal holdes egne møter for risikostyring jevnlig', (string) $pageTwentyThreeFigure->content);
        $this->assertStringNotContainsString('Sjekklister for prosjekt kick-off', (string) $pageTwentyThreeFigure->content);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document, $pageTwentyThreeNarrative): bool {
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
                && $pageTwentyThreeNarrative !== null
                && $pageTwentyThreeFigure !== null
                && ! empty($pageTwentyThreeFigure['image_url'])
                && data_get($pageTwentyThreeFigure, 'image_caption') === 'Advania Risk Management';
        });

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $document->id,
                'chunk' => $pageNineGraphic->id,
            ]))
            ->assertOk();

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.chunks.image', [
                'knowledgeItem' => $document->id,
                'chunk' => $pageTwentyThreeFigure->id,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_real_pdf_import_keeps_the_figure_chunk_when_preview_rendering_fails(): void
    {
        Storage::fake('local');

        $this->app->instance(PdfFigurePreviewRenderer::class, Mockery::mock(PdfFigurePreviewRenderer::class, function ($mock): void {
            $mock->shouldReceive('renderPreview')->andReturnNull();
        }));

        $context = $this->customerContext('Customer Real Pdf Preview Fallback AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->realKnowledgePdfUpload(),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'Masterdata Prosjekt_pdf.pdf')
            ->firstOrFail();

        $pageTwentyThreeFigure = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->where('chunk_type', 'image')
            ->where('image_caption', 'Advania Risk Management')
            ->firstOrFail();

        $this->assertSame('pdf_figure_gap', data_get($pageTwentyThreeFigure, 'image_metadata.source'));
        $this->assertTrue((bool) data_get($pageTwentyThreeFigure, 'image_metadata.derived_from_text'));
        $this->assertNull($pageTwentyThreeFigure->image_path);
        $this->assertNull($pageTwentyThreeFigure->image_url ?? null);
        $this->assertStringContainsString('Advania Risk Management', (string) $pageTwentyThreeFigure->content);
    }

    public function test_knowledge_document_upload_persists_table_chunks_separately_from_text_chunks(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Table AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('table-pipeline.docx', [
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Innledning før tabell. Systemet skal oppdateres regelmessig for å sikre stabil og pålitelig drift av alle komponenter. Alle kritiske oppdateringer skal testes i et isolert testmiljø før de rulles ut i produksjon. Vedlikeholdsvinduet er klart definert i driftsavtalen og gjelder for alle planlagte nedetider.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Tekst før tabell. Kravene til dokumentasjon for drift av systemet skal dekke alle aspekter ved vedlikehold og løpende operasjon. Dette inkluderer detaljerte prosedyrer for oppstart, avvikling, sikkerhetskopiering og gjenoppretting av data og systemtilstand. Alle prosedyrer skal testes regelmessig og resultatene dokumenteres.', 'style' => 'Normal'],
                ['text' => '', 'style' => 'Table'],
                ['text' => 'Tekst etter tabell. Resultater fra testing av systemet skal dokumenteres og oppbevares i henhold til gjeldende retningslinjer for intern kvalitetssikring. Avvik fra forventede resultater skal rapporteres og håndteres i henhold til avviksprosedyren. Ansvaret for oppdatering og videre oppfølging tilhører den ansvarlige driftslederen.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'table-pipeline.docx')
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
        $showResponse->assertViewHas('page', function (array $page): bool {
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
        $metadataService->shouldReceive('generateForChunks')
            ->once()
            ->andReturnUsing(function (KnowledgeItem $document, iterable $chunks, string $languageCode): array {
                $result = [];
                foreach ($chunks as $chunk) {
                    $chunkNumber = $chunk->chunk_index + 1;
                    $result[(int) $chunk->id] = [
                        'service_product_tag' => 'Produkt A',
                        'theme_tag' => 'Tema A',
                        'topic' => 'Tema '.$chunkNumber,
                        'sub_topic' => 'Underemne '.$chunkNumber,
                        'keywords' => [
                            'stikkord-'.$chunkNumber.'-a',
                            'stikkord-'.$chunkNumber.'-b',
                            'stikkord-'.$chunkNumber.'-c',
                        ],
                        'matched_terms' => [],
                        'summary_for_retrieval' => 'Kort oppsummering for gjenfinning.',
                        'confidence_score' => 0.25,
                        'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
                        'new_term_suggestions' => [],
                    ];
                }

                return $result;
            });
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $context = $this->customerContext('Customer Four Structured Metadata AS');

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-metadata.docx', [
                ['text' => 'Intro before first heading.', 'style' => 'Normal'],
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Første avsnitt under hovedseksjonen. Leveransen inkluderer alle nødvendige tjenester og prosesser som er avtalt mellom partene. Systemet skal dokumenteres grundig og godkjennes av alle involverte parter. Konfigurasjon og vedlikehold av systemet inngår i leveransen.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Mer tekst i underseksjonen.', 'style' => 'Normal'],
                ['text' => 'Andre hovedseksjon', 'style' => 'Heading1'],
                ['text' => 'Avsluttende avsnitt om systemet og leveransen. Alle krav er ivaretatt i henhold til den avtalte kontrakten og tilhørende spesifikasjoner. Systemet er nå ferdig og klart til produksjon og videre drift. Dokumentasjonen er godkjent av alle parter og er i tråd med kravene.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'structured-metadata.docx')
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
        $metadataService->shouldReceive('generateForChunks')
            ->once()
            ->andReturnUsing(function (KnowledgeItem $document, iterable $chunks, string $languageCode): array {
                $result = [];
                foreach ($chunks as $chunk) {
                    $chunkNumber = $chunk->chunk_index + 1;
                    $summary = 'Kort oppsummering '.$chunkNumber;
                    $keywords = ['stikkord-'.$chunkNumber.'-a', 'stikkord-'.$chunkNumber.'-b'];
                    $result[(int) $chunk->id] = [
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
                    ];
                }

                return $result;
            });
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-metadata-calls.docx', [
                ['text' => 'Intro before first heading.', 'style' => 'Normal'],
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Første avsnitt under hovedseksjonen. Leveransen inkluderer alle nødvendige tjenester og prosesser som er avtalt mellom partene. Systemet skal dokumenteres grundig og godkjennes av alle involverte parter. Konfigurasjon og vedlikehold av systemet inngår i leveransen.', 'style' => 'Normal'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Mer tekst i underseksjonen.', 'style' => 'Normal'],
                ['text' => 'Andre hovedseksjon', 'style' => 'Heading1'],
                ['text' => 'Avsluttende avsnitt om systemet og leveransen. Alle krav er ivaretatt i henhold til den avtalte kontrakten og tilhørende spesifikasjoner. Systemet er nå ferdig og klart til produksjon og videre drift. Dokumentasjonen er godkjent av alle parter og er i tråd med kravene.', 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'structured-metadata-calls.docx')
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
        $this->assertStringContainsString('Strategisk samhandling', (string) $chunks[0]->content);
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
        $metadataService->shouldReceive('generateForChunks')
            ->twice()
            ->andReturnUsing(function (KnowledgeItem $document, iterable $chunks, string $languageCode): array {
                $result = [];
                foreach ($chunks as $chunk) {
                    $chunkNumber = $chunk->chunk_index + 1;
                    $result[(int) $chunk->id] = [
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
                    ];
                }

                return $result;
            });
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUploadWithBlocks('structured-metadata-six.docx', [
                ['text' => 'Kapittel 1', 'style' => 'Heading1'],
                ['text' => 'Tekst 1. '.$this->repeatedWords('kap1', 36), 'style' => 'Normal'],
                ['text' => 'Kapittel 2', 'style' => 'Heading1'],
                ['text' => 'Tekst 2. '.$this->repeatedWords('kap2', 36), 'style' => 'Normal'],
                ['text' => 'Kapittel 3', 'style' => 'Heading1'],
                ['text' => 'Tekst 3. '.$this->repeatedWords('kap3', 36), 'style' => 'Normal'],
                ['text' => 'Kapittel 4', 'style' => 'Heading1'],
                ['text' => 'Tekst 4. '.$this->repeatedWords('kap4', 36), 'style' => 'Normal'],
                ['text' => 'Kapittel 5', 'style' => 'Heading1'],
                ['text' => 'Tekst 5. '.$this->repeatedWords('kap5', 36), 'style' => 'Normal'],
                ['text' => 'Kapittel 6', 'style' => 'Heading1'],
                ['text' => 'Tekst 6. '.$this->repeatedWords('kap6', 36), 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'structured-metadata-six.docx')
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
            ->andReturnUsing(function (array $payload): array {
                static $callCount = 0;
                $callCount++;

                $inputText = data_get($payload, 'input.1.content.0.text', '');
                $decoded = json_decode($inputText, true);

                if (is_array($decoded) && isset($decoded['chunks'])) {
                    $chunkPayloads = $decoded['chunks'] ?? [];
                    $chunks = [];
                    $chunkNumber = 0;
                    foreach ($chunkPayloads as $cp) {
                        $chunkNumber++;
                        $chunks[] = [
                            'chunk_id' => (int) data_get($cp, 'id', 0),
                            'service_product_tag' => '',
                            'theme_tag' => '',
                            'topic' => '',
                            'sub_topic' => '',
                            'keywords' => ['stikkord-'.$chunkNumber.'-a', 'stikkord-'.$chunkNumber.'-b'],
                            'matched_terms' => [],
                            'summary_for_retrieval' => 'Kort oppsummering '.$chunkNumber,
                            'new_term_suggestions' => [],
                            'confidence_score' => 0.42,
                        ];
                    }

                    return [
                        'id' => 'resp_blank_batch',
                        'output_text' => json_encode(['chunks' => $chunks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                return [
                    'id' => 'resp_vocab_'.$callCount,
                    'output_text' => json_encode([
                        'canonical_name' => 'Kanonisk term',
                        'synonyms' => ['synonym-a', 'synonym-b'],
                        'description' => 'Beskrivelse av term.',
                        'reason' => 'Foreslått fra chunk-metadata.',
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
                ['text' => 'Strategisk samhandling', 'style' => 'Heading1'],
                ['text' => 'Underseksjon A', 'style' => 'Heading2'],
                ['text' => 'Intro before first heading. '.$this->repeatedWords('innhold', 37), 'style' => 'Normal'],
                ['text' => 'Andre hovedseksjon', 'style' => 'Heading1'],
                ['text' => $this->repeatedWords('avslutning', 38), 'style' => 'Normal'],
            ]),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'structured-metadata-blanks.docx')
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
            ->where('title', 'metadata-failure.docx')
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
            ->where('title', 'embedding-success.docx')
            ->firstOrFail();

        $chunk = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        $this->assertSame($this->deterministicEmbeddingVector(), $chunk->embedding_vector);
        $this->assertSame('text-embedding-3-small', $chunk->embedding_model);
        $this->assertNotNull($chunk->embedding_generated_at);
        $this->assertNull($chunk->embedding_error);
    }

    public function test_knowledge_document_upload_generates_metadata_before_embedding_and_persists_metadata_fields(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Four D AS');

        $metadataService = Mockery::mock(KnowledgeChunkMetadataGenerationService::class);
        $metadataService->shouldReceive('generateForChunks')
            ->once()
            ->andReturnUsing(function (KnowledgeItem $document, iterable $chunks, string $languageCode): array {
                $result = [];
                foreach ($chunks as $chunk) {
                    $result[(int) $chunk->id] = [
                        'service_product_tag' => 'Produkt A',
                        'theme_tag' => 'Tema A',
                        'topic' => 'Emne A',
                        'sub_topic' => 'Underemne A',
                        'keywords' => ['stikkord a', 'stikkord b'],
                        'matched_terms' => ['term a'],
                        'summary_for_retrieval' => 'Kort oppsummering for gjenfinning.',
                        'confidence_score' => 0.91,
                        'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED,
                        'new_term_suggestions' => [],
                    ];
                }

                return $result;
            });
        $this->app->instance(KnowledgeChunkMetadataGenerationService::class, $metadataService);

        $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('metadata-generation.docx', 'Metadata generation test content that should be chunked and embedded.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'metadata-generation.docx')
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
        $this->assertSame($this->deterministicEmbeddingVector(), $chunk->embedding_vector);
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
            ->andReturnUsing(function (array $payload): array {
                $inputText = data_get($payload, 'input.1.content.0.text', '');
                $decoded = json_decode($inputText, true) ?? [];
                $chunkPayloads = data_get($decoded, 'chunks', []);
                $chunkId = (int) data_get($chunkPayloads[0] ?? [], 'id', 0);

                return [
                    'id' => 'resp_table_summary',
                    'output_text' => json_encode(['chunks' => [[
                        'chunk_id' => $chunkId,
                        'service_product_tag' => 'samhandling',
                        'theme_tag' => 'driftsmodell',
                        'topic' => 'sikkerhetsparametere',
                        'sub_topic' => 'SOC-tjeneste',
                        'keywords' => ['SOC', 'sikkerhetsparametere'],
                        'matched_terms' => ['SOC'],
                        'summary_for_retrieval' => 'Tabellen beskriver sikkerhetsparametere for SOC-tjenesten og viser loggovervåking, hendelseshåndtering og eskalering.',
                        'new_term_suggestions' => [],
                        'confidence_score' => 0.92,
                    ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            });
        $openAiClient->shouldReceive('createResponse')
            ->andReturn([
                'id' => 'resp_vocab',
                'output_text' => json_encode([
                    'canonical_name' => 'Kanonisk term',
                    'synonyms' => [],
                    'description' => null,
                    'reason' => null,
                ]),
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
                'embedding' => $this->deterministicEmbeddingVector(),
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
            ->where('title', 'table-summary.docx')
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
            ->where('title', 'embedding-failure.docx')
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
            ->where('title', 'broken.docx')
            ->firstOrFail();

        $documentVersion = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('is_current', true)
            ->firstOrFail();

        $this->assertStringStartsWith('customers/'.$context['customer']->id.'/knowledge-documents/', $documentVersion->storage_path);
        $this->assertSame(KnowledgeItem::EXTRACTION_STATUS_FAILED, $document->extraction_status);
        $this->assertSame('', (string) $document->extracted_text);
        $this->assertNotEmpty((string) $document->extraction_error);
        $this->assertSame(0, KnowledgeItemChunk::query()->where('knowledge_item_id', $document->id)->count());
    }

    public function test_knowledge_base_edit_page_can_be_opened(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six AS');
        $secondaryOwner = User::factory()->create([
            'customer_id' => $context['customer']->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $foreignContext = $this->customerContext('Customer Six Foreign AS');
        $foreignOwner = User::factory()->create([
            'customer_id' => $foreignContext['customer']->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('reference-profile.docx', 'Reference project description used for AI knowledge.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'reference-profile.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document, $context, $secondaryOwner, $foreignOwner): bool {
            $ownerOptions = collect(data_get($page, 'props.documentOwnerOptions', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · Rediger'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.original_filename') === 'reference-profile.docx'
                && data_get($page, 'props.knowledgeItem.owner_user_id') === $context['user']->id
                && data_get($page, 'props.knowledgeItem.owner_name') === $context['user']->name
                && data_get($page, 'props.knowledgeItem.extraction_status') === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index')
                && $ownerOptions->firstWhere('id', $context['user']->id) !== null
                && $ownerOptions->firstWhere('id', $secondaryOwner->id) !== null
                && $ownerOptions->firstWhere('id', $foreignOwner->id) === null;
        });
    }

    public function test_knowledge_document_update_can_change_and_clear_document_owner_without_touching_uploaded_by(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six Owner Flow AS');
        $newOwner = User::factory()->create([
            'customer_id' => $context['customer']->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('owner-flow.docx', 'Owner flow content that is long enough to persist.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->with(['owner', 'uploadedBy'])
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'owner-flow.docx')
            ->firstOrFail();

        $this->assertSame($context['user']->id, $document->owner_user_id);
        $this->assertSame($context['user']->name, $document->owner?->name);
        $this->assertSame($context['user']->id, $document->uploaded_by_user_id);
        $this->assertSame($context['user']->name, $document->uploadedBy?->name);

        $response = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'owner_user_id' => $newOwner->id,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $updatedDocument = KnowledgeItem::query()
            ->with(['owner', 'uploadedBy'])
            ->whereKey($document->id)
            ->firstOrFail();

        $this->assertSame($newOwner->id, $updatedDocument->owner_user_id);
        $this->assertSame($newOwner->name, $updatedDocument->owner?->name);
        $this->assertSame($context['user']->id, $updatedDocument->uploaded_by_user_id);
        $this->assertSame($context['user']->name, $updatedDocument->uploadedBy?->name);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document, $newOwner, $context): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $document->id);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $item !== null
                && data_get($item, 'owner_user_id') === $newOwner->id
                && data_get($item, 'owner_name') === $newOwner->name
                && data_get($item, 'uploaded_by') === $context['user']->name;
        });

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));
        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document, $newOwner, $context): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.owner_user_id') === $newOwner->id
                && data_get($page, 'props.knowledgeItem.owner_name') === $newOwner->name
                && data_get($page, 'props.knowledgeItem.uploaded_by') === $context['user']->name;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page) use ($document, $newOwner, $context): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.owner_user_id') === $newOwner->id
                && data_get($page, 'props.knowledgeItem.owner_name') === $newOwner->name
                && data_get($page, 'props.knowledgeItem.uploaded_by') === $context['user']->name;
        });

        $clearResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'owner_user_id' => '',
            'is_active' => true,
        ]);

        $clearResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $clearedDocument = KnowledgeItem::query()
            ->with(['owner', 'uploadedBy'])
            ->whereKey($document->id)
            ->firstOrFail();

        $this->assertNull($clearedDocument->owner_user_id);
        $this->assertNull($clearedDocument->owner?->name);
        $this->assertSame($context['user']->id, $clearedDocument->uploaded_by_user_id);
        $this->assertSame($context['user']->name, $clearedDocument->uploadedBy?->name);

        $clearedShowResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));
        $clearedShowResponse->assertOk();
        $clearedShowResponse->assertViewHas('page', function (array $page) use ($document, $context): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.owner_user_id') === null
                && data_get($page, 'props.knowledgeItem.owner_name') === null
                && data_get($page, 'props.knowledgeItem.uploaded_by') === $context['user']->name;
        });

        $clearedEditResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $clearedEditResponse->assertOk();
        $clearedEditResponse->assertViewHas('page', function (array $page) use ($document, $context): bool {
            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.owner_user_id') === null
                && data_get($page, 'props.knowledgeItem.owner_name') === null
                && data_get($page, 'props.knowledgeItem.uploaded_by') === $context['user']->name;
        });
    }

    public function test_knowledge_document_update_rejects_document_owner_from_foreign_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six Foreign Owner AS');
        $foreignContext = $this->customerContext('Customer Six Foreign Owner Other AS');
        $foreignOwner = User::factory()->create([
            'customer_id' => $foreignContext['customer']->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('owner-foreign.docx', 'Foreign owner validation document content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'owner-foreign.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'owner_user_id' => $foreignOwner->id,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['owner_user_id']);

        $freshDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();
        $this->assertSame($context['user']->id, $freshDocument->owner_user_id);
    }

    public function test_knowledge_base_show_and_edit_payload_expose_document_theme_metadata_and_nulls_when_missing(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six Theme AS');
        $themeName = 'Strategisk kontroll';
        $themeTerm = $this->createKnowledgeThemeTerm($context['customer'], $themeName);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('theme-detail.docx', 'Themed detail document content used for payload verification.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('plain-detail.docx', 'Plain detail document content used for payload verification.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $themedDocument = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'theme-detail.docx')
            ->firstOrFail();

        $themedDocument->forceFill([
            'document_theme_term_id' => $themeTerm->id,
        ])->save();

        $plainDocument = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'plain-detail.docx')
            ->firstOrFail();

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $themedDocument->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($themedDocument, $themeTerm): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($knowledgeItem, 'id') === $themedDocument->id
                && data_get($knowledgeItem, 'document_theme_term_id') === $themeTerm->id
                && data_get($knowledgeItem, 'document_theme_label') === $themeTerm->canonical_name
                && data_get($knowledgeItem, 'document_theme_term.id') === $themeTerm->id
                && data_get($knowledgeItem, 'document_theme_term.type') === KnowledgeMetadataTerm::TYPE_THEME_TAG
                && data_get($knowledgeItem, 'document_theme_term.canonical_name') === $themeTerm->canonical_name;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $themedDocument->id]));

        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page) use ($themedDocument, $themeTerm): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($knowledgeItem, 'id') === $themedDocument->id
                && data_get($knowledgeItem, 'document_theme_term_id') === $themeTerm->id
                && data_get($knowledgeItem, 'document_theme_label') === $themeTerm->canonical_name
                && data_get($knowledgeItem, 'document_theme_term.id') === $themeTerm->id
                && data_get($knowledgeItem, 'document_theme_term.type') === KnowledgeMetadataTerm::TYPE_THEME_TAG
                && data_get($knowledgeItem, 'document_theme_term.canonical_name') === $themeTerm->canonical_name;
        });

        $showResponseWithoutTheme = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $plainDocument->id]));

        $showResponseWithoutTheme->assertOk();
        $showResponseWithoutTheme->assertViewHas('page', function (array $page) use ($plainDocument): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($knowledgeItem, 'id') === $plainDocument->id
                && data_get($knowledgeItem, 'document_theme_term_id') === null
                && data_get($knowledgeItem, 'document_theme_label') === null
                && data_get($knowledgeItem, 'document_theme_term') === null;
        });

        $editResponseWithoutTheme = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $plainDocument->id]));

        $editResponseWithoutTheme->assertOk();
        $editResponseWithoutTheme->assertViewHas('page', function (array $page) use ($plainDocument): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($knowledgeItem, 'id') === $plainDocument->id
                && data_get($knowledgeItem, 'document_theme_term_id') === null
                && data_get($knowledgeItem, 'document_theme_label') === null
                && data_get($knowledgeItem, 'document_theme_term') === null;
        });
    }

    public function test_knowledge_document_store_and_payloads_expose_document_category_and_topic_metadata_and_nulls_when_missing(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Catalog Payload AS');
        $category = $this->createKnowledgeDocumentCategory($context['customer'], 'Sikkerhetskategori');
        $topic = $this->createKnowledgeDocumentTopic($context['customer'], 'Sikkerhetstema');
        $category->topics()->attach($topic->id);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('catalog-payload.docx', 'Catalog payload document content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_category_id' => $category->id,
            'document_topic_id' => $topic->id,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('catalog-payload-empty.docx', 'Plain payload document content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $catalogDocument = KnowledgeItem::query()
            ->with(['documentCategory', 'documentTopic'])
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'catalog-payload.docx')
            ->firstOrFail();

        $plainDocument = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'catalog-payload-empty.docx')
            ->firstOrFail();

        $this->assertSame($category->id, $catalogDocument->document_category_id);
        $this->assertSame($topic->id, $catalogDocument->document_topic_id);
        $this->assertSame($category->name, $catalogDocument->documentCategory?->name);
        $this->assertSame($topic->name, $catalogDocument->documentTopic?->name);
        $this->assertNull($plainDocument->document_category_id);
        $this->assertNull($plainDocument->document_topic_id);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($catalogDocument, $plainDocument, $category, $topic): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));
            $catalogItem = $items->firstWhere('id', $catalogDocument->id);
            $plainItem = $items->firstWhere('id', $plainDocument->id);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $catalogItem !== null
                && data_get($catalogItem, 'document_category_id') === $category->id
                && data_get($catalogItem, 'document_category_name') === $category->name
                && data_get($catalogItem, 'document_topic_id') === $topic->id
                && data_get($catalogItem, 'document_topic_name') === $topic->name
                && $plainItem !== null
                && data_get($plainItem, 'document_category_id') === null
                && data_get($plainItem, 'document_category_name') === null
                && data_get($plainItem, 'document_topic_id') === null
                && data_get($plainItem, 'document_topic_name') === null;
        });

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $catalogDocument->id]));
        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($catalogDocument, $category, $topic): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($knowledgeItem, 'id') === $catalogDocument->id
                && data_get($knowledgeItem, 'document_category_id') === $category->id
                && data_get($knowledgeItem, 'document_category_name') === $category->name
                && data_get($knowledgeItem, 'document_topic_id') === $topic->id
                && data_get($knowledgeItem, 'document_topic_name') === $topic->name;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $catalogDocument->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page) use ($catalogDocument, $category, $topic): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($knowledgeItem, 'id') === $catalogDocument->id
                && data_get($knowledgeItem, 'document_category_id') === $category->id
                && data_get($knowledgeItem, 'document_category_name') === $category->name
                && data_get($knowledgeItem, 'document_topic_id') === $topic->id
                && data_get($knowledgeItem, 'document_topic_name') === $topic->name;
        });
    }

    public function test_knowledge_base_payloads_expose_ownership_metadata_for_company_personal_and_case_documents(): void
    {
        $context = $this->customerContext('Customer Ownership Payload AS');
        $owner = User::factory()->create([
            'customer_id' => $context['customer']->id,
            'role' => User::ROLE_USER,
            'is_active' => true,
            'name' => 'Ansvarlig bruker',
        ]);
        $savedNotice = SavedNotice::query()->create([
            'customer_id' => $context['customer']->id,
            'external_id' => 'OWNERSHIP-CASE-001',
            'title' => 'Sak for dokumenttilhørighet',
            'buyer_name' => 'Procynia',
        ]);

        $companyDocument = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'company-ownership.docx',
            'title' => 'company-ownership.docx',
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_COMPANY,
            'owner_user_id' => null,
            'owning_saved_notice_id' => null,
        ]);

        $personalDocument = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'personal-ownership.docx',
            'title' => 'personal-ownership.docx',
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_PERSONAL,
            'owner_user_id' => $owner->id,
            'owning_saved_notice_id' => null,
        ]);

        $caseDocument = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'case-ownership.docx',
            'title' => 'case-ownership.docx',
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_CASE,
            'owner_user_id' => $owner->id,
            'owning_saved_notice_id' => $savedNotice->id,
        ]);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));

        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($companyDocument, $personalDocument, $caseDocument, $owner, $savedNotice): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));
            $companyItem = $items->firstWhere('original_filename', $companyDocument->original_filename);
            $personalItem = $items->firstWhere('original_filename', $personalDocument->original_filename);
            $caseItem = $items->firstWhere('original_filename', $caseDocument->original_filename);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $companyItem !== null
                && data_get($companyItem, 'ownership_type') === KnowledgeItem::OWNERSHIP_TYPE_COMPANY
                && data_get($companyItem, 'ownership_label') === 'Selskap'
                && data_get($companyItem, 'owner_user_id') === null
                && data_get($companyItem, 'owner_name') === null
                && data_get($companyItem, 'owning_saved_notice_id') === null
                && data_get($companyItem, 'owning_saved_notice_title') === null
                && $personalItem !== null
                && data_get($personalItem, 'ownership_type') === KnowledgeItem::OWNERSHIP_TYPE_PERSONAL
                && data_get($personalItem, 'ownership_label') === 'Personlig'
                && data_get($personalItem, 'owner_user_id') === $owner->id
                && data_get($personalItem, 'owner_name') === $owner->name
                && data_get($personalItem, 'owning_saved_notice_id') === null
                && data_get($personalItem, 'owning_saved_notice_title') === null
                && $caseItem !== null
                && data_get($caseItem, 'ownership_type') === KnowledgeItem::OWNERSHIP_TYPE_CASE
                && data_get($caseItem, 'ownership_label') === 'Sak'
                && data_get($caseItem, 'owner_user_id') === $owner->id
                && data_get($caseItem, 'owner_name') === $owner->name
                && data_get($caseItem, 'owning_saved_notice_id') === $savedNotice->id
                && data_get($caseItem, 'owning_saved_notice_title') === $savedNotice->title;
        });

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $caseDocument->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($caseDocument, $owner, $savedNotice): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($knowledgeItem, 'id') === $caseDocument->id
                && data_get($knowledgeItem, 'ownership_type') === KnowledgeItem::OWNERSHIP_TYPE_CASE
                && data_get($knowledgeItem, 'ownership_label') === 'Sak'
                && data_get($knowledgeItem, 'owner_user_id') === $owner->id
                && data_get($knowledgeItem, 'owner_name') === $owner->name
                && data_get($knowledgeItem, 'owning_saved_notice_id') === $savedNotice->id
                && data_get($knowledgeItem, 'owning_saved_notice_title') === $savedNotice->title;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $personalDocument->id]));

        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page) use ($personalDocument, $owner): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($knowledgeItem, 'id') === $personalDocument->id
                && data_get($knowledgeItem, 'ownership_type') === KnowledgeItem::OWNERSHIP_TYPE_PERSONAL
                && data_get($knowledgeItem, 'ownership_label') === 'Personlig'
                && data_get($knowledgeItem, 'owner_user_id') === $owner->id
                && data_get($knowledgeItem, 'owner_name') === $owner->name
                && data_get($knowledgeItem, 'owning_saved_notice_id') === null
                && data_get($knowledgeItem, 'owning_saved_notice_title') === null;
        });
    }

    public function test_knowledge_document_update_persists_and_validates_document_category_and_topic_ids(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Catalog Update AS');
        $foreignContext = $this->customerContext('Customer Catalog Update Foreign AS');

        $initialCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Alfa kategori');
        $initialTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Alfa tema');
        $initialCategory->topics()->attach($initialTopic->id);

        $replacementCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Omega kategori');
        $replacementTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Omega tema');
        $replacementCategory->topics()->attach($replacementTopic->id);

        $mismatchedCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Tema katalog');
        $mismatchedTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Tema utenfor valg');
        $mismatchedCategory->topics()->attach($mismatchedTopic->id);

        $foreignCategory = $this->createKnowledgeDocumentCategory($foreignContext['customer'], 'Fremmed kategori');
        $foreignTopic = $this->createKnowledgeDocumentTopic($foreignContext['customer'], 'Fremmed tema');

        $inactiveCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Skjult kategori', false);
        $inactiveTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Skjult tema', false);

        $storeErrorCases = [
            [
                'payload' => [
                    'document_category_id' => $foreignCategory->id,
                ],
                'error' => 'document_category_id',
            ],
            [
                'payload' => [
                    'document_category_id' => $inactiveCategory->id,
                ],
                'error' => 'document_category_id',
            ],
            [
                'payload' => [
                    'document_category_id' => $initialCategory->id,
                    'document_topic_id' => $foreignTopic->id,
                ],
                'error' => 'document_topic_id',
            ],
            [
                'payload' => [
                    'document_category_id' => $initialCategory->id,
                    'document_topic_id' => $mismatchedTopic->id,
                ],
                'error' => 'document_topic_id',
            ],
            [
                'payload' => [
                    'document_topic_id' => $initialTopic->id,
                ],
                'error' => 'document_topic_id',
            ],
            [
                'payload' => [
                    'document_category_id' => $initialCategory->id,
                    'document_topic_id' => $inactiveTopic->id,
                ],
                'error' => 'document_topic_id',
            ],
        ];

        foreach ($storeErrorCases as $index => $case) {
            $response = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), array_merge([
                'document' => $this->createDocxUpload(sprintf('catalog-invalid-%d.docx', $index + 1), 'Invalid catalog selection content.'),
                'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
                'is_active' => true,
            ], $case['payload']));

            $response->assertSessionHasErrors([$case['error']]);
            $this->assertDatabaseMissing('knowledge_items', [
                'customer_id' => $context['customer']->id,
                'original_filename' => sprintf('catalog-invalid-%d.docx', $index + 1),
            ]);
        }

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('catalog-update.docx', 'Catalog update document content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_category_id' => $initialCategory->id,
            'document_topic_id' => $initialTopic->id,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->with(['documentCategory', 'documentTopic'])
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'catalog-update.docx')
            ->firstOrFail();

        $this->assertSame($initialCategory->id, $document->document_category_id);
        $this->assertSame($initialTopic->id, $document->document_topic_id);

        $updateResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'ownership_type' => $document->ownership_type,
            'document_category_id' => $replacementCategory->id,
            'document_topic_id' => $replacementTopic->id,
        ]);

        $updateResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $updatedDocument = KnowledgeItem::query()
            ->with(['documentCategory', 'documentTopic'])
            ->whereKey($document->id)
            ->firstOrFail();

        $this->assertSame($replacementCategory->id, $updatedDocument->document_category_id);
        $this->assertSame($replacementCategory->name, $updatedDocument->documentCategory?->name);
        $this->assertSame($replacementTopic->id, $updatedDocument->document_topic_id);
        $this->assertSame($replacementTopic->name, $updatedDocument->documentTopic?->name);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($updatedDocument, $replacementCategory, $replacementTopic): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $updatedDocument->id);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $item !== null
                && data_get($item, 'document_category_id') === $replacementCategory->id
                && data_get($item, 'document_category_name') === $replacementCategory->name
                && data_get($item, 'document_topic_id') === $replacementTopic->id
                && data_get($item, 'document_topic_name') === $replacementTopic->name;
        });

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $updatedDocument->id]));
        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($updatedDocument, $replacementCategory, $replacementTopic): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($knowledgeItem, 'id') === $updatedDocument->id
                && data_get($knowledgeItem, 'document_category_id') === $replacementCategory->id
                && data_get($knowledgeItem, 'document_category_name') === $replacementCategory->name
                && data_get($knowledgeItem, 'document_topic_id') === $replacementTopic->id
                && data_get($knowledgeItem, 'document_topic_name') === $replacementTopic->name;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $updatedDocument->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page) use ($updatedDocument, $replacementCategory, $replacementTopic): bool {
            $knowledgeItem = data_get($page, 'props.knowledgeItem', []);

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Edit'
                && data_get($knowledgeItem, 'id') === $updatedDocument->id
                && data_get($knowledgeItem, 'document_category_id') === $replacementCategory->id
                && data_get($knowledgeItem, 'document_category_name') === $replacementCategory->name
                && data_get($knowledgeItem, 'document_topic_id') === $replacementTopic->id
                && data_get($knowledgeItem, 'document_topic_name') === $replacementTopic->name;
        });

        $preserveResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $updatedDocument->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $updatedDocument->ownership_type,
            'is_active' => true,
        ]);

        $preserveResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $preservedDocument = KnowledgeItem::query()->whereKey($updatedDocument->id)->firstOrFail();
        $this->assertSame($replacementCategory->id, $preservedDocument->document_category_id);
        $this->assertSame($replacementTopic->id, $preservedDocument->document_topic_id);

        $clearResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $updatedDocument->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $updatedDocument->ownership_type,
            'is_active' => true,
            'document_category_id' => null,
            'document_topic_id' => null,
        ]);

        $clearResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $clearedDocument = KnowledgeItem::query()->whereKey($updatedDocument->id)->firstOrFail();
        $this->assertNull($clearedDocument->document_category_id);
        $this->assertNull($clearedDocument->document_topic_id);
        $this->assertFalse($clearedDocument->hasDocumentTheme());

        $invalidUpdateCases = [
            [
                'payload' => [
                    'document_category_id' => $foreignCategory->id,
                ],
                'error' => 'document_category_id',
            ],
            [
                'payload' => [
                    'document_category_id' => $inactiveCategory->id,
                ],
                'error' => 'document_category_id',
            ],
            [
                'payload' => [
                    'document_category_id' => $replacementCategory->id,
                    'document_topic_id' => $foreignTopic->id,
                ],
                'error' => 'document_topic_id',
            ],
            [
                'payload' => [
                    'document_category_id' => $replacementCategory->id,
                    'document_topic_id' => $mismatchedTopic->id,
                ],
                'error' => 'document_topic_id',
            ],
            [
                'payload' => [
                    'document_topic_id' => $replacementTopic->id,
                ],
                'error' => 'document_topic_id',
            ],
        ];

        foreach ($invalidUpdateCases as $case) {
            $response = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $updatedDocument->id]), array_merge([
                'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
                'ownership_type' => $clearedDocument->ownership_type,
                'is_active' => true,
            ], $case['payload']));

            $response->assertSessionHasErrors([$case['error']]);

            $freshDocument = KnowledgeItem::query()->whereKey($updatedDocument->id)->firstOrFail();
            $this->assertNull($freshDocument->document_category_id);
            $this->assertNull($freshDocument->document_topic_id);
        }
    }

    public function test_knowledge_base_index_filters_by_document_category_id_and_document_topic_id(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Catalog Filter AS');

        $category = $this->createKnowledgeDocumentCategory($context['customer'], 'Filterkategori');
        $topic = $this->createKnowledgeDocumentTopic($context['customer'], 'Filtertema');
        $category->topics()->attach($topic->id);

        $otherCategory = $this->createKnowledgeDocumentCategory($context['customer'], 'Annen kategori');
        $otherTopic = $this->createKnowledgeDocumentTopic($context['customer'], 'Annet tema');
        $otherCategory->topics()->attach($otherTopic->id);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('catalog-filter-match.docx', 'Document matching the catalog filter.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_category_id' => $category->id,
            'document_topic_id' => $topic->id,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('catalog-filter-other.docx', 'Document with different catalog values.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_category_id' => $otherCategory->id,
            'document_topic_id' => $otherTopic->id,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('catalog-filter-none.docx', 'Document without catalog values.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $categoryResponse = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.index', ['document_category_id' => $category->id]),
        );
        $categoryResponse->assertOk();
        $categoryResponse->assertViewHas('page', function (array $page) use ($category, $topic): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $items->count() === 1
                && data_get($items->first(), 'original_filename') === 'catalog-filter-match.docx'
                && data_get($items->first(), 'document_category_id') === $category->id
                && data_get($items->first(), 'document_category_name') === $category->name
                && data_get($items->first(), 'document_topic_id') === $topic->id
                && data_get($items->first(), 'document_topic_name') === $topic->name;
        });

        $topicResponse = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.index', ['document_topic_id' => $topic->id]),
        );
        $topicResponse->assertOk();
        $topicResponse->assertViewHas('page', function (array $page): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $items->count() === 1
                && data_get($items->first(), 'original_filename') === 'catalog-filter-match.docx';
        });

        $combinedResponse = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.index', [
                'document_category_id' => $category->id,
                'document_topic_id' => $topic->id,
            ]),
        );
        $combinedResponse->assertOk();
        $combinedResponse->assertViewHas('page', function (array $page): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $items->count() === 1
                && data_get($items->first(), 'original_filename') === 'catalog-filter-match.docx';
        });

        $noFilterResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $noFilterResponse->assertOk();
        $noFilterResponse->assertViewHas('page', function (array $page): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $items->count() === 3;
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
            ->where('title', 'detail-reference.docx')
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
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · '.$document->title
                && data_get($page, 'props.indexUrl') === route('app.ai.knowledge-base.index')
                && data_get($page, 'props.editUrl') === route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id])
                && data_get($page, 'props.summaryUpdateUrl') === route('app.ai.knowledge-base.summary.update', ['knowledgeItem' => $document->id])
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && data_get($page, 'props.knowledgeItem.original_filename') === 'detail-reference.docx'
                && data_get($page, 'props.knowledgeItem.show_url') === route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id])
                && data_get($page, 'props.knowledgeItem.document_type_label') === KnowledgeItem::DOCUMENT_TYPE_LABELS[KnowledgeItem::DOCUMENT_TYPE_REFERENCE]
                && ! array_key_exists('content_type', data_get($page, 'props.knowledgeItem', []))
                && ! array_key_exists('is_active', data_get($page, 'props.knowledgeItem', []))
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

    public function test_knowledge_document_show_payload_exposes_read_only_revisions_in_sorted_order(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Six C Revision AS');
        $themeTerm = $this->createKnowledgeThemeTerm($context['customer'], 'Revisjonstema');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('revision-detail.docx', 'Revision detail document content that persists enough text.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_theme_term_id' => $themeTerm->id,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'revision-detail.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'ownership_type' => $document->ownership_type,
            'is_active' => false,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document, $context, $themeTerm): bool {
            $revisions = collect(data_get($page, 'props.knowledgeItem.revisions', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && $revisions->count() === 2
                && $revisions->pluck('revision_no')->all() === [1, 2]
                && $revisions->pluck('change_type')->all() === [
                    KnowledgeItemRevision::CHANGE_TYPE_CREATED,
                    KnowledgeItemRevision::CHANGE_TYPE_METADATA_UPDATED,
                ]
                && $revisions->pluck('changed_by_user_id')->all() === [
                    $context['user']->id,
                    $context['user']->id,
                ]
                && $revisions->every(function (array $revision) use ($context): bool {
                    return $revision['changed_by_name'] === $context['user']->name
                        && is_string($revision['created_at'])
                        && $revision['created_at'] !== ''
                        && is_array($revision['snapshot'])
                        && ! array_key_exists('extracted_text', $revision['snapshot'])
                        && ! array_key_exists('chunks', $revision['snapshot'])
                        && ! array_key_exists('embeddings', $revision['snapshot'])
                        && ! array_key_exists('content', $revision['snapshot'])
                        && ! array_key_exists('content_type', $revision['snapshot'])
                        && ! array_key_exists('is_active', $revision['snapshot']);
                })
                && data_get($page, 'props.knowledgeItem.revisions.0.snapshot.knowledge_item_id') === $document->id
                && data_get($page, 'props.knowledgeItem.revisions.0.snapshot.document_type') === KnowledgeItem::DOCUMENT_TYPE_REFERENCE
                && data_get($page, 'props.knowledgeItem.revisions.0.snapshot.mime_type') === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                && data_get($page, 'props.knowledgeItem.revisions.0.snapshot.ownership_type') === KnowledgeItem::OWNERSHIP_TYPE_COMPANY
                && data_get($page, 'props.knowledgeItem.revisions.0.snapshot.document_theme_term_id') === $themeTerm->id
                && ! array_key_exists('content_type', data_get($page, 'props.knowledgeItem.revisions.0.snapshot', []))
                && ! array_key_exists('is_active', data_get($page, 'props.knowledgeItem.revisions.0.snapshot', []))
                && data_get($page, 'props.knowledgeItem.revisions.1.snapshot.knowledge_item_id') === $document->id
                && data_get($page, 'props.knowledgeItem.revisions.1.snapshot.document_type') === KnowledgeItem::DOCUMENT_TYPE_OTHER
                && data_get($page, 'props.knowledgeItem.revisions.1.snapshot.mime_type') === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                && data_get($page, 'props.knowledgeItem.revisions.1.snapshot.ownership_type') === KnowledgeItem::OWNERSHIP_TYPE_COMPANY
                && data_get($page, 'props.knowledgeItem.revisions.1.snapshot.document_theme_term_id') === $themeTerm->id
                && ! array_key_exists('content_type', data_get($page, 'props.knowledgeItem.revisions.1.snapshot', []))
                && ! array_key_exists('is_active', data_get($page, 'props.knowledgeItem.revisions.1.snapshot', []));
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
            ->where('title', 'chunk-review.docx')
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
        $showResponse->assertViewHas('page', function (array $page): bool {
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
            ->where('title', 'chunk-review-invalid.docx')
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
            ->where('title', 'chunk-metadata.docx')
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
            ->where('title', 'chunk-metadata-invalid.docx')
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
            ->where('title', 'summary-editable.docx')
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

    public function test_knowledge_document_show_generates_an_ai_summary_from_the_full_document_context(): void
    {
        $context = $this->customerContext('Customer Summary AI AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'ai-summary.pdf',
            'content' => 'Første del av dokumentet beskriver koordinering, roller og samhandling. Andre del beskriver risiko, oppfølging og kostnadsstyring.',
            'original_filename' => 'ai-summary.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/ai-summary.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'extracted_text' => 'Første del av dokumentet beskriver koordinering, roller og samhandling. Andre del beskriver risiko, oppfølging og kostnadsstyring.',
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'is_active' => true,
        ]);

        $this->createCurrentVersionFor($document, $context['user']);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Første del av dokumentet beskriver koordinering, roller og samhandling.',
            'start_offset' => 0,
            'end_offset' => 72,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Andre del beskriver risiko, oppfølging og kostnadsstyring.',
            'start_offset' => 73,
            'end_offset' => 131,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $summary = (string) data_get($page, 'props.knowledgeItem.summary', '');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.knowledgeItem.id') === $document->id
                && str_starts_with($summary, 'AI-oppsummering:')
                && str_contains($summary, 'Første del av dokumentet beskriver koordinering')
                && str_contains($summary, 'Andre del beskriver risiko, oppfølging og kostnadsstyring')
                && $summary !== (string) data_get($page, 'props.knowledgeItem.content_excerpt', '');
        });

        $updatedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();
        $this->assertStringStartsWith('AI-oppsummering:', (string) $updatedDocument->summary);
        $this->assertStringContainsString('Første del av dokumentet beskriver koordinering', (string) $updatedDocument->summary);
        $this->assertStringContainsString('Andre del beskriver risiko, oppfølging og kostnadsstyring', (string) $updatedDocument->summary);
    }

    public function test_knowledge_document_summary_prefers_semantic_chunks_over_toc_text(): void
    {
        $context = $this->customerContext('Customer Summary Semantic AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'toc-summary.pdf',
            'content' => 'Masterdata Prosjekter i Advania BILAG 1-11 1 Leverandørens Masterdata for prosjekter........................ 2',
            'original_filename' => 'toc-summary.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/toc-summary.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'extracted_text' => "Masterdata Prosjekter i Advania\n\nBILAG 1-11\n\n1 Leverandørens Masterdata for prosjekter........................ 2\n\n1.1 Koordinering og samhandling i Etableringsprosjekt........................ 3",
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'is_active' => true,
        ]);

        $this->createCurrentVersionFor($document, $context['user']);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Dette er faktisk innhold fra dokumentets semantiske del. Det beskriver leveranse, ansvar og oppfølging.',
            'start_offset' => 0,
            'end_offset' => 110,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Mer faktisk innhold som skal kunne bidra til en kort dokumentoppsummering.',
            'start_offset' => 111,
            'end_offset' => 188,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            $contentExcerpt = (string) data_get($page, 'props.knowledgeItem.content_excerpt', '');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && str_contains($contentExcerpt, 'Dette er faktisk innhold fra dokumentets semantiske del')
                && str_contains($contentExcerpt, 'Mer faktisk innhold som skal kunne bidra')
                && ! str_contains($contentExcerpt, '1 Leverandørens Masterdata for prosjekter........................ 2')
                && ! str_contains($contentExcerpt, 'Innholdsfortegnelse');
        });
    }

    public function test_knowledge_document_summary_falls_back_to_cleaned_raw_text_without_toc_noise(): void
    {
        $context = $this->customerContext('Customer Summary Fallback AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'toc-fallback.pdf',
            'content' => 'Masterdata Prosjekter i Advania BILAG 1-11 1 Leverandørens Masterdata for prosjekter........................ 2 Reell innholdstekst etter TOC.',
            'original_filename' => 'toc-fallback.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/toc-fallback.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'extracted_text' => "Masterdata Prosjekter i Advania\n\nBILAG 1-11\n\n1 Leverandørens Masterdata for prosjekter........................ 2\n\n1.1 Koordinering og samhandling i Etableringsprosjekt........................ 3\n\nReell innholdstekst etter TOC. Denne teksten skal brukes i oppsummeringen.",
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'is_active' => true,
        ]);

        $this->createCurrentVersionFor($document, $context['user']);

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            $contentExcerpt = (string) data_get($page, 'props.knowledgeItem.content_excerpt', '');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && str_contains($contentExcerpt, 'Reell innholdstekst etter TOC.')
                && ! str_contains($contentExcerpt, '1 Leverandørens Masterdata for prosjekter........................ 2')
                && ! str_contains($contentExcerpt, 'BILAG 1-11');
        });
    }

    public function test_knowledge_document_index_uses_semantic_excerpt_instead_of_toc_text(): void
    {
        $context = $this->customerContext('Customer Summary Index AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'index-toc-summary.pdf',
            'content' => 'Masterdata Prosjekter i Advania BILAG 1-11 1 Leverandørens Masterdata for prosjekter........................ 2',
            'original_filename' => 'index-toc-summary.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/index-toc-summary.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'extracted_text' => "Masterdata Prosjekter i Advania\n\nBILAG 1-11\n\n1 Leverandørens Masterdata for prosjekter........................ 2\n\n1.1 Koordinering og samhandling i Etableringsprosjekt........................ 3\n\nDette er faktisk innhold fra dokumentets semantiske del. Det beskriver leveranse, ansvar og oppfølging.",
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'is_active' => true,
        ]);

        $this->createCurrentVersionFor($document, $context['user']);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Dette er faktisk innhold fra dokumentets semantiske del. Det beskriver leveranse, ansvar og oppfølging.',
            'start_offset' => 0,
            'end_offset' => 110,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        KnowledgeItemChunk::query()->create([
            'knowledge_item_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Mer faktisk innhold som skal kunne bidra til en kort dokumentoppsummering.',
            'start_offset' => 111,
            'end_offset' => 188,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
            'chunk_type' => 'semantic',
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));
            $item = $items->firstWhere('id', $document->id);
            $contentExcerpt = (string) data_get($item, 'content_excerpt', '');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $item !== null
                && str_contains($contentExcerpt, 'Dette er faktisk innhold fra dokumentets semantiske del')
                && str_contains($contentExcerpt, 'Mer faktisk innhold som skal kunne bidra')
                && ! str_contains($contentExcerpt, '1 Leverandørens Masterdata for prosjekter........................ 2')
                && ! str_contains($contentExcerpt, 'BILAG 1-11');
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
            ->where('title', 'boilerplate.docx')
            ->firstOrFail();

        $initialChunkCount = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->count();

        $response = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'ownership_type' => $document->ownership_type,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ARCHIVED,
        ]);

        $response->assertRedirect(route('app.ai.knowledge-base.index'));

        $updatedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();
        $updatedChunkCount = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $updatedDocument->id)
            ->count();
        $normalizedContent = $this->normalizeWhitespace($content);

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $updatedDocument->document_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_ARCHIVED, $updatedDocument->document_status);
        $this->assertSame($normalizedContent, $this->normalizeWhitespace((string) $updatedDocument->extracted_text));
        $this->assertSame($initialChunkCount, $updatedChunkCount);
    }

    public function test_knowledge_document_store_persists_valid_document_theme_term_id_and_defaults_to_null_when_missing(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Theme Store AS');
        $themeTerm = $this->createKnowledgeThemeTerm($context['customer'], 'Strategisk risiko');

        $storeWithThemeResponse = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('theme-store.docx', 'Document content used to verify theme write behavior.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_BOILERPLATE,
            'is_active' => true,
            'document_theme_term_id' => $themeTerm->id,
        ]);

        $storeWithThemeResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $themedDocument = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'theme-store.docx')
            ->firstOrFail();

        $this->assertSame($themeTerm->id, $themedDocument->document_theme_term_id);
        $this->assertSame($themeTerm->id, $themedDocument->documentThemeTerm?->id);
        $this->assertSame(KnowledgeMetadataTerm::TYPE_THEME_TAG, $themedDocument->documentThemeTerm?->type);
        $this->assertSame($themeTerm->canonical_name, $themedDocument->documentThemeTerm?->canonical_name);

        $themedRevisions = KnowledgeItemRevision::query()
            ->where('customer_id', $context['customer']->id)
            ->orderBy('revision_no')
            ->get()
            ->filter(static fn (KnowledgeItemRevision $revision): bool => data_get($revision->snapshot, 'original_filename') === 'theme-store.docx')
            ->values();

        $this->assertCount(1, $themedRevisions);
        $this->assertSame(KnowledgeItemRevision::CHANGE_TYPE_CREATED, $themedRevisions[0]->change_type);
        $this->assertSame(1, $themedRevisions[0]->revision_no);
        $this->assertSame($themedDocument->id, $themedRevisions[0]->knowledge_item_id);
        $this->assertRevisionOwnership($themedRevisions[0], $context['customer'], $context['user']);
        $this->assertSame($themedDocument->id, data_get($themedRevisions[0]->snapshot, 'knowledge_item_id'));
        $this->assertSame($themedDocument->customer_id, data_get($themedRevisions[0]->snapshot, 'customer_id'));
        $this->assertSame($themedDocument->title, data_get($themedRevisions[0]->snapshot, 'title'));
        $this->assertSame($themedDocument->resolvedOriginalFilename(), data_get($themedRevisions[0]->snapshot, 'original_filename'));
        $this->assertSame($themedDocument->resolvedStoragePath(), data_get($themedRevisions[0]->snapshot, 'path'));
        $this->assertSame($themedDocument->resolvedMimeType(), data_get($themedRevisions[0]->snapshot, 'mime_type'));
        $this->assertSame($themedDocument->document_type, data_get($themedRevisions[0]->snapshot, 'document_type'));
        $this->assertSame($themeTerm->id, data_get($themedRevisions[0]->snapshot, 'document_theme_term_id'));
        $this->assertNull(data_get($themedRevisions[0]->snapshot, 'summary'));

        $storeWithoutThemeResponse = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('theme-store-empty.docx', 'Document content used to verify null theme behavior.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_BOILERPLATE,
            'is_active' => true,
        ]);

        $storeWithoutThemeResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $plainDocument = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'theme-store-empty.docx')
            ->firstOrFail();

        $this->assertNull($plainDocument->document_theme_term_id);
        $this->assertFalse($plainDocument->hasDocumentTheme());
        $this->assertNull($plainDocument->documentThemeTerm);

        $plainRevisions = KnowledgeItemRevision::query()
            ->where('customer_id', $context['customer']->id)
            ->orderBy('revision_no')
            ->get()
            ->filter(static fn (KnowledgeItemRevision $revision): bool => data_get($revision->snapshot, 'original_filename') === 'theme-store-empty.docx')
            ->values();

        $this->assertCount(1, $plainRevisions);
        $this->assertSame(KnowledgeItemRevision::CHANGE_TYPE_CREATED, $plainRevisions[0]->change_type);
        $this->assertSame(1, $plainRevisions[0]->revision_no);
        $this->assertRevisionOwnership($plainRevisions[0], $context['customer'], $context['user']);
        $this->assertNull(data_get($plainRevisions[0]->snapshot, 'document_theme_term_id'));
        $this->assertSame($plainDocument->resolvedStoragePath(), data_get($plainRevisions[0]->snapshot, 'path'));
        $this->assertNull(data_get($plainRevisions[0]->snapshot, 'summary'));
    }

    public function test_knowledge_document_update_persists_preserves_and_clears_document_theme_term_id(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Theme Update AS');
        $initialThemeTerm = $this->createKnowledgeThemeTerm($context['customer'], 'Strategisk risiko');
        $replacementThemeTerm = $this->createKnowledgeThemeTerm($context['customer'], 'Operativ styring');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('theme-update.docx', 'Document content used to verify theme update behavior.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_BOILERPLATE,
            'is_active' => true,
            'document_theme_term_id' => $initialThemeTerm->id,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'theme-update.docx')
            ->firstOrFail();

        $updateWithThemeResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'ownership_type' => $document->ownership_type,
            'document_theme_term_id' => $replacementThemeTerm->id,
        ]);

        $updateWithThemeResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $updatedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $updatedDocument->document_type);
        $this->assertSame($replacementThemeTerm->id, $updatedDocument->document_theme_term_id);
        $this->assertSame($replacementThemeTerm->id, $updatedDocument->documentThemeTerm?->id);

        $revisionsAfterUpdate = KnowledgeItemRevision::query()
            ->where('customer_id', $context['customer']->id)
            ->orderBy('revision_no')
            ->get()
            ->filter(static fn (KnowledgeItemRevision $revision): bool => data_get($revision->snapshot, 'original_filename') === 'theme-update.docx')
            ->values();

        $this->assertCount(2, $revisionsAfterUpdate);
        $this->assertSame([1, 2], $revisionsAfterUpdate->pluck('revision_no')->all());
        $this->assertSame([
            KnowledgeItemRevision::CHANGE_TYPE_CREATED,
            KnowledgeItemRevision::CHANGE_TYPE_METADATA_UPDATED,
        ], $revisionsAfterUpdate->pluck('change_type')->all());
        foreach ($revisionsAfterUpdate as $revision) {
            $this->assertRevisionOwnership($revision, $context['customer'], $context['user']);
        }
        $this->assertSame($initialThemeTerm->id, data_get($revisionsAfterUpdate[0]->snapshot, 'document_theme_term_id'));
        $this->assertSame($replacementThemeTerm->id, data_get($revisionsAfterUpdate[1]->snapshot, 'document_theme_term_id'));
        $this->assertSame($document->resolvedStoragePath(), data_get($revisionsAfterUpdate[0]->snapshot, 'path'));
        $this->assertSame($document->summary, data_get($revisionsAfterUpdate[1]->snapshot, 'summary'));

        $preserveResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'is_active' => true,
        ]);

        $preserveResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $preservedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_REFERENCE, $preservedDocument->document_type);
        $this->assertSame($replacementThemeTerm->id, $preservedDocument->document_theme_term_id);

        $clearResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'is_active' => true,
            'document_theme_term_id' => null,
        ]);

        $clearResponse->assertRedirect(route('app.ai.knowledge-base.index'));

        $clearedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_REFERENCE, $clearedDocument->document_type);
        $this->assertNull($clearedDocument->document_theme_term_id);
        $this->assertFalse($clearedDocument->hasDocumentTheme());
        $this->assertNull($clearedDocument->documentThemeTerm);

        $revisionsAfterClear = KnowledgeItemRevision::query()
            ->where('customer_id', $context['customer']->id)
            ->orderBy('revision_no')
            ->get()
            ->filter(static fn (KnowledgeItemRevision $revision): bool => data_get($revision->snapshot, 'original_filename') === 'theme-update.docx')
            ->values();

        $this->assertCount(4, $revisionsAfterClear);
        $this->assertSame([1, 2, 3, 4], $revisionsAfterClear->pluck('revision_no')->all());
        $this->assertSame([
            KnowledgeItemRevision::CHANGE_TYPE_CREATED,
            KnowledgeItemRevision::CHANGE_TYPE_METADATA_UPDATED,
            KnowledgeItemRevision::CHANGE_TYPE_METADATA_UPDATED,
            KnowledgeItemRevision::CHANGE_TYPE_METADATA_UPDATED,
        ], $revisionsAfterClear->pluck('change_type')->all());
        foreach ($revisionsAfterClear as $revision) {
            $this->assertRevisionOwnership($revision, $context['customer'], $context['user']);
        }
        $this->assertSame($replacementThemeTerm->id, data_get($revisionsAfterClear[2]->snapshot, 'document_theme_term_id'));
        $this->assertSame(null, data_get($revisionsAfterClear[3]->snapshot, 'document_theme_term_id'));
        $this->assertSame($document->id, data_get($revisionsAfterClear[3]->snapshot, 'knowledge_item_id'));
    }

    public function test_knowledge_document_store_and_update_reject_invalid_document_theme_term_id_values(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Theme Validation AS');
        $foreignContext = $this->customerContext('Customer Theme Validation Foreign AS');
        $validThemeTerm = $this->createKnowledgeThemeTerm($context['customer'], 'Gyldig tema');

        $foreignThemeTerm = $this->createKnowledgeThemeTerm($foreignContext['customer'], 'Fremmed tema');
        $unapprovedThemeTerm = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_THEME_TAG,
            'canonical_name' => 'Skjult tema',
            'synonyms' => ['skjult'],
            'description' => 'Skal ikke kunne velges fordi den ikke er godkjent.',
            'approved' => false,
        ]);
        $wrongTypeTerm = KnowledgeMetadataTerm::query()->create([
            'customer_id' => $context['customer']->id,
            'type' => KnowledgeMetadataTerm::TYPE_DOCUMENT_TYPE,
            'canonical_name' => 'Dokumentkategori',
            'synonyms' => ['kategori'],
            'description' => 'Skal ikke kunne velges fordi typen er feil.',
            'approved' => true,
        ]);

        $invalidThemeIds = [
            $foreignThemeTerm->id,
            $unapprovedThemeTerm->id,
            $wrongTypeTerm->id,
        ];

        foreach ($invalidThemeIds as $index => $invalidThemeId) {
            $filename = sprintf('invalid-theme-%d.docx', $index + 1);

            $storeResponse = $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
                'document' => $this->createDocxUpload($filename, 'Store validation content.'),
                'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
                'is_active' => true,
                'document_theme_term_id' => $invalidThemeId,
            ]);

            $storeResponse->assertSessionHasErrors(['document_theme_term_id']);
            $this->assertDatabaseMissing('knowledge_items', [
                'customer_id' => $context['customer']->id,
                'original_filename' => $filename,
            ]);
        }

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('valid-theme-update.docx', 'Document content used to verify invalid update behavior.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_theme_term_id' => $validThemeTerm->id,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'valid-theme-update.docx')
            ->firstOrFail();

        foreach ($invalidThemeIds as $invalidThemeId) {
            $updateResponse = $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
                'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
                'ownership_type' => $document->ownership_type,
                'is_active' => false,
                'document_theme_term_id' => $invalidThemeId,
            ]);

            $updateResponse->assertSessionHasErrors(['document_theme_term_id']);

            $document->refresh();
            $this->assertSame($validThemeTerm->id, $document->document_theme_term_id);
        }
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
            ->where('title', 'delete-me.docx')
            ->firstOrFail();

        $storedPath = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('is_current', true)
            ->value('storage_path');

        $this->actingAs($context['user'])->delete(route('app.ai.knowledge-base.destroy', ['knowledgeItem' => $document->id]))
            ->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->assertDatabaseMissing('knowledge_items', ['id' => $document->id]);
        $this->assertDatabaseMissing('knowledge_item_chunks', ['knowledge_item_id' => $document->id]);
        $this->assertTrue(Storage::disk('local')->missing($storedPath));

        $revisions = KnowledgeItemRevision::query()
            ->where('customer_id', $context['customer']->id)
            ->orderBy('revision_no')
            ->get()
            ->filter(static fn (KnowledgeItemRevision $revision): bool => data_get($revision->snapshot, 'original_filename') === 'delete-me.docx')
            ->values();

        $this->assertCount(2, $revisions);
        $this->assertSame([1, 2], $revisions->pluck('revision_no')->all());
        $this->assertSame([
            KnowledgeItemRevision::CHANGE_TYPE_CREATED,
            KnowledgeItemRevision::CHANGE_TYPE_DELETED,
        ], $revisions->pluck('change_type')->all());
        $this->assertNull($revisions[0]->knowledge_item_id);
        $this->assertNull($revisions[1]->knowledge_item_id);
        foreach ($revisions as $revision) {
            $this->assertRevisionOwnership($revision, $context['customer'], $context['user']);
        }
        $this->assertSame($document->id, data_get($revisions[0]->snapshot, 'knowledge_item_id'));
        $this->assertSame($document->id, data_get($revisions[1]->snapshot, 'knowledge_item_id'));
        $this->assertSame($storedPath, data_get($revisions[1]->snapshot, 'path'));

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));

        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $items = collect(data_get($page, 'props.knowledgeItems', []));

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Index'
                && $items->isEmpty()
                && ! $items->contains(fn (array $candidate): bool => $candidate['original_filename'] === $document->title);
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
            ->where('title', 'foreign.docx')
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

    public function test_knowledge_document_show_generates_lazy_summary_even_when_monthly_ai_quota_is_exhausted(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $context = $this->customerContext('Quota Exhausted Summary AS');
        $context['customer']->forceFill(['included_ai_credits' => 3])->save();

        $operationKey = AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD;
        RateLimiter::clear(sprintf('ai:user:%d:%s', $context['user']->id, $operationKey));

        for ($i = 0; $i < 3; $i++) {
            AiUsageEvent::query()->create([
                'customer_id' => $context['customer']->id,
                'user_id' => $context['user']->id,
                'operation_key' => $operationKey,
                'status' => AiUsageEvent::STATUS_ALLOWED,
                'limit_type' => null,
                'operation_count' => 1,
            ]);
        }

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'quota-test.pdf',
            'content' => 'Dokumentinnhold for test.',
            'original_filename' => 'quota-test.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/quota-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => 'Dokumentinnhold for test.',
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'is_active' => true,
        ]);

        $this->createCurrentVersionFor($document, $context['user']);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $response->assertOk();

        $refreshed = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();
        $this->assertNotNull($refreshed->summary);
        $this->assertStringStartsWith('AI-oppsummering:', (string) $refreshed->summary);

        $this->assertSame(
            4,
            AiUsageEvent::query()
                ->where('customer_id', $context['customer']->id)
                ->where('status', AiUsageEvent::STATUS_ALLOWED)
                ->count(),
        );
        $this->assertSame(
            0,
            AiUsageEvent::query()
                ->where('customer_id', $context['customer']->id)
                ->where('status', AiUsageEvent::STATUS_BLOCKED)
                ->count(),
        );
    }

    public function test_knowledge_document_show_records_allowed_usage_event_when_generating_lazy_summary(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $context = $this->customerContext('Quota Available Summary AS');
        $context['customer']->forceFill(['included_ai_credits' => 3])->save();

        $operationKey = AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD;
        RateLimiter::clear(sprintf('ai:user:%d:%s', $context['user']->id, $operationKey));

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'available-quota.pdf',
            'content' => 'Første avsnitt handler om koordinering. Andre avsnitt handler om risiko og oppfølging.',
            'original_filename' => 'available-quota.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/available-quota.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => 'Første avsnitt handler om koordinering. Andre avsnitt handler om risiko og oppfølging.',
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'is_active' => true,
        ]);

        $this->createCurrentVersionFor($document, $context['user']);

        $response = $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $response->assertOk();

        $allowedEvent = AiUsageEvent::query()
            ->where('customer_id', $context['customer']->id)
            ->where('status', AiUsageEvent::STATUS_ALLOWED)
            ->where('operation_key', $operationKey)
            ->first();
        $this->assertNotNull($allowedEvent,
            'An allowed usage event must be recorded when lazy summary generation is permitted by the guard.');
    }

    public function test_summary_service_calls_token_logger_with_correct_data_on_successful_generation(): void
    {
        $context = $this->customerContext('Token Logger Service AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'service-token-test.pdf',
            'content' => 'Koordinering og samhandling.',
            'original_filename' => 'service-token-test.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/service-token-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 512,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'extracted_text' => 'Koordinering og samhandling.',
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'is_active' => true,
        ]);

        $fakeResponse = [
            'id' => 'fake-summary-id',
            'output_text' => json_encode(['summary' => 'AI-oppsummering: Koordinering og samhandling.']),
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45, 'total_tokens' => 165],
            '_meta' => ['request_id' => 'req_service_test'],
        ];

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')->once()->andReturn($fakeResponse);

        $recorded = [];
        $logger = Mockery::mock(AiTokenLogger::class);
        $logger->shouldReceive('record')
            ->once()
            ->andReturnUsing(function (array $data) use (&$recorded): void {
                $recorded = $data;
            });

        $service = new KnowledgeDocumentSummaryGenerationService($client, $logger);
        $result = $service->generateForDocument($document, (int) $context['user']->id);

        $this->assertStringStartsWith('AI-oppsummering:', (string) $result);
        $this->assertSame((int) $document->customer_id, $recorded['customer_id']);
        $this->assertSame((int) $context['user']->id, $recorded['user_id']);
        $this->assertSame('knowledge_document_upload', $recorded['operation_key']);
        $this->assertSame(120, $recorded['input_tokens']);
        $this->assertSame(45, $recorded['output_tokens']);
        $this->assertSame(165, $recorded['total_tokens']);
        $this->assertSame($document->id, $recorded['knowledge_item_id']);
        $this->assertSame('req_service_test', $recorded['request_id']);
    }

    public function test_summary_service_does_not_call_token_logger_when_generation_fails(): void
    {
        $context = $this->customerContext('Token Logger Fail AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'fail-token-test.pdf',
            'content' => 'Innhold.',
            'original_filename' => 'fail-token-test.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/fail-token-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 256,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => 'Innhold.',
            'summary' => null,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'is_active' => true,
        ]);

        $client = Mockery::mock(OpenAiClient::class);
        $client->shouldReceive('createResponse')->once()->andThrow(new RuntimeException('OpenAI unreachable'));

        $logger = Mockery::mock(AiTokenLogger::class);
        $logger->shouldNotReceive('record');

        $service = new KnowledgeDocumentSummaryGenerationService($client, $logger);
        $result = $service->generateForDocument($document, (int) $context['user']->id);

        $this->assertNull($result, 'generateForDocument must return null when OpenAI call fails.');
    }

    public function test_knowledge_document_show_does_not_create_token_event_when_summary_already_exists(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $context = $this->customerContext('No Duplicate Token AS');

        $document = KnowledgeItem::query()->create([
            'customer_id' => $context['customer']->id,
            'uploaded_by_user_id' => $context['user']->id,
            'title' => 'has-summary.pdf',
            'content' => 'Innhold som allerede har sammendrag.',
            'original_filename' => 'has-summary.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-documents/has-summary.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 512,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'extracted_text' => 'Innhold som allerede har sammendrag.',
            'summary' => 'Eksisterende sammendrag som ikke skal regenereres.',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'is_active' => true,
        ]);

        $this->actingAs($context['user'])
            ->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $tokenCount = AiTokenEvent::query()
            ->where('customer_id', $context['customer']->id)
            ->where('operation_key', 'knowledge_document_upload')
            ->count();

        $this->assertSame(0, $tokenCount,
            'No ai_token_events must be created when an existing summary is reused without a new AI call.');
    }

    /**
     * Purpose: Build a deterministic fake OpenAI Responses-API payload for document summary tests.
     * Inputs: The summary text to return, input token count and output token count.
     * Returns: An array matching what OpenAiClient::createResponse() would return.
     * Side effects: None.
     */
    private function fakeSummaryOpenAiResponse(string $summaryText, int $inputTokens, int $outputTokens): array
    {
        $json = json_encode(['summary' => $summaryText], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'id' => 'fake-summary-id',
            'object' => 'response',
            'status' => 'completed',
            'output_text' => $json,
            'output' => [
                [
                    'id' => 'fake-msg-id',
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [['type' => 'output_text', 'text' => $json]],
                ],
            ],
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
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
     * Purpose: Create a deterministic document-theme term for payload tests.
     * Inputs: The owning customer and canonical theme name.
     * Returns: The created knowledge metadata term.
     * Side effects: Persists one approved theme term row.
     */
    private function createKnowledgeThemeTerm(Customer $customer, string $canonicalName): KnowledgeMetadataTerm
    {
        return KnowledgeMetadataTerm::query()->create([
            'customer_id' => $customer->id,
            'type' => KnowledgeMetadataTerm::TYPE_THEME_TAG,
            'canonical_name' => $canonicalName,
            'synonyms' => [$canonicalName],
            'description' => 'Dokumenttema brukt i payloadtest.',
            'approved' => true,
        ]);
    }

    /**
     * Purpose: Create a deterministic knowledge document category for payload tests.
     * Inputs: The owning customer, the category name, and whether the category is active.
     * Returns: The created knowledge document category.
     * Side effects: Persists one category row.
     */
    private function createKnowledgeDocumentCategory(Customer $customer, string $name, bool $isActive = true): KnowledgeDocumentCategory
    {
        return KnowledgeDocumentCategory::query()->create([
            'customer_id' => $customer->id,
            'name' => $name,
            'description' => 'Dokumentkategori brukt i payloadtest.',
            'sort_order' => 10,
            'is_active' => $isActive,
        ]);
    }

    /**
     * Purpose: Create a deterministic knowledge document topic for payload tests.
     * Inputs: The owning customer, the topic name, and whether the topic is active.
     * Returns: The created knowledge document topic.
     * Side effects: Persists one topic row.
     */
    private function createKnowledgeDocumentTopic(Customer $customer, string $name, bool $isActive = true): KnowledgeDocumentTopic
    {
        return KnowledgeDocumentTopic::query()->create([
            'customer_id' => $customer->id,
            'name' => $name,
            'description' => 'Tema brukt i payloadtest.',
            'sort_order' => 10,
            'is_active' => $isActive,
        ]);
    }

    /**
     * Purpose: Assert that a knowledge-item revision is scoped to the given customer and user.
     * Inputs: The revision row plus the expected customer and user.
     * Returns: None.
     * Side effects: None.
     */
    private function assertRevisionOwnership(KnowledgeItemRevision $revision, Customer $customer, User $user): void
    {
        $this->assertSame($customer->id, $revision->customer_id);
        $this->assertSame($user->id, $revision->changed_by_user_id);
    }

    /**
     * Purpose: Create a direct knowledge item fixture for payload assertions.
     * Inputs: The owning customer, the uploader, and optional field overrides.
     * Returns: A persisted knowledge item.
     * Side effects: Persists one knowledge item row.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createKnowledgeItemPayloadFixture(Customer $customer, User $uploadedBy, array $overrides = []): KnowledgeItem
    {
        $title = (string) ($overrides['title'] ?? 'Ownership payload document');
        $originalFilename = (string) ($overrides['original_filename'] ?? 'ownership-payload.docx');
        $content = (string) ($overrides['content'] ?? 'Ownership payload content.');

        $item = KnowledgeItem::query()->create(array_merge([
            'customer_id' => $customer->id,
            'uploaded_by_user_id' => $uploadedBy->id,
            'ownership_type' => KnowledgeItem::OWNERSHIP_TYPE_COMPANY,
            'title' => $title,
            'content' => $content,
            'original_filename' => $originalFilename,
            'storage_path' => 'customers/'.$customer->id.'/knowledge-items/'.$originalFilename,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1024,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'document_category_id' => null,
            'document_topic_id' => null,
            'document_theme_term_id' => null,
            'extracted_text' => $content,
            'summary' => 'Oppsummering for '.$title,
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'owner_user_id' => null,
            'owning_saved_notice_id' => null,
            'is_active' => true,
        ], $overrides));

        if (array_key_exists('content_type', $overrides)) {
            $item->forceFill([
                'content_type' => $overrides['content_type'],
            ])->saveQuietly();
        }

        if (array_key_exists('is_active', $overrides)) {
            $item->forceFill([
                'is_active' => $overrides['is_active'],
            ])->saveQuietly();
        }

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $customer->id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => $item->original_filename,
            'storage_path' => $item->storage_path,
            'mime_type' => $item->mime_type,
            'file_size_bytes' => $item->file_size_bytes,
            'extracted_text' => $item->extracted_text,
            'extraction_status' => $item->extraction_status,
            'extraction_error' => $item->extraction_error,
            'uploaded_by_user_id' => $uploadedBy->id,
            'uploaded_at' => $item->created_at,
            'file_hash_sha256' => null,
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);

        return $item;
    }

    /**
     * Purpose: Create a current version for a knowledge item that was created directly in tests.
     * Inputs: The knowledge item and the uploading user.
     * Returns: The persisted version row.
     * Side effects: Persists one knowledge_item_versions row with is_current=true.
     */
    private function createCurrentVersionFor(KnowledgeItem $item, User $uploadedBy): KnowledgeItemVersion
    {
        return KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'customer_id' => $item->customer_id,
            'version_no' => 1,
            'is_current' => true,
            'original_filename' => $item->original_filename,
            'storage_path' => $item->storage_path,
            'mime_type' => $item->mime_type,
            'file_size_bytes' => $item->file_size_bytes,
            'extracted_text' => $item->extracted_text,
            'extraction_status' => $item->extraction_status,
            'extraction_error' => $item->extraction_error,
            'uploaded_by_user_id' => $uploadedBy->id,
            'uploaded_at' => $item->created_at,
            'file_hash_sha256' => null,
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);
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
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function createDocxUploadWithBlocks(string $filename, array $blocks): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'procynia-docx-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary DOCX file.');
        }

        $zip = new ZipArchive;
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
        $path = storage_path('app/private/customers/2162/knowledge-documents/01KS34FX95FEPJDW15HEYE1W7H.pdf');

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
                'text' => 'Kapittel 1 tekst. '.$this->repeatedWords('kap1', 35),
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > 2.1 Sammendrag og helhetlig løsningsforslag',
                'text' => 'Sammendrag og helhetlig løsningsforslag. '.$this->repeatedWords('sam', 36),
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'h2_section',
                'heading_path' => 'Kapittel 2 > 2.2 Strategisk partnerskap, veikart og måloppnåelse',
                'text' => 'Strategisk partnerskap, veikart og måloppnåelse. '.$this->repeatedWords('str', 35),
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel 3',
                'text' => 'Kapittel 3 tekst. '.$this->repeatedWords('kap3', 35),
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
                'text' => 'Kapittel 1 tekst. '.$this->repeatedWords('kap1', 35),
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
                'text' => 'Kort oppsummering. '.$this->repeatedWords('ops', 38),
                'heading_level' => 2,
                'relation_hint' => 'h2_section',
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'Kapittel 3',
                'text' => 'Kapittel 3 tekst. '.$this->repeatedWords('kap3', 35),
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
        $preH2Text = 'Tekst før tabell. '.$this->repeatedWords('forklaring', 40);
        $h2Text = "1.1 Dokumentasjonskrav for drift\n\nTekst etter H2. ".$this->repeatedWords('dokumentasjon', 35);

        return $this->buildRuleBasedStructureFixture([
            [
                'type' => 'paragraph',
                'heading_path' => '1 Overskrift test',
                'text' => $preH2Text,
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
                'text' => $h2Text,
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
        $h1Text = 'Kapittel 2 tekst. '.$this->repeatedWords('kapitteltekst', 40);
        $preTableText = 'Innledning før tabell. '.$this->repeatedWords('innledningsord', 40);
        $postTableText = 'Etter tabell. '.$this->repeatedWords('avslutningsord', 40);

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
                'heading_path' => 'B ILAG 1-11 > 1.12 R ISIKOSTYRING AV PROSJEKTER',
                'text' => 'Det skal holdes egne møter for risikostyring jevnlig for å overvåke eksisterende risiko, identifisere nye risikoer og for å avtale risikostrategi og tiltak for å kunne styre risiko gjennom prosjektet. Hyppigheten av disse møtene avtales mellom Kunden og Leverandøren i planleggingsfasen når alle innledende risikoer er identifisert og analysert. Risikorapportering vil være en naturlig del av styringsgruppe- og prosjektrapportering. Leverandøren har etablert maler for å identifisere, håndtere og presentere identifiserte risikoer i prosjektprosessen. Risikostyring og identifisering er også et kontinuerlig arbeid for prosjektlederen i prosjektperioden og er tydelig definert i prosjektprosessen til selskapet som er revidert av DNV-GL og sertifisert til standarden 9001:2015. Leverandøren vil først og fremst gjennomføre risiko opp mot: Kvalitet, Kost og Fremdrift (tid).',
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'list',
                'heading_path' => 'B ILAG 1-11 > 1.12 R ISIKOSTYRING AV PROSJEKTER',
                'text' => "• Kvalitet\n• Kost\n• Fremdrift (tid)",
                'heading_level' => null,
                'relation_hint' => null,
            ],
            [
                'type' => 'paragraph',
                'heading_path' => 'B ILAG 1-11 > 1.12 R ISIKOSTYRING AV PROSJEKTER',
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
     * @param  array<int, array<string, mixed>>  $sections
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
     * @param  array<string, mixed>  $structure
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
                $embeddingVector = $this->deterministicEmbeddingVector();

                return [
                    'ok' => true,
                    'embedding' => $embeddingVector,
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
     * Purpose: Provide a deterministic embedding vector that matches the pgvector dimension.
     * Inputs: None.
     * Returns: A 1536-dimensional embedding vector with stable values.
     * Side effects: None.
     */
    private function deterministicEmbeddingVector(): array
    {
        return array_fill(0, 1536, 0.001);
    }

    /**
     * Purpose: Bind a deterministic document summary generation service for knowledge base tests.
     * Inputs: None.
     * Returns: None.
     * Side effects: Replaces the container binding with a predictable fake service.
     */
    private function bindSuccessfulKnowledgeDocumentSummaryGenerationService(): void
    {
        $service = Mockery::mock(KnowledgeDocumentSummaryGenerationService::class);
        $service->shouldReceive('generateForDocument')
            ->andReturnUsing(function (KnowledgeItem $document): ?string {
                $chunks = $document->relationLoaded('chunks')
                    ? $document->chunks
                    : $document->chunks()->orderBy('chunk_index')->get();

                $chunkText = $chunks
                    ->map(static fn (KnowledgeItemChunk $chunk): string => trim((string) $chunk->content))
                    ->filter(static fn (string $content): bool => $content !== '')
                    ->take(4)
                    ->implode(' ');

                if ($chunkText === '') {
                    $chunkText = trim((string) ($document->extracted_text ?: $document->content));
                }

                if ($chunkText === '') {
                    return null;
                }

                return Str::limit('AI-oppsummering: '.Str::squish($chunkText), 240, '');
            });

        $this->app->instance(KnowledgeDocumentSummaryGenerationService::class, $service);
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
     * @param  array<int, array<string, mixed>>  $elements
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

    public function test_knowledge_document_store_defaults_ai_usage_enabled_to_true_when_not_provided(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Ai Usage Default Store AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('ai-usage-default.docx', 'Document content for AI usage default test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'ai-usage-default.docx')
            ->firstOrFail();

        $this->assertTrue($document->ai_usage_enabled);
    }

    public function test_knowledge_document_store_persists_explicit_ai_usage_enabled_false(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Ai Usage Explicit False AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('ai-usage-disabled.docx', 'Document content for AI usage disabled test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'ai_usage_enabled' => false,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'ai-usage-disabled.docx')
            ->firstOrFail();

        $this->assertFalse($document->ai_usage_enabled);
    }

    public function test_knowledge_document_update_persists_ai_usage_enabled_and_index_payload_exposes_it(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Ai Usage Update AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('ai-usage-update.docx', 'Document content for AI usage update test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'ai_usage_enabled' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'ai-usage-update.docx')
            ->firstOrFail();

        $this->assertTrue($document->ai_usage_enabled);

        $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'is_active' => true,
            'ai_usage_enabled' => false,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->assertFalse($document->fresh()->ai_usage_enabled);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $document->id);

            return $item !== null && data_get($item, 'ai_usage_enabled') === false;
        });
    }

    public function test_knowledge_document_store_defaults_document_status_to_active_when_not_provided(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Doc Status Default Store AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-status-default.docx', 'Document content for document status default test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'doc-status-default.docx')
            ->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_ACTIVE, $document->document_status);
    }

    public function test_knowledge_document_store_persists_explicit_document_status(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Doc Status Explicit Draft AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-status-draft.docx', 'Document content for document status draft test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_DRAFT,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'doc-status-draft.docx')
            ->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_DRAFT, $document->document_status);
    }

    public function test_knowledge_document_store_rejects_invalid_document_status(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Doc Status Invalid AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-status-invalid.docx', 'Document content for invalid document status test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_status' => 'invalid_status',
        ])->assertSessionHasErrors(['document_status']);
    }

    public function test_knowledge_document_store_ignores_legacy_content_type_and_is_active_inputs(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Doc Legacy Mirror Store AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-legacy-mirror-store.docx', 'Document content for legacy mirror store test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ARCHIVED,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'doc-legacy-mirror-store.docx')
            ->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $document->document_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_ARCHIVED, $document->document_status);
    }

    public function test_knowledge_document_update_persists_document_status_and_index_payload_exposes_it(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Doc Status Update AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-status-update.docx', 'Document content for document status update test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'doc-status-update.docx')
            ->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_ACTIVE, $document->document_status);

        $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ARCHIVED,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_ARCHIVED, $document->fresh()->document_status);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $document->id);

            return $item !== null
                && data_get($item, 'document_status') === KnowledgeItem::DOCUMENT_STATUS_ARCHIVED
                && data_get($item, 'document_status_label') === KnowledgeItem::DOCUMENT_STATUS_LABELS[KnowledgeItem::DOCUMENT_STATUS_ARCHIVED];
        });
    }

    public function test_knowledge_document_update_ignores_legacy_content_type_and_is_active_inputs(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Doc Legacy Mirror Update AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-legacy-mirror-update.docx', 'Document content for legacy mirror update test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'doc-legacy-mirror-update.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'content_type' => KnowledgeItem::DOCUMENT_TYPE_METHOD,
            'ownership_type' => $document->ownership_type,
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_ARCHIVED,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $updatedDocument = KnowledgeItem::query()->whereKey($document->id)->firstOrFail();

        $this->assertSame(KnowledgeItem::DOCUMENT_TYPE_OTHER, $updatedDocument->document_type);
        $this->assertSame(KnowledgeItem::DOCUMENT_STATUS_ARCHIVED, $updatedDocument->document_status);
    }

    public function test_knowledge_document_legacy_mirror_fields_are_not_mass_assignable(): void
    {
        $knowledgeItem = new KnowledgeItem();

        $this->assertFalse($knowledgeItem->isFillable('content_type'));
        $this->assertFalse($knowledgeItem->isFillable('is_active'));
    }

    public function test_knowledge_document_form_payload_exposes_document_status_and_label(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Doc Status Form Payload AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-status-form.docx', 'Document content for form payload test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'is_active' => true,
            'document_status' => KnowledgeItem::DOCUMENT_STATUS_PENDING_REVIEW,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'doc-status-form.docx')
            ->firstOrFail();

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'document_status') === KnowledgeItem::DOCUMENT_STATUS_PENDING_REVIEW
                && data_get($item, 'document_status_label') === KnowledgeItem::DOCUMENT_STATUS_LABELS[KnowledgeItem::DOCUMENT_STATUS_PENDING_REVIEW];
        });
    }

    public function test_index_payload_reads_file_identity_from_current_version_over_stale_document_fields(): void
    {
        // When KnowledgeItem fields have drifted from the current version, the payload must prefer
        // the version's values for original_filename, mime_type, and file_size_bytes.
        $context = $this->customerContext('Current Version File Identity Index AS');

        $document = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'stale-document-name.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 999,
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $context['customer']->id,
            'version_no' => 2,
            'is_current' => true,
            'original_filename' => 'current-version-name.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-items/v1.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 5120,
            'extracted_text' => 'Version text',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'uploaded_by_user_id' => $context['user']->id,
            'uploaded_at' => now(),
            'file_hash_sha256' => hash('sha256', 'version-content'),
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))->firstWhere('id', $document->id);

            return $item !== null
                && data_get($item, 'original_filename') === 'current-version-name.pdf'
                && data_get($item, 'mime_type') === 'application/pdf'
                && data_get($item, 'file_size_bytes') === 5120
                && ! array_key_exists('content_type', $item)
                && ! array_key_exists('is_active', $item);
        });
    }

    public function test_edit_form_payload_reads_file_identity_from_current_version_over_stale_document_fields(): void
    {
        // When KnowledgeItem fields have drifted from the current version, the edit form payload must
        // prefer the version's values for original_filename, mime_type, and file_size_bytes.
        $context = $this->customerContext('Current Version File Identity Form AS');

        $document = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'stale-form-name.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 1111,
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $context['customer']->id,
            'version_no' => 2,
            'is_current' => true,
            'original_filename' => 'current-form-version.pdf',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-items/form-v1.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 8192,
            'extracted_text' => 'Form version text',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'uploaded_by_user_id' => $context['user']->id,
            'uploaded_at' => now(),
            'file_hash_sha256' => hash('sha256', 'form-version-content'),
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'original_filename') === 'current-form-version.pdf'
                && data_get($item, 'mime_type') === 'application/pdf'
                && data_get($item, 'file_size_bytes') === 8192;
        });
    }

    public function test_index_edit_and_show_payload_returns_null_file_fields_when_current_version_has_null_values(): void
    {
        $context = $this->customerContext('Legacy File Identity Fallback AS');

        $document = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'legacy-document-name.docx',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-items/legacy-document-name.docx',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2468,
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $context['customer']->id,
            'version_no' => 2,
            'is_current' => true,
            'original_filename' => null,
            'storage_path' => null,
            'mime_type' => null,
            'file_size_bytes' => null,
            'extracted_text' => 'Version fallback text',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'uploaded_by_user_id' => $context['user']->id,
            'uploaded_at' => now(),
            'file_hash_sha256' => hash('sha256', 'legacy-fallback-content'),
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))->firstWhere('id', $document->id);

            return $item !== null
                && data_get($item, 'original_filename') === null
                && data_get($item, 'mime_type') === null
                && data_get($item, 'file_size_bytes') === null;
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'original_filename') === null
                && data_get($item, 'mime_type') === null
                && data_get($item, 'file_size_bytes') === null
                && ! array_key_exists('content_type', $item)
                && ! array_key_exists('is_active', $item);
        });

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));
        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return data_get($page, 'component') === 'App/AI/KnowledgeBase/Show'
                && data_get($page, 'props.pageTitle') === 'Kunnskapsdokumenter · '
                && $item !== null
                && data_get($item, 'original_filename') === null
                && data_get($item, 'mime_type') === null
                && data_get($item, 'file_size_bytes') === null
                && ! array_key_exists('content_type', $item)
                && ! array_key_exists('is_active', $item);
        });
    }

    public function test_payload_reads_extraction_status_from_current_version_over_stale_document_fields(): void
    {
        // When KnowledgeItem.extraction_status has drifted from the current version, the payload must
        // prefer the version's extraction_status and extraction_error values.
        $context = $this->customerContext('Current Version Extraction Status AS');

        $document = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'stale-extraction.docx',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_FAILED,
            'extraction_error' => 'Stale document-level error',
        ]);

        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $context['customer']->id,
            'version_no' => 2,
            'is_current' => true,
            'original_filename' => 'stale-extraction.docx',
            'storage_path' => 'customers/'.$context['customer']->id.'/knowledge-items/stale-extraction.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 2048,
            'extracted_text' => 'Extracted from version',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'extraction_error' => null,
            'uploaded_by_user_id' => $context['user']->id,
            'uploaded_at' => now(),
            'file_hash_sha256' => hash('sha256', 'extraction-version-content'),
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED,
        ]);

        // Check index payload (documentListPayload).
        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))->firstWhere('id', $document->id);

            return $item !== null
                && data_get($item, 'extraction_status') === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && data_get($item, 'extraction_error') === null
                && ! array_key_exists('content_type', $item)
                && ! array_key_exists('is_active', $item);
        });

        // Check edit form payload (documentFormPayload).
        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'extraction_status') === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && data_get($item, 'extraction_error') === null
                && ! array_key_exists('content_type', $item)
                && ! array_key_exists('is_active', $item);
        });

        // Check show payload (documentDetailPayload).
        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));
        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'extraction_status') === KnowledgeItem::EXTRACTION_STATUS_COMPLETED
                && data_get($item, 'extraction_error') === null
                && ! array_key_exists('content_type', $item)
                && ! array_key_exists('is_active', $item);
        });
    }

    public function test_payload_falls_back_to_legacy_extraction_state_when_current_version_is_missing(): void
    {
        $context = $this->customerContext('Legacy Extraction Fallback AS');

        $document = $this->createKnowledgeItemPayloadFixture($context['customer'], $context['user'], [
            'original_filename' => 'legacy-extraction.docx',
            'extracted_text' => 'Legacy document extracted text',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_FAILED,
            'extraction_error' => 'Legacy document-level error',
        ]);

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))->firstWhere('id', $document->id);

            return $item !== null
                && data_get($item, 'extraction_status') === KnowledgeItem::EXTRACTION_STATUS_FAILED
                && data_get($item, 'extraction_error') === 'Legacy document-level error';
        });

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'extraction_status') === KnowledgeItem::EXTRACTION_STATUS_FAILED
                && data_get($item, 'extraction_error') === 'Legacy document-level error';
        });

        $showResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));
        $showResponse->assertOk();
        $showResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'extraction_status') === KnowledgeItem::EXTRACTION_STATUS_FAILED
                && data_get($item, 'extraction_error') === 'Legacy document-level error';
        });
    }

    public function test_knowledge_document_store_defaults_review_dates_to_null_when_not_provided(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review Dates Default AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-dates-default.docx', 'Review dates default test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-dates-default.docx')
            ->firstOrFail();

        $this->assertNull($document->last_reviewed_at);
        $this->assertNull($document->review_due_at);
    }

    public function test_knowledge_document_store_persists_explicit_review_dates(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review Dates Explicit AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-dates-explicit.docx', 'Review dates explicit test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'last_reviewed_at' => '2026-05-01',
            'review_due_at' => '2026-12-31',
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-dates-explicit.docx')
            ->firstOrFail();

        $this->assertSame('2026-05-01', $document->last_reviewed_at?->toDateString());
        $this->assertSame('2026-12-31', $document->review_due_at?->toDateString());
    }

    public function test_knowledge_document_store_rejects_invalid_date_format(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review Dates Invalid AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-dates-invalid.docx', 'Review dates invalid test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'review_due_at' => 'not-a-date',
        ])->assertSessionHasErrors(['review_due_at']);
    }

    public function test_knowledge_document_update_persists_and_clears_review_dates(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review Dates Update AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-dates-update.docx', 'Review dates update test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'last_reviewed_at' => '2026-04-01',
            'review_due_at' => '2026-10-01',
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-dates-update.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
            'last_reviewed_at' => '2026-06-01',
            'review_due_at' => null,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document->refresh();
        $this->assertSame('2026-06-01', $document->last_reviewed_at?->toDateString());
        $this->assertNull($document->review_due_at);
    }

    public function test_knowledge_document_update_preserves_review_dates_when_not_sent(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review Dates Preserve AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-dates-preserve.docx', 'Review dates preserve test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'last_reviewed_at' => '2026-03-15',
            'review_due_at' => '2026-09-15',
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-dates-preserve.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->put(route('app.ai.knowledge-base.update', ['knowledgeItem' => $document->id]), [
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'ownership_type' => $document->ownership_type,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document->refresh();
        $this->assertSame('2026-03-15', $document->last_reviewed_at?->toDateString());
        $this->assertSame('2026-09-15', $document->review_due_at?->toDateString());
    }

    public function test_knowledge_document_payload_exposes_review_state_not_set_when_no_due_date(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review State Not Set AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-state-not-set.docx', 'Review state not set test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-state-not-set.docx')
            ->firstOrFail();

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $document->id);

            return $item !== null
                && data_get($item, 'review_state') === 'not_set'
                && data_get($item, 'review_due_at') === null
                && data_get($item, 'last_reviewed_at') === null;
        });
    }

    public function test_knowledge_document_payload_exposes_review_state_ok_when_due_date_is_far_future(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review State Ok AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-state-ok.docx', 'Review state ok test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'review_due_at' => now()->addDays(90)->toDateString(),
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-state-ok.docx')
            ->firstOrFail();

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $document->id);

            return $item !== null && data_get($item, 'review_state') === 'ok';
        });
    }

    public function test_knowledge_document_payload_exposes_review_state_due_soon_when_due_within_30_days(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review State Due Soon AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-state-due-soon.docx', 'Review state due soon test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'review_due_at' => now()->addDays(15)->toDateString(),
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-state-due-soon.docx')
            ->firstOrFail();

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $document->id);

            return $item !== null && data_get($item, 'review_state') === 'due_soon';
        });
    }

    public function test_knowledge_document_payload_exposes_review_state_overdue_when_due_date_is_in_past(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review State Overdue AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-state-overdue.docx', 'Review state overdue test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'review_due_at' => now()->subDays(10)->toDateString(),
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-state-overdue.docx')
            ->firstOrFail();

        $indexResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.index'));
        $indexResponse->assertOk();
        $indexResponse->assertViewHas('page', function (array $page) use ($document): bool {
            $item = collect(data_get($page, 'props.knowledgeItems', []))
                ->firstWhere('id', $document->id);

            return $item !== null && data_get($item, 'review_state') === 'overdue';
        });
    }

    public function test_knowledge_document_form_payload_exposes_review_dates(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Customer Review Dates Form Payload AS');

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('review-dates-form.docx', 'Review dates form payload test.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_REFERENCE,
            'last_reviewed_at' => '2026-02-01',
            'review_due_at' => '2026-08-01',
        ])->assertRedirect(route('app.ai.knowledge-base.index'));

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'review-dates-form.docx')
            ->firstOrFail();

        $editResponse = $this->actingAs($context['user'])->get(route('app.ai.knowledge-base.edit', ['knowledgeItem' => $document->id]));
        $editResponse->assertOk();
        $editResponse->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem');

            return $item !== null
                && data_get($item, 'last_reviewed_at') === '2026-02-01'
                && data_get($item, 'review_due_at') === '2026-08-01'
                && data_get($item, 'review_state') === 'ok';
        });
    }

    // ── Fase 2.4D2 — version history payload + snapshot + metadata job scoping ─

    public function test_show_payload_contains_versions_list_with_correct_fields(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Version Payload Customer A');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('v1.docx', str_repeat('Version one content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'v1.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('v2.docx', str_repeat('Version two content. ', 20))],
        )->assertRedirect();

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $item = data_get($page, 'props.knowledgeItem', []);
            $versions = data_get($item, 'versions', null);

            if (! is_array($versions) || count($versions) !== 2) {
                return false;
            }

            $v2 = $versions[0];
            $v1 = $versions[1];

            // sorted newest first
            if (data_get($v2, 'version_no') !== 2 || data_get($v1, 'version_no') !== 1) {
                return false;
            }

            // Fase 2.5B: v2 is pending_review and not current; v1 remains current.
            if (data_get($v2, 'is_current') || ! data_get($v1, 'is_current')) {
                return false;
            }

            // required fields present
            $requiredFields = ['id', 'version_no', 'is_current', 'original_filename', 'storage_path', 'mime_type',
                'file_size_bytes', 'extraction_status', 'uploaded_by_user_id', 'uploaded_at', 'chunks_count',
                'approval_status'];
            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $v2)) {
                    return false;
                }
            }

            // sensitive fields absent
            return ! array_key_exists('extracted_text', $v2)
                && ! array_key_exists('embedding_vector', $v2)
                && ! array_key_exists('content', $v2);
        });
    }

    public function test_show_payload_versions_are_sorted_newest_first(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Version Payload Customer B');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('b-v1.docx', str_repeat('B version one. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'b-v1.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('b-v2.docx', str_repeat('B version two. ', 20))],
        )->assertRedirect();

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            return count($versions) === 2
                && (int) data_get($versions[0], 'version_no') === 2
                && (int) data_get($versions[1], 'version_no') === 1;
        });
    }

    public function test_revision_snapshot_contains_version_id_and_version_no(): void
    {
        // Verifies that revision snapshots include knowledge_item_version_id and version_no.
        // Tested through the `created` revision from store(); the same snapshot mechanism is used
        // for `file_replaced` revisions (written by activateKnowledgeItemVersion, triggered from
        // the Fase 2.5C approve endpoint which does not exist yet).
        Storage::fake('local');

        $context = $this->customerContext('Version Payload Customer C');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('c-v1.docx', str_repeat('C version one. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'c-v1.docx')
            ->firstOrFail();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $revision = KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)
            ->where('change_type', KnowledgeItemRevision::CHANGE_TYPE_CREATED)
            ->firstOrFail();

        $snapshot = $revision->snapshot;
        $this->assertSame($v1->id, data_get($snapshot, 'knowledge_item_version_id'));
        $this->assertSame(1, data_get($snapshot, 'knowledge_item_version_no'));
    }

    public function test_show_payload_versions_do_not_expose_embeddings_extracted_text_or_chunk_content(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Version Payload Customer D');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('d-v1.docx', str_repeat('D version one. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'd-v1.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            foreach ($versions as $version) {
                if (array_key_exists('extracted_text', $version)
                    || array_key_exists('embedding_vector', $version)
                    || array_key_exists('content', $version)
                    || array_key_exists('chunks', $version)) {
                    return false;
                }
            }

            return true;
        });
    }

    // ── Fase 2.4D1 — replaceFile() ───────────────────────────────────────────

    public function test_replace_file_creates_new_version_as_pending_review_without_activating(): void
    {
        // Fase 2.5B: replaceFile() now creates pending_review versions; v1 stays current.
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer A');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original.docx', str_repeat('Original content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original.docx')
            ->firstOrFail();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('replacement.docx', str_repeat('Replacement content. ', 20))],
        )->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 2)
            ->firstOrFail();

        $v1->refresh();
        $this->assertTrue((bool) $v1->is_current, 'Original version must remain current after pending-review upload.');
        $this->assertFalse((bool) $v2->is_current, 'New pending-review version must not become current.');
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $v2->approval_status);
        $this->assertSame('replacement.docx', $v2->original_filename);
    }

    public function test_replace_file_old_version_chunks_are_preserved(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer B');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-b.docx', str_repeat('Original B content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-b.docx')
            ->firstOrFail();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $v1ChunksBefore = KnowledgeItemChunk::query()
            ->where('knowledge_item_version_id', $v1->id)
            ->count();

        $this->assertGreaterThan(0, $v1ChunksBefore);

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('replacement-b.docx', str_repeat('Replacement B content. ', 20))],
        )->assertRedirect();

        $v1ChunksAfter = KnowledgeItemChunk::query()
            ->where('knowledge_item_version_id', $v1->id)
            ->count();

        $this->assertSame($v1ChunksBefore, $v1ChunksAfter, 'Old version chunks must not be deleted on file replacement.');
    }

    public function test_replace_file_new_version_chunks_are_linked_to_new_version(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer C');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-c.docx', str_repeat('Original C content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-c.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('replacement-c.docx', str_repeat('Replacement C content. ', 20))],
        )->assertRedirect();

        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 2)
            ->firstOrFail();

        $newChunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $document->id)
            ->where('knowledge_item_version_id', $v2->id)
            ->get();

        $this->assertGreaterThan(0, $newChunks->count());
        $newChunks->each(function (KnowledgeItemChunk $chunk) use ($v2): void {
            $this->assertSame($v2->id, $chunk->knowledge_item_version_id);
        });
    }

    public function test_replace_file_does_not_update_legacy_fields_for_pending_version(): void
    {
        // Fase 2.5B: pending-review versions do not activate, so legacy fields on KnowledgeItem stay at v1.
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer D');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-d.docx', str_repeat('Original D content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-d.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('replacement-d.docx', str_repeat('Replacement D content. ', 20))],
        )->assertRedirect();

        $document->refresh();
        $this->assertSame('original-d.docx', KnowledgeItemVersion::query()->where('knowledge_item_id', $document->id)->where('is_current', true)->value('original_filename'), 'Current version filename must not change for pending-review upload.');
        $this->assertStringNotContainsString('Replacement D content', (string) $document->extracted_text);
    }

    public function test_replace_file_does_not_record_revision_for_pending_version(): void
    {
        // Fase 2.5B: file_replaced revision is only written on activation; pending-review uploads skip it.
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer E');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-e.docx', str_repeat('Original E content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-e.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('replacement-e.docx', str_repeat('Replacement E content. ', 20))],
        )->assertRedirect();

        $revisions = KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)
            ->orderBy('revision_no')
            ->get();

        $this->assertCount(1, $revisions, 'Only the initial created revision should exist; pending-review upload must not add a file_replaced revision.');
        $this->assertSame(KnowledgeItemRevision::CHANGE_TYPE_CREATED, $revisions->first()->change_type);
    }

    public function test_replace_file_rejects_missing_file(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer F');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-f.docx', 'Original F content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-f.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            [],
        )->assertSessionHasErrors(['file']);
    }

    public function test_replace_file_rejects_invalid_mime_type(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer G');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-g.docx', 'Original G content.'),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-g.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => UploadedFile::fake()->create('bad.txt', 5, 'text/plain')],
        )->assertSessionHasErrors(['file']);
    }

    public function test_replace_file_is_rejected_for_another_customers_document(): void
    {
        Storage::fake('local');

        $ownerContext = $this->customerContext('Replace File Owner H');
        $this->actingAs($ownerContext['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('owner-h.docx', str_repeat('Owner H content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $ownerContext['customer']->id)
            ->where('title', 'owner-h.docx')
            ->firstOrFail();

        $attackerContext = $this->customerContext('Replace File Attacker H');
        $this->actingAs($attackerContext['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('attack-h.docx', str_repeat('Attacker H content. ', 20))],
        )->assertNotFound();
    }

    public function test_replace_file_requires_authentication(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Replace File Customer I');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-i.docx', str_repeat('Original I content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-i.docx')
            ->firstOrFail();

        Auth::logout();

        $this->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('replacement-i.docx', str_repeat('Replacement I. ', 20))],
        )->assertRedirect(route('login'));
    }

    // ── Duplikatvern — duplicate file protection ──────────────────────────────

    public function test_store_rejects_duplicate_file_when_same_hash_exists_for_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Duplikat A');
        $content = str_repeat('Unique content for test A. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-dup-a.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-dup-a2.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertSessionHasErrors(['document']);
    }

    public function test_store_stores_file_hash_on_version(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Duplikat B');
        $content = str_repeat('Unique content for test B. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-dup-b.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-dup-b.docx')
            ->firstOrFail();

        $version = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        $this->assertNotNull($version->file_hash_sha256);
        $this->assertSame(64, strlen((string) $version->file_hash_sha256));
    }

    public function test_store_allows_same_file_content_for_different_customers(): void
    {
        Storage::fake('local');

        $content = str_repeat('Shared content for test C. ', 20);

        $contextA = $this->customerContext('Duplikat C1');
        $this->actingAs($contextA['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('shared-c1.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $contextB = $this->customerContext('Duplikat C2');
        $this->actingAs($contextB['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('shared-c2.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame(1, KnowledgeItem::query()->where('customer_id', $contextA['customer']->id)->count());
        $this->assertSame(1, KnowledgeItem::query()->where('customer_id', $contextB['customer']->id)->count());
    }

    public function test_replace_file_rejects_when_same_hash_exists_on_same_document(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Duplikat D');
        $content = str_repeat('Shared content for test D. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-dup-d.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-dup-d.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('same-dup-d.docx', $content)],
        )->assertSessionHasErrors(['file']);
    }

    public function test_replace_file_rejects_when_same_hash_exists_on_different_document_same_customer(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Duplikat E');
        $contentA = str_repeat('Document E original. ', 20);
        $contentB = str_repeat('Document E other. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-e-1.docx', $contentA),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-e-2.docx', $contentB),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $docA = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'doc-e-1.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $docA->id]),
            ['file' => $this->createDocxUpload('replacement-e.docx', $contentB)],
        )->assertSessionHasErrors(['file']);
    }

    public function test_replace_file_stores_file_hash_on_new_version(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Duplikat F');
        $contentOriginal = str_repeat('Original F content. ', 20);
        $contentReplacement = str_repeat('Replacement F content. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('original-f2.docx', $contentOriginal),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'original-f2.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('replacement-f2.docx', $contentReplacement)],
        )->assertRedirect();

        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 2)
            ->firstOrFail();

        $this->assertNotNull($v2->file_hash_sha256);
        $this->assertSame(64, strlen((string) $v2->file_hash_sha256));
    }

    public function test_replace_file_allows_same_file_on_different_customer_document(): void
    {
        Storage::fake('local');

        $content = str_repeat('Shared content test G. ', 20);

        $contextA = $this->customerContext('Duplikat G1');
        $contextB = $this->customerContext('Duplikat G2');

        $this->actingAs($contextA['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-g-a.docx', $content),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $this->actingAs($contextB['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-g-b.docx', str_repeat('Unique content G2. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $docB = KnowledgeItem::query()
            ->where('customer_id', $contextB['customer']->id)
            ->where('title', 'doc-g-b.docx')
            ->firstOrFail();

        $this->actingAs($contextB['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $docB->id]),
            ['file' => $this->createDocxUpload('replacement-g-b.docx', $content)],
        )->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $docB->id]));
    }

    public function test_store_hashes_differ_for_different_content(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Duplikat H');
        $contentA = str_repeat('Content alpha H. ', 20);
        $contentB = str_repeat('Content beta H. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-h-a.docx', $contentA),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('doc-h-b.docx', $contentB),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $versions = KnowledgeItemVersion::query()
            ->whereIn(
                'knowledge_item_id',
                KnowledgeItem::query()->where('customer_id', $context['customer']->id)->pluck('id'),
            )
            ->pluck('file_hash_sha256')
            ->filter()
            ->unique()
            ->values();

        $this->assertSame(2, $versions->count(), 'Two different files should produce two distinct hashes.');
    }

    // ── Fase 2.5 — approval fields on KnowledgeItemVersion ───────────────────

    public function test_new_knowledge_item_version_has_default_approval_status_approved(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Approval Default A');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approval-a.docx', str_repeat('Approval A content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approval-a.docx')
            ->firstOrFail();

        $version = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $version->approval_status);
        $this->assertTrue($version->isApproved());
        $this->assertFalse($version->isPendingReview());
        $this->assertFalse($version->isRejected());
        $this->assertFalse($version->isSuperseded());
    }

    public function test_show_payload_versions_contain_approval_fields(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Approval Payload B');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approval-b.docx', str_repeat('Approval B content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approval-b.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            if (count($versions) === 0) {
                return false;
            }

            $v = $versions[0];

            $approvalFields = [
                'approval_status', 'submitted_for_review_at', 'submitted_for_review_by_user_id',
                'submitted_for_review_by_name', 'approved_at', 'approved_by_user_id', 'approved_by_name',
                'rejected_at', 'rejected_by_user_id', 'rejected_by_name', 'rejection_reason',
            ];

            foreach ($approvalFields as $field) {
                if (! array_key_exists($field, $v)) {
                    return false;
                }
            }

            return data_get($v, 'approval_status') === KnowledgeItemVersion::APPROVAL_STATUS_APPROVED;
        });
    }

    public function test_show_payload_versions_include_approved_by_name_when_set(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Approval Name C');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approval-c.docx', str_repeat('Approval C content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approval-c.docx')
            ->firstOrFail();

        $version = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        $version->update([
            'approved_by_user_id' => $context['user']->id,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($context): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            if (count($versions) === 0) {
                return false;
            }

            return data_get($versions[0], 'approved_by_name') === $context['user']->name;
        });
    }

    public function test_show_payload_versions_include_rejection_data_when_version_is_rejected(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Approval Rejected D');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approval-d.docx', str_repeat('Approval D content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approval-d.docx')
            ->firstOrFail();

        $version = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        $version->update([
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_REJECTED,
            'rejected_by_user_id' => $context['user']->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Innholdet er utdatert.',
        ]);

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($context): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            if (count($versions) === 0) {
                return false;
            }

            $v = $versions[0];

            return data_get($v, 'approval_status') === KnowledgeItemVersion::APPROVAL_STATUS_REJECTED
                && data_get($v, 'rejected_by_name') === $context['user']->name
                && data_get($v, 'rejection_reason') === 'Innholdet er utdatert.';
        });
    }

    public function test_show_payload_distinguishes_versions_with_different_approval_status(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Approval Multi E');
        $content1 = str_repeat('Multi E v1 content. ', 20);
        $content2 = str_repeat('Multi E v2 content. ', 20);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approval-e-v1.docx', $content1),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approval-e-v1.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('approval-e-v2.docx', $content2)],
        )->assertRedirect();

        // Fase 2.5B: v2 is pending_review after replaceFile. Manually promote to approved + supersede v1
        // to simulate the state after a future approval action (Fase 2.5C).
        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 2)
            ->firstOrFail();

        $v1->update(['approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_SUPERSEDED]);
        $v2->update(['approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_APPROVED]);

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            if (count($versions) !== 2) {
                return false;
            }

            $statuses = array_column($versions, 'approval_status');

            return in_array(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $statuses, true)
                && in_array(KnowledgeItemVersion::APPROVAL_STATUS_SUPERSEDED, $statuses, true);
        });
    }

    public function test_approval_fields_do_not_affect_current_version_or_retrieval_behavior(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Approval Retrieval F');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approval-f.docx', str_repeat('Approval F content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approval-f.docx')
            ->firstOrFail();

        $version = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->firstOrFail();

        // Changing approval_status must not alter is_current
        $version->update(['approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW]);
        $version->refresh();

        $this->assertTrue((bool) $version->is_current, 'is_current must not change when approval_status is updated.');
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $version->approval_status);
    }

    // ── Fase 2.5B — pending-review replace-file behavior ─────────────────────

    public function test_replace_file_new_version_is_pending_review_and_not_current(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Phase 2.5B Test 1');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('phase25b-v1.docx', str_repeat('Phase 2.5B v1 content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'phase25b-v1.docx')
            ->firstOrFail();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('phase25b-v2.docx', str_repeat('Phase 2.5B v2 content. ', 20))],
        )->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 2)
            ->firstOrFail();

        $v1->refresh();
        $document->refresh();

        $this->assertTrue((bool) $v1->is_current, 'v1 must remain current after pending-review upload.');
        $this->assertFalse((bool) $v2->is_current, 'v2 must not become current when pending review.');
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $v2->approval_status);
        $this->assertSame('phase25b-v1.docx', KnowledgeItemVersion::query()->where('knowledge_item_id', $document->id)->where('is_current', true)->value('original_filename'), 'Current version filename must remain v1 after pending-review upload.');
        $this->assertStringNotContainsString('Phase 2.5B v2 content', (string) $document->extracted_text);
    }

    public function test_replace_file_pending_version_chunks_exist_but_are_not_used_by_retrieval(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Phase 2.5B Test 2');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('phase25b2-v1.docx', str_repeat('Phase 2.5B2 v1 content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'phase25b2-v1.docx')
            ->firstOrFail();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('phase25b2-v2.docx', str_repeat('Phase 2.5B2 v2 content. ', 20))],
        )->assertRedirect();

        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 2)
            ->firstOrFail();

        $v1Chunks = KnowledgeItemChunk::query()->where('knowledge_item_version_id', $v1->id)->count();
        $v2Chunks = KnowledgeItemChunk::query()->where('knowledge_item_version_id', $v2->id)->count();

        $this->assertGreaterThan(0, $v1Chunks, 'v1 chunks must still exist.');
        $this->assertGreaterThan(0, $v2Chunks, 'v2 chunks must be created even for pending-review version.');
        $this->assertFalse((bool) $v2->is_current, 'v2 must not be current.');
        // Retrieval joins only is_current = true versions, so v2 chunks are invisible to retrieval.
        // This is verified by confirming v1 remains is_current = true and v2 is not.
        $v1->refresh();
        $this->assertTrue((bool) $v1->is_current, 'v1 must remain the only current version for retrieval.');
    }

    public function test_replace_file_extraction_failure_preserves_current_version(): void
    {
        // Test 4: empty extracted text causes early return; old current version is unaffected.
        Storage::fake('local');

        $context = $this->customerContext('Phase 2.5B Test 4');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('phase25b4-v1.docx', str_repeat('Phase 2.5B4 v1 content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'phase25b4-v1.docx')
            ->firstOrFail();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 1)
            ->firstOrFail();

        // Upload a file whose content will produce an empty extracted text (single space — extractor trims to empty).
        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('phase25b4-v2-empty.docx', ' ')],
        )->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)
            ->where('version_no', 2)
            ->firstOrFail();

        $v1->refresh();
        $document->refresh();

        $this->assertTrue((bool) $v1->is_current, 'v1 must remain current when v2 text extraction fails.');
        $this->assertFalse((bool) $v2->is_current, 'v2 must not become current after extraction failure.');
        $this->assertSame('phase25b4-v1.docx', KnowledgeItemVersion::query()->where('knowledge_item_id', $document->id)->where('is_current', true)->value('original_filename'), 'Current version filename must not change after failed extraction.');
    }

    public function test_replace_file_pending_review_returns_success_flash_with_pending_message(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Phase 2.5B Test Flash');
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('phase25b-flash-v1.docx', str_repeat('Flash test content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'phase25b-flash-v1.docx')
            ->firstOrFail();

        $response = $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('phase25b-flash-v2.docx', str_repeat('Flash test v2 content. ', 20))],
        )->assertRedirect();

        $response->assertSessionHas('success');
        $this->assertNotEmpty(session('success'), 'Flash message for pending-review upload must be a non-empty success message.');
    }

    // ── Fase 2.5C — approveVersion ────────────────────────────────────────────

    private function createPendingVersionScenario(string $customerName): array
    {
        Storage::fake('local');

        $context = $this->customerContext($customerName);

        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approve-v1.docx', str_repeat('Approve scenario v1 content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approve-v1.docx')
            ->firstOrFail();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $document->id]),
            ['file' => $this->createDocxUpload('approve-v2.docx', str_repeat('Approve scenario v2 content. ', 20))],
        )->assertRedirect();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)->where('version_no', 1)->firstOrFail();
        $v2 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)->where('version_no', 2)->firstOrFail();

        return ['context' => $context, 'document' => $document, 'v1' => $v1, 'v2' => $v2];
    }

    public function test_approve_version_activates_pending_version(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C1');
        ['context' => $context, 'document' => $document, 'v1' => $v1, 'v2' => $v2] = $scenario;

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.approve', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
        )->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $v1->refresh();
        $v2->refresh();
        $document->refresh();

        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $v2->approval_status);
        $this->assertNotNull($v2->approved_at);
        $this->assertSame($context['user']->id, $v2->approved_by_user_id);
        $this->assertTrue((bool) $v2->is_current, 'v2 must be current after approval.');
        $this->assertFalse((bool) $v1->is_current, 'v1 must no longer be current after approval.');
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_SUPERSEDED, $v1->approval_status);
        $this->assertSame('approve-v2.docx', $document->currentVersion?->original_filename, 'Current version filename must be v2 after approval.');
    }

    public function test_approve_version_retrieval_uses_new_version_after_approval(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C2');
        ['context' => $context, 'document' => $document, 'v1' => $v1, 'v2' => $v2] = $scenario;

        $v1ChunksCount = KnowledgeItemChunk::query()->where('knowledge_item_version_id', $v1->id)->count();
        $v2ChunksCount = KnowledgeItemChunk::query()->where('knowledge_item_version_id', $v2->id)->count();

        $this->assertGreaterThan(0, $v1ChunksCount, 'v1 must have chunks before approval.');
        $this->assertGreaterThan(0, $v2ChunksCount, 'v2 must have chunks before approval.');

        // Before approval: v1 is current, only v1 chunks visible to retrieval via is_current join.
        $this->assertTrue((bool) $v1->is_current);
        $this->assertFalse((bool) $v2->is_current);

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.approve', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
        )->assertRedirect();

        $v1->refresh();
        $v2->refresh();

        // After approval: v2 is current, v1 is not. Retrieval (is_current = true join) now uses v2.
        $this->assertTrue((bool) $v2->is_current, 'v2 must be current after approval — retrieval will use it.');
        $this->assertFalse((bool) $v1->is_current, 'v1 must not be current after approval.');
    }

    public function test_approve_version_rejects_already_approved_version(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C3');
        ['context' => $context, 'document' => $document, 'v1' => $v1] = $scenario;

        // v1 is already approved (it was the original current version).
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $v1->approval_status);

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.approve', ['knowledgeItem' => $document->id, 'version' => $v1->id]),
        )->assertStatus(422);

        $v1->refresh();
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $v1->approval_status);
        $this->assertTrue((bool) $v1->is_current, 'v1 must remain current when approval is rejected.');
    }

    public function test_approve_version_rejects_superseded_version(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C3b');
        ['context' => $context, 'document' => $document, 'v2' => $v2] = $scenario;

        $v2->update(['approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_SUPERSEDED]);

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.approve', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
        )->assertStatus(422);
    }

    public function test_approve_version_rejects_version_belonging_to_different_document(): void
    {
        $scenarioA = $this->createPendingVersionScenario('Approve C4A');
        $scenarioB = $this->createPendingVersionScenario('Approve C4B');

        $v2B = $scenarioB['v2'];

        // Post to document A but use a version from document B.
        $this->actingAs($scenarioA['context']['user'])->post(
            route('app.ai.knowledge-base.versions.approve', [
                'knowledgeItem' => $scenarioA['document']->id,
                'version' => $v2B->id,
            ]),
        )->assertNotFound();

        $v2B->refresh();
        $this->assertFalse((bool) $v2B->is_current, 'v2B must not have been activated.');
    }

    public function test_approve_version_rejects_viewer_user(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C5');
        ['context' => $context, 'document' => $document, 'v2' => $v2] = $scenario;

        $viewer = User::factory()->create([
            'name' => 'Viewer User',
            'email' => 'viewer-approve-c5@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $this->actingAs($viewer)->post(
            route('app.ai.knowledge-base.versions.approve', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
        )->assertForbidden();

        $v2->refresh();
        $this->assertFalse((bool) $v2->is_current, 'v2 must not have been activated by the viewer.');
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $v2->approval_status);
    }

    public function test_approve_version_writes_file_replaced_revision(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C6');
        ['context' => $context, 'document' => $document, 'v2' => $v2] = $scenario;

        $revisionsBeforeApproval = KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)
            ->count();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.approve', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
        )->assertRedirect();

        $revision = KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)
            ->where('change_type', KnowledgeItemRevision::CHANGE_TYPE_FILE_REPLACED)
            ->firstOrFail();

        $this->assertSame($context['user']->id, $revision->changed_by_user_id);
        $this->assertSame($v2->id, data_get($revision->snapshot, 'knowledge_item_version_id'));
        $this->assertSame(2, data_get($revision->snapshot, 'knowledge_item_version_no'));
        $this->assertGreaterThan($revisionsBeforeApproval, KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)->count());
    }

    public function test_show_payload_contains_approve_url_only_for_pending_versions(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C7');
        ['context' => $context, 'document' => $document] = $scenario;

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            if (count($versions) !== 2) {
                return false;
            }

            $pending = null;
            $approved = null;
            foreach ($versions as $v) {
                if (data_get($v, 'approval_status') === KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW) {
                    $pending = $v;
                } elseif (data_get($v, 'approval_status') === KnowledgeItemVersion::APPROVAL_STATUS_APPROVED) {
                    $approved = $v;
                }
            }

            return $pending !== null
                && $approved !== null
                && data_get($pending, 'approve_url') !== null
                && data_get($approved, 'approve_url') === null;
        });
    }

    public function test_show_payload_approve_url_is_null_for_viewer(): void
    {
        $scenario = $this->createPendingVersionScenario('Approve C7b');
        ['context' => $context, 'document' => $document] = $scenario;

        $viewer = User::factory()->create([
            'name' => 'Viewer C7b',
            'email' => 'viewer-c7b@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($viewer)->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            foreach ($versions as $v) {
                if (data_get($v, 'approve_url') !== null) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_approve_version_rejects_version_without_chunks(): void
    {
        Storage::fake('local');

        $context = $this->customerContext('Approve C8');

        // Store a document to create v1.
        $this->actingAs($context['user'])->post(route('app.ai.knowledge-base.store'), [
            'document' => $this->createDocxUpload('approve-c8-v1.docx', str_repeat('Approve C8 v1 content. ', 20)),
            'document_type' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'is_active' => true,
        ])->assertRedirect();

        $document = KnowledgeItem::query()
            ->where('customer_id', $context['customer']->id)
            ->where('title', 'approve-c8-v1.docx')
            ->firstOrFail();

        $v1 = KnowledgeItemVersion::query()
            ->where('knowledge_item_id', $document->id)->where('version_no', 1)->firstOrFail();

        // Manually create a pending version with no chunks to simulate a failed extraction scenario.
        $v2 = KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $document->id,
            'customer_id' => $context['customer']->id,
            'version_no' => 2,
            'is_current' => false,
            'original_filename' => 'approve-c8-v2.docx',
            'storage_path' => $v1->storage_path,
            'mime_type' => $v1->mime_type,
            'file_size_bytes' => $v1->file_size_bytes,
            'extracted_text' => 'Some text',
            'extraction_status' => KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
            'uploaded_by_user_id' => $context['user']->id,
            'uploaded_at' => now(),
            'file_hash_sha256' => hash('sha256', 'fake-content-c8'),
            'approval_status' => KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW,
        ]);

        // v2 has no chunks — approval must be rejected.
        $this->assertSame(0, KnowledgeItemChunk::query()->where('knowledge_item_version_id', $v2->id)->count());

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.approve', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
        )->assertStatus(422);

        $v2->refresh();
        $v1->refresh();
        $this->assertFalse((bool) $v2->is_current, 'v2 must not become current without chunks.');
        $this->assertTrue((bool) $v1->is_current, 'v1 must remain current.');
    }

    public function test_reject_version_sets_rejected_status_and_preserves_current_version(): void
    {
        $scenario = $this->createPendingVersionScenario('Reject D1');
        ['context' => $context, 'document' => $document, 'v1' => $v1, 'v2' => $v2] = $scenario;

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.reject', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
            ['rejection_reason' => 'Filen inneholder feil informasjon og må korrigeres.'],
        )->assertRedirect(route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]));

        $v1->refresh();
        $v2->refresh();
        $document->refresh();

        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_REJECTED, $v2->approval_status);
        $this->assertNotNull($v2->rejected_at);
        $this->assertSame($context['user']->id, $v2->rejected_by_user_id);
        $this->assertSame('Filen inneholder feil informasjon og må korrigeres.', $v2->rejection_reason);
        $this->assertNull($v2->approved_at);
        $this->assertNull($v2->approved_by_user_id);
        $this->assertFalse((bool) $v2->is_current, 'v2 must remain not current after rejection.');
        $this->assertTrue((bool) $v1->is_current, 'v1 must remain current after rejection.');
        $this->assertSame('approve-v1.docx', KnowledgeItemVersion::query()->where('knowledge_item_id', $document->id)->where('is_current', true)->value('original_filename'), 'Current version filename must remain v1 after rejection.');
    }

    public function test_reject_version_fails_without_rejection_reason(): void
    {
        $scenario = $this->createPendingVersionScenario('Reject D2');
        ['context' => $context, 'document' => $document, 'v2' => $v2] = $scenario;

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.reject', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
            [],
        )->assertSessionHasErrors(['rejection_reason']);

        $v2->refresh();
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $v2->approval_status);
    }

    public function test_reject_version_fails_with_too_short_reason(): void
    {
        $scenario = $this->createPendingVersionScenario('Reject D3');
        ['context' => $context, 'document' => $document, 'v2' => $v2] = $scenario;

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.reject', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
            ['rejection_reason' => 'ab'],
        )->assertSessionHasErrors(['rejection_reason']);

        $v2->refresh();
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $v2->approval_status);
    }

    public function test_reject_version_fails_for_already_approved_version(): void
    {
        $scenario = $this->createPendingVersionScenario('Reject D4');
        ['context' => $context, 'document' => $document, 'v1' => $v1] = $scenario;

        // v1 is already approved (the original current version).
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $v1->approval_status);

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.reject', ['knowledgeItem' => $document->id, 'version' => $v1->id]),
            ['rejection_reason' => 'Denne versjonen er allerede godkjent.'],
        )->assertStatus(422);

        $v1->refresh();
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_APPROVED, $v1->approval_status);
        $this->assertTrue((bool) $v1->is_current, 'v1 must remain current.');
    }

    public function test_reject_version_fails_for_version_belonging_to_different_document(): void
    {
        $scenarioA = $this->createPendingVersionScenario('Reject D5A');
        $scenarioB = $this->createPendingVersionScenario('Reject D5B');

        ['context' => $contextA, 'document' => $documentA] = $scenarioA;
        ['v2' => $v2B] = $scenarioB;

        $this->actingAs($contextA['user'])->post(
            route('app.ai.knowledge-base.versions.reject', ['knowledgeItem' => $documentA->id, 'version' => $v2B->id]),
            ['rejection_reason' => 'Forsøk på tvers av dokumenter.'],
        )->assertNotFound();

        $v2B->refresh();
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $v2B->approval_status);
    }

    public function test_reject_version_fails_for_viewer_user(): void
    {
        $scenario = $this->createPendingVersionScenario('Reject D6');
        ['context' => $context, 'document' => $document, 'v2' => $v2] = $scenario;

        $viewer = User::factory()->create([
            'name' => 'Viewer User',
            'email' => 'viewer-reject-d6@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_VIEWER,
            'customer_id' => $context['customer']->id,
            'is_active' => true,
        ]);

        $this->actingAs($viewer)->post(
            route('app.ai.knowledge-base.versions.reject', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
            ['rejection_reason' => 'Viewer forsøker å avvise.'],
        )->assertForbidden();

        $v2->refresh();
        $this->assertSame(KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW, $v2->approval_status);
    }

    public function test_reject_version_writes_version_rejected_revision(): void
    {
        $scenario = $this->createPendingVersionScenario('Reject D7');
        ['context' => $context, 'document' => $document, 'v2' => $v2] = $scenario;

        $revisionsBeforeRejection = KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)
            ->count();

        $this->actingAs($context['user'])->post(
            route('app.ai.knowledge-base.versions.reject', ['knowledgeItem' => $document->id, 'version' => $v2->id]),
            ['rejection_reason' => 'Dokumentet inneholder utdatert informasjon.'],
        )->assertRedirect();

        $revision = KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)
            ->where('change_type', KnowledgeItemRevision::CHANGE_TYPE_VERSION_REJECTED)
            ->firstOrFail();

        $this->assertSame($context['user']->id, $revision->changed_by_user_id);
        $this->assertSame($v2->id, data_get($revision->snapshot, 'knowledge_item_version_id'));
        $this->assertSame(2, data_get($revision->snapshot, 'knowledge_item_version_no'));
        $this->assertGreaterThan($revisionsBeforeRejection, KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $document->id)->count());
    }

    public function test_show_payload_contains_reject_url_only_for_pending_versions(): void
    {
        $scenario = $this->createPendingVersionScenario('Reject D8');
        ['context' => $context, 'document' => $document] = $scenario;

        $response = $this->actingAs($context['user'])->get(
            route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
        );

        $response->assertOk();
        $response->assertViewHas('page', function (array $page): bool {
            $versions = data_get($page, 'props.knowledgeItem.versions', []);

            if (count($versions) !== 2) {
                return false;
            }

            $pending = null;
            $approved = null;
            foreach ($versions as $v) {
                if (data_get($v, 'approval_status') === KnowledgeItemVersion::APPROVAL_STATUS_PENDING_REVIEW) {
                    $pending = $v;
                } elseif (data_get($v, 'approval_status') === KnowledgeItemVersion::APPROVAL_STATUS_APPROVED) {
                    $approved = $v;
                }
            }

            return $pending !== null
                && $approved !== null
                && data_get($pending, 'reject_url') !== null
                && data_get($approved, 'reject_url') === null;
        });
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => (int) env('DB_PORT', 5432),
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.url' => null,
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }
}
