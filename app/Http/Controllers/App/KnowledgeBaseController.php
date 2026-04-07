<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\DocumentChunker;
use App\Services\DocumentTextExtractor;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            DB::transaction(function () use (
                $customerId,
                $payload,
                $request,
                $storedPath,
                $extractedText,
                $extractionFailed,
            ): void {
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

                $this->syncChunks($knowledgeDocument, $extractedText);
            });
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
     * Purpose: Validate and normalize the upload payload for a knowledge document.
     * Inputs: The current frontend request.
     * Returns: A normalized payload ready for persistence.
     * Side effects: Throws validation errors when the request is invalid.
     */
    private function validatedStorePayload(Request $request): array
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:20480'],
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
            'content_excerpt' => $this->contentExcerpt($knowledgeDocument),
            'is_active' => (bool) $knowledgeDocument->is_active,
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
        ];
    }

    /**
     * Purpose: Regenerate deterministic chunks for one knowledge document.
     * Inputs: The knowledge document and its extracted text.
     * Returns: None.
     * Side effects: Deletes old chunks and inserts the current chunk set.
     */
    private function syncChunks(KnowledgeItem $knowledgeDocument, string $extractedText): void
    {
        $knowledgeDocument->chunks()->delete();
        $chunkPayloads = $this->documentChunker->chunkText($extractedText);

        if ($chunkPayloads === []) {
            return;
        }

        $knowledgeDocument->chunks()->createMany(
            array_map(
                static fn (array $chunkPayload, int $chunkIndex): array => [
                    'chunk_index' => $chunkIndex,
                    'content' => (string) ($chunkPayload['content'] ?? ''),
                    'start_offset' => (int) ($chunkPayload['char_start'] ?? 0),
                    'end_offset' => (int) ($chunkPayload['char_end'] ?? 0),
                ],
                $chunkPayloads,
                array_keys($chunkPayloads),
            ),
        );
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
