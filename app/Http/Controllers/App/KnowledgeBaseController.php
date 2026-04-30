<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataGenerationService;
use App\Services\DocumentTextExtractor;
use App\Services\Knowledge\AiKnowledgeChunkBoundaryService;
use App\Services\Knowledge\KnowledgeChunkBoundaryValidator;
use App\Services\Knowledge\KnowledgeChunkBuilder;
use App\Services\Knowledge\KnowledgeDocumentStructureParser;
use App\Services\Ai\Knowledge\KnowledgeChunkVocabularyCandidateService;
use App\Services\KnowledgeChunkCoverageService;
use App\Services\OpenAi\EmbeddingService;
use App\Support\CustomerContext;
use App\Support\CosineSimilarity;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class KnowledgeBaseController extends Controller
{
    private const RULE_BASED_CHUNK_MAX_WORDS = 800;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly KnowledgeDocumentStructureParser $knowledgeDocumentStructureParser,
        private readonly AiKnowledgeChunkBoundaryService $aiKnowledgeChunkBoundaryService,
        private readonly KnowledgeChunkBoundaryValidator $knowledgeChunkBoundaryValidator,
        private readonly KnowledgeChunkBuilder $knowledgeChunkBuilder,
        private readonly KnowledgeChunkCoverageService $knowledgeChunkCoverageService,
        private readonly KnowledgeChunkMetadataGenerationService $knowledgeChunkMetadataGenerationService,
        private readonly KnowledgeChunkVocabularyCandidateService $knowledgeChunkVocabularyCandidateService,
    ) {
    }

    /**
     * Purpose: Render the customer knowledge document index within the AI area.
     * Inputs: The current frontend request.
     * Returns: An Inertia response with a customer-scoped knowledge document list.
     * Side effects: None.
     */
    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);

        $knowledgeDocuments = $this->scopedDocumentsQuery($customerId)
            ->with(['uploadedBy'])
            ->withCount('chunks')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (KnowledgeItem $knowledgeDocument): array => $this->documentListPayload($knowledgeDocument))
            ->values()
            ->all();

        return Inertia::render('App/AI/KnowledgeBase/Index', [
            'pageTitle' => 'Kunnskapsdokumenter',
            'knowledgeItems' => $knowledgeDocuments,
            'createUrl' => route('app.ai.knowledge-base.create'),
        ]);
    }

    /**
     * Purpose: Render the detailed view for one customer knowledge document.
     * Inputs: The current frontend request and the route-bound knowledge document.
     * Returns: An Inertia response with the document overview and chunk list.
     * Side effects: None.
     */
    public function show(Request $request, KnowledgeItem $knowledgeItem): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocumentsQuery($customerId)
            ->with([
                'uploadedBy',
                'chunks' => static fn ($query) => $query->orderBy('chunk_index'),
            ])
            ->withCount('chunks')
            ->whereKey($knowledgeItem->id)
            ->firstOrFail();
        return Inertia::render('App/AI/KnowledgeBase/Show', [
            'pageTitle' => 'Kunnskapsdokumenter · '.$record->original_filename,
            'knowledgeItem' => $this->documentDetailPayload($record),
            'indexUrl' => route('app.ai.knowledge-base.index'),
            'summaryUpdateUrl' => route('app.ai.knowledge-base.summary.update', ['knowledgeItem' => $record->id]),
            'editUrl' => route('app.ai.knowledge-base.edit', ['knowledgeItem' => $record->id]),
        ]);
    }

    /**
     * Purpose: Update the human-readable document summary for a visible knowledge document.
     * Inputs: The current frontend request and the route-bound knowledge document.
     * Returns: A redirect back to the detailed view of the document.
     * Side effects: Updates only the summary column in the database.
     */
    public function updateSummary(Request $request, KnowledgeItem $knowledgeItem): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocument($customerId, $knowledgeItem->id);
        $payload = $this->validatedSummaryPayload($request);

        $record->forceFill([
            'summary' => $payload['summary'],
        ])->save();

        return redirect()
            ->route('app.ai.knowledge-base.show', ['knowledgeItem' => $record->id])
            ->with('success', 'Dokumentoppsummering oppdatert.');
    }

    /**
     * Purpose: Update the review status for one knowledge chunk.
     * Inputs: The current frontend request, the parent knowledge document, and the chunk.
     * Returns: A redirect back to the detailed view of the document.
     * Side effects: Updates only the chunk review status in the database.
     */
    public function updateChunkReviewStatus(
        Request $request,
        KnowledgeItem $knowledgeItem,
        KnowledgeItemChunk $chunk,
    ): RedirectResponse {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocument($customerId, $knowledgeItem->id);
        $chunkRecord = $this->scopedChunk($record->id, $chunk->id);
        $payload = $this->validatedChunkReviewStatusPayload($request);

        $chunkRecord->forceFill([
            'review_status' => $payload['review_status'],
        ])->save();

        return redirect()
            ->route('app.ai.knowledge-base.show', ['knowledgeItem' => $record->id])
            ->with('success', 'Chunk-status oppdatert.');
    }

    /**
     * Purpose: Update the product metadata for one knowledge chunk.
     * Inputs: The current frontend request, the parent knowledge document, and the chunk.
     * Returns: A redirect back to the detailed view of the document.
     * Side effects: Updates only the editable chunk metadata fields in the database.
     */
    public function updateChunkMetadata(
        Request $request,
        KnowledgeItem $knowledgeItem,
        KnowledgeItemChunk $chunk,
    ): RedirectResponse {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocument($customerId, $knowledgeItem->id);
        $chunkRecord = $this->scopedChunk($record->id, $chunk->id);
        $payload = $this->validatedChunkMetadataPayload($request);

        $chunkRecord->forceFill([
            'title' => $payload['title'],
            'ai_summary' => $payload['ai_summary'],
            'service_product_tag' => $payload['service_product_tag'],
            'theme_tag' => $payload['theme_tag'],
            'topic' => $payload['topic'],
            'sub_topic' => $payload['sub_topic'],
            'keywords' => $payload['keywords'],
        ])->save();

        return redirect()
            ->route('app.ai.knowledge-base.show', ['knowledgeItem' => $record->id])
            ->with('success', 'Chunk-metadata oppdatert.');
    }

    /**
     * Purpose: Render the knowledge document upload form.
     * Inputs: The current frontend request.
     * Returns: An Inertia response for the create page.
     * Side effects: None.
     */
    public function create(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);

        return Inertia::render('App/AI/KnowledgeBase/Create', [
            'pageTitle' => 'Kunnskapsdokumenter · Last opp',
            'documentTypeOptions' => $this->documentTypeOptions(),
            'defaultDocumentType' => KnowledgeItem::DOCUMENT_TYPE_OTHER,
            'storeUrl' => route('app.ai.knowledge-base.store'),
            'indexUrl' => route('app.ai.knowledge-base.index'),
        ]);
    }

    /**
     * Purpose: Persist one uploaded knowledge document and regenerate its chunks.
     * Inputs: The current frontend request.
     * Returns: A redirect back to the knowledge document index.
     * Side effects: Stores a file on disk and creates a customer-scoped knowledge document row.
     */
    public function store(Request $request): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $payload = $this->validatedStorePayload($request);
        $storedPath = null;

        try {
            $document = $payload['document'];
            $storedFilename = $this->storedFilename($document->getClientOriginalExtension());
            $storedPath = Storage::disk('local')->putFileAs(
                sprintf('customers/%d/knowledge-documents', $customerId),
                $document,
                $storedFilename,
            );

            abort_unless(is_string($storedPath) && $storedPath !== '', 500, 'Failed to store the knowledge document.');

            $absolutePath = Storage::disk('local')->path($storedPath);
            $extractedText = $this->documentTextExtractor->extractText($absolutePath);
            $extractionFailed = trim($extractedText) === '';
            $structure = $this->knowledgeDocumentStructureParser->parse($absolutePath);
            $chunkPayloads = $this->buildRuleBasedH2ChunkPayloads($structure);

            Log::info('[PROCYNIA][KNOWLEDGE_CHUNKING] Rule-based H2 chunk payloads built without AI boundary.', [
                'customer_id' => $customerId,
                'document_title' => $document->getClientOriginalName(),
                'chunk_payload_count' => count($chunkPayloads),
            ]);

            $result = DB::transaction(function () use (
                $customerId,
                $payload,
                $request,
                $storedPath,
                $extractedText,
                $chunkPayloads,
                $extractionFailed,
            ): array {
                $knowledgeDocument = KnowledgeItem::query()->create([
                    'customer_id' => $customerId,
                    'uploaded_by_user_id' => $request->user()?->id,
                    'title' => $payload['document']->getClientOriginalName(),
                    'content' => $extractedText !== ''
                        ? $extractedText
                        : $payload['document']->getClientOriginalName(),
                    'original_filename' => $payload['document']->getClientOriginalName(),
                    'storage_path' => $storedPath,
                    'mime_type' => $payload['document']->getClientMimeType(),
                    'file_size_bytes' => (int) $payload['document']->getSize(),
                    'content_type' => $payload['document_type'],
                    'document_type' => $payload['document_type'],
                    'extracted_text' => $extractedText,
                    'extraction_status' => $extractionFailed
                        ? KnowledgeItem::EXTRACTION_STATUS_FAILED
                        : KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
                    'extraction_error' => $extractionFailed
                        ? 'Tekst kunne ikke trekkes ut fra dokumentet.'
                        : null,
                    'is_active' => $payload['is_active'],
                ]);

                return [
                    'knowledge_document' => $knowledgeDocument,
                    'chunks' => $this->syncChunks($knowledgeDocument, $chunkPayloads),
                ];
            });

            $this->syncChunkEmbeddings($result['knowledge_document'], $result['chunks']);
        } catch (Throwable $throwable) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            throw $throwable;
        }

        return redirect()
            ->route('app.ai.knowledge-base.index')
            ->with('success', 'Kunnskapsdokument lastet opp.');
    }

    /**
     * Purpose: Render the edit form for a visible knowledge document.
     * Inputs: The current frontend request and the route-bound knowledge document.
     * Returns: An Inertia response for the edit page.
     * Side effects: None.
     */
    public function edit(Request $request, KnowledgeItem $knowledgeItem): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocument($customerId, $knowledgeItem->id);

        return Inertia::render('App/AI/KnowledgeBase/Edit', [
            'pageTitle' => 'Kunnskapsdokumenter · Rediger',
            'knowledgeItem' => $this->documentFormPayload($record),
            'documentTypeOptions' => $this->documentTypeOptions(),
            'updateUrl' => route('app.ai.knowledge-base.update', ['knowledgeItem' => $record->id]),
            'deleteUrl' => route('app.ai.knowledge-base.destroy', ['knowledgeItem' => $record->id]),
            'indexUrl' => route('app.ai.knowledge-base.index'),
        ]);
    }

    /**
     * Purpose: Update the metadata for a visible knowledge document.
     * Inputs: The current frontend request and the route-bound knowledge document.
     * Returns: A redirect back to the knowledge document index.
     * Side effects: Updates the document metadata in the database.
     */
    public function update(Request $request, KnowledgeItem $knowledgeItem): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocument($customerId, $knowledgeItem->id);
        $payload = $this->validatedUpdatePayload($request);

        $record->forceFill([
            'document_type' => $payload['document_type'],
            'content_type' => $payload['document_type'],
            'is_active' => $payload['is_active'],
        ])->save();

        return redirect()
            ->route('app.ai.knowledge-base.index')
            ->with('success', 'Kunnskapsdokument oppdatert.');
    }

    /**
     * Purpose: Delete a visible knowledge document.
     * Inputs: The current frontend request and the route-bound knowledge document.
     * Returns: A redirect back to the knowledge document index.
     * Side effects: Deletes the knowledge document row, its chunks, and the stored file when present.
     */
    public function destroy(Request $request, KnowledgeItem $knowledgeItem): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocument($customerId, $knowledgeItem->id);
        $storedPath = $record->storage_path;

        DB::transaction(function () use ($record): void {
            $record->chunks()->delete();
            $record->delete();
        });

        if (is_string($storedPath) && $storedPath !== '') {
            Storage::disk('local')->delete($storedPath);
        }

        return redirect()
            ->route('app.ai.knowledge-base.index')
            ->with('success', 'Kunnskapsdokument slettet.');
    }

    /**
     * Purpose: Resolve the authenticated customer context for knowledge document access.
     * Inputs: Incoming request carrying the current authenticated user.
     * Returns: The current user and customer id.
     * Side effects: Aborts with HTTP 403 if the customer context is unavailable.
     */
    private function frontendContext(Request $request): array
    {
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $user->canAccessCustomerFrontend()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    /**
     * Purpose: Scope the knowledge document listing to the current customer.
     * Inputs: The current customer id.
     * Returns: A query builder constrained to the customer.
     * Side effects: None.
     */
    private function scopedDocumentsQuery(int $customerId): Builder
    {
        return KnowledgeItem::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('storage_path');
    }

    /**
     * Purpose: Resolve one visible knowledge document for the current customer.
     * Inputs: The current customer id and the route-bound knowledge document id.
     * Returns: The matching knowledge document record.
     * Side effects: Throws a 404 when the item is outside the current customer scope.
     */
    private function scopedDocument(int $customerId, int $knowledgeItemId): KnowledgeItem
    {
        return $this->scopedDocumentsQuery($customerId)
            ->whereKey($knowledgeItemId)
            ->firstOrFail();
    }

    /**
     * Purpose: Resolve one visible knowledge chunk for the current customer.
     * Inputs: The knowledge document id and the route-bound chunk id.
     * Returns: The matching knowledge chunk record.
     * Side effects: Throws a 404 when the chunk is outside the current document scope.
     */
    private function scopedChunk(int $knowledgeItemId, int $knowledgeItemChunkId): KnowledgeItemChunk
    {
        return KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $knowledgeItemId)
            ->whereKey($knowledgeItemChunkId)
            ->firstOrFail();
    }

    /**
     * Purpose: Validate and normalize the upload payload for a knowledge document.
     * Inputs: The current frontend request.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedStorePayload(Request $request): array
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,docx,xlsx', 'max:20480'],
            'document_type' => ['required', 'string', Rule::in(KnowledgeItem::DOCUMENT_TYPES)],
            'is_active' => ['required', 'boolean'],
        ]);

        return [
            'document' => $validated['document'],
            'document_type' => Str::lower(trim((string) $validated['document_type'])),
            'is_active' => (bool) $validated['is_active'],
        ];
    }

    /**
     * Purpose: Validate and normalize the metadata update payload for a knowledge document.
     * Inputs: The current frontend request.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedUpdatePayload(Request $request): array
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(KnowledgeItem::DOCUMENT_TYPES)],
            'is_active' => ['required', 'boolean'],
        ]);

        return [
            'document_type' => Str::lower(trim((string) $validated['document_type'])),
            'is_active' => (bool) $validated['is_active'],
        ];
    }

    /**
     * Purpose: Validate and normalize the summary update payload for a knowledge document.
     * Inputs: The current frontend request.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedSummaryPayload(Request $request): array
    {
        $validated = $request->validate([
            'summary' => ['nullable', 'string', 'max:20000'],
        ]);

        $summary = trim((string) ($validated['summary'] ?? ''));

        return [
            'summary' => $summary !== '' ? $summary : null,
        ];
    }

    /**
     * Purpose: Validate and normalize the review status payload for one knowledge chunk.
     * Inputs: The current frontend request.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedChunkReviewStatusPayload(Request $request): array
    {
        $validated = $request->validate([
            'review_status' => ['required', 'string', Rule::in(KnowledgeItemChunk::REVIEW_STATUSES)],
        ]);

        return [
            'review_status' => Str::lower(trim((string) $validated['review_status'])),
        ];
    }

    /**
     * Purpose: Validate and normalize the editable product metadata for one knowledge chunk.
     * Inputs: The current frontend request.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedChunkMetadataPayload(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'ai_summary' => ['nullable', 'string', 'max:20000'],
            'service_product_tag' => ['nullable', 'string', 'max:191'],
            'theme_tag' => ['nullable', 'string', 'max:191'],
            'topic' => ['nullable', 'string', 'max:191'],
            'sub_topic' => ['nullable', 'string', 'max:191'],
            'keywords' => ['nullable', 'string', 'max:4000'],
        ]);

        return [
            'title' => $this->cleanNullableString($validated['title'] ?? null, 255),
            'ai_summary' => $this->cleanNullableString($validated['ai_summary'] ?? null, 20000),
            'service_product_tag' => $this->cleanNullableString($validated['service_product_tag'] ?? null, 191),
            'theme_tag' => $this->cleanNullableString($validated['theme_tag'] ?? null, 191),
            'topic' => $this->cleanNullableString($validated['topic'] ?? null, 191),
            'sub_topic' => $this->cleanNullableString($validated['sub_topic'] ?? null, 191),
            'keywords' => $this->knowledgeChunkCoverageService->normalizeKeywords($validated['keywords'] ?? null),
        ];
    }

    /**
     * Purpose: Build the document type options used by the knowledge document forms.
     * Inputs: None.
     * Returns: An ordered list of document type options.
     * Side effects: None.
     */
    private function documentTypeOptions(): array
    {
        return array_map(
            static fn (string $documentType): array => [
                'value' => $documentType,
                'label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$documentType] ?? $documentType,
            ],
            KnowledgeItem::DOCUMENT_TYPES,
        );
    }

    /**
     * Purpose: Convert a knowledge document into a compact index payload.
     * Inputs: A customer-scoped knowledge document with chunk counts loaded.
     * Returns: An array ready for the index page.
     * Side effects: None.
     */
    private function documentListPayload(KnowledgeItem $knowledgeDocument): array
    {
        return [
            'id' => $knowledgeDocument->id,
            'original_filename' => $knowledgeDocument->original_filename,
            'document_type' => $knowledgeDocument->document_type,
            'document_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocument->document_type] ?? $knowledgeDocument->document_type,
            'content_type' => $knowledgeDocument->document_type,
            'content_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocument->document_type] ?? $knowledgeDocument->document_type,
            'content_excerpt' => $this->contentExcerpt($knowledgeDocument),
            'summary' => $knowledgeDocument->summary,
            'is_active' => (bool) $knowledgeDocument->is_active,
            'is_active_label' => $knowledgeDocument->is_active ? 'Aktiv' : 'Inaktiv',
            'extraction_status' => $knowledgeDocument->extraction_status,
            'extraction_status_label' => KnowledgeItem::EXTRACTION_STATUS_LABELS[$knowledgeDocument->extraction_status] ?? $knowledgeDocument->extraction_status,
            'extraction_error' => $knowledgeDocument->extraction_error,
            'chunk_count' => (int) ($knowledgeDocument->chunks_count ?? $knowledgeDocument->chunks->count()),
            'file_size_bytes' => $knowledgeDocument->file_size_bytes,
            'file_size_human' => $this->humanFileSize($knowledgeDocument->file_size_bytes),
            'uploaded_at' => optional($knowledgeDocument->created_at)?->toIso8601String(),
            'updated_at' => optional($knowledgeDocument->updated_at)?->toIso8601String(),
            'uploaded_by' => $knowledgeDocument->uploadedBy?->name,
            'mime_type' => $knowledgeDocument->mime_type,
            'show_url' => route('app.ai.knowledge-base.show', ['knowledgeItem' => $knowledgeDocument->id]),
            'edit_url' => route('app.ai.knowledge-base.edit', ['knowledgeItem' => $knowledgeDocument->id]),
            'delete_url' => route('app.ai.knowledge-base.destroy', ['knowledgeItem' => $knowledgeDocument->id]),
        ];
    }

    /**
     * Purpose: Convert a knowledge document into the edit form payload.
     * Inputs: A customer-scoped knowledge document.
     * Returns: A frontend-ready array for the edit page.
     * Side effects: None.
     */
    private function documentFormPayload(KnowledgeItem $knowledgeDocument): array
    {
        return [
            'id' => $knowledgeDocument->id,
            'original_filename' => $knowledgeDocument->original_filename,
            'document_type' => $knowledgeDocument->document_type,
            'content_type' => $knowledgeDocument->document_type,
            'document_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocument->document_type] ?? $knowledgeDocument->document_type,
            'content_type_label' => KnowledgeItem::CONTENT_TYPE_LABELS[$knowledgeDocument->content_type] ?? $knowledgeDocument->content_type,
            'content_excerpt' => $this->contentExcerpt($knowledgeDocument),
            'summary' => $knowledgeDocument->summary,
            'is_active' => (bool) $knowledgeDocument->is_active,
            'is_active_label' => $knowledgeDocument->is_active ? 'Aktiv' : 'Inaktiv',
            'file_size_bytes' => $knowledgeDocument->file_size_bytes,
            'file_size_human' => $this->humanFileSize($knowledgeDocument->file_size_bytes),
            'uploaded_at' => optional($knowledgeDocument->created_at)?->toIso8601String(),
            'updated_at' => optional($knowledgeDocument->updated_at)?->toIso8601String(),
            'uploaded_by' => $knowledgeDocument->uploadedBy?->name,
            'mime_type' => $knowledgeDocument->mime_type,
            'extraction_status' => $knowledgeDocument->extraction_status,
            'extraction_status_label' => KnowledgeItem::EXTRACTION_STATUS_LABELS[$knowledgeDocument->extraction_status] ?? $knowledgeDocument->extraction_status,
            'extraction_error' => $knowledgeDocument->extraction_error,
            'chunk_count' => $knowledgeDocument->chunks->count(),
            'show_url' => route('app.ai.knowledge-base.show', ['knowledgeItem' => $knowledgeDocument->id]),
        ];
    }

    /**
     * Purpose: Convert a knowledge document into the detailed view payload.
     * Inputs: A customer-scoped knowledge document.
     * Returns: A frontend-ready array for the detail page.
     * Side effects: None.
     */
    private function documentDetailPayload(KnowledgeItem $knowledgeDocument): array
    {
        return array_merge(
            $this->documentFormPayload($knowledgeDocument),
            [
                'show_url' => route('app.ai.knowledge-base.show', ['knowledgeItem' => $knowledgeDocument->id]),
                'edit_url' => route('app.ai.knowledge-base.edit', ['knowledgeItem' => $knowledgeDocument->id]),
                'index_url' => route('app.ai.knowledge-base.index'),
                'chunks' => $knowledgeDocument->chunks
                    ->map(static fn (KnowledgeItemChunk $chunk): array => [
                        'id' => $chunk->id,
                        'chunk_index' => (int) $chunk->chunk_index,
                        'title' => $chunk->title,
                        'fallback_title' => sprintf('Chunk %d', $chunk->chunk_index + 1),
                        'content' => (string) $chunk->content,
                        'content_preview' => Str::limit(Str::squish((string) $chunk->content), 320, '...'),
                        'review_status' => $chunk->review_status ?: KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
                        'review_status_label' => KnowledgeItemChunk::REVIEW_STATUS_LABELS[$chunk->review_status ?: KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW] ?? ($chunk->review_status ?: KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW),
                        'review_status_update_url' => route('app.ai.knowledge-base.chunks.review-status.update', [
                            'knowledgeItem' => $knowledgeDocument->id,
                            'chunk' => $chunk->id,
                        ]),
                        'metadata_update_url' => route('app.ai.knowledge-base.chunks.metadata.update', [
                            'knowledgeItem' => $knowledgeDocument->id,
                            'chunk' => $chunk->id,
                        ]),
                        'ai_summary' => $chunk->ai_summary,
                        'service_product_tag' => $chunk->service_product_tag,
                        'theme_tag' => $chunk->theme_tag,
                        'topic' => $chunk->topic,
                        'sub_topic' => $chunk->sub_topic,
                        'keywords' => $chunk->keywords,
                        'section_title' => $chunk->section_title,
                        'section_path' => $chunk->section_path,
                        'heading_path' => $chunk->heading_path,
                        'chunk_type' => $chunk->chunk_type,
                        'matched_terms' => $chunk->matched_terms,
                        'summary_for_retrieval' => $chunk->summary_for_retrieval,
                        'confidence_score' => $chunk->confidence_score,
                        'metadata_status' => $chunk->metadata_status,
                        'start_offset' => (int) $chunk->start_offset,
                        'end_offset' => (int) $chunk->end_offset,
                        'embedding_model' => $chunk->embedding_model,
                        'embedding_generated_at' => optional($chunk->embedding_generated_at)?->toIso8601String(),
                        'embedding_error' => $chunk->embedding_error,
                        'table_markdown' => $chunk->table_markdown,
                        'table_text' => $chunk->table_text,
                        'table_metadata' => $chunk->table_metadata,
                        'table_json' => $chunk->table_json,
                        'table_html' => $chunk->table_html,
                        'table_complexity' => $chunk->table_complexity,
                        'table_warnings' => $chunk->table_warnings,
                        'source_filename' => $knowledgeDocument->original_filename,
                        'source_filetype' => $knowledgeDocument->mime_type,
                        'knowledge_item_id' => $knowledgeDocument->id,
                    ])
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * Purpose: Build deterministic chunk payloads directly from parser output for controlled rule-based chunking.
     * Inputs: The parsed knowledge document structure containing source_text and structural elements.
     * Returns: Ordered chunk payloads for H1-only sections, H2 sections, and block-based subchunks when a section is oversized.
     * Side effects: None.
     */
    private function buildRuleBasedH2ChunkPayloads(array $structure): array
    {
        $sourceText = (string) data_get($structure, 'source_text', '');
        $sourceTextLength = mb_strlen($sourceText, 'UTF-8');
        $elements = array_values(array_filter(
            (array) data_get($structure, 'elements', []),
            static fn ($element): bool => is_array($element),
        ));
        $chunkRanges = [];
        $structuralCandidateCount = 0;
        $skippedOrEmptyRangesCount = 0;
        $duplicateRangeCount = 0;
        $overlappingRangeCount = 0;
        $coveredCharacterCount = 0;

        foreach ($this->groupElementsByPrimaryHeading($elements) as $group) {
            foreach ($this->buildRuleBasedChunkRangesForPrimaryHeadingGroup($group) as $chunkRange) {
                $startOffset = (int) ($chunkRange['start_offset'] ?? 0);
                $endOffset = (int) ($chunkRange['end_offset'] ?? 0);
                $candidateIndex = $structuralCandidateCount++;
                $chunkKind = (string) ($chunkRange['chunk_kind'] ?? 'h2_section');
                $headingPath = $this->cleanNullableString($chunkRange['heading_path'] ?? null, 255);
                $sectionTitle = $this->cleanNullableString($chunkRange['section_title'] ?? null, 255) ?? $headingPath;
                $sectionPath = $this->cleanNullableString($chunkRange['section_path'] ?? null, 255) ?? $sectionTitle;
                $rawCandidateContent = mb_substr($sourceText, $startOffset, max(0, $endOffset - $startOffset), 'UTF-8');
                $candidateContent = trim($rawCandidateContent);
                $candidateContentLength = mb_strlen($candidateContent, 'UTF-8');
                $candidateWordCount = count(preg_split('/\s+/u', $candidateContent, -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $willSplit = $chunkKind !== 'table' && $candidateWordCount > self::RULE_BASED_CHUNK_MAX_WORDS;

                $chunkRange['candidate_index'] = $candidateIndex;
                $chunkRange['content_length'] = $candidateContentLength;
                $chunkRange['word_count'] = $candidateWordCount;
                $chunkRange['will_split'] = $willSplit;
                $chunkRange['excerpt'] = mb_substr($candidateContent, 0, 120, 'UTF-8');
                $chunkRange['section_title'] = $sectionTitle;
                $chunkRange['section_path'] = $sectionPath;
                $chunkRange['heading_path'] = $headingPath ?? $sectionTitle;

                if ($willSplit) {
                    $blocks = [];
                    $rawBlocks = preg_split('/\n{2,}/u', $rawCandidateContent, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $blockCursor = 0;

                    foreach ($rawBlocks as $blockIndex => $blockText) {
                        $blockText = trim((string) $blockText);

                        if ($blockText === '') {
                            continue;
                        }

                        $relativeStart = mb_strpos($rawCandidateContent, $blockText, $blockCursor, 'UTF-8');

                        if ($relativeStart === false) {
                            $relativeStart = $blockCursor;
                        }

                        $relativeEnd = $relativeStart + mb_strlen($blockText, 'UTF-8');
                        $blockCursor = $relativeEnd;

                        $blocks[] = [
                            'block_index' => $blockIndex,
                            'relative_start' => $relativeStart,
                            'relative_end' => $relativeEnd,
                            'absolute_start_offset' => $startOffset + $relativeStart,
                            'absolute_end_offset' => $startOffset + $relativeEnd,
                            'word_count' => count(preg_split('/\s+/u', $blockText, -1, PREG_SPLIT_NO_EMPTY) ?: []),
                            'starts_with' => mb_substr($blockText, 0, 80, 'UTF-8'),
                        ];
                    }

                    $subChunkRanges = $this->buildRuleBasedSubChunksFromBlocks($chunkRange, $blocks, $sourceText);

                    foreach ($subChunkRanges as $subChunkRange) {
                        $chunkRanges[] = $subChunkRange;
                    }

                    continue;
                }

                $chunkRanges[] = $chunkRange;
            }
        }

        $chunkRanges = $this->subtractTableRangesFromSemanticRanges($chunkRanges);

        if ($chunkRanges !== []) {
            usort(
                $chunkRanges,
                static function (array $left, array $right): int {
                    $startComparison = ((int) ($left['start_offset'] ?? 0)) <=> ((int) ($right['start_offset'] ?? 0));

                    if ($startComparison !== 0) {
                        return $startComparison;
                    }

                    return ((int) ($left['end_offset'] ?? 0)) <=> ((int) ($right['end_offset'] ?? 0));
                },
            );

            $chunkPayloads = [];
            $seenRanges = [];
            $lastAcceptedEndOffset = null;

            foreach ($chunkRanges as $chunkRange) {
                $startOffset = (int) ($chunkRange['start_offset'] ?? 0);
                $endOffset = (int) ($chunkRange['end_offset'] ?? 0);
                $partIndex = isset($chunkRange['part_index']) ? (int) $chunkRange['part_index'] : null;

                if ($endOffset <= $startOffset) {
                    $skippedOrEmptyRangesCount++;
                    continue;
                }

                $rangeSignature = $startOffset.'-'.$endOffset;

                if (isset($seenRanges[$rangeSignature])) {
                    $duplicateRangeCount++;
                    continue;
                }

                $seenRanges[$rangeSignature] = true;

                if ($lastAcceptedEndOffset !== null && $startOffset < $lastAcceptedEndOffset) {
                    $overlappingRangeCount++;
                }

                $rawContent = mb_substr($sourceText, $startOffset, $endOffset - $startOffset, 'UTF-8');
                $content = trim($rawContent);

                if ($content === '') {
                    $skippedOrEmptyRangesCount++;
                    continue;
                }

                $headingPath = $this->cleanNullableString($chunkRange['heading_path'] ?? null, 255);
                $chunkKind = (string) ($chunkRange['chunk_kind'] ?? 'h2_section');

                if ($chunkKind === 'table') {
                    $tableJson = is_array($chunkRange['table_json'] ?? null) ? $chunkRange['table_json'] : [];
                    $tableText = trim((string) ($chunkRange['table_text'] ?? (string) data_get($tableJson, 'table_text', '')));
                    // Purpose: Keep markdown only as a legacy fallback. table_json and table_html are the primary table representations.
                    $tableMarkdown = trim((string) ($chunkRange['table_markdown'] ?? (string) data_get($tableJson, 'table_markdown', '')));
                    $tableHtml = trim((string) ($chunkRange['table_html'] ?? (string) data_get($tableJson, 'table_html', '')));
                    $tableComplexity = $this->cleanNullableString($chunkRange['table_complexity'] ?? data_get($tableJson, 'complexity'), 32) ?? 'complex';
                    $tableWarnings = array_values(array_unique(array_filter(array_map(
                        static fn ($warning): string => trim((string) ($warning ?? '')),
                        (array) ($chunkRange['table_warnings'] ?? data_get($tableJson, 'warnings', [])),
                    ), static fn (string $warning): bool => $warning !== '')));
                    $tableLabel = $this->cleanNullableString($chunkRange['title'] ?? null, 255) ?? 'Tabell';
                    $tableSectionPath = $this->cleanNullableString($chunkRange['section_path'] ?? null, 255) ?? $headingPath;
                    $tableTitleText = $this->tableTitleFromJson($tableJson);
                    $tableContentParts = [];

                    if ($tableSectionPath !== null && $tableSectionPath !== '') {
                        $tableContentParts[] = $tableSectionPath;
                    }

                    if ($tableTitleText !== null && $tableTitleText !== '' && $tableTitleText !== $tableLabel) {
                        $tableContentParts[] = $tableTitleText;
                    } else {
                        $tableContentParts[] = $tableLabel;
                    }

                    if ($tableText !== '') {
                        $tableContentParts[] = $tableText;
                    }

                    $content = trim(implode("\n\n", $tableContentParts));
                    $wordCount = count(preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                    $contentLength = mb_strlen($content, 'UTF-8');

                    if ($wordCount > self::RULE_BASED_CHUNK_MAX_WORDS && ! in_array('large_table_not_split', $tableWarnings, true)) {
                        $tableWarnings[] = 'large_table_not_split';
                    }

                    $coveredCharacterCount += $endOffset - $startOffset;
                    $lastAcceptedEndOffset = $endOffset;

                    $chunkPayloads[] = [
                        'content' => $content,
                        'start_offset' => $startOffset,
                        'end_offset' => $endOffset,
                        'section_title' => $headingPath,
                        'section_path' => $tableSectionPath ?? $headingPath,
                        'heading_path' => $headingPath,
                        'chunk_type' => 'table',
                        'title' => $tableLabel,
                        'part_index' => $partIndex,
                        'topic' => null,
                        'sub_topic' => null,
                        'keywords' => null,
                        'table_json' => $tableJson !== [] ? $tableJson : null,
                        'table_html' => $tableHtml !== '' ? $tableHtml : null,
                        'table_markdown' => $tableMarkdown !== '' ? $tableMarkdown : null,
                        'table_text' => $tableText !== '' ? $tableText : null,
                        'table_complexity' => $tableComplexity !== '' ? $tableComplexity : null,
                        'table_warnings' => $tableWarnings,
                        'table_metadata' => [
                            'source' => 'docx_table',
                            'heading_path' => $headingPath,
                            'heading_title' => $this->cleanNullableString($tableTitleText ?? null, 255) ?? $tableLabel,
                            'section_path' => $tableSectionPath ?? $headingPath,
                            'row_count' => (int) data_get($tableJson, 'row_count', 0),
                            'column_count' => (int) data_get($tableJson, 'column_count', 0),
                            'table_index_in_document' => (int) data_get($tableJson, 'source_metadata.table_index_in_document', data_get($chunkRange, 'table_index_in_document', 0)),
                            'source_table_start_offset' => $startOffset,
                            'source_table_end_offset' => $endOffset,
                            'split_part' => null,
                            'split_total' => null,
                            'original_row_count' => (int) data_get($tableJson, 'row_count', 0),
                            'rows_in_part' => (int) data_get($tableJson, 'row_count', 0),
                            'table_complexity' => $tableComplexity,
                            'table_warnings' => $tableWarnings,
                        ],
                    ];

                    continue;
                }

                if ($chunkKind === 'h1_section' && $partIndex === null && $headingPath !== null && $headingPath !== '') {
                    $content = trim($headingPath."\n\n".$content);
                }

                $wordCount = count(preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $contentLength = mb_strlen($content, 'UTF-8');

                $coveredCharacterCount += $endOffset - $startOffset;
                $lastAcceptedEndOffset = $endOffset;

                $chunkPayloads[] = [
                    'content' => $content,
                    'start_offset' => $startOffset,
                    'end_offset' => $endOffset,
                    'section_title' => $headingPath,
                    'section_path' => $this->cleanNullableString($chunkRange['section_path'] ?? null, 255) ?? $headingPath,
                    'heading_path' => $headingPath,
                    'chunk_type' => 'semantic',
                    'title' => $headingPath,
                    'part_index' => $partIndex,
                    'topic' => null,
                    'sub_topic' => null,
                    'keywords' => null,
                ];
            }

            if ($chunkPayloads !== []) {
                return $chunkPayloads;
            }
        }

        $content = trim($sourceText);

        if ($content === '') {
            return [];
        }

        $wordCount = count(preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return [
            [
                'content' => $content,
                'start_offset' => 0,
                'end_offset' => mb_strlen($sourceText, 'UTF-8'),
                'section_title' => null,
                'section_path' => null,
                'heading_path' => null,
                'chunk_type' => 'document',
                'title' => null,
                'part_index' => null,
                'topic' => null,
                'sub_topic' => null,
                'keywords' => null,
            ],
        ];
    }

    /**
     * Purpose: Group normalized structural elements by their top-level heading context.
     * Inputs: Ordered parser elements with heading_path metadata.
     * Returns: Ordered element groups where each group belongs to one primary heading.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array{
     *     primary_heading: ?string,
     *     elements: array<int, array<string, mixed>>
     * }>
     */
    private function groupElementsByPrimaryHeading(array $elements): array
    {
        $groups = [];
        $currentGroup = [];
        $currentPrimaryHeading = null;
        $pendingPrelude = [];
        $seenRealHeading = false;

        foreach ($elements as $element) {
            $text = trim((string) data_get($element, 'text', ''));

            if ($text === '') {
                continue;
            }

            $primaryHeading = $this->primaryHeadingFromPath(data_get($element, 'heading_path'));

            if ($primaryHeading === null) {
                if (! $seenRealHeading) {
                    $pendingPrelude[] = $element;

                    continue;
                }

                $currentGroup[] = $element;

                continue;
            }

            if (! $seenRealHeading) {
                $seenRealHeading = true;
                $currentPrimaryHeading = $primaryHeading;
                $currentGroup = array_merge($pendingPrelude, [$element]);
                $pendingPrelude = [];

                continue;
            }

            if ($primaryHeading !== $currentPrimaryHeading) {
                if ($currentGroup !== []) {
                    $groups[] = [
                        'primary_heading' => $currentPrimaryHeading,
                        'elements' => $currentGroup,
                    ];
                }

                $currentGroup = [$element];
                $currentPrimaryHeading = $primaryHeading;

                continue;
            }

            $currentGroup[] = $element;
        }

        if ($currentGroup !== []) {
            $groups[] = [
                'primary_heading' => $currentPrimaryHeading,
                'elements' => $currentGroup,
            ];
        } elseif ($pendingPrelude !== []) {
            $groups[] = [
                'primary_heading' => null,
                'elements' => $pendingPrelude,
            ];
        }

        return $groups;
    }

    /**
     * Purpose: Convert one primary heading group into chunk ranges without splitting H2 sections.
     * Inputs: One ordered set of elements belonging to the same top-level heading context.
     * Returns: Ordered chunk ranges for either the full H1-only group or its contained H2 sections.
     * Side effects: None.
     *
     * @param array{
     *     primary_heading: ?string,
     *     elements: array<int, array<string, mixed>>
     * } $group
     * @return array<int, array{start_offset: int, end_offset: int, heading_path: ?string, chunk_kind: string}>
     */
    private function buildRuleBasedChunkRangesForPrimaryHeadingGroup(array $group): array
    {
        $groupElements = array_values(array_filter(
            (array) ($group['elements'] ?? []),
            static fn ($element): bool => is_array($element),
        ));

        if ($groupElements === []) {
            return [];
        }

        $primaryHeading = $this->cleanNullableString($group['primary_heading'] ?? null, 255);

        if ($primaryHeading === null) {
            return [];
        }

        $h2Sections = array_values(array_filter(
            $groupElements,
            static fn (array $element): bool => (string) data_get($element, 'type', '') === 'h2_section',
        ));
        $tableElements = array_values(array_filter(
            $groupElements,
            static fn (array $element): bool => (string) data_get($element, 'type', '') === 'table',
        ));

        if ($h2Sections === []) {
            if ($tableElements !== []) {
                return $this->buildRuleBasedChunkRangesForH1GroupWithTables($groupElements, $primaryHeading);
            }

            $startOffset = null;
            $endOffset = null;

            foreach ($groupElements as $element) {
                $elementStart = (int) data_get($element, 'start_offset', 0);
                $elementEnd = (int) data_get($element, 'end_offset', 0);

                if ($startOffset === null || $elementStart < $startOffset) {
                    $startOffset = $elementStart;
                }

                if ($endOffset === null || $elementEnd > $endOffset) {
                    $endOffset = $elementEnd;
                }
            }

            if ($startOffset === null || $endOffset === null || $endOffset <= $startOffset) {
                return [];
            }

            return [[
                'start_offset' => $startOffset,
                'end_offset' => $endOffset,
                'heading_path' => $primaryHeading,
                'section_title' => $primaryHeading,
                'section_path' => $primaryHeading,
                'chunk_kind' => 'h1_section',
            ]];
        }

        $ranges = [];
        $firstH2Start = (int) data_get($h2Sections[0], 'start_offset', 0);
        $preH2Elements = array_values(array_filter(
            $groupElements,
            static fn (array $element): bool => (int) data_get($element, 'start_offset', 0) < $firstH2Start,
        ));

        if ($preH2Elements !== []) {
            $leadingStart = null;

            foreach ($preH2Elements as $element) {
                $elementStart = (int) data_get($element, 'start_offset', 0);

                if ($leadingStart === null || $elementStart < $leadingStart) {
                    $leadingStart = $elementStart;
                }
            }

            if ($leadingStart !== null && $leadingStart < $firstH2Start) {
                $ranges[] = [
                    'start_offset' => $leadingStart,
                    'end_offset' => $firstH2Start,
                    'heading_path' => $primaryHeading,
                    'section_title' => $primaryHeading,
                    'section_path' => $primaryHeading,
                    'chunk_kind' => 'h1_section',
                ];
            }
        }

        foreach ($h2Sections as $index => $h2Section) {
            $startOffset = (int) data_get($h2Section, 'start_offset', 0);
            $endOffset = (int) data_get($h2Section, 'end_offset', 0);
            $fullHeadingPath = $this->cleanNullableString($h2Section['heading_path'] ?? null, 255) ?? $primaryHeading;
            $headingTitle = $this->headingLeafFromPath($fullHeadingPath) ?? $fullHeadingPath;

            if ($endOffset <= $startOffset) {
                continue;
            }

            $ranges[] = [
                'start_offset' => $startOffset,
                'end_offset' => $endOffset,
                'heading_path' => $headingTitle,
                'section_title' => $headingTitle,
                'section_path' => $fullHeadingPath,
                'chunk_kind' => 'h2_section',
            ];
        }

        foreach ($tableElements as $tableElement) {
            foreach ($this->buildTableChunkRangesFromElement($tableElement) as $tableRange) {
                $ranges[] = $tableRange;
            }
        }

        return $ranges;
    }

    /**
     * Purpose: Convert a heading group without H2 sections into text and table chunk ranges.
     * Inputs: Ordered elements belonging to one primary H1 context and a resolved primary heading.
     * Returns: Ordered chunk ranges where text runs are semantic chunks and tables are dedicated table chunks.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $groupElements
     * @return array<int, array<string, mixed>>
     */
    private function buildRuleBasedChunkRangesForH1GroupWithTables(array $groupElements, string $primaryHeading): array
    {
        $ranges = [];
        $currentTextElements = [];
        $headingSeed = null;

        foreach ($groupElements as $element) {
            if ((string) data_get($element, 'type', '') === 'heading' && (int) data_get($element, 'heading_level', 0) === 1) {
                $headingSeed = $element;

                break;
            }
        }

        if ($headingSeed === null && $groupElements !== []) {
            $headingSeed = $groupElements[0];
        }

        $flushTextElements = function () use (&$ranges, &$currentTextElements, $primaryHeading): void {
            if ($currentTextElements === []) {
                return;
            }

            $startOffset = null;
            $endOffset = null;

            foreach ($currentTextElements as $element) {
                $elementStart = (int) data_get($element, 'start_offset', 0);
                $elementEnd = (int) data_get($element, 'end_offset', 0);

                if ($startOffset === null || $elementStart < $startOffset) {
                    $startOffset = $elementStart;
                }

                if ($endOffset === null || $elementEnd > $endOffset) {
                    $endOffset = $elementEnd;
                }
            }

            if (count($currentTextElements) === 1) {
                $onlyElement = $currentTextElements[0] ?? [];
                $onlyElementType = (string) data_get($onlyElement, 'type', '');
                $onlyElementHeadingLevel = (int) data_get($onlyElement, 'heading_level', 0);

                if ($onlyElementType === 'heading' && $onlyElementHeadingLevel === 1) {
                    $currentTextElements = [];

                    return;
                }
            }

            if ($startOffset !== null && $endOffset !== null && $endOffset > $startOffset) {
                $ranges[] = [
                    'start_offset' => $startOffset,
                    'end_offset' => $endOffset,
                    'heading_path' => $primaryHeading,
                    'section_title' => $primaryHeading,
                    'section_path' => $primaryHeading,
                    'chunk_kind' => 'h1_section',
                ];
            }

            $currentTextElements = [];
        };

        foreach ($groupElements as $element) {
            $type = (string) data_get($element, 'type', '');

            if ($type === 'table') {
                $flushTextElements();

                foreach ($this->buildTableChunkRangesFromElement($element) as $tableRange) {
                    $ranges[] = $tableRange;
                }

                $currentTextElements = is_array($headingSeed) ? [$headingSeed] : [];

                continue;
            }

            $currentTextElements[] = $element;
        }

        $flushTextElements();

        return $ranges;
    }

    /**
     * Purpose: Convert one parsed table element into one dedicated table chunk range.
     * Inputs: A parsed table element with structured table metadata.
     * Returns: One ordered table chunk range that preserves the entire table structure.
     * Side effects: None.
     *
     * @param array<string, mixed> $tableElement
     * @return array<int, array<string, mixed>>
     */
    private function buildTableChunkRangesFromElement(array $tableElement): array
    {
        $tableJson = is_array(data_get($tableElement, 'table_json', null)) ? data_get($tableElement, 'table_json') : [];

        if ($tableJson === []) {
            return [];
        }

        $fullHeadingPath = $this->cleanNullableString($tableElement['heading_path'] ?? null, 255);
        $headingTitle = $this->headingLeafFromPath($fullHeadingPath) ?? $fullHeadingPath;
        $sectionPath = $fullHeadingPath ?? $headingTitle;
        $tableIndexInDocument = isset($tableElement['table_index_in_document'])
            ? (int) $tableElement['table_index_in_document']
            : (int) data_get($tableJson, 'source_metadata.table_index_in_document', 0);
        $sourceStartOffset = (int) data_get($tableElement, 'start_offset', 0);
        $sourceEndOffset = (int) data_get($tableElement, 'end_offset', $sourceStartOffset);
        $tableText = trim((string) data_get($tableElement, 'table_text', data_get($tableJson, 'table_text', data_get($tableElement, 'text', ''))));
        // Purpose: Keep markdown only as a legacy fallback. table_json and table_html are the primary table representations.
        $tableMarkdown = trim((string) data_get($tableElement, 'table_markdown', data_get($tableJson, 'table_markdown', '')));
        $tableHtml = trim((string) data_get($tableElement, 'table_html', data_get($tableJson, 'table_html', '')));
        $tableComplexity = $this->cleanNullableString(data_get($tableElement, 'table_complexity', data_get($tableJson, 'complexity')), 32) ?? 'complex';
        $tableWarnings = array_values(array_unique(array_filter(array_map(
            static fn ($warning): string => trim((string) ($warning ?? '')),
            (array) data_get($tableElement, 'table_warnings', data_get($tableJson, 'warnings', [])),
        ), static fn (string $warning): bool => $warning !== '')));
        $tableTitleText = $this->tableTitleFromJson($tableJson);
        $tableLabel = $tableTitleText !== null && $tableTitleText !== ''
            ? $tableTitleText
            : 'Tabell';
        $tableContentParts = [];

        if ($sectionPath !== null && $sectionPath !== '') {
            $tableContentParts[] = $sectionPath;
        }

        $tableContentParts[] = $tableLabel;

        if ($tableText !== '') {
            $tableContentParts[] = $tableText;
        }

        $content = trim(implode("\n\n", $tableContentParts));
        $wordCount = count(preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($wordCount > self::RULE_BASED_CHUNK_MAX_WORDS && ! in_array('large_table_not_split', $tableWarnings, true)) {
            $tableWarnings[] = 'large_table_not_split';
        }

        $tableWarnings = array_values(array_unique(array_filter($tableWarnings, static fn (string $warning): bool => trim($warning) !== '')));
        $rowCount = (int) data_get($tableJson, 'row_count', 0);
        $columnCount = (int) data_get($tableJson, 'column_count', 0);

        $tableMetadata = [
            'source' => 'docx_table',
            'heading_path' => $fullHeadingPath,
            'heading_title' => $tableTitleText ?? $headingTitle,
            'section_path' => $sectionPath,
            'row_count' => $rowCount,
            'column_count' => $columnCount,
            'table_index_in_document' => $tableIndexInDocument,
            'source_table_start_offset' => $sourceStartOffset,
            'source_table_end_offset' => $sourceEndOffset,
            'split_part' => null,
            'split_total' => null,
            'original_row_count' => $rowCount,
            'rows_in_part' => $rowCount,
            'table_complexity' => $tableComplexity,
            'table_warnings' => $tableWarnings,
        ];

        return [[
            'start_offset' => $sourceStartOffset,
            'end_offset' => $sourceEndOffset,
            'heading_path' => $headingTitle,
            'section_title' => $headingTitle,
            'section_path' => $sectionPath,
            'chunk_kind' => 'table',
            'title' => $tableLabel,
            'table_index_in_document' => $tableIndexInDocument,
            'part_index' => null,
            'content' => $content,
            'table_json' => $tableJson,
            'table_html' => $tableHtml !== '' ? $tableHtml : null,
            'table_markdown' => $tableMarkdown !== '' ? $tableMarkdown : null,
            'table_text' => $tableText !== '' ? $tableText : null,
            'table_complexity' => $tableComplexity,
            'table_warnings' => $tableWarnings,
            'table_metadata' => $tableMetadata,
        ]];
    }

    /**
     * Purpose: Remove any table overlaps from text ranges so table content only lives in table chunks.
     * Inputs: Ordered chunk ranges that may include both semantic and table ranges.
     * Returns: Chunk ranges where semantic ranges have been split around table ranges.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $chunkRanges
     * @return array<int, array<string, mixed>>
     */
    private function subtractTableRangesFromSemanticRanges(array $chunkRanges): array
    {
        $tableRanges = array_values(array_filter(
            $chunkRanges,
            static fn (array $chunkRange): bool => (string) ($chunkRange['chunk_kind'] ?? '') === 'table',
        ));

        if ($tableRanges === []) {
            return $chunkRanges;
        }

        $adjustedRanges = [];

        foreach ($chunkRanges as $chunkRange) {
            if ((string) ($chunkRange['chunk_kind'] ?? '') === 'table') {
                $adjustedRanges[] = $chunkRange;

                continue;
            }

            $segments = [[
                'start_offset' => (int) ($chunkRange['start_offset'] ?? 0),
                'end_offset' => (int) ($chunkRange['end_offset'] ?? 0),
            ]];

            foreach ($tableRanges as $tableRange) {
                $tableStart = (int) ($tableRange['start_offset'] ?? 0);
                $tableEnd = (int) ($tableRange['end_offset'] ?? 0);
                $nextSegments = [];

                foreach ($segments as $segment) {
                    $segmentStart = (int) ($segment['start_offset'] ?? 0);
                    $segmentEnd = (int) ($segment['end_offset'] ?? 0);

                    if ($segmentEnd <= $segmentStart) {
                        continue;
                    }

                    if ($tableEnd <= $segmentStart || $tableStart >= $segmentEnd) {
                        $nextSegments[] = $segment;

                        continue;
                    }

                    if ($tableStart > $segmentStart) {
                        $nextSegments[] = [
                            'start_offset' => $segmentStart,
                            'end_offset' => min($tableStart, $segmentEnd),
                        ];
                    }

                    if ($tableEnd < $segmentEnd) {
                        $nextSegments[] = [
                            'start_offset' => max($tableEnd, $segmentStart),
                            'end_offset' => $segmentEnd,
                        ];
                    }
                }

                $segments = array_values(array_filter(
                    $nextSegments,
                    static fn (array $segment): bool => (int) ($segment['end_offset'] ?? 0) > (int) ($segment['start_offset'] ?? 0),
                ));

                if ($segments === []) {
                    break;
                }
            }

            foreach ($segments as $segment) {
                $adjustedRange = $chunkRange;
                $adjustedRange['start_offset'] = (int) ($segment['start_offset'] ?? $chunkRange['start_offset'] ?? 0);
                $adjustedRange['end_offset'] = (int) ($segment['end_offset'] ?? $chunkRange['end_offset'] ?? 0);
                $adjustedRanges[] = $adjustedRange;
            }
        }

        return $adjustedRanges;
    }

    /**
     * Purpose: Resolve the visible title text from a structured table payload.
     * Inputs: The structured table JSON array.
     * Returns: The title row text or null when no title row exists.
     * Side effects: None.
     *
     * @param array<string, mixed> $tableJson
     */
    private function tableTitleFromJson(array $tableJson): ?string
    {
        $rows = array_values(array_filter((array) data_get($tableJson, 'rows', []), static fn ($row): bool => is_array($row)));
        $titleRowIndex = isset($tableJson['title_row_index']) ? (int) $tableJson['title_row_index'] : null;

        if ($titleRowIndex === null || ! isset($rows[$titleRowIndex])) {
            return null;
        }

        $rowCells = array_values(array_filter((array) data_get($rows[$titleRowIndex], 'cells', []), static fn ($cell): bool => is_array($cell)));
        $cellTexts = array_values(array_filter(array_map(
            static fn (array $cell): string => trim((string) ($cell['text'] ?? '')),
            $rowCells,
        ), static fn (string $text): bool => $text !== ''));

        return $cellTexts !== [] ? trim(implode(' ', $cellTexts)) : null;
    }

    /**
     * Purpose: Split a table row matrix into controlled parts that stay under the configured word limit.
     * Inputs: The normalized table rows and the maximum permitted word count per part.
     * Returns: Ordered row parts where each part repeats the header row.
     * Side effects: None.
     *
     * @param array<int, array<int, string>> $rows
     * @return array<int, array{
     *     rows: array<int, array<int, string>>
     * }>
     */
    private function splitTableRowsIntoParts(array $rows, int $maxWords): array
    {
        if ($rows === []) {
            return [];
        }

        $headerRow = array_values((array) array_shift($rows));
        $bodyRows = array_values(array_filter($rows, static fn ($row): bool => is_array($row)));

        if ($bodyRows === []) {
            return [[
                'rows' => [$headerRow],
            ]];
        }

        $parts = [];
        $currentBodyRows = [];

        foreach ($bodyRows as $row) {
            $candidateRows = array_merge([$headerRow], $currentBodyRows, [$row]);
            $candidateWordCount = $this->tableRowsWordCount($candidateRows);

            if ($currentBodyRows !== [] && $candidateWordCount > $maxWords) {
                $parts[] = [
                    'rows' => array_merge([$headerRow], $currentBodyRows),
                ];
                $currentBodyRows = [];
            }

            $currentBodyRows[] = $row;
        }

        if ($currentBodyRows !== []) {
            $parts[] = [
                'rows' => array_merge([$headerRow], $currentBodyRows),
            ];
        }

        if ($parts === []) {
            $parts[] = [
                'rows' => [$headerRow],
            ];
        }

        return $parts;
    }

    /**
     * Purpose: Convert table rows into a searchable plain-text representation.
     * Inputs: A row matrix with cell values.
     * Returns: A newline-separated flat text table.
     * Side effects: None.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function tableRowsToText(array $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = implode(' | ', array_map(
                static fn (string $cell): string => trim($cell),
                $row,
            ));
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Purpose: Convert table rows into markdown.
     * Inputs: A row matrix with cell values.
     * Returns: A simple markdown table string.
     * Side effects: None.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function tableRowsToMarkdown(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $normalizedRows = array_map(
            static fn (array $row): array => array_map(
                static fn ($cell): string => trim((string) ($cell ?? '')),
                $row,
            ),
            $rows,
        );

        $headerRow = array_shift($normalizedRows);

        if (! is_array($headerRow) || $headerRow === []) {
            return '';
        }

        $columnCount = count($headerRow);
        foreach ($normalizedRows as $row) {
            $columnCount = max($columnCount, count($row));
        }

        $padRow = static function (array $row, int $columnCount): array {
            $normalized = array_map(
                static fn ($cell): string => trim((string) ($cell ?? '')),
                $row,
            );

            while (count($normalized) < $columnCount) {
                $normalized[] = '';
            }

            return $normalized;
        };

        $markdownRows = [];
        $markdownRows[] = '| '.implode(' | ', $padRow($headerRow, $columnCount)).' |';
        $markdownRows[] = '| '.implode(' | ', array_fill(0, $columnCount, '---')).' |';

        foreach ($normalizedRows as $row) {
            $markdownRows[] = '| '.implode(' | ', $padRow($row, $columnCount)).' |';
        }

        return implode("\n", $markdownRows);
    }

    /**
     * Purpose: Count the words in one normalized table row matrix.
     * Inputs: The table rows that will be rendered into content.
     * Returns: The approximate total word count.
     * Side effects: None.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function tableRowsWordCount(array $rows): int
    {
        return count(preg_split('/\s+/u', trim($this->tableRowsToText($rows)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Purpose: Count the widest column count in a normalized table row matrix.
     * Inputs: The table rows that will be persisted as one chunk or chunk part.
     * Returns: The maximum number of cells found in any row.
     * Side effects: None.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function tableColumnCount(array $rows): int
    {
        $columnCount = 0;

        foreach ($rows as $row) {
            $columnCount = max($columnCount, count($row));
        }

        return $columnCount;
    }

    /**
     * Purpose: Split an oversized structural candidate into block-based subchunks.
     * Inputs: The candidate range metadata, ordered block descriptors, and canonical source text.
     * Returns: Ordered chunk ranges derived from the block boundaries.
     * Side effects: None.
     *
     * @param array<string, mixed> $candidate
     * @param array<int, array{
     *     block_index: int,
     *     relative_start: int,
     *     relative_end: int,
     *     absolute_start_offset: int,
     *     absolute_end_offset: int,
     *     word_count: int,
     *     starts_with: string
     * }> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function buildRuleBasedSubChunksFromBlocks(array $candidate, array $blocks, string $sourceText): array
    {
        if ($blocks === []) {
            return [];
        }

        $candidateIndex = isset($candidate['candidate_index']) ? (int) $candidate['candidate_index'] : 0;
        $headingPath = $this->cleanNullableString($candidate['heading_path'] ?? null, 255);
        $sectionTitle = $this->cleanNullableString($candidate['section_title'] ?? null, 255) ?? $headingPath;
        $sectionPath = $this->cleanNullableString($candidate['section_path'] ?? null, 255) ?? $sectionTitle;
        $chunkKind = (string) ($candidate['chunk_kind'] ?? 'h2_section');
        $candidateEndOffset = isset($candidate['end_offset']) ? (int) $candidate['end_offset'] : null;
        $subChunks = [];
        $currentBlocks = [];
        $currentWordCount = 0;

        $flushCurrentBlocks = function (array &$currentBlocks, int &$currentWordCount, array &$subChunks, ?int $boundaryEndOffset = null) use ($candidateIndex, $headingPath, $sectionTitle, $sectionPath, $chunkKind): void {
            if ($currentBlocks === []) {
                return;
            }

            $firstBlock = $currentBlocks[0];
            $lastBlock = $currentBlocks[array_key_last($currentBlocks)];
            $startOffset = (int) ($firstBlock['absolute_start_offset'] ?? 0);
            $endOffset = $boundaryEndOffset !== null
                ? $boundaryEndOffset
                : (int) ($lastBlock['absolute_end_offset'] ?? $startOffset);

            if ($endOffset <= $startOffset) {
                $currentBlocks = [];
                $currentWordCount = 0;

                return;
            }

            $subChunks[] = [
                'start_offset' => $startOffset,
                'end_offset' => $endOffset,
                'heading_path' => $headingPath,
                'section_title' => $sectionTitle,
                'section_path' => $sectionPath,
                'chunk_kind' => $chunkKind,
                'candidate_index' => $candidateIndex,
            ];

            $currentBlocks = [];
            $currentWordCount = 0;
        };

        foreach ($blocks as $block) {
            $blockWordCount = (int) ($block['word_count'] ?? 0);

            if ($currentBlocks !== [] && $currentWordCount + $blockWordCount > self::RULE_BASED_CHUNK_MAX_WORDS) {
                $flushCurrentBlocks(
                    $currentBlocks,
                    $currentWordCount,
                    $subChunks,
                    isset($block['absolute_start_offset']) ? (int) $block['absolute_start_offset'] : null,
                );
            }

            $currentBlocks[] = $block;
            $currentWordCount += $blockWordCount;
        }

        $flushCurrentBlocks(
            $currentBlocks,
            $currentWordCount,
            $subChunks,
            $candidateEndOffset,
        );

        if (count($subChunks) <= 1) {
            return $subChunks;
        }

        foreach ($subChunks as $index => &$subChunk) {
            $subChunk['part_index'] = $index + 1;
        }
        unset($subChunk);

        return $subChunks;
    }

    /**
     * Purpose: Resolve the most specific heading segment from a heading path.
     * Inputs: A heading path string or null.
     * Returns: The deepest heading segment or null when no heading exists.
     * Side effects: None.
     */
    private function headingLeafFromPath(mixed $headingPath): ?string
    {
        $text = trim((string) ($headingPath ?? ''));

        if ($text === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(' > ', $text),
        ), static fn (string $part): bool => $part !== ''));

        return $parts !== [] ? $parts[array_key_last($parts)] : null;
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

        return $parts[0] ?? null;
    }

    /**
     * Purpose: Persist the final knowledge chunks for one knowledge document.
     * Inputs: The knowledge document and the validated chunk payloads.
     * Returns: None.
     * Side effects: Deletes old chunks and inserts the current chunk set.
     */
    private function syncChunks(KnowledgeItem $knowledgeDocument, array $chunkPayloads): Collection
    {
        $knowledgeDocument->chunks()->delete();

        if ($chunkPayloads === []) {
            return collect();
        }

        return collect($knowledgeDocument->chunks()->createMany(
                array_map(
                    static fn (array $chunkPayload, int $chunkIndex): array => [
                        'chunk_index' => $chunkIndex,
                        'content' => (string) ($chunkPayload['content'] ?? ''),
                        'start_offset' => (int) ($chunkPayload['start_offset'] ?? 0),
                        'end_offset' => (int) ($chunkPayload['end_offset'] ?? 0),
                        'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
                        'section_title' => $chunkPayload['section_title'] ?? null,
                        'section_path' => $chunkPayload['section_path'] ?? null,
                        'heading_path' => $chunkPayload['heading_path'] ?? null,
                        'chunk_type' => $chunkPayload['chunk_type'] ?? null,
                        'title' => $chunkPayload['title'] ?? null,
                        'topic' => $chunkPayload['topic'] ?? null,
                        'sub_topic' => $chunkPayload['sub_topic'] ?? null,
                        'keywords' => $chunkPayload['keywords'] ?? null,
                        'table_json' => $chunkPayload['table_json'] ?? null,
                        'table_html' => $chunkPayload['table_html'] ?? null,
                        'table_complexity' => $chunkPayload['table_complexity'] ?? null,
                        'table_warnings' => $chunkPayload['table_warnings'] ?? null,
                        'table_markdown' => $chunkPayload['table_markdown'] ?? null,
                        'table_text' => $chunkPayload['table_text'] ?? null,
                        'table_metadata' => $chunkPayload['table_metadata'] ?? null,
                    ],
                    $chunkPayloads,
                    array_keys($chunkPayloads),
                ),
            ));
    }

    /**
     * Purpose: Generate and persist embeddings for the freshly created knowledge chunks.
     * Inputs: The knowledge document and its persisted chunks.
     * Returns: None.
     * Side effects: Calls OpenAI, stores embeddings, and records per-chunk failures.
     */
    private function syncChunkEmbeddings(KnowledgeItem $knowledgeDocument, Collection $chunks): void
    {
        if ($chunks->isEmpty()) {
            return;
        }

        foreach ($chunks as $chunk) {
            if (! $chunk instanceof KnowledgeItemChunk) {
                continue;
            }

            $chunk->refresh();
            $chunkKeywords = $this->normalizeExactKeywordList($chunk->keywords);
            $chunkContent = trim((string) $chunk->content);
            $contentWordCount = count(preg_split('/\s+/u', $chunkContent, -1, PREG_SPLIT_NO_EMPTY) ?: []);

            Log::info('[PROCYNIA][CHUNK_METADATA]', [
                'knowledge_item_id' => $knowledgeDocument->id,
                'chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'heading_path' => $chunk->heading_path,
                'content_word_count' => $contentWordCount,
                'metadata_generation_started' => true,
            ]);

            $metadataOutcome = $this->knowledgeChunkMetadataGenerationService->generateForChunk($knowledgeDocument, $chunk);
            $embeddingInput = $metadataOutcome['embedding_input'] ?? $chunk->content;
            $metadataKeywords = $this->normalizeExactKeywordList(data_get($metadataOutcome, 'keywords'));

            if ($metadataKeywords === null || $metadataKeywords === []) {
                $metadataKeywords = $chunkKeywords;
            }

            $metadataGenerationFailed = ($metadataOutcome['metadata_status'] ?? null) === KnowledgeItemChunk::METADATA_STATUS_FAILED;

            Log::info('[PROCYNIA][CHUNK_METADATA]', [
                'knowledge_item_id' => $knowledgeDocument->id,
                'chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'heading_path' => $chunk->heading_path,
                'content_word_count' => $contentWordCount,
                'metadata_generation_started' => false,
                'metadata_generation_succeeded' => ! $metadataGenerationFailed,
                'metadata_generation_failed' => $metadataGenerationFailed,
                'validation_failed_reason' => $metadataGenerationFailed ? 'metadata_status_failed' : null,
            ]);

            $metadataUpdates = [
                'service_product_tag' => $this->cleanNullableString(data_get($metadataOutcome, 'service_product_tag'), 191)
                    ?? $this->cleanNullableString($chunk->service_product_tag, 191),
                'theme_tag' => $this->cleanNullableString(data_get($metadataOutcome, 'theme_tag'), 191)
                    ?? $this->cleanNullableString($chunk->theme_tag, 191),
                'topic' => $this->cleanNullableString(data_get($metadataOutcome, 'topic'), 191)
                    ?? $this->cleanNullableString($chunk->topic, 191),
                'sub_topic' => $this->cleanNullableString(data_get($metadataOutcome, 'sub_topic'), 191)
                    ?? $this->cleanNullableString($chunk->sub_topic, 191),
                'keywords' => $metadataKeywords,
                'matched_terms' => $metadataOutcome['matched_terms'] ?? null,
                'summary_for_retrieval' => $metadataOutcome['summary_for_retrieval'] ?? null,
                'confidence_score' => $metadataOutcome['confidence_score'] ?? null,
                'metadata_status' => $metadataOutcome['metadata_status'] ?? KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
            ];

            $chunkText = trim((string) $embeddingInput);

            if ($chunkText === '') {
                $chunk->forceFill([
                    'embedding_error' => 'Chunk content was empty and was not embedded.',
                ])->save();

                continue;
            }

            $chunk->forceFill($metadataUpdates)->save();
            $chunk->refresh();

            Log::info('[PROCYNIA][KNOWLEDGE_CHUNKING] Chunk persisted with metadata', [
                'knowledge_item_id' => $knowledgeDocument->id,
                'knowledge_item_chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'has_topic' => trim((string) $chunk->topic) !== '',
                'has_sub_topic' => trim((string) $chunk->sub_topic) !== '',
                'keywords_count' => count($this->normalizeExactKeywordList($chunk->keywords) ?? []),
            ]);

            $this->knowledgeChunkVocabularyCandidateService->syncForChunk($knowledgeDocument, $chunk);

            $outcome = app(EmbeddingService::class)->tryEmbedText($chunkText);

            if (! ($outcome['ok'] ?? false)) {
                $this->logChunkEmbeddingFailure($knowledgeDocument, $chunk, $outcome);

                $chunk->forceFill([
                    'embedding_error' => (string) ($outcome['error_message'] ?? 'Knowledge chunk embedding failed.'),
                ])->save();

                continue;
            }

            $chunk->forceFill([
                'embedding_vector' => $outcome['embedding'] ?? null,
                'embedding_model' => $outcome['model'] ?? null,
                'embedding_generated_at' => now(),
                'embedding_error' => null,
            ])->save();
        }
    }

    /**
     * Purpose: Generate embeddings for rule-based chunks without running generative metadata enrichment.
     * Inputs: The knowledge document and its persisted chunks.
     * Returns: None.
     * Side effects: Calls the embedding service and stores embedding fields on each chunk.
     */
    private function syncChunkEmbeddingsWithoutMetadata(KnowledgeItem $knowledgeDocument, Collection $chunks): void
    {
        if ($chunks->isEmpty()) {
            return;
        }

        foreach ($chunks as $chunk) {
            if (! $chunk instanceof KnowledgeItemChunk) {
                continue;
            }

            $chunk->refresh();
            $chunkText = trim((string) $chunk->content);

            if ($chunkText === '') {
                $chunk->forceFill([
                    'embedding_error' => 'Chunk content was empty and was not embedded.',
                ])->save();

                continue;
            }

            Log::info('[PROCYNIA][KNOWLEDGE_CHUNKING] Rule-based chunk persisted without AI metadata.', [
                'knowledge_item_id' => $knowledgeDocument->id,
                'knowledge_item_chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'chunk_content_length' => mb_strlen($chunkText, 'UTF-8'),
            ]);

            $outcome = app(EmbeddingService::class)->tryEmbedText($chunkText);

            if (! ($outcome['ok'] ?? false)) {
                $this->logChunkEmbeddingFailure($knowledgeDocument, $chunk, $outcome);

                $chunk->forceFill([
                    'embedding_error' => (string) ($outcome['error_message'] ?? 'Knowledge chunk embedding failed.'),
                ])->save();

                continue;
            }

            $chunk->forceFill([
                'embedding_vector' => $outcome['embedding'] ?? null,
                'embedding_model' => $outcome['model'] ?? null,
                'embedding_generated_at' => now(),
                'embedding_error' => null,
            ])->save();
        }
    }

    /**
     * Purpose: Log a chunk embedding failure with enough context to diagnose the upstream call.
     * Inputs: The knowledge document, the chunk, and the embedding outcome.
     * Returns: None.
     * Side effects: Writes a warning or error log entry.
     */
    private function logChunkEmbeddingFailure(KnowledgeItem $knowledgeDocument, KnowledgeItemChunk $chunk, array $outcome): void
    {
        $chunkContent = trim((string) $chunk->content);
        $chunkLength = mb_strlen($chunkContent, 'UTF-8');

        $context = [
            'knowledge_item_id' => $knowledgeDocument->id,
            'knowledge_item_title' => $knowledgeDocument->title,
            'knowledge_item_chunk_id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'chunk_content_length' => $chunkLength,
            'chunk_heading_preview' => Str::limit(trim((string) Str::before($chunkContent, "\n")), 200, ''),
            'chunk_content_excerpt_start' => $chunkLength > 0
                ? mb_substr($chunkContent, 0, min(500, $chunkLength), 'UTF-8')
                : '',
            'chunk_content_excerpt_end' => $chunkLength > 0
                ? mb_substr($chunkContent, max(0, $chunkLength - 500), null, 'UTF-8')
                : '',
            'embedding_model' => $outcome['model'] ?? null,
            'upstream_status' => $outcome['upstream_status'] ?? null,
            'request_id' => $outcome['request_id'] ?? null,
            'error_type' => $outcome['error_type'] ?? null,
            'error_message' => $outcome['error_message'] ?? null,
            'response_body_excerpt' => $outcome['response_body_excerpt'] ?? null,
        ];

        if (in_array($outcome['error_type'] ?? null, ['unexpected_response', 'invalid_request'], true)) {
            Log::error('Knowledge chunk embedding failed.', $context);

            return;
        }

        Log::warning('Knowledge chunk embedding failed.', $context);
    }

    /**
     * Purpose: Convert a file size in bytes into a short human-readable string.
     * Inputs: A nullable byte count.
     * Returns: A formatted size label for the UI.
     * Side effects: None.
     */
    private function humanFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        $units = ['KB', 'MB', 'GB'];
        $size = $bytes / 1024;
        $unit = 'KB';

        foreach ($units as $candidateUnit) {
            $unit = $candidateUnit;

            if ($size < 1024 || $candidateUnit === 'GB') {
                break;
            }

            $size /= 1024;
        }

        return sprintf('%.1f %s', $size, $unit);
    }

    /**
     * Purpose: Build a short, readable excerpt from the extracted knowledge document text.
     * Inputs: A customer-scoped knowledge document.
     * Returns: A compact excerpt for list and detail views.
     * Side effects: None.
     */
    private function contentExcerpt(KnowledgeItem $knowledgeDocument): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $knowledgeDocument->extracted_text) ?? '');

        if ($text !== '') {
            return Str::limit($text, 360, '...');
        }

        if ($knowledgeDocument->extraction_status === KnowledgeItem::EXTRACTION_STATUS_FAILED) {
            return 'Tekstuttrekk feilet.';
        }

        return 'Ingen ekstrahert tekst.';
    }

    private function cleanNullableString(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $maxLength, '');
    }

    /**
     * Purpose: Normalize a chunk keyword list without changing the user-facing values.
     * Inputs: Raw keyword data from the persisted chunk or metadata payload.
     * Returns: A trimmed, de-duplicated keyword list or null when no usable keywords exist.
     * Side effects: None.
     *
     * @return array<int, string>|null
     */
    private function normalizeExactKeywordList(mixed $keywords): ?array
    {
        if ($keywords instanceof Collection) {
            $keywords = $keywords->all();
        } elseif (is_string($keywords)) {
            $keywords = json_decode($keywords, true);
        }

        if (! is_array($keywords)) {
            return null;
        }

        $normalized = [];
        $seen = [];

        foreach ($keywords as $keyword) {
            $text = trim(Str::squish((string) $keyword));

            if ($text === '') {
                continue;
            }

            if (isset($seen[$text])) {
                continue;
            }

            $seen[$text] = true;
            $normalized[] = $text;
        }

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * Purpose: Build a stable stored filename for a knowledge document upload.
     * Inputs: The uploaded file extension.
     * Returns: A deterministic filename suitable for disk storage.
     * Side effects: None.
     */
    private function storedFilename(?string $extension): string
    {
        $normalizedExtension = Str::lower(trim((string) $extension));

        if ($normalizedExtension === '') {
            $normalizedExtension = 'bin';
        }

        return Str::ulid().'.'.$normalizedExtension;
    }
}
