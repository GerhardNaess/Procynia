<?php

namespace App\Services\Ai\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeMetadataTermSuggestion;
use App\Models\KnowledgeVocabularyAnalysisBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class KnowledgeVocabularyAnalysisBatchService
{
    public function __construct(
        private readonly KnowledgeMetadataVocabularyService $vocabularyService,
        private readonly KnowledgeVocabularyExtractionService $extractionService,
        private readonly KnowledgeVocabularySuggestionValidationService $validationService,
    ) {
    }

    /**
     * Purpose: Create a new vocabulary analysis batch for representative documents.
     * Inputs: Customer id, selected document ids, and the creating user id.
     * Returns: The persisted analysis batch.
     * Side effects: Creates a batch row and logs the action.
     */
    public function createBatch(int $customerId, array $documentIds, int $userId): KnowledgeVocabularyAnalysisBatch
    {
        $batch = KnowledgeVocabularyAnalysisBatch::query()->create([
            'customer_id' => $customerId,
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_UPLOADED,
            'source_document_ids' => $this->normalizeDocumentIds($documentIds),
            'summary' => null,
            'error_message' => null,
            'created_by' => $userId,
        ]);

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary analysis batch created.', [
            'customer_id' => $customerId,
            'batch_id' => $batch->id,
            'created_by' => $userId,
            'source_document_count' => count((array) $batch->source_document_ids),
        ]);

        return $batch->fresh();
    }

    /**
     * Purpose: Execute the vocabulary analysis lifecycle for one batch.
     * Inputs: The batch id.
     * Returns: The refreshed batch row.
     * Side effects: Updates batch status and may create pending suggestions.
     */
    public function startAnalysis(int $batchId): KnowledgeVocabularyAnalysisBatch
    {
        $batch = KnowledgeVocabularyAnalysisBatch::query()->findOrFail($batchId);

        if ($batch->isTerminal()) {
            return $batch;
        }

        $batch->forceFill([
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_PARSING,
            'error_message' => null,
        ])->save();

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary batch parsing started.', [
            'customer_id' => (int) $batch->customer_id,
            'batch_id' => $batch->id,
        ]);

        try {
            $documents = $this->loadAnalysisDocuments($batch);

            if ($documents->isEmpty()) {
                return $this->markFailed($batch->id, 'Ingen representative dokumenter kunne brukes til vokabularanalyse.');
            }

            $batch->forceFill([
                'status' => KnowledgeVocabularyAnalysisBatch::STATUS_ANALYZING,
            ])->save();

            Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary batch analysis started.', [
                'customer_id' => (int) $batch->customer_id,
                'batch_id' => $batch->id,
                'document_count' => $documents->count(),
            ]);

            $catalog = $this->vocabularyService->buildCatalogForCustomer((int) $batch->customer_id);
            $rawPayload = $this->extractionService->extract($batch, $documents, $catalog);
            $validation = $this->validationService->validateAndPersist($batch, $rawPayload, $catalog);
            $batchSummary = trim((string) data_get($validation, 'batch_summary', ''));

            if ($batchSummary !== '') {
                $batch->forceFill([
                    'summary' => Str::limit($batchSummary, 4000, ''),
                ])->save();
            }

            $suggestionCount = (int) data_get($validation, 'created_count', 0) + (int) data_get($validation, 'related_count', 0);

            if ($suggestionCount > 0) {
                return $this->markPendingReview($batch->id);
            }

            return $this->markCompleted($batch->id);
        } catch (Throwable $exception) {
            Log::warning('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary batch analysis failed.', [
                'customer_id' => (int) $batch->customer_id,
                'batch_id' => $batch->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->markFailed($batch->id, $exception->getMessage());
        }
    }

    /**
     * Purpose: Mark a batch as failed and store the failure reason.
     * Inputs: The batch id and error reason.
     * Returns: The refreshed batch row.
     * Side effects: Updates the batch status in the database.
     */
    public function markFailed(int $batchId, string $reason): KnowledgeVocabularyAnalysisBatch
    {
        $batch = KnowledgeVocabularyAnalysisBatch::query()->findOrFail($batchId);

        $batch->forceFill([
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_FAILED,
            'error_message' => Str::limit(trim($reason), 4000, ''),
        ])->save();

        return $batch->fresh();
    }

    /**
     * Purpose: Mark a batch as pending review.
     * Inputs: The batch id.
     * Returns: The refreshed batch row.
     * Side effects: Updates the batch status in the database.
     */
    public function markPendingReview(int $batchId): KnowledgeVocabularyAnalysisBatch
    {
        $batch = KnowledgeVocabularyAnalysisBatch::query()->findOrFail($batchId);

        $batch->forceFill([
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW,
            'error_message' => null,
        ])->save();

        return $batch->fresh();
    }

    /**
     * Purpose: Mark a batch as completed.
     * Inputs: The batch id.
     * Returns: The refreshed batch row.
     * Side effects: Updates the batch status in the database.
     */
    public function markCompleted(int $batchId): KnowledgeVocabularyAnalysisBatch
    {
        $batch = KnowledgeVocabularyAnalysisBatch::query()->findOrFail($batchId);

        $batch->forceFill([
            'status' => KnowledgeVocabularyAnalysisBatch::STATUS_COMPLETED,
            'error_message' => null,
        ])->save();

        return $batch->fresh();
    }

    /**
     * Purpose: Mark a pending review batch as completed when all suggestions have been processed.
     * Inputs: Customer id and batch id.
     * Returns: The refreshed batch row when the batch is completed, or null when pending work remains.
     * Side effects: May update the batch status in the database.
     */
    public function completeIfReviewFinished(int $customerId, ?int $batchId): ?KnowledgeVocabularyAnalysisBatch
    {
        if ($batchId === null) {
            return null;
        }

        $batch = KnowledgeVocabularyAnalysisBatch::query()
            ->where('customer_id', $customerId)
            ->whereKey($batchId)
            ->firstOrFail();

        if ($batch->status !== KnowledgeVocabularyAnalysisBatch::STATUS_PENDING_REVIEW) {
            return null;
        }

        $hasPendingSuggestions = KnowledgeMetadataTermSuggestion::query()
            ->where('customer_id', $customerId)
            ->where('batch_id', $batchId)
            ->where('status', KnowledgeMetadataTermSuggestion::STATUS_PENDING)
            ->exists();

        if ($hasPendingSuggestions) {
            return null;
        }

        Log::info('[PROCYNIA][KNOWLEDGE_VOCABULARY] Vocabulary batch completed after review.', [
            'customer_id' => $customerId,
            'batch_id' => $batchId,
        ]);

        return $this->markCompleted($batch->id);
    }

    /**
     * Purpose: Load the selected documents for analysis within the batch customer scope.
     * Inputs: The analysis batch.
     * Returns: A collection of usable knowledge documents.
     * Side effects: None.
     */
    private function loadAnalysisDocuments(KnowledgeVocabularyAnalysisBatch $batch): Collection
    {
        $documentIds = $this->normalizeDocumentIds($batch->source_document_ids ?? []);

        if ($documentIds === []) {
            return collect();
        }

        return KnowledgeItem::query()
            ->where('customer_id', $batch->customer_id)
            ->where('ownership_type', KnowledgeItem::OWNERSHIP_TYPE_COMPANY)
            ->whereIn('id', $documentIds)
            ->whereNotNull('storage_path')
            ->with([
                'chunks' => static fn ($query) => $query->orderBy('chunk_index'),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function (KnowledgeItem $document) use ($batch): bool {
                $content = trim((string) ($document->extracted_text ?: $document->content));

                if ($content !== '') {
                    return true;
                }

                Log::warning('[PROCYNIA][KNOWLEDGE_VOCABULARY] Skipping document without usable text for analysis.', [
                    'customer_id' => (int) $batch->customer_id,
                    'batch_id' => $batch->id,
                    'knowledge_item_id' => $document->id,
                    'file_name' => $document->original_filename,
                ]);

                return false;
            })
            ->values();
    }

    /**
     * Purpose: Normalize a list of source document ids.
     * Inputs: Raw ids from the request or persisted batch row.
     * Returns: A unique list of positive integers.
     * Side effects: None.
     */
    private function normalizeDocumentIds(mixed $documentIds): array
    {
        if (! is_array($documentIds)) {
            return [];
        }

        return collect($documentIds)
            ->map(static fn (mixed $documentId): int => (int) $documentId)
            ->filter(static fn (int $documentId): bool => $documentId > 0)
            ->unique()
            ->values()
            ->all();
    }
}
