<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\User;
use App\Services\DocumentChunker;
use App\Services\DocumentTextExtractor;
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
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly DocumentChunker $documentChunker,
        private readonly KnowledgeChunkCoverageService $knowledgeChunkCoverageService,
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
            'retrievalTestUrl' => route('app.ai.knowledge-base.retrieval-test'),
        ]);
    }

    /**
     * Purpose: Run an internal retrieval test against scoped knowledge chunks.
     * Inputs: The current frontend request containing a user query.
     * Returns: A redirect back to the previous page with retrieval debug results in flash state.
     * Side effects: Embeds the query and scores candidate chunks deterministically.
     */
    public function retrievalTest(Request $request): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $payload = $request->validate([
            'query' => ['required', 'string', 'max:4000'],
        ]);

        $query = trim((string) $payload['query']);
        $queryEmbeddingOutcome = app(EmbeddingService::class)->tryEmbedText($query);

        if (! ($queryEmbeddingOutcome['ok'] ?? false)) {
            return redirect()
                ->back()
                ->with('retrievalTest', [
                    'query' => $query,
                    'embedding_model' => $queryEmbeddingOutcome['model'] ?? null,
                    'error' => (string) ($queryEmbeddingOutcome['error_message'] ?? 'Kunne ikke lage embedding av spørsmålet.'),
                    'results' => [],
                    'candidate_count' => 0,
                ]);
        }

        $queryEmbedding = is_array($queryEmbeddingOutcome['embedding'] ?? null)
            ? $queryEmbeddingOutcome['embedding']
            : [];

        if ($queryEmbedding === []) {
            return redirect()
                ->back()
                ->with('retrievalTest', [
                    'query' => $query,
                    'embedding_model' => $queryEmbeddingOutcome['model'] ?? null,
                    'error' => 'Spørsmålsembedding manglet en gyldig vektor.',
                    'results' => [],
                    'candidate_count' => 0,
                ]);
        }

        $candidateChunks = $this->retrievalTestChunksForCustomer($customerId);
        $scoredResults = [];

        foreach ($candidateChunks as $candidate) {
            $chunkEmbedding = data_get($candidate, 'embedding_vector');

            if (! is_array($chunkEmbedding) || $chunkEmbedding === []) {
                continue;
            }

            $score = app(CosineSimilarity::class)->calculate($queryEmbedding, $chunkEmbedding);

            if ($score === null) {
                continue;
            }

            $content = trim((string) data_get($candidate, 'content', ''));
            $title = trim((string) data_get($candidate, 'title', ''));
            $keywords = $this->knowledgeChunkCoverageService->normalizeKeywords(data_get($candidate, 'keywords')) ?? [];

            $scoredResults[] = [
                'score' => $score,
                'knowledge_item_id' => (int) data_get($candidate, 'knowledge_item_id'),
                'chunk_id' => (int) data_get($candidate, 'chunk_id'),
                'chunk_index' => (int) data_get($candidate, 'chunk_index', 0),
                'document_title' => (string) data_get($candidate, 'knowledge_item_title', ''),
                'heading_path' => $title !== '' ? $title : sprintf('Chunk %d', ((int) data_get($candidate, 'chunk_index', 0)) + 1),
                'topic' => (string) data_get($candidate, 'topic', ''),
                'sub_topic' => (string) data_get($candidate, 'sub_topic', ''),
                'keywords' => $keywords,
                'section_title' => (string) data_get($candidate, 'section_title', ''),
                'section_path' => (string) data_get($candidate, 'section_path', ''),
                'content_preview' => Str::limit(Str::squish($content), 800, '...'),
            ];
        }

        usort(
            $scoredResults,
            static function (array $left, array $right): int {
                if (abs($left['score'] - $right['score']) > 0.000001) {
                    return $right['score'] <=> $left['score'];
                }

                if ($left['knowledge_item_id'] !== $right['knowledge_item_id']) {
                    return $right['knowledge_item_id'] <=> $left['knowledge_item_id'];
                }

                if ($left['chunk_index'] !== $right['chunk_index']) {
                    return $left['chunk_index'] <=> $right['chunk_index'];
                }

                return $left['chunk_id'] <=> $right['chunk_id'];
            },
        );

        $results = array_slice($scoredResults, 0, 5);

        return redirect()
            ->back()
            ->with('retrievalTest', [
                'query' => $query,
                'embedding_model' => $queryEmbeddingOutcome['model'] ?? null,
                'candidate_count' => count($scoredResults),
                'result_count' => count($results),
                'results' => $results,
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
            $structuredBlocks = $this->documentTextExtractor->extractStructuredText($absolutePath);
            $extractionFailed = trim($extractedText) === '';

            $result = DB::transaction(function () use (
                $customerId,
                $payload,
                $request,
                $storedPath,
                $extractedText,
                $structuredBlocks,
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
                    'chunks' => $this->syncChunks($knowledgeDocument, $structuredBlocks),
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
                        'start_offset' => (int) $chunk->start_offset,
                        'end_offset' => (int) $chunk->end_offset,
                        'embedding_model' => $chunk->embedding_model,
                        'embedding_generated_at' => optional($chunk->embedding_generated_at)?->toIso8601String(),
                        'embedding_error' => $chunk->embedding_error,
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
     * Purpose: Resolve the candidate chunks that can participate in a retrieval test.
     * Inputs: The current customer id.
     * Returns: A collection of active knowledge chunks with embedding vectors.
     * Side effects: None.
     */
    private function retrievalTestChunksForCustomer(int $customerId): Collection
    {
        return KnowledgeItemChunk::query()
            ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_item_chunks.knowledge_item_id')
            ->where('knowledge_items.customer_id', $customerId)
            ->where('knowledge_items.is_active', true)
            ->whereNotNull('knowledge_items.storage_path')
            ->where('knowledge_items.extraction_status', KnowledgeItem::EXTRACTION_STATUS_COMPLETED)
            ->whereNotNull('knowledge_item_chunks.embedding_vector')
            ->orderByDesc('knowledge_items.updated_at')
            ->orderByDesc('knowledge_items.id')
            ->orderBy('knowledge_item_chunks.chunk_index')
            ->orderBy('knowledge_item_chunks.id')
            ->limit(1000)
            ->get([
                'knowledge_item_chunks.*',
                'knowledge_items.original_filename as knowledge_item_title',
            ])
            ->values();
    }

    /**
     * Purpose: Regenerate deterministic chunks for one knowledge document.
     * Inputs: The knowledge document and its structured text blocks.
     * Returns: None.
     * Side effects: Deletes old chunks and inserts the current chunk set.
     */
    private function syncChunks(KnowledgeItem $knowledgeDocument, array $structuredBlocks): Collection
    {
        $knowledgeDocument->chunks()->delete();
        $chunkPayloads = $this->documentChunker->chunkStructured($structuredBlocks);

        if ($chunkPayloads === []) {
            return collect();
        }

        return collect($knowledgeDocument->chunks()->createMany(
                array_map(
                    static fn (array $chunkPayload, int $chunkIndex): array => [
                        'chunk_index' => $chunkIndex,
                        'content' => (string) ($chunkPayload['content'] ?? ''),
                        'start_offset' => (int) ($chunkPayload['char_start'] ?? 0),
                        'end_offset' => (int) ($chunkPayload['char_end'] ?? 0),
                        'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
                        'section_title' => $chunkPayload['section_title'] ?? null,
                        'section_path' => $chunkPayload['section_path'] ?? null,
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

            $chunkText = trim((string) $chunk->content);

            if ($chunkText === '') {
                $chunk->forceFill([
                    'embedding_error' => 'Chunk content was empty and was not embedded.',
                ])->save();

                continue;
            }

            $outcome = app(\App\Services\OpenAi\EmbeddingService::class)->tryEmbedText($chunkText);

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
            return Str::limit($text, 180, '...');
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
