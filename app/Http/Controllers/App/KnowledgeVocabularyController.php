<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeMetadataTerm;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Knowledge\KnowledgeMetadataVocabularyService;
use App\Services\Ai\Knowledge\KnowledgeVocabularyAnalysisBatchService;
use App\Services\Ai\Knowledge\KnowledgeVocabularyApprovalService;
use App\Services\Billing\BillingEntitlementService;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeVocabularyController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly KnowledgeMetadataVocabularyService $vocabularyService,
        private readonly KnowledgeVocabularyAnalysisBatchService $analysisBatchService,
        private readonly KnowledgeVocabularyApprovalService $approvalService,
        private readonly AiUsageGuard $aiUsageGuard,
    ) {
    }

    /**
     * Purpose: Render the vocabulary workspace within the AI area.
     * Inputs: The current frontend request.
     * Returns: An Inertia response with approved vocabulary, suggestions and batches.
     * Side effects: None.
     */
    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $this->syncReviewedBatches($customerId);

        return Inertia::render('App/AI/KnowledgeVocabulary/Index', [
            'pageTitle' => 'Standardvokabular',
            'approvedVocabularyGroups' => $this->approvedVocabularyGroups($customerId),
            'suggestions' => $this->pendingSuggestionPayload($customerId),
            'recentBatches' => $this->recentBatchPayload($customerId),
            'sourceDocuments' => $this->representativeDocumentsPayload($customerId),
            'typeOptions' => $this->typeOptions(),
            'storeBatchUrl' => route('app.ai.knowledge-vocabulary.analysis-batches.store'),
            'approveSuggestionBaseUrl' => route('app.ai.knowledge-vocabulary.suggestions.approve', ['suggestion' => '__ID__']),
            'rejectSuggestionBaseUrl' => route('app.ai.knowledge-vocabulary.suggestions.reject', ['suggestion' => '__ID__']),
            'mergeSuggestionBaseUrl' => route('app.ai.knowledge-vocabulary.suggestions.merge', ['suggestion' => '__ID__']),
            'editAndApproveSuggestionBaseUrl' => route('app.ai.knowledge-vocabulary.suggestions.edit-and-approve', ['suggestion' => '__ID__']),
        ]);
    }

    /**
     * Purpose: Create and analyze a new vocabulary batch from selected documents.
     * Inputs: The current frontend request.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Persists a batch row, runs analysis, and creates pending suggestions.
     */
    public function storeBatch(Request $request): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $payload = $request->validate([
            'source_document_ids' => ['required', 'array', 'min:1'],
            'source_document_ids.*' => ['integer', 'min:1'],
        ]);

        $documentIds = collect($payload['source_document_ids'])
            ->map(static fn (mixed $documentId): int => (int) $documentId)
            ->filter(static fn (int $documentId): bool => $documentId > 0)
            ->unique()
            ->values()
            ->all();

        if ($documentIds === []) {
            throw ValidationException::withMessages([
                'source_document_ids' => 'Velg minst ett gyldig dokument.',
            ]);
        }

        $customer = Customer::query()->findOrFail($customerId);
        $this->assertAiAccess($customer);
        $usageWarning = $this->aiUsageGuard->assertCanStartAiOperation(
            $customer,
            $user,
            AiUsageGuard::OPERATION_KNOWLEDGE_VOCABULARY_ANALYSIS_BATCH,
            count($documentIds),
        );

        if ($usageWarning !== null) {
            session()->flash('warning', $usageWarning);
        }

        $batch = $this->analysisBatchService->createBatch($customerId, $documentIds, (int) $user->id);
        $batch = $this->analysisBatchService->startAnalysis($batch->id);

        return redirect()
            ->route('app.ai.knowledge-vocabulary.index')
            ->with('success', $this->batchFlashMessage($batch));
    }

    /**
     * Purpose: Delete one failed vocabulary analysis batch.
     * Inputs: The current frontend request and the route-bound batch.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Deletes the failed batch and its dependent suggestions.
     */
    public function destroyBatch(Request $request, KnowledgeVocabularyAnalysisBatch $batch): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedBatch($customerId, $batch->id);

        if ($record->status !== KnowledgeVocabularyAnalysisBatch::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'batch' => 'Kun feilede batcher kan slettes.',
            ]);
        }

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary batch deleted.', [
            'customer_id' => $customerId,
            'batch_id' => $record->id,
            'deleted_by' => (int) $user->id,
        ]);

        DB::transaction(function () use ($record): void {
            KnowledgeMetadataTermSuggestion::query()
                ->where('batch_id', $record->id)
                ->delete();

            $record->delete();
        });

        return redirect()
            ->route('app.ai.knowledge-vocabulary.index')
            ->with('success', sprintf('Batch #%d ble slettet.', $record->id));
    }

    /**
     * Purpose: Update one approved vocabulary term.
     * Inputs: The current frontend request and the route-bound approved term.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Updates the authoritative vocabulary catalog.
     */
    public function updateTerm(Request $request, KnowledgeMetadataTerm $term): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedApprovedTerm($customerId, $term->id);
        $payload = $request->validate([
            'type' => ['required', 'string', Rule::in(KnowledgeMetadataTerm::TYPES)],
            'canonical_name' => ['required', 'string', 'max:191'],
            'synonyms' => ['nullable', 'string', 'max:4000'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);

        $normalizedType = trim((string) $payload['type']);
        $canonicalName = trim((string) $payload['canonical_name']);
        $synonyms = $this->commaSeparatedList($payload['synonyms'] ?? null);
        $description = $this->nullableString($payload['description'] ?? null);

        $catalog = $this->vocabularyService->buildCatalogForCustomer($customerId);
        $conflict = $this->resolveApprovedTermConflict($catalog, $normalizedType, $canonicalName, $synonyms, $record->id);

        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'canonical_name' => 'Et annet godkjent begrep med samme navn eller synonym finnes allerede.',
            ]);
        }

        DB::transaction(function () use ($record, $normalizedType, $canonicalName, $synonyms, $description): void {
            $record->forceFill([
                'type' => $normalizedType,
                'canonical_name' => $canonicalName,
                'synonyms' => $this->normalizeApprovedSynonyms($canonicalName, $synonyms),
                'description' => $description,
                'approved' => true,
            ])->save();
        });

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Approved vocabulary term updated.', [
            'customer_id' => $customerId,
            'term_id' => $record->id,
            'updated_by' => (int) $user->id,
        ]);

        return back()->with('success', 'Vokabularet ble oppdatert.');
    }

    /**
     * Purpose: Delete one approved vocabulary term.
     * Inputs: The current frontend request and the route-bound approved term.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Deletes the approved term and detaches related suggestions.
     */
    public function destroyTerm(Request $request, KnowledgeMetadataTerm $term): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedApprovedTerm($customerId, $term->id);

        DB::transaction(function () use ($record): void {
            KnowledgeMetadataTermSuggestion::query()
                ->where('related_existing_term_id', $record->id)
                ->update([
                    'related_existing_term_id' => null,
                ]);

            $record->delete();
        });

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Approved vocabulary term deleted.', [
            'customer_id' => $customerId,
            'term_id' => $record->id,
            'deleted_by' => (int) $user->id,
        ]);

        return redirect()
            ->route('app.ai.knowledge-vocabulary.index')
            ->with('success', 'Godkjent vokabular ble slettet.');
    }

    /**
     * Purpose: Approve one pending suggestion as a vocabulary term.
     * Inputs: The current frontend request and the route-bound suggestion.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Creates or merges vocabulary data in the authoritative catalog.
     */
    public function approveSuggestion(Request $request, KnowledgeMetadataTermSuggestion $suggestion): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedSuggestion($customerId, $suggestion->id);

        $updatedSuggestion = $this->approvalService->approveSuggestion($record->id, (int) $user->id);
        $this->analysisBatchService->completeIfReviewFinished($customerId, $updatedSuggestion->batch_id);

        return back()->with('success', $this->suggestionFlashMessage($updatedSuggestion));
    }

    /**
     * Purpose: Reject one pending suggestion.
     * Inputs: The current frontend request and the route-bound suggestion.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Marks the suggestion as rejected.
     */
    public function rejectSuggestion(Request $request, KnowledgeMetadataTermSuggestion $suggestion): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedSuggestion($customerId, $suggestion->id);

        $updatedSuggestion = $this->approvalService->rejectSuggestion($record->id, (int) $user->id);
        $this->analysisBatchService->completeIfReviewFinished($customerId, $updatedSuggestion->batch_id);

        return back()->with('success', $this->suggestionFlashMessage($updatedSuggestion));
    }

    /**
     * Purpose: Merge one pending suggestion into an existing approved term.
     * Inputs: The current frontend request, the route-bound suggestion, and the target term id.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Updates the target term and marks the suggestion as merged.
     */
    public function mergeSuggestion(Request $request, KnowledgeMetadataTermSuggestion $suggestion): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedSuggestion($customerId, $suggestion->id);
        $payload = $request->validate([
            'existing_term_id' => ['required', 'integer', 'min:1'],
        ]);
        $term = $this->scopedApprovedTerm($customerId, (int) $payload['existing_term_id']);

        $updatedSuggestion = $this->approvalService->mergeSuggestion($record->id, $term->id, (int) $user->id);
        $this->analysisBatchService->completeIfReviewFinished($customerId, $updatedSuggestion->batch_id);

        return back()->with('success', $this->suggestionFlashMessage($updatedSuggestion));
    }

    /**
     * Purpose: Edit one pending suggestion and then approve it.
     * Inputs: The current frontend request and the route-bound suggestion.
     * Returns: A redirect back to the vocabulary workspace.
     * Side effects: Updates the suggestion and then writes to the authoritative catalog.
     */
    public function editAndApproveSuggestion(Request $request, KnowledgeMetadataTermSuggestion $suggestion): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedSuggestion($customerId, $suggestion->id);
        $payload = $request->validate([
            'suggested_type' => ['required', 'string', Rule::in(KnowledgeMetadataTerm::TYPES)],
            'suggested_canonical_name' => ['required', 'string', 'max:191'],
            'suggested_synonyms' => ['nullable', 'string', 'max:4000'],
            'suggested_description' => ['nullable', 'string', 'max:4000'],
            'reason' => ['nullable', 'string', 'max:4000'],
        ]);

        $normalizedPayload = [
            'suggested_type' => trim((string) $payload['suggested_type']),
            'suggested_canonical_name' => trim((string) $payload['suggested_canonical_name']),
            'suggested_synonyms' => $this->commaSeparatedList($payload['suggested_synonyms'] ?? null),
            'suggested_description' => $this->nullableString($payload['suggested_description'] ?? null),
            'reason' => $this->nullableString($payload['reason'] ?? null),
        ];

        $updatedSuggestion = $this->approvalService->editAndApproveSuggestion($record->id, $normalizedPayload, (int) $user->id);
        $this->analysisBatchService->completeIfReviewFinished($customerId, $updatedSuggestion->batch_id);

        return back()->with('success', $this->suggestionFlashMessage($updatedSuggestion));
    }

    /**
     * Purpose: Resolve the authenticated customer context for vocabulary access.
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
     * Purpose: Verify that the current customer may use the AI features in the vocabulary workspace.
     * Inputs: The customer resolved from the current frontend context.
     * Returns: None.
     * Side effects: Aborts with HTTP 403 when the customer lacks AI entitlement.
     */
    private function assertAiAccess(Customer $customer): void
    {
        abort_unless(app(BillingEntitlementService::class)->canUseAiOffer($customer), 403, __('procynia.ai.ai_access_unavailable_message'));
    }

    /**
     * Purpose: Scope a suggestion row to the current customer.
     * Inputs: Customer id and suggestion id.
     * Returns: The matching suggestion row.
     * Side effects: Aborts with HTTP 404 when the row does not belong to the customer.
     */
    private function scopedSuggestion(int $customerId, int $suggestionId): KnowledgeMetadataTermSuggestion
    {
        return KnowledgeMetadataTermSuggestion::query()
            ->where('customer_id', $customerId)
            ->whereKey($suggestionId)
            ->firstOrFail();
    }

    /**
     * Purpose: Scope a batch row to the current customer.
     * Inputs: Customer id and batch id.
     * Returns: The matching batch row.
     * Side effects: Aborts with HTTP 404 when the row does not belong to the customer.
     */
    private function scopedBatch(int $customerId, int $batchId): KnowledgeVocabularyAnalysisBatch
    {
        return KnowledgeVocabularyAnalysisBatch::query()
            ->where('customer_id', $customerId)
            ->whereKey($batchId)
            ->firstOrFail();
    }

    /**
     * Purpose: Scope an approved term row to the current customer.
     * Inputs: Customer id and term id.
     * Returns: The matching approved term row.
     * Side effects: Aborts with HTTP 404 when the row does not belong to the customer.
     */
    private function scopedApprovedTerm(int $customerId, int $termId): KnowledgeMetadataTerm
    {
        return KnowledgeMetadataTerm::query()
            ->where('customer_id', $customerId)
            ->where('approved', true)
            ->whereKey($termId)
            ->firstOrFail();
    }

    /**
     * Purpose: Reconcile stale review batches that no longer have pending suggestions.
     * Inputs: The current customer id.
     * Returns: None.
     * Side effects: May update one or more batch rows from pending_review to completed.
     */
    private function syncReviewedBatches(int $customerId): void
    {
        KnowledgeVocabularyAnalysisBatch::query()
            ->where('customer_id', $customerId)
            ->where('status', KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $batchId) use ($customerId): void {
                $this->analysisBatchService->completeIfReviewFinished($customerId, $batchId);
            });
    }

    /**
     * Purpose: Build grouped approved vocabulary payloads for the workspace.
     * Inputs: Customer id.
     * Returns: Approved terms grouped by type with display labels.
     * Side effects: None.
     */
    private function approvedVocabularyGroups(int $customerId): array
    {
        $catalog = $this->vocabularyService->buildCatalogForCustomer($customerId);
        $groups = [];

        foreach (KnowledgeMetadataTerm::TYPES as $type) {
            $terms = (array) data_get($catalog, 'groups.'.$type, []);

            if ($terms === []) {
                continue;
            }

            $groups[] = [
                'type' => $type,
                'label' => KnowledgeMetadataTerm::TYPE_LABELS[$type] ?? $type,
                'count' => count($terms),
                'terms' => collect($terms)
                    ->map(static fn (array $term): array => [
                        'id' => (int) data_get($term, 'id', 0),
                        'type' => (string) data_get($term, 'type', $type),
                        'canonical_name' => (string) data_get($term, 'canonical_name', ''),
                'synonyms' => (array) data_get($term, 'synonyms', []),
                'description' => data_get($term, 'description'),
                'approved' => (bool) data_get($term, 'approved', true),
                'edit_url' => route('app.ai.knowledge-vocabulary.terms.update', ['term' => (int) data_get($term, 'id', 0)]),
                'delete_url' => route('app.ai.knowledge-vocabulary.terms.destroy', ['term' => (int) data_get($term, 'id', 0)]),
            ])
                    ->values()
                    ->all(),
            ];
        }

        return $groups;
    }

    /**
     * Purpose: Build pending suggestion payloads for the workspace.
     * Inputs: Customer id.
     * Returns: A customer-scoped list of pending suggestions.
     * Side effects: None.
     */
    private function pendingSuggestionPayload(int $customerId): array
    {
        $suggestions = KnowledgeMetadataTermSuggestion::query()
            ->with([
                'analysisBatch.creator',
                'relatedExistingTerm',
                'sourceChunk.knowledgeItem',
            ])
            ->where('customer_id', $customerId)
            ->where('status', KnowledgeMetadataTermSuggestion::STATUS_PENDING)
            ->orderByDesc('id')
            ->get();

        return $suggestions
            ->map(fn (KnowledgeMetadataTermSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'customer_id' => (int) $suggestion->customer_id,
                'batch_id' => $suggestion->batch_id,
                'batch_label' => $suggestion->analysisBatch ? sprintf('Batch #%d', $suggestion->analysisBatch->id) : '—',
                'batch_status' => $suggestion->analysisBatch ? $suggestion->analysisBatch->status : null,
                'source_chunk_id' => $suggestion->source_chunk_id,
                'source_label' => $this->suggestionSourceLabel($suggestion),
                'related_existing_term_id' => $suggestion->related_existing_term_id,
                'related_existing_term_label' => $suggestion->relatedExistingTerm?->canonical_name,
                'suggested_type' => $this->normalizeSuggestionType((string) $suggestion->suggested_type),
                'suggested_type_label' => KnowledgeMetadataTerm::TYPE_LABELS[$this->normalizeSuggestionType((string) $suggestion->suggested_type)] ?? $this->normalizeSuggestionType((string) $suggestion->suggested_type),
                'suggested_term' => $suggestion->suggested_term ?: $suggestion->suggested_canonical_name,
                'suggested_canonical_name' => $suggestion->suggested_canonical_name,
                'suggested_synonyms' => $suggestion->suggested_synonyms ?? [],
                'suggested_description' => $suggestion->suggested_description,
                'suggested_canonical_parent' => $suggestion->suggested_canonical_parent,
                'reason' => $suggestion->reason,
                'confidence_score' => $suggestion->confidence_score,
                'status' => $suggestion->status,
                'created_at' => optional($suggestion->created_at)?->toIso8601String(),
                'approve_url' => route('app.ai.knowledge-vocabulary.suggestions.approve', ['suggestion' => $suggestion->id]),
                'reject_url' => route('app.ai.knowledge-vocabulary.suggestions.reject', ['suggestion' => $suggestion->id]),
                'merge_url' => route('app.ai.knowledge-vocabulary.suggestions.merge', ['suggestion' => $suggestion->id]),
                'edit_and_approve_url' => route('app.ai.knowledge-vocabulary.suggestions.edit-and-approve', ['suggestion' => $suggestion->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * Purpose: Build recent batch payloads for the workspace.
     * Inputs: Customer id.
     * Returns: A customer-scoped list of recent vocabulary analysis batches.
     * Side effects: None.
     */
    private function recentBatchPayload(int $customerId): array
    {
        $batches = KnowledgeVocabularyAnalysisBatch::query()
            ->with('creator')
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $documentMap = $this->documentTitleMap($customerId, $batches->pluck('source_document_ids')->flatten()->filter()->unique()->values()->all());

        return $batches
            ->map(function (KnowledgeVocabularyAnalysisBatch $batch) use ($documentMap): array {
                $sourceDocumentIds = $this->normalizeIds($batch->source_document_ids ?? []);
                $sourceDocuments = collect($sourceDocumentIds)
                    ->map(fn (int $documentId): string => $documentMap[$documentId] ?? sprintf('Dokument #%d', $documentId))
                    ->values()
                    ->all();

                return [
                    'id' => $batch->id,
                    'customer_id' => (int) $batch->customer_id,
                    'status' => $batch->status,
                    'status_label' => KnowledgeVocabularyAnalysisBatch::STATUS_LABELS[$batch->status] ?? $batch->status,
                    'summary' => $batch->summary,
                    'error_message' => $batch->error_message,
                    'source_document_ids' => $sourceDocumentIds,
                    'source_documents' => $sourceDocuments,
                    'source_document_count' => count($sourceDocumentIds),
                    'delete_url' => $batch->status === KnowledgeVocabularyAnalysisBatch::STATUS_FAILED
                        ? route('app.ai.knowledge-vocabulary.analysis-batches.destroy', ['batch' => $batch->id])
                        : null,
                    'created_by' => $batch->creator?->name,
                    'created_at' => optional($batch->created_at)?->toIso8601String(),
                    'updated_at' => optional($batch->updated_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Build a source document selection list for the analysis form.
     * Inputs: Customer id.
     * Returns: A list of representative documents that can seed vocabulary analysis.
     * Side effects: None.
     */
    private function representativeDocumentsPayload(int $customerId): array
    {
        return KnowledgeItem::query()
            ->withCount('chunks')
            ->where('customer_id', $customerId)
            ->where('ownership_type', KnowledgeItem::OWNERSHIP_TYPE_COMPANY)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('knowledge_item_versions')
                    ->whereColumn('knowledge_item_versions.knowledge_item_id', 'knowledge_items.id')
                    ->where('knowledge_item_versions.is_current', true)
                    ->whereNotNull('knowledge_item_versions.storage_path')
                    ->where('knowledge_item_versions.extraction_status', KnowledgeItem::EXTRACTION_STATUS_COMPLETED);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->map(fn (KnowledgeItem $document): array => [
                'id' => $document->id,
                'original_filename' => $document->resolvedOriginalFilename(),
                'title' => $document->title,
                'summary' => $document->summary,
                'document_type' => $document->document_type,
                'document_type_label' => KnowledgeItem::DOCUMENT_TYPE_LABELS[$document->document_type] ?? $document->document_type,
                'chunk_count' => (int) ($document->chunks_count ?? 0),
                'updated_at' => optional($document->updated_at)?->toIso8601String(),
                'show_url' => route('app.ai.knowledge-base.show', ['knowledgeItem' => $document->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * Purpose: Build a fast lookup map for document ids to titles.
     * Inputs: Customer id and a list of document ids.
     * Returns: An id-to-title map.
     * Side effects: None.
     */
    private function documentTitleMap(int $customerId, array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return KnowledgeItem::query()
            ->where('knowledge_items.customer_id', $customerId)
            ->whereIn('knowledge_items.id', $documentIds)
            ->leftJoin('knowledge_item_versions as kiv_current', function ($join): void {
                $join->on('kiv_current.knowledge_item_id', '=', 'knowledge_items.id')
                    ->where('kiv_current.is_current', true);
            })
            ->select(['knowledge_items.id', 'kiv_current.original_filename'])
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->id => trim((string) $row->original_filename) !== '' ? (string) $row->original_filename : '—',
            ])
            ->all();
    }

    /**
     * Purpose: Build the vocabulary type options for the UI.
     * Inputs: None.
     * Returns: A stable list of type labels and values.
     * Side effects: None.
     */
    private function typeOptions(): array
    {
        return collect(KnowledgeMetadataTerm::TYPES)
            ->map(static fn (string $type): array => [
                'value' => $type,
                'label' => KnowledgeMetadataTerm::TYPE_LABELS[$type] ?? $type,
            ])
            ->values()
            ->all();
    }

    /**
     * Purpose: Resolve whether an edited approved term conflicts with another approved term.
     * Inputs: The approved vocabulary catalog, the edited type, canonical name, synonyms, and current term id.
     * Returns: The conflicting term row or null.
     * Side effects: None.
     */
    private function resolveApprovedTermConflict(array $catalog, string $type, string $canonicalName, array $synonyms, int $currentTermId): ?array
    {
        $resolved = $this->vocabularyService->resolveCatalogTerm($catalog, $type, $canonicalName);

        if ($resolved !== null && (int) data_get($resolved, 'id', 0) !== $currentTermId) {
            return $resolved;
        }

        foreach ($synonyms as $synonym) {
            $resolved = $this->vocabularyService->resolveCatalogTerm($catalog, $type, $synonym);

            if ($resolved !== null && (int) data_get($resolved, 'id', 0) !== $currentTermId) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Purpose: Normalize approved term synonyms and remove the canonical name if present.
     * Inputs: The canonical name and raw synonym list.
     * Returns: A de-duplicated synonym list.
     * Side effects: None.
     */
    private function normalizeApprovedSynonyms(string $canonicalName, array $synonyms): array
    {
        $canonicalKey = mb_strtolower(trim($canonicalName), 'UTF-8');

        return collect($synonyms)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->reject(static fn (string $value): bool => mb_strtolower($value, 'UTF-8') === $canonicalKey)
            ->unique(fn (string $value): string => mb_strtolower($value, 'UTF-8'))
            ->values()
            ->all();
    }

    /**
     * Purpose: Normalize a list of ids into unique positive integers.
     * Inputs: Raw id values.
     * Returns: A de-duplicated integer list.
     * Side effects: None.
     */
    private function normalizeIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Purpose: Convert a comma-separated string into a stable list of trimmed values.
     * Inputs: Raw text or null.
     * Returns: A de-duplicated string list.
     * Side effects: None.
     */
    private function commaSeparatedList(mixed $value): array
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return [];
        }

        return collect(preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", $text), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn (string $item): string => trim($item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique(fn (string $item): string => mb_strtolower($item, 'UTF-8'))
            ->values()
            ->all();
    }

    /**
     * Purpose: Normalize a nullable text field to a trimmed nullable string.
     * Inputs: Raw text or null.
     * Returns: A trimmed string or null.
     * Side effects: None.
     */
    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Purpose: Convert a batch row into a concise flash message.
     * Inputs: The refreshed analysis batch.
     * Returns: A user-facing status message.
     * Side effects: None.
     */
    private function batchFlashMessage(KnowledgeVocabularyAnalysisBatch $batch): string
    {
        return match ($batch->status) {
            KnowledgeVocabularyAnalysisBatch::STATUS_FAILED => 'Vokabularanalyse feilet.',
            KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW => 'Vokabularanalyse fullført. Forslag ligger klare for review.',
            default => 'Vokabularanalyse fullført.',
        };
    }

    /**
     * Purpose: Convert a suggestion row into a concise flash message.
     * Inputs: The refreshed suggestion row.
     * Returns: A user-facing status message.
     * Side effects: None.
     */
    private function suggestionFlashMessage(KnowledgeMetadataTermSuggestion $suggestion): string
    {
        return match ($suggestion->status) {
            KnowledgeMetadataTermSuggestion::STATUS_REJECTED => 'Forslag avvist.',
            KnowledgeMetadataTermSuggestion::STATUS_MERGED => 'Forslag slått sammen.',
            KnowledgeMetadataTermSuggestion::STATUS_APPROVED => 'Forslag godkjent.',
            default => 'Forslag oppdatert.',
        };
    }

    /**
     * Purpose: Resolve a suggestion source label for the workspace list.
     * Inputs: A pending suggestion row.
     * Returns: A short source label or null when no source chunk exists.
     * Side effects: None.
     */
    private function suggestionSourceLabel(KnowledgeMetadataTermSuggestion $suggestion): ?string
    {
        if ($suggestion->sourceChunk === null) {
            return null;
        }

        $documentTitle = $suggestion->sourceChunk->knowledgeItem?->resolvedOriginalFilename()
            ?? $suggestion->sourceChunk->knowledgeItem?->title
            ?? null;
        $chunkNumber = (int) $suggestion->sourceChunk->chunk_index + 1;

        if ($documentTitle === null) {
            return sprintf('Chunk #%d', $chunkNumber);
        }

        return sprintf('%s · Chunk %d', $documentTitle, $chunkNumber);
    }

    /**
     * Purpose: Normalize a vocabulary suggestion type to the canonical field name.
     * Inputs: A raw suggestion type value.
     * Returns: The canonical type key or the original trimmed value when unsupported.
     * Side effects: None.
     */
    private function normalizeSuggestionType(string $type): string
    {
        $normalized = trim($type);
        $normalized = KnowledgeMetadataTerm::TYPE_ALIASES[$normalized] ?? $normalized;

        return $normalized !== '' ? $normalized : $type;
    }

}
