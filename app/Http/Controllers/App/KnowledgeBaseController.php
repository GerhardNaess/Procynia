<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateKnowledgeChunkMetadataForDocument;
use App\Jobs\GenerateKnowledgeChunkMetadataBatch;
use App\Models\Customer;
use App\Models\KnowledgeDocumentCategory;
use App\Models\KnowledgeDocumentTopic;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Models\KnowledgeItemRevision;
use App\Models\KnowledgeItemVersion;
use App\Models\KnowledgeMetadataTerm;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataGenerationService;
use App\Services\Ai\Knowledge\KnowledgeDocumentSummaryGenerationService;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use App\Services\DocumentTextExtractor;
use App\Services\Knowledge\AiKnowledgeChunkBoundaryService;
use App\Services\Knowledge\KnowledgeChunkBoundaryValidator;
use App\Services\Knowledge\KnowledgeChunkBuilder;
use App\Services\Knowledge\KnowledgeDocumentStructureParser;
use App\Services\Knowledge\PdfFigurePreviewRenderer;
use App\Services\Ai\Knowledge\KnowledgeChunkVocabularyCandidateService;
use App\Services\Billing\BillingEntitlementService;
use App\Services\KnowledgeChunkCoverageService;
use App\Services\OpenAi\EmbeddingService;
use App\Support\CustomerContext;
use App\Support\CosineSimilarity;
use App\Support\PgVector;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KnowledgeBaseController extends Controller
{
    private const RULE_BASED_CHUNK_MAX_WORDS = 800;
    private const RULE_BASED_MIN_SEMANTIC_CHUNK_WORDS = 40;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly DocumentTextExtractor $documentTextExtractor,
        private readonly KnowledgeDocumentStructureParser $knowledgeDocumentStructureParser,
        private readonly AiKnowledgeChunkBoundaryService $aiKnowledgeChunkBoundaryService,
        private readonly KnowledgeChunkBoundaryValidator $knowledgeChunkBoundaryValidator,
        private readonly KnowledgeChunkBuilder $knowledgeChunkBuilder,
        private readonly KnowledgeChunkCoverageService $knowledgeChunkCoverageService,
        private readonly KnowledgeChunkMetadataGenerationService $knowledgeChunkMetadataGenerationService,
        private readonly KnowledgeDocumentSummaryGenerationService $knowledgeDocumentSummaryGenerationService,
        private readonly KnowledgeChunkVocabularyCandidateService $knowledgeChunkVocabularyCandidateService,
        private readonly KnowledgeMetadataVocabularyService $knowledgeMetadataVocabularyService,
        private readonly PdfFigurePreviewRenderer $pdfFigurePreviewRenderer,
        private readonly AiUsageGuard $aiUsageGuard,
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

        $documentCategoryFilter = $request->query('document_category_id');
        $documentTopicFilter = $request->query('document_topic_id');

        $knowledgeDocuments = $this->scopedDocumentsQuery($customerId)
            ->when(
                is_numeric($documentCategoryFilter) && (int) $documentCategoryFilter > 0,
                static fn ($query) => $query->where('document_category_id', (int) $documentCategoryFilter),
            )
            ->when(
                is_numeric($documentTopicFilter) && (int) $documentTopicFilter > 0,
                static fn ($query) => $query->where('document_topic_id', (int) $documentTopicFilter),
            )
            ->with([
                'documentCategory',
                'documentTopic',
                'owner',
                'owningSavedNotice',
                'documentThemeTerm',
                'uploadedBy',
                'chunks' => static fn ($query) => $query
                    ->select(['id', 'knowledge_item_id', 'chunk_index', 'chunk_type', 'content'])
                    ->orderBy('chunk_index'),
            ])
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
                'documentCategory',
                'documentTopic',
                'owner',
                'owningSavedNotice',
                'documentThemeTerm',
                'uploadedBy',
                'revisions' => static fn ($query) => $query
                    ->with('changedBy')
                    ->orderBy('revision_no')
                    ->orderBy('id'),
                'chunks' => static fn ($query) => $query->orderBy('chunk_index'),
                'versions' => static fn ($query) => $query
                    ->with('uploadedBy', 'approvedBy', 'rejectedBy', 'submittedForReviewBy')
                    ->withCount('chunks')
                    ->orderByDesc('version_no'),
            ])
            ->withCount('chunks')
            ->whereKey($knowledgeItem->id)
            ->firstOrFail();

        $this->ensureDocumentSummaryWithGuard($record, $user);

        return Inertia::render('App/AI/KnowledgeBase/Show', [
            'pageTitle' => 'Kunnskapsdokumenter · '.$record->original_filename,
            'knowledgeItem' => $this->documentDetailPayload($record),
            'indexUrl' => route('app.ai.knowledge-base.index'),
            'summaryUpdateUrl' => route('app.ai.knowledge-base.summary.update', ['knowledgeItem' => $record->id]),
            'editUrl' => route('app.ai.knowledge-base.edit', ['knowledgeItem' => $record->id]),
            'replaceFileUrl' => route('app.ai.knowledge-base.file.replace', ['knowledgeItem' => $record->id]),
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
     * Purpose: Update editable metadata or manually maintained content for one knowledge chunk.
     * Inputs: The current frontend request, the parent knowledge document, and the chunk.
     * Returns: A redirect back to the detailed view of the document.
     * Side effects: Updates one chunk only; content changes regenerate only that chunk embedding and queue metadata for that chunk.
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

        if ($this->requestHasManualChunkContentPayload($request)) {
            $customer = Customer::query()->findOrFail($customerId);
            $this->assertAiAccess($customer);
            $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
                $customer,
                $user,
                AiUsageGuard::OPERATION_KNOWLEDGE_CHUNK_METADATA_UPDATE,
            );

            if ($usageWarning !== null) {
                session()->flash('warning', $usageWarning);
            }
        }

        $contentChanged = $this->applyManualChunkContentUpdate($request, $record, $chunkRecord, $payload);

        if ($contentChanged) {
            $chunkRecord->refresh();
            $this->resetGeneratedChunkFields($chunkRecord);
            $this->regenerateChunkEmbeddingWithoutMetadata($record, $chunkRecord);
            GenerateKnowledgeChunkMetadataBatch::dispatch((int) $record->id, [(int) $chunkRecord->id]);

            return redirect()
                ->route('app.ai.knowledge-base.show', ['knowledgeItem' => $record->id])
                ->with('success', 'Chunk-innhold oppdatert. Embedding og metadata regenereres for denne chunken.');
        }

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
            'documentCategoryOptions' => $this->documentCategoryOptionsForCustomer($customerId),
            'documentOwnershipOptions' => $this->documentOwnershipOptions(),
            'documentThemeOptions' => $this->documentThemeOptionsForCustomer($customerId),
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
        $payload = $this->validatedStorePayload($request, $customerId);
        $customer = Customer::query()->findOrFail($customerId);
        $this->assertAiAccess($customer);
        $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
            $customer,
            $user,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
        );

        if ($usageWarning !== null) {
            session()->flash('warning', $usageWarning);
        }

        $fileHash = $this->calculateFileHash($payload['document']);
        $existingVersion = KnowledgeItemVersion::query()
            ->where('customer_id', $customerId)
            ->where('file_hash_sha256', $fileHash)
            ->first();
        if ($existingVersion !== null) {
            throw ValidationException::withMessages([
                'document' => __('procynia.knowledge_base.validation.duplicate_file_new_document'),
                'duplicate_file' => 'new_document',
            ]);
        }

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
                $user,
                $storedPath,
                $absolutePath,
                $extractedText,
                $chunkPayloads,
                $extractionFailed,
                $fileHash,
            ): array {
                $knowledgeDocument = KnowledgeItem::query()->create([
                    'customer_id' => $customerId,
                    'uploaded_by_user_id' => $request->user()?->id,
                    'owner_user_id' => $request->user()?->id,
                    'ownership_type' => $payload['ownership_type'],
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
                    'document_category_id' => $payload['document_category_id'],
                    'document_topic_id' => $payload['document_topic_id'],
                    'document_theme_term_id' => $payload['document_theme_term_id'],
                    'ai_usage_enabled' => $payload['ai_usage_enabled'],
                    'document_status' => $payload['document_status'],
                    'last_reviewed_at' => $payload['last_reviewed_at'],
                    'review_due_at' => $payload['review_due_at'],
                    'extracted_text' => $extractedText,
                    'extraction_status' => $extractionFailed
                        ? KnowledgeItem::EXTRACTION_STATUS_FAILED
                        : KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
                    'extraction_error' => $extractionFailed
                        ? 'Tekst kunne ikke trekkes ut fra dokumentet.'
                        : null,
                    'is_active' => $payload['is_active'],
                ]);

                $knowledgeVersion = KnowledgeItemVersion::query()->create([
                    'knowledge_item_id' => $knowledgeDocument->id,
                    'customer_id' => $customerId,
                    'version_no' => 1,
                    'is_current' => true,
                    'original_filename' => $knowledgeDocument->original_filename,
                    'storage_path' => $storedPath,
                    'mime_type' => $knowledgeDocument->mime_type,
                    'file_size_bytes' => $knowledgeDocument->file_size_bytes,
                    'extracted_text' => $extractedText,
                    'extraction_status' => $knowledgeDocument->extraction_status,
                    'extraction_error' => $knowledgeDocument->extraction_error,
                    'uploaded_by_user_id' => $request->user()?->id,
                    'uploaded_at' => $knowledgeDocument->created_at,
                    'file_hash_sha256' => $fileHash,
                ]);

                $this->recordKnowledgeItemRevision(
                    $knowledgeDocument,
                    KnowledgeItemRevision::CHANGE_TYPE_CREATED,
                    (int) $user->id,
                    $knowledgeVersion,
                );

                return [
                    'knowledge_document' => $knowledgeDocument,
                    'knowledge_version' => $knowledgeVersion,
                    'chunks' => $this->syncChunks($knowledgeDocument, $chunkPayloads, $absolutePath, $knowledgeVersion),
                ];
            });

            $this->syncChunkEmbeddingsWithoutMetadata($result['knowledge_document'], $result['chunks']);
            $this->ensureDocumentSummary($result['knowledge_document'], (int) $user->id);
            GenerateKnowledgeChunkMetadataForDocument::dispatch((int) $result['knowledge_document']->id, (int) $result['knowledge_version']->id);
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
        $record->loadMissing([
            'documentCategory',
            'documentTopic',
            'owner',
            'owningSavedNotice',
            'documentThemeTerm',
            'uploadedBy',
            'chunks' => static fn ($query) => $query
                ->select(['id', 'knowledge_item_id', 'chunk_index', 'chunk_type', 'content'])
                ->orderBy('chunk_index'),
        ]);

        return Inertia::render('App/AI/KnowledgeBase/Edit', [
            'pageTitle' => 'Kunnskapsdokumenter · Rediger',
            'knowledgeItem' => $this->documentFormPayload($record),
            'documentTypeOptions' => $this->documentTypeOptions(),
            'documentCategoryOptions' => $this->documentCategoryOptionsForCustomer($customerId),
            'documentOwnershipOptions' => $this->documentOwnershipOptions(),
            'documentThemeOptions' => $this->documentThemeOptionsForCustomer($customerId),
            'documentOwnerOptions' => $this->documentOwnerOptionsForCustomer($customerId),
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
        $payload = $this->validatedUpdatePayload($request, $customerId, $record);

        DB::transaction(function () use ($record, $payload, $user): void {
            $updates = [
                'document_type' => $payload['document_type'],
                'content_type' => $payload['document_type'],
                'ownership_type' => $payload['ownership_type'],
                'is_active' => $payload['is_active'],
                'ai_usage_enabled' => $payload['ai_usage_enabled'],
                'document_status' => $payload['document_status'],
                'last_reviewed_at' => $payload['last_reviewed_at'],
                'review_due_at' => $payload['review_due_at'],
            ];

            if (array_key_exists('document_category_id', $payload)) {
                $updates['document_category_id'] = $payload['document_category_id'];
            }

            if (array_key_exists('document_topic_id', $payload)) {
                $updates['document_topic_id'] = $payload['document_topic_id'];
            }

            if (array_key_exists('document_theme_term_id', $payload)) {
                $updates['document_theme_term_id'] = $payload['document_theme_term_id'];
            }

            if (array_key_exists('owner_user_id', $payload)) {
                $updates['owner_user_id'] = $payload['owner_user_id'];
            }

            $record->forceFill($updates)->save();
            $this->recordKnowledgeItemRevision(
                $record,
                KnowledgeItemRevision::CHANGE_TYPE_METADATA_UPDATED,
                (int) $user->id,
            );
        });

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

        DB::transaction(function () use ($record, $user): void {
            $this->recordKnowledgeItemRevision(
                $record,
                KnowledgeItemRevision::CHANGE_TYPE_DELETED,
                (int) $user->id,
            );
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
     * Purpose: Replace the file on an existing knowledge document with a new upload.
     * Inputs: The current frontend request and the route-bound knowledge document.
     * Returns: A redirect back to the document show page.
     * Side effects: Stores a new file, creates a new KnowledgeItemVersion, regenerates chunks
     *               and embeddings for the new version, activates it, and updates legacy fields.
     */
    public function replaceFile(Request $request, KnowledgeItem $knowledgeItem): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedDocument($customerId, $knowledgeItem->id);
        $customer = Customer::query()->findOrFail($customerId);
        $this->assertAiAccess($customer);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,xlsx', 'max:20480'],
        ]);

        $file = $validated['file'];

        $fileHash = $this->calculateFileHash($file);
        if (KnowledgeItemVersion::query()->where('knowledge_item_id', $record->id)->where('file_hash_sha256', $fileHash)->exists()) {
            throw ValidationException::withMessages([
                'file' => __('procynia.knowledge_base.validation.duplicate_file_same_document'),
                'duplicate_file' => 'same_document',
            ]);
        }
        if (KnowledgeItemVersion::query()->where('customer_id', $customerId)->where('knowledge_item_id', '!=', $record->id)->where('file_hash_sha256', $fileHash)->exists()) {
            throw ValidationException::withMessages([
                'file' => __('procynia.knowledge_base.validation.duplicate_file_other_document'),
                'duplicate_file' => 'other_document',
            ]);
        }

        $newStoredPath = null;

        try {
            $storedFilename = $this->storedFilename($file->getClientOriginalExtension());
            $newStoredPath = Storage::disk('local')->putFileAs(
                sprintf('customers/%d/knowledge-documents', $customerId),
                $file,
                $storedFilename,
            );

            abort_unless(is_string($newStoredPath) && $newStoredPath !== '', 500, 'Failed to store the replacement file.');

            $absolutePath = Storage::disk('local')->path($newStoredPath);
            $extractedText = $this->documentTextExtractor->extractText($absolutePath);
            $extractionFailed = trim($extractedText) === '';

            $nextVersionNo = ((int) KnowledgeItemVersion::query()
                ->where('knowledge_item_id', $record->id)
                ->max('version_no')) + 1;

            $newVersion = KnowledgeItemVersion::query()->create([
                'knowledge_item_id' => $record->id,
                'customer_id' => $customerId,
                'version_no' => $nextVersionNo,
                'is_current' => false,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $newStoredPath,
                'mime_type' => $file->getClientMimeType(),
                'file_size_bytes' => (int) $file->getSize(),
                'extracted_text' => $extractedText,
                'extraction_status' => $extractionFailed
                    ? KnowledgeItem::EXTRACTION_STATUS_FAILED
                    : KnowledgeItem::EXTRACTION_STATUS_COMPLETED,
                'extraction_error' => $extractionFailed
                    ? 'Tekst kunne ikke trekkes ut fra dokumentet.'
                    : null,
                'uploaded_by_user_id' => $request->user()?->id,
                'uploaded_at' => now(),
                'file_hash_sha256' => $fileHash,
            ]);

            if ($extractionFailed) {
                return redirect()
                    ->route('app.ai.knowledge-base.show', ['knowledgeItem' => $record->id])
                    ->with('error', 'Tekstuttrekk feilet for ny fil. Eksisterende versjon er beholdt som aktiv.');
            }

            $structure = $this->knowledgeDocumentStructureParser->parse($absolutePath);
            $chunkPayloads = $this->buildRuleBasedH2ChunkPayloads($structure);

            $newChunks = DB::transaction(function () use ($record, $chunkPayloads, $absolutePath, $newVersion): Collection {
                return $this->syncChunks($record, $chunkPayloads, $absolutePath, $newVersion);
            });

            $this->syncChunkEmbeddingsWithoutMetadata($record, $newChunks);

            DB::transaction(function () use ($record, $newVersion, $user, $extractedText): void {
                KnowledgeItemVersion::query()
                    ->where('knowledge_item_id', $record->id)
                    ->where('id', '!=', $newVersion->id)
                    ->update(['is_current' => false]);

                $newVersion->forceFill(['is_current' => true])->save();

                $record->forceFill([
                    'original_filename' => $newVersion->original_filename,
                    'storage_path' => $newVersion->storage_path,
                    'mime_type' => $newVersion->mime_type,
                    'file_size_bytes' => $newVersion->file_size_bytes,
                    'extracted_text' => $extractedText,
                    'extraction_status' => $newVersion->extraction_status,
                    'extraction_error' => $newVersion->extraction_error,
                    'uploaded_by_user_id' => $newVersion->uploaded_by_user_id,
                ])->save();

                $this->recordKnowledgeItemRevision(
                    $record,
                    KnowledgeItemRevision::CHANGE_TYPE_FILE_REPLACED,
                    (int) $user->id,
                    $newVersion,
                );
            });

            GenerateKnowledgeChunkMetadataForDocument::dispatch((int) $record->id, (int) $newVersion->id);

        } catch (Throwable $throwable) {
            if (is_string($newStoredPath) && $newStoredPath !== '') {
                Storage::disk('local')->delete($newStoredPath);
            }

            throw $throwable;
        }

        return redirect()
            ->route('app.ai.knowledge-base.show', ['knowledgeItem' => $record->id])
            ->with('success', 'Ny filversjon lastet opp og aktivert.');
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
     * Purpose: Verify that the current customer may use the AI features in the knowledge base area.
     * Inputs: The customer resolved from the current frontend context.
     * Returns: None.
     * Side effects: Aborts with HTTP 403 when the customer lacks AI entitlement.
     */
    private function assertAiAccess(Customer $customer): void
    {
        abort_unless(app(BillingEntitlementService::class)->canUseAiOffer($customer), 403, __('procynia.ai.ai_access_unavailable_message'));
    }

    /**
     * Purpose: Ensure the document has a persisted AI summary when the customer may use AI features.
     * Inputs: The knowledge document loaded for the current customer.
     * Returns: The persisted summary string when one is available, otherwise null.
     * Side effects: May call OpenAI and updates the document summary column.
     */
    /**
     * Purpose: Generate a lazy document summary only when one is missing and the monthly AI quota allows it.
     * Inputs: The knowledge document and the currently authenticated user.
     * Returns: The summary string or null when generation is skipped or fails.
     * Side effects: May call the AI summary service and update the document row; silently skips when quota is exhausted.
     */
    private function ensureDocumentSummaryWithGuard(KnowledgeItem $knowledgeDocument, User $user): ?string
    {
        $existingSummary = trim((string) $knowledgeDocument->summary);

        if ($existingSummary !== '') {
            return $existingSummary;
        }

        if ($knowledgeDocument->extraction_status !== KnowledgeItem::EXTRACTION_STATUS_COMPLETED) {
            return null;
        }

        $customer = Customer::query()->find($knowledgeDocument->customer_id);

        if (! $customer instanceof Customer || ! app(BillingEntitlementService::class)->canUseAiOffer($customer)) {
            return null;
        }

        $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
            $customer,
            $user,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
        );

        if ($usageWarning !== null) {
            session()->flash('warning', $usageWarning);
        }

        return $this->ensureDocumentSummary($knowledgeDocument, (int) $user->id);
    }

    private function ensureDocumentSummary(KnowledgeItem $knowledgeDocument, ?int $userId = null): ?string
    {
        $existingSummary = trim((string) $knowledgeDocument->summary);

        if ($existingSummary !== '') {
            return $existingSummary;
        }

        if ($knowledgeDocument->extraction_status !== KnowledgeItem::EXTRACTION_STATUS_COMPLETED) {
            return null;
        }

        $customer = Customer::query()->find($knowledgeDocument->customer_id);

        if (! $customer instanceof Customer || ! app(BillingEntitlementService::class)->canUseAiOffer($customer)) {
            return null;
        }

        $summary = $this->knowledgeDocumentSummaryGenerationService->generateForDocument($knowledgeDocument, $userId);
        $summary = $this->cleanNullableString($summary, 20000);

        if ($summary === null) {
            return null;
        }

        $knowledgeDocument->forceFill([
            'summary' => $summary,
        ])->save();
        $knowledgeDocument->summary = $summary;

        return $summary;
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
    private function validatedStorePayload(Request $request, int $customerId): array
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,docx,xlsx', 'max:20480'],
            'document_type' => ['required', 'string', Rule::in(KnowledgeItem::DOCUMENT_TYPES)],
            'ownership_type' => ['nullable', 'string', Rule::in(KnowledgeItem::OWNERSHIP_TYPES)],
            'document_category_id' => $this->documentCategoryValidationRulesForCustomer($customerId),
            'document_topic_id' => $this->documentTopicValidationRulesForCustomer($customerId),
            'ai_usage_enabled' => ['sometimes', 'boolean'],
            'document_status' => ['sometimes', 'string', Rule::in(KnowledgeItem::DOCUMENT_STATUSES)],
            'document_theme_term_id' => $this->documentThemeValidationRulesForCustomer($customerId),
            'last_reviewed_at' => ['sometimes', 'nullable', 'date'],
            'review_due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $catalogPayload = $this->validatedDocumentCatalogSelection($request, $customerId);
        $documentStatus = array_key_exists('document_status', $validated)
            ? (string) $validated['document_status']
            : KnowledgeItem::DOCUMENT_STATUS_ACTIVE;

        return [
            'document' => $validated['document'],
            'document_type' => Str::lower(trim((string) $validated['document_type'])),
            'ownership_type' => array_key_exists('ownership_type', $validated)
                ? Str::lower(trim((string) $validated['ownership_type']))
                : KnowledgeItem::OWNERSHIP_TYPE_COMPANY,
            'document_category_id' => $catalogPayload['document_category_id'] ?? null,
            'document_topic_id' => $catalogPayload['document_topic_id'] ?? null,
            'is_active' => $documentStatus === KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'ai_usage_enabled' => array_key_exists('ai_usage_enabled', $validated)
                ? (bool) $validated['ai_usage_enabled']
                : true,
            'document_status' => $documentStatus,
            'document_theme_term_id' => array_key_exists('document_theme_term_id', $validated)
                ? $this->normalizeNullableDocumentThemeTermId($validated['document_theme_term_id'])
                : null,
            'last_reviewed_at' => $this->normalizeNullableDateString($validated['last_reviewed_at'] ?? null),
            'review_due_at' => $this->normalizeNullableDateString($validated['review_due_at'] ?? null),
        ];
    }

    /**
     * Purpose: Validate and normalize the metadata update payload for a knowledge document.
     * Inputs: The current frontend request.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedUpdatePayload(Request $request, int $customerId, KnowledgeItem $knowledgeDocument): array
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(KnowledgeItem::DOCUMENT_TYPES)],
            'ownership_type' => ['required', 'string', Rule::in(KnowledgeItem::OWNERSHIP_TYPES)],
            'document_category_id' => $this->documentCategoryValidationRulesForCustomer($customerId),
            'document_topic_id' => $this->documentTopicValidationRulesForCustomer($customerId),
            'ai_usage_enabled' => ['sometimes', 'boolean'],
            'document_status' => ['sometimes', 'string', Rule::in(KnowledgeItem::DOCUMENT_STATUSES)],
            'document_theme_term_id' => $this->documentThemeValidationRulesForCustomer($customerId),
            'owner_user_id' => $this->documentOwnerValidationRulesForCustomer($customerId),
            'last_reviewed_at' => ['sometimes', 'nullable', 'date'],
            'review_due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $catalogPayload = $this->validatedDocumentCatalogSelection($request, $customerId, $knowledgeDocument);
        $documentStatus = array_key_exists('document_status', $validated)
            ? (string) $validated['document_status']
            : (string) ($knowledgeDocument->document_status ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE);
        $payload = [
            'document_type' => Str::lower(trim((string) $validated['document_type'])),
            'ownership_type' => Str::lower(trim((string) $validated['ownership_type'])),
            'is_active' => $documentStatus === KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'ai_usage_enabled' => array_key_exists('ai_usage_enabled', $validated)
                ? (bool) $validated['ai_usage_enabled']
                : (bool) $knowledgeDocument->ai_usage_enabled,
            'document_status' => $documentStatus,
            'last_reviewed_at' => array_key_exists('last_reviewed_at', $validated)
                ? $this->normalizeNullableDateString($validated['last_reviewed_at'])
                : $knowledgeDocument->last_reviewed_at?->toDateString(),
            'review_due_at' => array_key_exists('review_due_at', $validated)
                ? $this->normalizeNullableDateString($validated['review_due_at'])
                : $knowledgeDocument->review_due_at?->toDateString(),
        ];

        if (array_key_exists('document_category_id', $catalogPayload)) {
            $payload['document_category_id'] = $catalogPayload['document_category_id'];
        }

        if (array_key_exists('document_topic_id', $catalogPayload)) {
            $payload['document_topic_id'] = $catalogPayload['document_topic_id'];
        }

        if (array_key_exists('document_theme_term_id', $validated)) {
            $payload['document_theme_term_id'] = $this->normalizeNullableDocumentThemeTermId($validated['document_theme_term_id']);
        }

        if (array_key_exists('owner_user_id', $validated)) {
            $payload['owner_user_id'] = $validated['owner_user_id'] !== null
                ? (int) $validated['owner_user_id']
                : null;
        }

        return $payload;
    }

    /**
     * Purpose: Validate and normalize the document category/topic selection for a knowledge document.
     * Inputs: The current frontend request, the customer id, and the current document when updating.
     * Returns: A normalized payload with catalog ids when they are present or need clearing.
     * Side effects: Throws validation errors when category/topic selections are invalid.
     *
     * @return array<string, int|null>
     */
    private function validatedDocumentCatalogSelection(Request $request, int $customerId, ?KnowledgeItem $knowledgeDocument = null): array
    {
        $validated = $request->validate([
            'document_category_id' => ['sometimes', 'nullable', 'integer', $this->documentCategoryValidationRuleForCustomer($customerId)],
            'document_topic_id' => ['sometimes', 'nullable', 'integer', $this->documentTopicValidationRuleForCustomer($customerId)],
        ]);

        $categoryProvided = array_key_exists('document_category_id', $validated);
        $topicProvided = array_key_exists('document_topic_id', $validated);

        $categoryId = $categoryProvided
            ? $this->normalizeNullableDocumentCatalogId($validated['document_category_id'])
            : $knowledgeDocument?->document_category_id;
        $topicId = $topicProvided
            ? $this->normalizeNullableDocumentCatalogId($validated['document_topic_id'])
            : $knowledgeDocument?->document_topic_id;

        if ($categoryProvided && $categoryId === null) {
            if ($topicProvided && $topicId !== null) {
                throw ValidationException::withMessages([
                    'document_topic_id' => __('procynia.knowledge_base.validation.document_topic_requires_category'),
                ]);
            }

            $topicId = null;
        }

        if ($topicId !== null && $categoryId === null) {
            throw ValidationException::withMessages([
                'document_topic_id' => __('procynia.knowledge_base.validation.document_topic_requires_category'),
            ]);
        }

        $category = null;
        if ($categoryId !== null) {
            $category = KnowledgeDocumentCategory::query()
                ->forCustomer($customerId)
                ->active()
                ->with(['topics' => static fn ($query) => $query
                    ->forCustomer($customerId)
                    ->active()
                    ->ordered()])
                ->whereKey($categoryId)
                ->first();

            if (! $category instanceof KnowledgeDocumentCategory) {
                throw ValidationException::withMessages([
                    'document_category_id' => __('procynia.knowledge_base.validation.invalid_document_category'),
                ]);
            }
        }

        $topic = null;
        if ($topicId !== null) {
            $topic = KnowledgeDocumentTopic::query()
                ->forCustomer($customerId)
                ->active()
                ->whereKey($topicId)
                ->first();

            if (! $topic instanceof KnowledgeDocumentTopic) {
                throw ValidationException::withMessages([
                    'document_topic_id' => __('procynia.knowledge_base.validation.invalid_document_topic'),
                ]);
            }
        }

        if ($category !== null && $topic !== null && ! $category->topics->contains(fn (KnowledgeDocumentTopic $allowedTopic): bool => (int) $allowedTopic->id === (int) $topic->id)) {
            throw ValidationException::withMessages([
                'document_topic_id' => __('procynia.knowledge_base.validation.document_topic_requires_category'),
            ]);
        }

        $payload = [];

        if ($categoryProvided || $knowledgeDocument === null) {
            $payload['document_category_id'] = $categoryId;
        }

        if ($topicProvided || ($categoryProvided && $categoryId === null) || ($knowledgeDocument !== null && $knowledgeDocument->document_category_id !== null && $knowledgeDocument->document_topic_id !== null && $categoryProvided && $categoryId !== null)) {
            $payload['document_topic_id'] = $topicId;
        }

        return $payload;
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
     * Purpose: Validate and normalize editable metadata and optional manual chunk content updates.
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
            'content' => ['nullable', 'string', 'max:200000'],
            'table_text' => ['nullable', 'string', 'max:200000'],
            'table_markdown' => ['nullable', 'string', 'max:200000'],
            'table_html' => ['nullable', 'string', 'max:500000'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'image_alt_text' => ['nullable', 'string', 'max:2000'],
            'image_caption' => ['nullable', 'string', 'max:2000'],
            'ocr_text' => ['nullable', 'string', 'max:20000'],
            'image_description' => ['nullable', 'string', 'max:20000'],
        ]);

        return [
            'title' => $this->cleanNullableString($validated['title'] ?? null, 255),
            'ai_summary' => $this->cleanNullableString($validated['ai_summary'] ?? null, 20000),
            'service_product_tag' => $this->cleanNullableString($validated['service_product_tag'] ?? null, 191),
            'theme_tag' => $this->cleanNullableString($validated['theme_tag'] ?? null, 191),
            'topic' => $this->cleanNullableString($validated['topic'] ?? null, 191),
            'sub_topic' => $this->cleanNullableString($validated['sub_topic'] ?? null, 191),
            'keywords' => $this->knowledgeChunkCoverageService->normalizeKeywords($validated['keywords'] ?? null),
            'content' => array_key_exists('content', $validated)
                ? $this->cleanNullableString($validated['content'] ?? null, 200000)
                : null,
            'table_text' => array_key_exists('table_text', $validated)
                ? $this->cleanNullableString($validated['table_text'] ?? null, 200000)
                : null,
            'table_markdown' => array_key_exists('table_markdown', $validated)
                ? $this->cleanNullableString($validated['table_markdown'] ?? null, 200000)
                : null,
            'table_html' => array_key_exists('table_html', $validated)
                ? $this->cleanNullableString($validated['table_html'] ?? null, 500000)
                : null,
            'image' => $request->file('image'),
            'image_alt_text' => array_key_exists('image_alt_text', $validated)
                ? $this->cleanNullableString($validated['image_alt_text'] ?? null, 2000)
                : null,
            'image_caption' => array_key_exists('image_caption', $validated)
                ? $this->cleanNullableString($validated['image_caption'] ?? null, 2000)
                : null,
            'ocr_text' => array_key_exists('ocr_text', $validated)
                ? $this->cleanNullableString($validated['ocr_text'] ?? null, 20000)
                : null,
            'image_description' => array_key_exists('image_description', $validated)
                ? $this->cleanNullableString($validated['image_description'] ?? null, 20000)
                : null,
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
     * Purpose: Build the selectable document category options used by the knowledge document forms.
     * Inputs: The customer id.
     * Returns: A stable list of customer-scoped categories with allowed topic selections.
     * Side effects: None.
     */
    private function documentCategoryOptionsForCustomer(int $customerId): array
    {
        return KnowledgeDocumentCategory::query()
            ->forCustomer($customerId)
            ->active()
            ->with(['topics' => static fn ($query) => $query
                ->forCustomer($customerId)
                ->active()
                ->ordered()])
            ->ordered()
            ->get()
            ->map(static function (KnowledgeDocumentCategory $category): array {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'topics' => $category->topics
                        ->map(static fn (KnowledgeDocumentTopic $topic): array => [
                            'id' => $topic->id,
                            'name' => $topic->name,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Build the selectable ownership options used by the knowledge document forms.
     * Inputs: None.
     * Returns: A stable list of ownership options.
     * Side effects: None.
     */
    private function documentOwnershipOptions(): array
    {
        return [
            [
                'value' => KnowledgeItem::OWNERSHIP_TYPE_COMPANY,
                'label' => __('procynia.knowledge.ownership_company'),
                'selectable' => true,
            ],
            [
                'value' => KnowledgeItem::OWNERSHIP_TYPE_PERSONAL,
                'label' => __('procynia.knowledge.ownership_personal'),
                'selectable' => true,
            ],
            [
                'value' => KnowledgeItem::OWNERSHIP_TYPE_CASE,
                'label' => __('procynia.knowledge.ownership_case'),
                'selectable' => false,
            ],
        ];
    }

    /**
     * Purpose: Build the validation rules for a customer-scoped document category id.
     * Inputs: The customer id.
     * Returns: Validation rules that only accept active categories for the current customer.
     * Side effects: None.
     *
     * @return array<int, mixed>
     */
    private function documentCategoryValidationRulesForCustomer(int $customerId): array
    {
        return [
            'sometimes',
            'nullable',
            'integer',
            $this->documentCategoryValidationRuleForCustomer($customerId),
        ];
    }

    /**
     * Purpose: Build the validation rule for a customer-scoped document category id.
     * Inputs: The customer id.
     * Returns: A validation rule accepting only active customer-owned categories.
     * Side effects: None.
     */
    private function documentCategoryValidationRuleForCustomer(int $customerId): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists(KnowledgeDocumentCategory::class, 'id')->where(fn ($query) => $query
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereNull('deleted_at'));
    }

    /**
     * Purpose: Build the validation rules for a customer-scoped document topic id.
     * Inputs: The customer id.
     * Returns: Validation rules that only accept active topics for the current customer.
     * Side effects: None.
     *
     * @return array<int, mixed>
     */
    private function documentTopicValidationRulesForCustomer(int $customerId): array
    {
        return [
            'sometimes',
            'nullable',
            'integer',
            $this->documentTopicValidationRuleForCustomer($customerId),
        ];
    }

    /**
     * Purpose: Build the validation rule for a customer-scoped document topic id.
     * Inputs: The customer id.
     * Returns: A validation rule accepting only active customer-owned topics.
     * Side effects: None.
     */
    private function documentTopicValidationRuleForCustomer(int $customerId): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists(KnowledgeDocumentTopic::class, 'id')->where(fn ($query) => $query
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereNull('deleted_at'));
    }

    /**
     * Purpose: Normalize an optional document category/topic id after validation.
     * Inputs: A validated raw category or topic id.
     * Returns: An integer id or null.
     * Side effects: None.
     */
    private function normalizeNullableDocumentCatalogId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Purpose: Convert the ownership data into a frontend payload.
     * Inputs: A customer-scoped knowledge document.
     * Returns: The document ownership fields needed by read payloads.
     * Side effects: None.
     */
    private function ownershipPayload(KnowledgeItem $knowledgeDocument): array
    {
        return [
            'ownership_type' => $knowledgeDocument->ownership_type,
            'ownership_label' => $this->ownershipLabel($knowledgeDocument),
            'owner_user_id' => $knowledgeDocument->owner_user_id,
            'owner_name' => $this->ownerName($knowledgeDocument),
            'owning_saved_notice_id' => $knowledgeDocument->owning_saved_notice_id,
            'owning_saved_notice_title' => $this->owningSavedNoticeTitle($knowledgeDocument),
        ];
    }

    /**
     * Purpose: Resolve the user-facing document category name for a knowledge document.
     * Inputs: A customer-scoped knowledge document.
     * Returns: The category name or null when no category is assigned.
     * Side effects: None.
     */
    private function documentCategoryName(KnowledgeItem $knowledgeDocument): ?string
    {
        $name = trim((string) ($knowledgeDocument->documentCategory?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Purpose: Resolve the user-facing document topic name for a knowledge document.
     * Inputs: A customer-scoped knowledge document.
     * Returns: The topic name or null when no topic is assigned.
     * Side effects: None.
     */
    private function documentTopicName(KnowledgeItem $knowledgeDocument): ?string
    {
        $name = trim((string) ($knowledgeDocument->documentTopic?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Purpose: Resolve the display label for a knowledge document ownership type.
     * Inputs: A customer-scoped knowledge document.
     * Returns: A localized ownership label or the raw ownership type when unknown.
     * Side effects: None.
     */
    private function ownershipLabel(KnowledgeItem $knowledgeDocument): ?string
    {
        $ownershipType = trim((string) $knowledgeDocument->ownership_type);

        if ($ownershipType === '') {
            return null;
        }

        return match ($ownershipType) {
            KnowledgeItem::OWNERSHIP_TYPE_COMPANY => 'Selskap',
            KnowledgeItem::OWNERSHIP_TYPE_PERSONAL => 'Personlig',
            KnowledgeItem::OWNERSHIP_TYPE_CASE => 'Sak',
            default => $ownershipType,
        };
    }

    /**
     * Purpose: Resolve the user-facing owner name for a knowledge document.
     * Inputs: A customer-scoped knowledge document.
     * Returns: The owning user name or null when no owner is assigned.
     * Side effects: None.
     */
    private function ownerName(KnowledgeItem $knowledgeDocument): ?string
    {
        if ($knowledgeDocument->owner_user_id === null) {
            return null;
        }

        $name = trim((string) ($knowledgeDocument->owner?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Purpose: Resolve the user-facing SavedNotice title for a case-owned knowledge document.
     * Inputs: A customer-scoped knowledge document.
     * Returns: The saved notice title or null when no case is assigned.
     * Side effects: None.
     */
    private function owningSavedNoticeTitle(KnowledgeItem $knowledgeDocument): ?string
    {
        if ($knowledgeDocument->owning_saved_notice_id === null) {
            return null;
        }

        $title = trim((string) ($knowledgeDocument->owningSavedNotice?->title ?? ''));

        return $title !== '' ? $title : null;
    }

    /**
     * Purpose: Build the approved document theme options for one customer.
     * Inputs: The customer id.
     * Returns: A stable option list for document-theme selection.
     * Side effects: None.
     */
    private function documentThemeOptionsForCustomer(int $customerId): array
    {
        $catalog = $this->knowledgeMetadataVocabularyService->buildCatalogForCustomer($customerId);
        $themeTerms = data_get($catalog, 'groups.'.KnowledgeMetadataTerm::TYPE_THEME_TAG, []);

        if (! is_array($themeTerms)) {
            return [];
        }

        $options = array_values(array_filter(array_map(
            static function (array $term): ?array {
                $id = (int) data_get($term, 'id');
                $label = trim((string) data_get($term, 'canonical_name', ''));
                $type = (string) data_get($term, 'type', '');

                if ($id <= 0 || $label === '' || $type !== KnowledgeMetadataTerm::TYPE_THEME_TAG) {
                    return null;
                }

                return [
                    'id' => $id,
                    'label' => $label,
                    'type' => $type,
                ];
            },
            $themeTerms,
        )));

        usort(
            $options,
            static function (array $left, array $right): int {
                return strcmp(
                    mb_strtolower((string) data_get($left, 'label', ''), 'UTF-8'),
                    mb_strtolower((string) data_get($right, 'label', ''), 'UTF-8'),
                ) ?: ((int) data_get($left, 'id', 0) <=> (int) data_get($right, 'id', 0));
            },
        );

        return $options;
    }

    /**
     * Purpose: Build the selectable document owner options for one customer.
     * Inputs: The customer id.
     * Returns: A stable list of customer-scoped user options.
     * Side effects: None.
     */
    private function documentOwnerOptionsForCustomer(int $customerId): array
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active'])
            ->map(static function (User $user): array {
                return [
                    'id' => $user->id,
                    'label' => $user->is_active
                        ? sprintf('%s · %s', $user->name, $user->email)
                        : sprintf('%s · %s (inactive)', $user->name, $user->email),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Build the list of approved document theme term ids for one customer.
     * Inputs: The customer id.
     * Returns: A stable list of theme term ids.
     * Side effects: None.
     *
     * @return array<int, int>
     */
    private function documentThemeTermIdsForCustomer(int $customerId): array
    {
        return array_values(array_filter(
            array_map(
                static fn (array $option): int => (int) data_get($option, 'id', 0),
                $this->documentThemeOptionsForCustomer($customerId),
            ),
            static fn (int $termId): bool => $termId > 0,
        ));
    }

    /**
     * Purpose: Build the validation rules for a customer-scoped document owner user id.
     * Inputs: The customer id.
     * Returns: Validation rules that only accept assignable users for the current customer.
     * Side effects: None.
     *
     * @return array<int, mixed>
     */
    private function documentOwnerValidationRulesForCustomer(int $customerId): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists(User::class, 'id')->where(fn ($query) => $query
                ->where('customer_id', $customerId)
                ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])),
        ];
    }

    /**
     * Purpose: Build the validation rules for a customer-scoped document theme term id.
     * Inputs: The customer id.
     * Returns: Validation rules that only accept approved theme terms for the current customer.
     * Side effects: None.
     *
     * @return array<int, mixed>
     */
    private function documentThemeValidationRulesForCustomer(int $customerId): array
    {
        return [
            'sometimes',
            'nullable',
            'integer',
            Rule::in($this->documentThemeTermIdsForCustomer($customerId)),
        ];
    }

    /**
     * Purpose: Normalize an optional document theme term id after validation.
     * Inputs: A validated raw theme term id.
     * Returns: An integer id or null.
     * Side effects: None.
     */
    private function normalizeNullableDocumentThemeTermId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function normalizeNullableDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value)->toDateString();
    }

    private function resolveReviewStateForDocument(KnowledgeItem $knowledgeDocument): string
    {
        $dueAt = $knowledgeDocument->review_due_at;

        if ($dueAt === null) {
            return 'not_set';
        }

        $today = today();

        if ($dueAt->lt($today)) {
            return 'overdue';
        }

        if ($dueAt->lte($today->copy()->addDays(30))) {
            return 'due_soon';
        }

        return 'ok';
    }

    /**
     * Purpose: Resolve the document-level theme label for frontend payloads.
     * Inputs: A customer-scoped knowledge document.
     * Returns: The canonical theme name or null when no theme is assigned.
     * Side effects: None.
     */
    private function documentThemeLabel(KnowledgeItem $knowledgeDocument): ?string
    {
        $label = trim((string) ($knowledgeDocument->documentThemeTerm?->canonical_name ?? ''));

        return $label !== '' ? $label : null;
    }

    /**
     * Purpose: Convert the assigned theme term into a compact payload for frontend reads.
     * Inputs: A customer-scoped knowledge document.
     * Returns: A small theme object or null when no theme is assigned.
     * Side effects: None.
     *
     * @return array{id:int,type:string,canonical_name:?string}|null
     */
    private function documentThemeTermPayload(KnowledgeItem $knowledgeDocument): ?array
    {
        $term = $knowledgeDocument->documentThemeTerm;

        if (! $term instanceof KnowledgeMetadataTerm) {
            return null;
        }

        return [
            'id' => $term->id,
            'type' => $term->type,
            'canonical_name' => $this->documentThemeLabel($knowledgeDocument),
        ];
    }

    /**
     * Purpose: Convert a knowledge document revision into a read-only payload.
     * Inputs: A persisted knowledge document revision.
     * Returns: A compact revision array for frontend reads.
     * Side effects: None.
     *
     * @return array<string, mixed>
     */
    private function documentRevisionPayload(KnowledgeItemRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'revision_no' => $revision->revision_no,
            'change_type' => $revision->change_type,
            'changed_by_user_id' => $revision->changed_by_user_id,
            'changed_by_name' => $revision->changedBy?->name,
            'created_at' => optional($revision->created_at)?->toIso8601String(),
            'snapshot' => $revision->snapshot,
        ];
    }

    /**
     * Purpose: Build a revision snapshot for the current persisted state of one knowledge document.
     * Inputs: A customer-scoped knowledge document.
     * Returns: An append-only snapshot array.
     * Side effects: None.
     *
     * @return array<string, mixed>
     */
    private function knowledgeItemRevisionSnapshot(KnowledgeItem $knowledgeDocument, ?KnowledgeItemVersion $activeVersion = null): array
    {
        return [
            'knowledge_item_id' => $knowledgeDocument->id,
            'customer_id' => $knowledgeDocument->customer_id,
            'title' => $knowledgeDocument->title,
            'original_filename' => $knowledgeDocument->original_filename,
            'path' => $knowledgeDocument->storage_path,
            'mime_type' => $knowledgeDocument->mime_type,
            'document_category_id' => $knowledgeDocument->document_category_id,
            'document_category_name' => $this->documentCategoryName($knowledgeDocument),
            'document_topic_id' => $knowledgeDocument->document_topic_id,
            'document_topic_name' => $this->documentTopicName($knowledgeDocument),
            'document_type' => $knowledgeDocument->document_type,
            'document_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocument->document_type] ?? $knowledgeDocument->document_type,
            'content_type' => $knowledgeDocument->content_type,
            'ownership_type' => $knowledgeDocument->ownership_type,
            'owner_user_id' => $knowledgeDocument->owner_user_id,
            'owning_saved_notice_id' => $knowledgeDocument->owning_saved_notice_id,
            'document_theme_term_id' => $knowledgeDocument->document_theme_term_id,
            'is_active' => (bool) $knowledgeDocument->is_active,
            'ai_usage_enabled' => (bool) $knowledgeDocument->ai_usage_enabled,
            'document_status' => $knowledgeDocument->document_status ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'last_reviewed_at' => $knowledgeDocument->last_reviewed_at?->toDateString(),
            'review_due_at' => $knowledgeDocument->review_due_at?->toDateString(),
            'extraction_status' => $knowledgeDocument->extraction_status,
            'extraction_error' => $knowledgeDocument->extraction_error,
            'summary' => $knowledgeDocument->summary,
            'uploaded_by_user_id' => $knowledgeDocument->uploaded_by_user_id,
            'created_at' => $knowledgeDocument->created_at?->toIso8601String(),
            'updated_at' => $knowledgeDocument->updated_at?->toIso8601String(),
            'knowledge_item_version_id' => $activeVersion?->id,
            'knowledge_item_version_no' => $activeVersion?->version_no,
        ];
    }

    /**
     * Purpose: Persist one append-only revision row for a knowledge document.
     * Inputs: The customer-scoped knowledge document, a revision change type, and the current user id.
     * Returns: The created revision model.
     * Side effects: Writes one revision row.
     */
    private function recordKnowledgeItemRevision(
        KnowledgeItem $knowledgeDocument,
        string $changeType,
        ?int $changedByUserId,
        ?KnowledgeItemVersion $activeVersion = null,
    ): KnowledgeItemRevision {
        return KnowledgeItemRevision::query()->create([
            'knowledge_item_id' => $knowledgeDocument->id,
            'customer_id' => $knowledgeDocument->customer_id,
            'revision_no' => $this->nextKnowledgeItemRevisionNo($knowledgeDocument),
            'change_type' => $changeType,
            'changed_by_user_id' => $changedByUserId,
            'snapshot' => $this->knowledgeItemRevisionSnapshot($knowledgeDocument, $activeVersion),
        ]);
    }

    /**
     * Purpose: Resolve the next revision number for one knowledge document.
     * Inputs: The customer-scoped knowledge document.
     * Returns: The next revision number in sequence.
     * Side effects: None.
     */
    private function nextKnowledgeItemRevisionNo(KnowledgeItem $knowledgeDocument): int
    {
        return (int) (KnowledgeItemRevision::query()
            ->where('knowledge_item_id', $knowledgeDocument->id)
            ->max('revision_no') ?? 0) + 1;
    }

    /**
     * Purpose: Convert a knowledge document into a compact index payload.
     * Inputs: A customer-scoped knowledge document with chunk counts loaded.
     * Returns: An array ready for the index page.
     * Side effects: None.
     */
    private function documentListPayload(KnowledgeItem $knowledgeDocument): array
    {
        return array_merge($this->ownershipPayload($knowledgeDocument), [
            'id' => $knowledgeDocument->id,
            'original_filename' => $knowledgeDocument->original_filename,
            'document_category_id' => $knowledgeDocument->document_category_id,
            'document_category_name' => $this->documentCategoryName($knowledgeDocument),
            'document_topic_id' => $knowledgeDocument->document_topic_id,
            'document_topic_name' => $this->documentTopicName($knowledgeDocument),
            'document_theme_term_id' => $knowledgeDocument->document_theme_term_id,
            'document_theme_label' => $this->documentThemeLabel($knowledgeDocument),
            'document_type' => $knowledgeDocument->document_type,
            'document_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocument->document_type] ?? $knowledgeDocument->document_type,
            'content_type' => $knowledgeDocument->document_type,
            'content_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocument->document_type] ?? $knowledgeDocument->document_type,
            'content_excerpt' => $this->contentExcerpt($knowledgeDocument),
            'summary' => $knowledgeDocument->summary,
            'is_active' => (bool) $knowledgeDocument->is_active,
            'is_active_label' => $knowledgeDocument->is_active ? 'Aktiv' : 'Inaktiv',
            'ai_usage_enabled' => (bool) $knowledgeDocument->ai_usage_enabled,
            'document_status' => $knowledgeDocument->document_status ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'document_status_label' => KnowledgeItem::DOCUMENT_STATUS_LABELS[$knowledgeDocument->document_status ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE] ?? KnowledgeItem::DOCUMENT_STATUS_LABELS[KnowledgeItem::DOCUMENT_STATUS_ACTIVE],
            'last_reviewed_at' => $knowledgeDocument->last_reviewed_at?->toDateString(),
            'review_due_at' => $knowledgeDocument->review_due_at?->toDateString(),
            'review_state' => $this->resolveReviewStateForDocument($knowledgeDocument),
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
        ]);
    }

    /**
     * Purpose: Convert a knowledge document into the edit form payload.
     * Inputs: A customer-scoped knowledge document.
     * Returns: A frontend-ready array for the edit page.
     * Side effects: None.
     */
    private function documentFormPayload(KnowledgeItem $knowledgeDocument): array
    {
        return array_merge($this->ownershipPayload($knowledgeDocument), [
            'id' => $knowledgeDocument->id,
            'original_filename' => $knowledgeDocument->original_filename,
            'document_category_id' => $knowledgeDocument->document_category_id,
            'document_category_name' => $this->documentCategoryName($knowledgeDocument),
            'document_topic_id' => $knowledgeDocument->document_topic_id,
            'document_topic_name' => $this->documentTopicName($knowledgeDocument),
            'document_theme_term_id' => $knowledgeDocument->document_theme_term_id,
            'document_theme_label' => $this->documentThemeLabel($knowledgeDocument),
            'document_theme_term' => $this->documentThemeTermPayload($knowledgeDocument),
            'document_type' => $knowledgeDocument->document_type,
            'content_type' => $knowledgeDocument->document_type,
            'document_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$knowledgeDocument->document_type] ?? $knowledgeDocument->document_type,
            'content_type_label' => KnowledgeItem::CONTENT_TYPE_LABELS[$knowledgeDocument->content_type] ?? $knowledgeDocument->content_type,
            'content_excerpt' => $this->contentExcerpt($knowledgeDocument),
            'summary' => $knowledgeDocument->summary,
            'is_active' => (bool) $knowledgeDocument->is_active,
            'is_active_label' => $knowledgeDocument->is_active ? 'Aktiv' : 'Inaktiv',
            'ai_usage_enabled' => (bool) $knowledgeDocument->ai_usage_enabled,
            'document_status' => $knowledgeDocument->document_status ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE,
            'document_status_label' => KnowledgeItem::DOCUMENT_STATUS_LABELS[$knowledgeDocument->document_status ?? KnowledgeItem::DOCUMENT_STATUS_ACTIVE] ?? KnowledgeItem::DOCUMENT_STATUS_LABELS[KnowledgeItem::DOCUMENT_STATUS_ACTIVE],
            'last_reviewed_at' => $knowledgeDocument->last_reviewed_at?->toDateString(),
            'review_due_at' => $knowledgeDocument->review_due_at?->toDateString(),
            'review_state' => $this->resolveReviewStateForDocument($knowledgeDocument),
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
        ]);
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
                        'content_update_url' => route('app.ai.knowledge-base.chunks.metadata.update', [
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
                        'image_path' => $chunk->image_path,
                        'image_disk' => $chunk->image_disk,
                        'image_mime_type' => $chunk->image_mime_type,
                        'image_original_filename' => $chunk->image_original_filename,
                        'image_width' => $chunk->image_width,
                        'image_height' => $chunk->image_height,
                        'image_hash' => $chunk->image_hash,
                        'image_metadata' => $chunk->image_metadata,
                        'image_alt_text' => $chunk->image_alt_text,
                        'image_caption' => $chunk->image_caption,
                        'ocr_text' => $chunk->ocr_text,
                        'image_description' => $chunk->image_description,
                        'image_url' => $chunk->chunk_type === 'image' && $chunk->image_path !== null
                            ? route('app.ai.knowledge-base.chunks.image', [
                                'knowledgeItem' => $knowledgeDocument->id,
                                'chunk' => $chunk->id,
                                'v' => $chunk->image_hash ?: optional($chunk->updated_at)?->getTimestamp(),
                            ])
                            : null,
                        'source_filename' => $knowledgeDocument->original_filename,
                        'source_filetype' => $knowledgeDocument->mime_type,
                        'knowledge_item_id' => $knowledgeDocument->id,
                    ])
                    ->values()
                    ->all(),
                'revisions' => $knowledgeDocument->revisions
                    ->map(fn (KnowledgeItemRevision $revision): array => $this->documentRevisionPayload($revision))
                    ->values()
                    ->all(),
                'versions' => $knowledgeDocument->versions
                    ->map(static fn (KnowledgeItemVersion $version): array => [
                        'id' => $version->id,
                        'version_no' => (int) $version->version_no,
                        'is_current' => (bool) $version->is_current,
                        'original_filename' => $version->original_filename,
                        'storage_path' => $version->storage_path,
                        'mime_type' => $version->mime_type,
                        'file_size_bytes' => (int) $version->file_size_bytes,
                        'extraction_status' => $version->extraction_status,
                        'extraction_error' => $version->extraction_error,
                        'uploaded_by_user_id' => $version->uploaded_by_user_id,
                        'uploaded_by_name' => $version->uploadedBy?->name,
                        'uploaded_at' => optional($version->uploaded_at)?->toIso8601String(),
                        'created_at' => optional($version->created_at)?->toIso8601String(),
                        'updated_at' => optional($version->updated_at)?->toIso8601String(),
                        'chunks_count' => (int) ($version->chunks_count ?? 0),
                        'approval_status' => $version->approval_status,
                        'submitted_for_review_at' => optional($version->submitted_for_review_at)?->toIso8601String(),
                        'submitted_for_review_by_user_id' => $version->submitted_for_review_by_user_id,
                        'submitted_for_review_by_name' => $version->submittedForReviewBy?->name,
                        'approved_at' => optional($version->approved_at)?->toIso8601String(),
                        'approved_by_user_id' => $version->approved_by_user_id,
                        'approved_by_name' => $version->approvedBy?->name,
                        'rejected_at' => optional($version->rejected_at)?->toIso8601String(),
                        'rejected_by_user_id' => $version->rejected_by_user_id,
                        'rejected_by_name' => $version->rejectedBy?->name,
                        'rejection_reason' => $version->rejection_reason,
                    ])
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * Purpose: Apply a manual content update to one chunk without reprocessing the full source document.
     * Inputs: The request, the parent document, the selected chunk, and the validated payload.
     * Returns: True when chunk content or structural media fields were changed.
     * Side effects: Updates only the selected chunk and may replace one stored image file.
     */
    private function applyManualChunkContentUpdate(
        Request $request,
        KnowledgeItem $knowledgeDocument,
        KnowledgeItemChunk $chunk,
        array $payload,
    ): bool {
        if (! $this->requestHasManualChunkContentPayload($request)) {
            return false;
        }

        $chunkType = (string) ($chunk->chunk_type ?? 'semantic');

        if ($chunkType === 'image') {
            return $this->applyManualImageChunkUpdate($request, $knowledgeDocument, $chunk, $payload);
        }

        if ($chunkType === 'table') {
            return $this->applyManualTableChunkUpdate($request, $chunk, $payload);
        }

        return $this->applyManualTextChunkUpdate($chunk, $payload);
    }

    /**
     * Purpose: Determine whether the request intends to change primary chunk content rather than only product metadata.
     * Inputs: The current request.
     * Returns: True when content, table, or image fields are present.
     * Side effects: None.
     */
    private function requestHasManualChunkContentPayload(Request $request): bool
    {
        return $request->has('content')
            || $request->has('table_text')
            || $request->has('table_markdown')
            || $request->has('table_html')
            || $request->has('image_alt_text')
            || $request->has('image_caption')
            || $request->has('ocr_text')
            || $request->has('image_description')
            || $request->hasFile('image');
    }

    /**
     * Purpose: Persist manually edited text content for one semantic chunk.
     * Inputs: The selected chunk and validated payload.
     * Returns: True when the chunk was updated.
     * Side effects: Updates the selected chunk content and review status only.
     */
    private function applyManualTextChunkUpdate(KnowledgeItemChunk $chunk, array $payload): bool
    {
        $content = $payload['content'] ?? null;

        abort_unless($content !== null && $content !== '', 422, 'Chunk content cannot be empty.');

        $chunk->forceFill([
            'content' => $content,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
        ])->save();

        return true;
    }

    /**
     * Purpose: Persist manually edited table content for one table chunk.
     * Inputs: The request, selected chunk, and validated payload.
     * Returns: True when the chunk was updated.
     * Side effects: Updates only table text fields on the selected chunk and marks generated metadata stale.
     */
    private function applyManualTableChunkUpdate(Request $request, KnowledgeItemChunk $chunk, array $payload): bool
    {
        $tableText = $request->has('table_text')
            ? $payload['table_text']
            : $this->cleanNullableString($chunk->table_text, 200000);
        $content = $request->has('content')
            ? $payload['content']
            : ($tableText ?? $this->cleanNullableString($chunk->content, 200000));

        abort_unless($content !== null && $content !== '', 422, 'Table chunk content cannot be empty.');

        $tableWarnings = $this->normalizedTableWarnings($chunk->table_warnings);

        if (! in_array('manual_table_content_edited', $tableWarnings, true)) {
            $tableWarnings[] = 'manual_table_content_edited';
        }

        $tableMetadata = is_array($chunk->table_metadata) ? $chunk->table_metadata : [];
        $tableMetadata['manual_update_source'] = 'knowledge_base_chunk_editor';
        $tableMetadata['manual_updated_at'] = now()->toIso8601String();

        $chunk->forceFill([
            'content' => $content,
            'table_text' => $tableText ?? $content,
            'table_markdown' => $request->has('table_markdown') ? $payload['table_markdown'] : $chunk->table_markdown,
            'table_html' => $request->has('table_html') ? $payload['table_html'] : $chunk->table_html,
            'table_json' => null,
            'table_complexity' => 'manual_edit',
            'table_warnings' => $tableWarnings,
            'table_metadata' => $tableMetadata,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
        ])->save();

        return true;
    }

    /**
     * Purpose: Replace or update one image chunk without re-running document parsing or full chunking.
     * Inputs: The request, parent document, selected image chunk, and validated payload.
     * Returns: True when the image chunk was updated.
     * Side effects: May store one new image file, delete the previous image file, and updates only the selected chunk.
     */
    private function applyManualImageChunkUpdate(
        Request $request,
        KnowledgeItem $knowledgeDocument,
        KnowledgeItemChunk $chunk,
        array $payload,
    ): bool {
        $oldImageDisk = $this->cleanNullableString($chunk->image_disk, 64) ?? 'local';
        $oldImagePath = $this->cleanNullableString($chunk->image_path, 1024);
        $imageUpdates = [];
        $uploadedImage = $payload['image'] ?? null;

        if ($uploadedImage instanceof UploadedFile) {
            $imageUpdates = $this->storeManualChunkImage($knowledgeDocument, $uploadedImage);
        }

        $imageOriginalFilename = $this->cleanNullableString(
            $imageUpdates['image_original_filename'] ?? $chunk->image_original_filename,
            255,
        ) ?? 'image';
        $imageAltText = $this->normalizeGraphicAltText($request->has('image_alt_text') ? $payload['image_alt_text'] : $this->cleanNullableString($chunk->image_alt_text, 2000));
        $imageCaption = $this->normalizeGraphicAltText($request->has('image_caption') ? $payload['image_caption'] : $this->cleanNullableString($chunk->image_caption, 2000));
        $ocrText = $request->has('ocr_text') ? $payload['ocr_text'] : $this->cleanNullableString($chunk->ocr_text, 20000);
        $imageDescription = $request->has('image_description') ? $payload['image_description'] : $this->cleanNullableString($chunk->image_description, 20000);
        $content = $request->has('content')
            ? $payload['content']
            : $this->manualImageChunkContent($chunk, $imageOriginalFilename, $imageAltText, $imageCaption, $ocrText, $imageDescription);

        if ($uploadedImage instanceof UploadedFile && $content === $this->cleanNullableString($chunk->content, 200000)) {
            $content = $this->manualImageChunkContent($chunk, $imageOriginalFilename, $imageAltText, $imageCaption, $ocrText, $imageDescription);
        }

        abort_unless($content !== null && $content !== '', 422, 'Image chunk content cannot be empty.');

        $imageMetadata = is_array($chunk->image_metadata) ? $chunk->image_metadata : [];
        $imageMetadata['manual_update_source'] = 'knowledge_base_chunk_editor';
        $imageMetadata['manual_updated_at'] = now()->toIso8601String();

        if ($uploadedImage instanceof UploadedFile) {
            $imageMetadata['manual_original_filename'] = $uploadedImage->getClientOriginalName();
        }

        $chunk->forceFill(array_merge([
            'content' => $content,
            'title' => $this->resolveGraphicChunkTitle($this->cleanNullableString($chunk->title, 255), $imageCaption, $imageAltText, $this->imageIndexFromMetadata($imageMetadata)),
            'image_alt_text' => $imageAltText,
            'image_caption' => $imageCaption,
            'ocr_text' => $ocrText,
            'image_description' => $imageDescription,
            'image_metadata' => $imageMetadata,
            'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
        ], $imageUpdates))->save();

        $newImageDisk = $this->cleanNullableString($imageUpdates['image_disk'] ?? null, 64);
        $newImagePath = $this->cleanNullableString($imageUpdates['image_path'] ?? null, 1024);

        if ($oldImagePath !== null && $newImagePath !== null && $oldImagePath !== $newImagePath) {
            Storage::disk($oldImageDisk)->delete($oldImagePath);
        }

        return true;
    }

    /**
     * Purpose: Store a replacement image for one image chunk and return database-ready image attributes.
     * Inputs: The parent knowledge document and uploaded image file.
     * Returns: Image storage and metadata attributes for the chunk row.
     * Side effects: Writes one image file to local storage.
     */
    private function storeManualChunkImage(KnowledgeItem $knowledgeDocument, UploadedFile $image): array
    {
        $imageBytes = file_get_contents((string) $image->getRealPath());

        abort_unless(is_string($imageBytes) && $imageBytes !== '', 422, 'Uploaded image could not be read.');

        $imageMimeType = $this->cleanNullableString($image->getMimeType(), 191) ?? 'application/octet-stream';
        $imageExtension = Str::lower(trim((string) $image->getClientOriginalExtension()));

        if ($imageExtension === '') {
            $imageExtension = $this->imageExtensionFromMimeType($imageMimeType);
        }

        $imageHash = hash('sha256', $imageBytes);
        $imagePath = sprintf('knowledge-images/%d/%s.%s', $knowledgeDocument->id, $imageHash, $imageExtension);
        $imageSize = @getimagesize((string) $image->getRealPath());

        abort_unless(Storage::disk('local')->put($imagePath, $imageBytes), 500, 'Failed to store the knowledge image.');

        return [
            'image_path' => $imagePath,
            'image_disk' => 'local',
            'image_mime_type' => $imageMimeType,
            'image_original_filename' => $this->cleanNullableString($image->getClientOriginalName(), 255) ?? 'image.'.$imageExtension,
            'image_width' => is_array($imageSize) ? (int) ($imageSize[0] ?? 0) : null,
            'image_height' => is_array($imageSize) ? (int) ($imageSize[1] ?? 0) : null,
            'image_hash' => $imageHash,
        ];
    }

    /**
     * Purpose: Resolve a safe image extension when the uploaded image filename lacks one.
     * Inputs: The detected MIME type.
     * Returns: A storage extension supported by the chunk image editor.
     * Side effects: None.
     */
    private function imageExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }

    /**
     * Purpose: Remove Word-generated fallback names from graphics alt text.
     * Inputs: Raw alt text from DOCX parsing or manual chunk editing.
     * Returns: The alt text when it is meaningful, otherwise null for generated names like Bilde 7 or Grafikk 7.
     * Side effects: None.
     */
    private function normalizeGraphicAltText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->isGeneratedGraphicFallbackLabel($value) ? null : $this->cleanNullableString($value, 2000);
    }

    /**
     * Purpose: Detect internal DOCX image names that should not be shown as user-facing metadata.
     * Inputs: A candidate label from title, alt text, or parser fallback.
     * Returns: True when the value is a generated label like Bilde 7, Grafikk 7, Picture 7, or Image 7.
     * Side effects: None.
     */
    private function isGeneratedGraphicFallbackLabel(?string $value): bool
    {
        $text = $this->cleanNullableString($value, 255);

        if ($text === null) {
            return false;
        }

        return preg_match('/^(bilde|grafikk|picture|image|graphic)\s*\d+$/iu', $text) === 1;
    }

    /**
     * Purpose: Extract the numeric order from generated DOCX image fallback labels.
     * Inputs: A candidate title or alt text value.
     * Returns: The numeric suffix when present, otherwise null.
     * Side effects: None.
     */
    private function generatedGraphicFallbackNumber(?string $value): ?int
    {
        $text = $this->cleanNullableString($value, 255);

        if ($text === null) {
            return null;
        }

        if (preg_match('/^(?:bilde|grafikk|picture|image|graphic)\s*(\d+)$/iu', $text, $matches) !== 1) {
            return null;
        }

        $number = (int) ($matches[1] ?? 0);

        return $number > 0 ? $number : null;
    }

    /**
     * Purpose: Resolve a stable user-facing graphics title without leaking internal DOCX image names.
     * Inputs: Candidate title, caption, normalized alt text, and parser image order.
     * Returns: A title using Grafikk for generated fallback labels.
     * Side effects: None.
     */
    private function resolveGraphicChunkTitle(?string $candidateTitle, ?string $caption, ?string $altText, int $imageIndexInDocument = 0): string
    {
        $candidateTitle = $this->cleanNullableString($candidateTitle, 255);
        $caption = $this->cleanNullableString($caption, 255);
        $altText = $this->cleanNullableString($altText, 255);

        if ($candidateTitle !== null && ! $this->isGeneratedGraphicFallbackLabel($candidateTitle)) {
            return $candidateTitle;
        }

        if ($caption !== null) {
            return $caption;
        }

        if ($altText !== null && ! $this->isGeneratedGraphicFallbackLabel($altText)) {
            return $altText;
        }

        $generatedNumber = $imageIndexInDocument > 0
            ? $imageIndexInDocument
            : ($this->generatedGraphicFallbackNumber($candidateTitle) ?? $this->generatedGraphicFallbackNumber($altText));

        return $generatedNumber !== null ? 'Grafikk '.$generatedNumber : 'Grafikk';
    }

    /**
     * Purpose: Resolve an image order from stored metadata when a dedicated column is not available.
     * Inputs: Image metadata stored on a chunk.
     * Returns: The positive image index or 0 when none is known.
     * Side effects: None.
     */
    private function imageIndexFromMetadata(array $imageMetadata): int
    {
        $imageIndex = (int) data_get($imageMetadata, 'image_index_in_document', 0);

        if ($imageIndex <= 0) {
            $imageIndex = (int) data_get($imageMetadata, 'source_metadata.document_order_index', 0);
        }

        return $imageIndex > 0 ? $imageIndex : 0;
    }

    /**
     * Purpose: Build searchable content for an image chunk after manual image or text changes.
     * Inputs: The existing chunk and normalized image metadata fields.
     * Returns: A stable searchable text representation for embedding and retrieval.
     * Side effects: None.
     */
    private function manualImageChunkContent(
        KnowledgeItemChunk $chunk,
        string $imageOriginalFilename,
        ?string $imageAltText,
        ?string $imageCaption,
        ?string $ocrText,
        ?string $imageDescription,
    ): string {
        $parts = [];
        $sectionPath = $this->cleanNullableString($chunk->section_path, 255)
            ?? $this->cleanNullableString($chunk->heading_path, 255);

        if ($sectionPath !== null) {
            $parts[] = 'Grafikk i seksjon: '.$sectionPath;
        } else {
            $parts[] = 'Grafikk';
        }

        $parts[] = 'Grafikkfil: '.$imageOriginalFilename;

        if ($imageCaption !== null) {
            $parts[] = 'Grafikktekst: '.$imageCaption;
        }

        if ($imageAltText !== null) {
            $parts[] = 'Alternativ tekst: '.$imageAltText;
        }

        if ($ocrText !== null) {
            $parts[] = 'OCR-tekst: '.$ocrText;
        }

        if ($imageDescription !== null) {
            $parts[] = 'Grafikkbeskrivelse: '.$imageDescription;
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Purpose: Reset generated fields when a chunk content source changes manually.
     * Inputs: The selected chunk.
     * Returns: None.
     * Side effects: Clears generated metadata and marks the chunk as pending review.
     */
    private function resetGeneratedChunkFields(KnowledgeItemChunk $chunk): void
    {
        $chunk->forceFill([
            'ai_summary' => null,
            'service_product_tag' => null,
            'theme_tag' => null,
            'topic' => null,
            'sub_topic' => null,
            'keywords' => null,
            'matched_terms' => null,
            'summary_for_retrieval' => null,
            'confidence_score' => null,
            'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_PENDING_REVIEW,
            'embedding_vector' => null,
            'embedding_vector_pgvector' => null,
            'embedding_model' => null,
            'embedding_generated_at' => null,
            'embedding_error' => null,
        ])->save();
    }

    /**
     * Purpose: Regenerate the embedding for one manually edited chunk without touching any other chunks.
     * Inputs: The parent document and the selected chunk.
     * Returns: None.
     * Side effects: Calls the embedding service and updates only this chunk embedding fields.
     */
    private function regenerateChunkEmbeddingWithoutMetadata(KnowledgeItem $knowledgeDocument, KnowledgeItemChunk $chunk): void
    {
        $chunk->refresh();
        $chunkText = $this->chunkTextForEmbedding($chunk);

        if ($chunkText === '') {
            $chunk->forceFill([
                'embedding_error' => 'Chunk content was empty and was not embedded.',
            ])->save();

            return;
        }

        $outcome = app(EmbeddingService::class)->tryEmbedText($chunkText);

        if (! ($outcome['ok'] ?? false)) {
            $this->logChunkEmbeddingFailure($knowledgeDocument, $chunk, $outcome);

            $chunk->forceFill([
                'embedding_error' => (string) ($outcome['error_message'] ?? 'Knowledge chunk embedding failed.'),
            ])->save();

            return;
        }

        $this->persistChunkEmbedding($chunk, $outcome);
    }

    /**
     * Purpose: Resolve the embedding source text for one chunk after manual updates.
     * Inputs: The selected chunk.
     * Returns: The text used for embedding.
     * Side effects: None.
     */
    private function chunkTextForEmbedding(KnowledgeItemChunk $chunk): string
    {
        if (($chunk->chunk_type ?? null) === 'table') {
            return trim((string) ($chunk->table_text ?: $chunk->content));
        }

        return trim((string) $chunk->content);
    }

    /**
     * Purpose: Persist one generated embedding in both the JSON fallback column and the pgvector column.
     * Inputs: The selected chunk and the successful embedding outcome.
     * Returns: None.
     * Side effects: Updates the chunk embedding fields in the database.
     */
    private function persistChunkEmbedding(KnowledgeItemChunk $chunk, array $outcome): void
    {
        $embedding = is_array($outcome['embedding'] ?? null)
            ? array_values($outcome['embedding'])
            : null;

        $chunk->forceFill([
            'embedding_vector' => $embedding,
            'embedding_vector_pgvector' => $embedding !== null ? PgVector::literal($embedding) : null,
            'embedding_model' => $outcome['model'] ?? null,
            'embedding_generated_at' => now(),
            'embedding_error' => null,
        ])->save();
    }

    /**
     * Purpose: Normalize table warnings into a safe string list.
     * Inputs: Raw table warnings from the selected chunk.
     * Returns: A de-duplicated list of warning strings.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    private function normalizedTableWarnings(mixed $warnings): array
    {
        if ($warnings instanceof Collection) {
            $warnings = $warnings->all();
        }

        if (! is_array($warnings)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($warnings as $warning) {
            $text = trim((string) $warning);

            if ($text === '' || isset($seen[$text])) {
                continue;
            }

            $seen[$text] = true;
            $normalized[] = $text;
        }

        return $normalized;
    }

    /**
     * Purpose: Stream one stored knowledge image back to an authorized frontend user.
     * Inputs: The current frontend request, the parent knowledge document, and the image chunk.
     * Returns: A binary file response with the image content and MIME type.
     * Side effects: Reads the stored image file from disk.
     */
    public function showChunkImage(Request $request, KnowledgeItem $knowledgeItem, KnowledgeItemChunk $chunk): BinaryFileResponse
    {
        [, $customerId] = $this->frontendContext($request);

        abort_unless((int) $knowledgeItem->customer_id === $customerId, 403);

        $record = $this->scopedDocument($customerId, $knowledgeItem->id);
        $chunkRecord = $this->scopedChunk($record->id, $chunk->id);

        abort_unless((string) $chunkRecord->chunk_type === 'image', 404);

        $imagePath = $this->cleanNullableString($chunkRecord->image_path, 1024);
        abort_unless($imagePath !== null, 404);

        $imageDisk = $this->cleanNullableString($chunkRecord->image_disk, 64) ?? 'local';
        $storage = Storage::disk($imageDisk);

        abort_unless($storage->exists($imagePath), 404);

        $mimeType = $this->cleanNullableString($chunkRecord->image_mime_type, 191) ?? 'application/octet-stream';
        $absolutePath = $storage->path($imagePath);

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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
        $looseTextChunkRanges = [];

        foreach ($this->groupElementsByPrimaryHeading($elements) as $group) {
            $groupElements = array_values(array_filter(
                (array) ($group['elements'] ?? []),
                static fn ($element): bool => is_array($element),
            ));
            $primaryHeading = $this->cleanNullableString($group['primary_heading'] ?? null, 255);

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
                $willSplit = ! in_array($chunkKind, ['table', 'image'], true) && $candidateWordCount > self::RULE_BASED_CHUNK_MAX_WORDS;

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

            if ($primaryHeading !== null && $groupElements !== []) {
                foreach ($this->buildRuleBasedLooseTextChunkRanges($groupElements, $primaryHeading) as $textRange) {
                    $looseTextChunkRanges[] = $textRange;
                }
            }
        }

        $chunkRanges = $this->subtractTableRangesFromSemanticRanges($chunkRanges);
        $figureChunkRanges = $this->buildFigureChunkRangesFromGaps($chunkRanges, $sourceText);

        if ($figureChunkRanges !== []) {
            $chunkRanges = array_merge($chunkRanges, $figureChunkRanges);
        }

        $imageChunkRanges = array_values(array_filter(
            $chunkRanges,
            static fn (array $chunkRange): bool => (string) ($chunkRange['chunk_kind'] ?? '') === 'image',
        ));

        foreach ($looseTextChunkRanges as $textRange) {
            $overlapsImageChunk = false;

            foreach ($imageChunkRanges as $imageChunkRange) {
                $imageStartOffset = (int) ($imageChunkRange['start_offset'] ?? 0);
                $imageEndOffset = (int) ($imageChunkRange['end_offset'] ?? 0);

                if ((int) ($textRange['start_offset'] ?? 0) < $imageEndOffset && (int) ($textRange['end_offset'] ?? 0) > $imageStartOffset) {
                    $overlapsImageChunk = true;
                    break;
                }
            }

            if ($overlapsImageChunk) {
                continue;
            }

            $chunkRanges[] = $textRange;
        }

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
            $tableSequenceInDocument = 0;
            $graphicSequenceInDocument = 0;

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
                    $tableSequenceInDocument++;
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
                    $tableLabel = 'Tabell '.$tableSequenceInDocument;
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
                            'table_sequence_in_document' => $tableSequenceInDocument,
                            'source_table_title' => $this->cleanNullableString($tableTitleText ?? null, 255),
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

                if ($chunkKind === 'image') {
                    $graphicSequenceInDocument++;
                    $imageAltText = $this->normalizeGraphicAltText($this->cleanNullableString($chunkRange['image_alt_text'] ?? null, 2000));
                    $imageCaption = $this->normalizeGraphicAltText($this->cleanNullableString($chunkRange['image_caption'] ?? null, 2000));
                    $imageMetadata = is_array($chunkRange['image_metadata'] ?? null) ? $chunkRange['image_metadata'] : [];
                    $sourceImageIndexInDocument = isset($chunkRange['image_index_in_document'])
                        ? (int) $chunkRange['image_index_in_document']
                        : $this->imageIndexFromMetadata($imageMetadata);
                    $imageMetadata['graphic_sequence_in_document'] = $graphicSequenceInDocument;

                    if ($sourceImageIndexInDocument > 0) {
                        $imageMetadata['source_image_index_in_document'] = $sourceImageIndexInDocument;
                    }

                    $imageLabel = $this->resolveGraphicChunkTitle($this->cleanNullableString($chunkRange['title'] ?? null, 255), $imageCaption, $imageAltText, $graphicSequenceInDocument);
                    $imageContent = trim((string) ($chunkRange['content'] ?? ''));

                    $coveredCharacterCount += $endOffset - $startOffset;
                    $lastAcceptedEndOffset = $endOffset;

                    $chunkPayloads[] = [
                        'content' => $imageContent,
                        'start_offset' => $startOffset,
                        'end_offset' => $endOffset,
                        'section_title' => $headingPath,
                        'section_path' => $this->cleanNullableString($chunkRange['section_path'] ?? null, 255) ?? $headingPath,
                        'heading_path' => $headingPath,
                        'chunk_type' => 'image',
                        'title' => $imageLabel,
                        'part_index' => $partIndex,
                        'topic' => null,
                        'sub_topic' => null,
                        'keywords' => null,
                        'image_bytes' => $chunkRange['image_bytes'] ?? null,
                        'image_path' => $chunkRange['image_path'] ?? null,
                        'image_disk' => $chunkRange['image_disk'] ?? null,
                        'image_mime_type' => $chunkRange['image_mime_type'] ?? null,
                        'image_original_filename' => $chunkRange['image_original_filename'] ?? null,
                        'image_width' => $chunkRange['image_width'] ?? null,
                        'image_height' => $chunkRange['image_height'] ?? null,
                        'image_hash' => $chunkRange['image_hash'] ?? null,
                        'image_metadata' => $imageMetadata,
                        'image_alt_text' => $imageAltText,
                        'image_caption' => $imageCaption,
                        'ocr_text' => $chunkRange['ocr_text'] ?? null,
                        'image_description' => $chunkRange['image_description'] ?? null,
                    ];

                    continue;
                }

                if ($chunkKind === 'h1_section' && $partIndex === null && $headingPath !== null && $headingPath !== '') {
                    $content = trim($headingPath."\n\n".$content);
                }

                $wordCount = count(preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $contentLength = mb_strlen($content, 'UTF-8');

                if ($wordCount < self::RULE_BASED_MIN_SEMANTIC_CHUNK_WORDS) {
                    $skippedOrEmptyRangesCount++;
                    continue;
                }

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
        $imageElements = array_values(array_filter(
            $groupElements,
            static fn (array $element): bool => (string) data_get($element, 'type', '') === 'image',
        ));

        if ($h2Sections === []) {
            if ($tableElements !== [] || $imageElements !== []) {
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

        foreach ($imageElements as $imageElement) {
            foreach ($this->buildImageChunkRangesFromElement($imageElement) as $imageRange) {
                $ranges[] = $imageRange;
            }
        }

        return $ranges;
    }

    /**
     * Purpose: Convert a heading group without H2 sections into text, table, and image chunk ranges.
     * Inputs: Ordered elements belonging to one primary H1 context and a resolved primary heading.
     * Returns: Ordered chunk ranges where text runs are semantic chunks and structural elements are dedicated chunks.
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

            if ($type === 'table' || $type === 'image') {
                $flushTextElements();

                $structuralRanges = $type === 'table'
                    ? $this->buildTableChunkRangesFromElement($element)
                    : $this->buildImageChunkRangesFromElement($element);

                foreach ($structuralRanges as $structuralRange) {
                    $ranges[] = $structuralRange;
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
     * Purpose: Convert raw text elements that sit outside generated H2 sections into fallback semantic ranges.
     * Inputs: The ordered elements in one primary heading group and the resolved primary heading.
     * Returns: Ordered fallback text ranges for uncovered paragraph and list content outside generated H2 sections.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $groupElements
     * @return array<int, array{start_offset: int, end_offset: int, heading_path: ?string, section_title: ?string, section_path: ?string, chunk_kind: string}>
     */
    private function buildRuleBasedLooseTextChunkRanges(array $groupElements, string $primaryHeading): array
    {
        $h2Sections = array_values(array_filter(
            $groupElements,
            static fn (array $element): bool => (string) data_get($element, 'type', '') === 'h2_section',
        ));
        $preH2Elements = [];

        if ($h2Sections !== []) {
            $firstH2Start = (int) data_get($h2Sections[0], 'start_offset', 0);

            $preH2Elements = array_values(array_filter(
                $groupElements,
                static fn (array $element): bool => (int) data_get($element, 'start_offset', 0) < $firstH2Start,
            ));
        }

        $looseTextElements = array_values(array_filter(
            $groupElements,
            static function (array $element): bool {
                return in_array((string) data_get($element, 'type', ''), ['paragraph', 'list'], true);
            },
        ));

        if ($h2Sections === [] || $looseTextElements === []) {
            return [];
        }

        $reservedRanges = [];

        if ($preH2Elements !== []) {
            $leadingStart = null;
            $leadingEnd = null;

            foreach ($preH2Elements as $element) {
                $elementStart = (int) data_get($element, 'start_offset', 0);
                $elementEnd = (int) data_get($element, 'end_offset', 0);

                if ($leadingStart === null || $elementStart < $leadingStart) {
                    $leadingStart = $elementStart;
                }

                if ($leadingEnd === null || $elementEnd > $leadingEnd) {
                    $leadingEnd = $elementEnd;
                }
            }

            if ($leadingStart !== null && $leadingEnd !== null && $leadingEnd > $leadingStart) {
                $reservedRanges[] = [
                    'start_offset' => $leadingStart,
                    'end_offset' => $leadingEnd,
                ];
            }
        }

        foreach ($h2Sections as $h2Section) {
            $sectionStart = (int) data_get($h2Section, 'start_offset', 0);
            $sectionEnd = (int) data_get($h2Section, 'end_offset', 0);

            if ($sectionEnd > $sectionStart) {
                $reservedRanges[] = [
                    'start_offset' => $sectionStart,
                    'end_offset' => $sectionEnd,
                ];
            }
        }

        $ranges = [];
        $currentStart = null;
        $currentEnd = null;
        $currentHeadingPath = $primaryHeading;

        $flushCurrentRange = function () use (&$ranges, &$currentStart, &$currentEnd, $currentHeadingPath): void {
            if ($currentStart === null || $currentEnd === null || $currentEnd <= $currentStart) {
                $currentStart = null;
                $currentEnd = null;

                return;
            }

            $ranges[] = [
                'start_offset' => $currentStart,
                'end_offset' => $currentEnd,
                'heading_path' => $currentHeadingPath,
                'section_title' => $currentHeadingPath,
                'section_path' => $currentHeadingPath,
                'chunk_kind' => 'h1_section',
            ];

            $currentStart = null;
            $currentEnd = null;
        };

        foreach ($looseTextElements as $element) {
            $startOffset = (int) data_get($element, 'start_offset', 0);
            $endOffset = (int) data_get($element, 'end_offset', 0);

            if ($endOffset <= $startOffset) {
                continue;
            }

            $isCovered = false;

            foreach ($reservedRanges as $reservedRange) {
                $reservedStart = (int) data_get($reservedRange, 'start_offset', 0);
                $reservedEnd = (int) data_get($reservedRange, 'end_offset', 0);

                if ($startOffset >= $reservedStart && $endOffset <= $reservedEnd) {
                    $isCovered = true;
                    break;
                }
            }

            if ($isCovered) {
                $flushCurrentRange();
                continue;
            }

            if ($currentStart !== null && $currentEnd !== null && $startOffset <= ($currentEnd + 2)) {
                $currentEnd = max($currentEnd, $endOffset);
                continue;
            }

            $flushCurrentRange();
            $currentStart = $startOffset;
            $currentEnd = $endOffset;
        }

        $flushCurrentRange();

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
        $tableLabel = 'Tabell';
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
            'source_table_title' => $tableTitleText,
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
     * Purpose: Convert one parsed image element into one dedicated image chunk range.
     * Inputs: A parsed image element with stored image metadata.
     * Returns: One ordered image chunk range that preserves the image context and file reference.
     * Side effects: None.
     *
     * @param array<string, mixed> $imageElement
     * @return array<int, array<string, mixed>>
     */
    private function buildImageChunkRangesFromElement(array $imageElement): array
    {
        $fullHeadingPath = $this->cleanNullableString($imageElement['heading_path'] ?? null, 255);
        $headingTitle = $this->headingLeafFromPath($fullHeadingPath) ?? $fullHeadingPath;
        $sectionPath = $fullHeadingPath ?? $headingTitle;
        $imageIndexInDocument = isset($imageElement['image_index_in_document'])
            ? (int) $imageElement['image_index_in_document']
            : (int) data_get($imageElement, 'source_metadata.document_order_index', 0);
        $sourceStartOffset = (int) data_get($imageElement, 'start_offset', 0);
        $sourceEndOffset = (int) data_get($imageElement, 'end_offset', $sourceStartOffset);
        $rawImageAltText = $this->cleanNullableString(data_get($imageElement, 'image_alt_text'), 2000);
        $imageAltText = $this->normalizeGraphicAltText($rawImageAltText);
        $imageCaption = $this->normalizeGraphicAltText($this->cleanNullableString(data_get($imageElement, 'image_caption'), 2000));
        $imageLabel = $this->resolveGraphicChunkTitle($rawImageAltText, $imageCaption, $imageAltText, $imageIndexInDocument);
        $imageContentParts = [];

        if ($sectionPath !== null && $sectionPath !== '') {
            $imageContentParts[] = 'Grafikk i seksjon: '.$sectionPath;
        } else {
            $imageContentParts[] = 'Grafikk';
        }

        if ($imageCaption !== null) {
            $imageContentParts[] = 'Grafikktekst: '.$imageCaption;
        }

        if ($imageAltText !== null) {
            $imageContentParts[] = 'Alternativ tekst: '.$imageAltText;
        }

        if ($imageCaption === null && $imageAltText === null) {
            $imageContentParts[] = 'Ingen grafikktekst eller alternativ tekst er registrert.';
        }

        $content = trim(implode("\n\n", $imageContentParts));
        $imageMetadata = is_array(data_get($imageElement, 'image_metadata', null)) ? data_get($imageElement, 'image_metadata') : [];

        return [[
            'start_offset' => $sourceStartOffset,
            'end_offset' => $sourceEndOffset,
            'heading_path' => $headingTitle ?? $imageLabel,
            'section_title' => $headingTitle ?? $imageLabel,
            'section_path' => $sectionPath ?? $headingTitle ?? $imageLabel,
            'chunk_kind' => 'image',
            'title' => $imageLabel,
            'part_index' => null,
            'content' => $content,
            'image_bytes' => $imageElement['image_bytes'] ?? null,
            'image_path' => $imageElement['image_path'] ?? null,
            'image_disk' => $imageElement['image_disk'] ?? null,
            'image_mime_type' => $imageElement['image_mime_type'] ?? null,
            'image_original_filename' => $imageElement['image_original_filename'] ?? null,
            'image_width' => $imageElement['image_width'] ?? null,
            'image_height' => $imageElement['image_height'] ?? null,
            'image_hash' => $imageElement['image_hash'] ?? null,
            'image_metadata' => $imageMetadata,
            'image_alt_text' => $imageAltText,
            'image_caption' => $imageCaption,
            'ocr_text' => $imageElement['ocr_text'] ?? null,
            'image_description' => $imageElement['image_description'] ?? null,
            'source_metadata' => is_array(data_get($imageElement, 'source_metadata', null)) ? data_get($imageElement, 'source_metadata') : null,
            'image_index_in_document' => $imageIndexInDocument,
        ]];
    }

    /**
     * Purpose: Convert uncovered text gaps between existing chunk ranges into conservative figure-like image chunks.
     * Inputs: Ordered chunk ranges already produced for the document and the canonical source text.
     * Returns: Additional image chunk ranges for gaps that resemble embedded figures or diagrams.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $chunkRanges
     * @return array<int, array<string, mixed>>
     */
    private function buildFigureChunkRangesFromGaps(array $chunkRanges, string $sourceText): array
    {
        if ($chunkRanges === [] || trim($sourceText) === '') {
            return [];
        }

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

        $normalizeLine = static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? '');
        $wordCount = static fn (string $text): int => count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $normalizePath = static fn (mixed $value): string => trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
        $isTitleLikeLine = static function (string $line) use ($wordCount): bool {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

            if ($line === '') {
                return false;
            }

            $lineWordCount = $wordCount($line);

            if ($lineWordCount < 2 || $lineWordCount > 4) {
                return false;
            }

            if (mb_strlen($line, 'UTF-8') > 40) {
                return false;
            }

            if (preg_match('/[.!?:;]$/u', $line)) {
                return false;
            }

            return preg_match('/^[\p{Lu}\d]/u', $line) === 1;
        };
        $isListLikeLine = static fn (string $line): bool => preg_match('/^(?:\d+\.|\d+\)|[•\-–])\s*/u', trim($line)) === 1;
        $existingImagePaths = [];

        foreach ($chunkRanges as $chunkRange) {
            if ((string) ($chunkRange['chunk_kind'] ?? '') !== 'image') {
                continue;
            }

            $existingHeadingPath = $normalizePath($chunkRange['heading_path'] ?? null);
            $existingSectionPath = $normalizePath($chunkRange['section_path'] ?? null);

            if ($existingHeadingPath !== '') {
                $existingImagePaths['heading:'.$existingHeadingPath] = true;
            }

            if ($existingSectionPath !== '') {
                $existingImagePaths['section:'.$existingSectionPath] = true;
            }
        }

        $figureChunkRanges = [];
        $rangeCount = count($chunkRanges);

        for ($index = 0; $index < $rangeCount - 1; $index++) {
            $leftRange = $chunkRanges[$index];
            $rightRange = $chunkRanges[$index + 1];
            $leftKind = (string) ($leftRange['chunk_kind'] ?? '');
            $rightKind = (string) ($rightRange['chunk_kind'] ?? '');

            if (in_array($leftKind, ['table', 'image'], true) || in_array($rightKind, ['table', 'image'], true)) {
                continue;
            }

            $gapStart = (int) ($leftRange['end_offset'] ?? 0);
            $gapEnd = (int) ($rightRange['start_offset'] ?? 0);

            if ($gapEnd <= $gapStart) {
                continue;
            }

            $gapText = trim((string) mb_substr($sourceText, $gapStart, $gapEnd - $gapStart, 'UTF-8'));

            if ($gapText === '') {
                continue;
            }

            $lineInfos = [];
            $lineCursor = 0;

            foreach (preg_split('/\n+/u', $gapText) ?: [] as $rawLine) {
                $rawLine = trim((string) $rawLine);

                if ($rawLine === '') {
                    continue;
                }

                $relativeStart = mb_strpos($gapText, $rawLine, $lineCursor, 'UTF-8');

                if ($relativeStart === false) {
                    $relativeStart = $lineCursor;
                }

                $relativeEnd = $relativeStart + mb_strlen($rawLine, 'UTF-8');
                $lineCursor = $relativeEnd;

                $lineInfos[] = [
                    'raw' => $rawLine,
                    'normalized' => $normalizeLine($rawLine),
                    'relative_start' => $relativeStart,
                    'relative_end' => $relativeEnd,
                ];
            }

            $lines = array_values(array_filter(array_map(
                static fn (array $lineInfo): string => (string) ($lineInfo['normalized'] ?? ''),
                $lineInfos,
            ), static fn (string $line): bool => $line !== ''));

            if ($lines === []) {
                continue;
            }

            $titleLineIndex = null;

            foreach ($lines as $lineIndex => $line) {
                if ($isTitleLikeLine($line)) {
                    $titleLineIndex = $lineIndex;

                    break;
                }
            }

            if ($titleLineIndex === null) {
                continue;
            }

            $suffixLines = array_slice($lines, $titleLineIndex + 1);

            if (count($suffixLines) < 3) {
                continue;
            }

            $shortSuffixLineCount = 0;
            $listLikeSuffixLineCount = 0;
            $longSuffixLineCount = 0;

            foreach ($suffixLines as $suffixLine) {
                $suffixWordCount = $wordCount($suffixLine);

                if ($suffixWordCount <= 6) {
                    $shortSuffixLineCount++;
                }

                if ($isListLikeLine($suffixLine)) {
                    $listLikeSuffixLineCount++;
                }

                if ($suffixWordCount > 12 || mb_strlen($suffixLine, 'UTF-8') > 90 || preg_match('/[.!?]\s*$/u', $suffixLine) === 1) {
                    $longSuffixLineCount++;
                }
            }

            $isFigureLikeGap = $shortSuffixLineCount >= 3
                && $listLikeSuffixLineCount >= 2
                && $longSuffixLineCount === 0;

            $figureHeadingPath = $this->cleanNullableString($leftRange['section_path'] ?? $leftRange['heading_path'] ?? null, 255)
                ?? $this->cleanNullableString($rightRange['section_path'] ?? $rightRange['heading_path'] ?? null, 255);

            if ($figureHeadingPath === null || $figureHeadingPath === '') {
                continue;
            }

            $figureHeadingKey = $normalizePath($figureHeadingPath);
            $figureSectionKey = $normalizePath($leftRange['section_path'] ?? $leftRange['heading_path'] ?? null);

            if ($figureSectionKey === '') {
                $figureSectionKey = $normalizePath($rightRange['section_path'] ?? $rightRange['heading_path'] ?? null);
            }

            // Existing real image chunks win over synthetic figure gaps in the same section or heading.
            if (($figureHeadingKey !== '' && isset($existingImagePaths['heading:'.$figureHeadingKey]))
                || ($figureSectionKey !== '' && isset($existingImagePaths['section:'.$figureSectionKey]))) {
                continue;
            }

            if (! $isFigureLikeGap) {
                if ($longSuffixLineCount > 0 || $wordCount($gapText) >= self::RULE_BASED_MIN_SEMANTIC_CHUNK_WORDS) {
                    $figureChunkRanges[] = [
                        'heading_path' => $figureHeadingPath,
                        'section_path' => $figureHeadingPath,
                        'start_offset' => $gapStart,
                        'end_offset' => $gapEnd,
                        'chunk_kind' => 'semantic',
                    ];
                }

                continue;
            }

            $figureCaption = $normalizeLine($lines[$titleLineIndex] ?? '');
            $figureStartRelative = (int) ($lineInfos[$titleLineIndex]['relative_start'] ?? 0);
            $figureText = trim((string) mb_substr($gapText, $figureStartRelative, $gapEnd - $gapStart - $figureStartRelative, 'UTF-8'));
            $prefixText = trim((string) mb_substr($gapText, 0, $figureStartRelative, 'UTF-8'));

            if ($prefixText !== '' && $wordCount($prefixText) >= self::RULE_BASED_MIN_SEMANTIC_CHUNK_WORDS) {
                $figureChunkRanges[] = [
                    'heading_path' => $figureHeadingPath,
                    'section_path' => $figureHeadingPath,
                    'start_offset' => $gapStart,
                    'end_offset' => $gapStart + $figureStartRelative,
                    'chunk_kind' => 'semantic',
                ];
            }

            $figureElement = [
                'heading_path' => $figureHeadingPath,
                'start_offset' => $gapStart + $figureStartRelative,
                'end_offset' => $gapEnd,
                'image_caption' => $figureCaption !== '' ? $figureCaption : null,
                'image_alt_text' => $figureText,
                'image_description' => $figureText,
                'ocr_text' => $figureText,
                'image_metadata' => [
                    'source' => 'pdf_figure_gap',
                    'derived_from_text' => true,
                    'gap_character_count' => mb_strlen($figureText, 'UTF-8'),
                    'gap_word_count' => $wordCount($figureText),
                    'gap_line_count' => count(array_slice($lineInfos, $titleLineIndex)),
                ],
            ];

            foreach ($this->buildImageChunkRangesFromElement($figureElement) as $figureChunkRange) {
                $figureChunkRanges[] = $figureChunkRange;
            }
        }

        return $figureChunkRanges;
    }

    /**
     * Purpose: Remove any table overlaps from text ranges so table content only lives in table chunks.
     * Inputs: Ordered chunk ranges that may include semantic, table, and image ranges.
     * Returns: Chunk ranges where semantic ranges have been split around structural ranges.
     * Side effects: None.
     *
     * @param array<int, array<string, mixed>> $chunkRanges
     * @return array<int, array<string, mixed>>
     */
    private function subtractTableRangesFromSemanticRanges(array $chunkRanges): array
    {
        $tableRanges = array_values(array_filter(
            $chunkRanges,
            static fn (array $chunkRange): bool => in_array((string) ($chunkRange['chunk_kind'] ?? ''), ['table', 'image'], true),
        ));

        if ($tableRanges === []) {
            return $chunkRanges;
        }

        $adjustedRanges = [];

        foreach ($chunkRanges as $chunkRange) {
            if (in_array((string) ($chunkRange['chunk_kind'] ?? ''), ['table', 'image'], true)) {
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
    private function syncChunks(KnowledgeItem $knowledgeDocument, array $chunkPayloads, ?string $sourceDocumentPath = null, ?KnowledgeItemVersion $version = null): Collection
    {
        // When a specific version is provided, delete only that version's chunks so that
        // older versions' chunks are preserved (required for file replacement in 2.4D+).
        if ($version !== null) {
            KnowledgeItemChunk::query()
                ->where('knowledge_item_id', $knowledgeDocument->id)
                ->where('knowledge_item_version_id', $version->id)
                ->delete();
        } else {
            $knowledgeDocument->chunks()->delete();
        }

        if ($chunkPayloads === []) {
            return collect();
        }

        $chunkAttributes = array_map(
            function (array $chunkPayload, int $chunkIndex) use ($knowledgeDocument, $sourceDocumentPath, $version): array {
                $chunkType = (string) ($chunkPayload['chunk_type'] ?? '');
                $attributes = [
                    'knowledge_item_version_id' => $version?->id,
                    'chunk_index' => $chunkIndex,
                    'content' => (string) ($chunkPayload['content'] ?? ''),
                    'start_offset' => (int) ($chunkPayload['start_offset'] ?? 0),
                    'end_offset' => (int) ($chunkPayload['end_offset'] ?? 0),
                    'review_status' => KnowledgeItemChunk::REVIEW_STATUS_PENDING_REVIEW,
                    'section_title' => $chunkPayload['section_title'] ?? null,
                    'section_path' => $chunkPayload['section_path'] ?? null,
                    'heading_path' => $chunkPayload['heading_path'] ?? null,
                    'chunk_type' => $chunkType !== '' ? $chunkType : null,
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
                    'image_path' => $chunkPayload['image_path'] ?? null,
                    'image_disk' => $chunkPayload['image_disk'] ?? null,
                    'image_mime_type' => $chunkPayload['image_mime_type'] ?? null,
                    'image_original_filename' => $chunkPayload['image_original_filename'] ?? null,
                    'image_width' => $chunkPayload['image_width'] ?? null,
                    'image_height' => $chunkPayload['image_height'] ?? null,
                    'image_hash' => $chunkPayload['image_hash'] ?? null,
                    'image_metadata' => $chunkPayload['image_metadata'] ?? null,
                    'image_alt_text' => $chunkPayload['image_alt_text'] ?? null,
                    'image_caption' => $chunkPayload['image_caption'] ?? null,
                    'ocr_text' => $chunkPayload['ocr_text'] ?? null,
                    'image_description' => $chunkPayload['image_description'] ?? null,
                ];

                if ($chunkType === 'image') {
                    $imageBytes = $chunkPayload['image_bytes'] ?? null;
                    $imageDisk = 'local';
                    $imageMimeType = $this->cleanNullableString($attributes['image_mime_type'] ?? null, 191);
                    $imageOriginalFilename = $this->cleanNullableString($attributes['image_original_filename'] ?? null, 255) ?? 'image';
                    $imageHash = $this->cleanNullableString($attributes['image_hash'] ?? null, 128);
                    $imageMetadata = is_array($attributes['image_metadata'] ?? null) ? $attributes['image_metadata'] : [];

                    if ((! is_string($imageBytes) || trim($imageBytes) === '')
                        && is_string($sourceDocumentPath)
                        && $sourceDocumentPath !== ''
                        && (string) data_get($imageMetadata, 'source') === 'pdf_figure_gap'
                        && (bool) data_get($imageMetadata, 'derived_from_text')) {
                        $preview = $this->pdfFigurePreviewRenderer->renderPreview($sourceDocumentPath, $chunkPayload);

                        if (is_array($preview)) {
                            $previewBytes = $preview['image_bytes'] ?? null;

                            if (is_string($previewBytes) && trim($previewBytes) !== '') {
                                $imageBytes = $previewBytes;
                                $imageMimeType = $this->cleanNullableString($preview['image_mime_type'] ?? null, 191) ?? 'image/png';
                                $imageOriginalFilename = $this->cleanNullableString($preview['image_original_filename'] ?? null, 255) ?? $imageOriginalFilename;
                                $attributes['image_width'] = $preview['image_width'] ?? $attributes['image_width'];
                                $attributes['image_height'] = $preview['image_height'] ?? $attributes['image_height'];
                                $imageMetadata = array_merge(
                                    $imageMetadata,
                                    is_array($preview['image_metadata'] ?? null) ? $preview['image_metadata'] : [],
                                );
                                $attributes['image_mime_type'] = $imageMimeType;
                                $attributes['image_original_filename'] = $imageOriginalFilename;
                                $attributes['image_metadata'] = $imageMetadata;
                            }
                        }
                    }

                    if (is_string($imageBytes) && trim($imageBytes) !== '') {
                        $imageHash = $imageHash ?? hash('sha256', $imageBytes);
                        $imageExtension = Str::lower((string) pathinfo($imageOriginalFilename, PATHINFO_EXTENSION));

                        if ($imageExtension === '') {
                            $imageExtension = match ($imageMimeType) {
                                'image/jpeg' => 'jpg',
                                'image/png' => 'png',
                                'image/gif' => 'gif',
                                'image/bmp' => 'bmp',
                                'image/webp' => 'webp',
                                'image/tiff' => 'tiff',
                                'image/svg+xml' => 'svg',
                                default => 'bin',
                            };
                        }

                        $imagePath = sprintf('knowledge-images/%d/%s.%s', $knowledgeDocument->id, $imageHash, $imageExtension);

                        abort_unless(Storage::disk($imageDisk)->put($imagePath, $imageBytes), 500, 'Failed to store the knowledge image.');

                        $attributes['image_path'] = $imagePath;
                        $attributes['image_disk'] = $imageDisk;
                        $attributes['image_mime_type'] = $imageMimeType;
                        $attributes['image_original_filename'] = $imageOriginalFilename;
                        $attributes['image_hash'] = $imageHash;
                    }

                    unset($attributes['image_bytes']);
                }

                return $attributes;
            },
            $chunkPayloads,
            array_keys($chunkPayloads),
        );

        return collect($knowledgeDocument->chunks()->createMany($chunkAttributes));
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
                'ai_summary' => $this->cleanNullableString($chunk->ai_summary, 20000)
                    ?? $this->cleanNullableString(data_get($metadataOutcome, 'summary_for_retrieval'), 20000),
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

            $this->persistChunkEmbedding($chunk, $outcome);
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

            $this->persistChunkEmbedding($chunk, $outcome);
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
        $summaryText = $this->documentSummaryText($knowledgeDocument);

        if ($summaryText !== '') {
            return Str::limit($summaryText, 360, '...');
        }

        if ($knowledgeDocument->extraction_status === KnowledgeItem::EXTRACTION_STATUS_FAILED) {
            return 'Tekstuttrekk feilet.';
        }

        return trim((string) $knowledgeDocument->extracted_text) !== '' ? '' : 'Ingen ekstrahert tekst.';
    }

    /**
     * Purpose: Resolve the best summary source text for a knowledge document.
     * Inputs: A customer-scoped knowledge document.
     * Returns: Clean semantic chunk text when available, otherwise filtered extracted text.
     * Side effects: None.
     */
    private function documentSummaryText(KnowledgeItem $knowledgeDocument): string
    {
        $semanticText = $this->semanticChunkSummaryText($knowledgeDocument);

        if ($semanticText !== '') {
            $cleanSemanticText = $this->cleanDocumentSummaryText($semanticText);

            if ($cleanSemanticText !== '') {
                return $cleanSemanticText;
            }
        }

        return $this->cleanDocumentSummaryText((string) $knowledgeDocument->extracted_text);
    }

    /**
     * Purpose: Collect summary candidate text from loaded semantic/document chunks.
     * Inputs: A customer-scoped knowledge document with optional chunk relation loading.
     * Returns: Chunk text in reading order or an empty string when chunks are unavailable.
     * Side effects: None.
     */
    private function semanticChunkSummaryText(KnowledgeItem $knowledgeDocument): string
    {
        if (! $knowledgeDocument->relationLoaded('chunks')) {
            return '';
        }

        $chunks = $knowledgeDocument->chunks
            ->filter(static function (KnowledgeItemChunk $chunk): bool {
                return in_array($chunk->chunk_type, ['semantic', 'document'], true);
            })
            ->sortBy('chunk_index')
            ->map(static fn (KnowledgeItemChunk $chunk): string => trim((string) $chunk->content))
            ->filter(static fn (string $content): bool => $content !== '')
            ->values()
            ->all();

        return implode("\n\n", $chunks);
    }

    /**
     * Purpose: Remove TOC/dotted-leader noise from a summary candidate.
     * Inputs: Raw semantic or extracted text.
     * Returns: A compact body-text excerpt without TOC lines.
     * Side effects: None.
     */
    private function cleanDocumentSummaryText(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [];
        $cleanParagraphs = [];

        foreach ($paragraphs as $paragraph) {
            $lines = preg_split('/\n/u', trim($paragraph)) ?: [];
            $cleanLines = [];

            foreach ($lines as $line) {
                $normalizedLine = $this->normalizeSummaryTextLine($line);

                if ($normalizedLine === '' || $this->isTocSummaryLine($normalizedLine)) {
                    continue;
                }

                $cleanLines[] = $normalizedLine;
            }

            $cleanParagraph = trim(implode(' ', $cleanLines));

            if ($cleanParagraph === '') {
                continue;
            }

            $cleanParagraphs[] = $cleanParagraph;
        }

        return trim(preg_replace('/\s+/u', ' ', implode("\n\n", $cleanParagraphs)) ?? '');
    }

    /**
     * Purpose: Normalize one summary candidate line before TOC filtering.
     * Inputs: A raw extracted line.
     * Returns: A collapsed single-line string.
     * Side effects: None.
     */
    private function normalizeSummaryTextLine(string $line): string
    {
        return trim(preg_replace('/\s+/u', ' ', $line) ?? '');
    }

    /**
     * Purpose: Determine whether a line looks like TOC noise rather than document body text.
     * Inputs: One normalized summary line.
     * Returns: True when the line resembles a table of contents entry or heading.
     * Side effects: None.
     */
    private function isTocSummaryLine(string $line): bool
    {
        if ($line === '') {
            return false;
        }

        $normalized = Str::ascii(mb_strtolower(trim($line), 'UTF-8'));

        if (preg_match('/\b(?:innholdsfortegnelse|table of contents|contents)\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\s*(?:bilag|vedlegg)\b/iu', $normalized) === 1 && mb_strlen($normalized, 'UTF-8') <= 80 && ! preg_match('/[.!?]/u', $normalized)) {
            return true;
        }

        if (mb_strlen($normalized, 'UTF-8') > 180) {
            return false;
        }

        if (preg_match('/^\s*(?:\d+(?:\.\d+)*|bilag\s+\d+(?:-\d+)?|vedlegg\s+[a-z0-9]+)\b/iu', $normalized) !== 1) {
            return false;
        }

        return preg_match('/(?:\.{4,}|\s{6,})\s*(?:\d{1,3}|[ivxlcdm]{1,5})\s*$/iu', $normalized) === 1;
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

    private function calculateFileHash(UploadedFile $file): string
    {
        return (string) hash_file('sha256', $file->getRealPath());
    }
}
