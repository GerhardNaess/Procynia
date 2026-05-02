<?php

namespace App\Jobs;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use App\Services\Ai\Knowledge\KnowledgeChunkMetadataGenerationService;
use App\Services\Ai\Knowledge\KnowledgeChunkVocabularyCandidateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateKnowledgeChunkMetadataBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;

    /**
     * @param array<int, int> $knowledgeItemChunkIds
     */
    public function __construct(
        public readonly int $knowledgeItemId,
        public readonly array $knowledgeItemChunkIds,
    ) {
    }

    /**
     * Purpose: Generate AI metadata for one batch of chunks belonging to a knowledge document.
     * Inputs: Metadata and vocabulary services resolved by the Laravel container.
     * Returns: None.
     * Side effects: Calls the AI metadata service once for the batch, updates chunk metadata fields, and creates vocabulary candidates.
     */
    public function handle(
        KnowledgeChunkMetadataGenerationService $metadataGenerationService,
        KnowledgeChunkVocabularyCandidateService $vocabularyCandidateService,
    ): void {
        $chunkIds = $this->normalizedChunkIds($this->knowledgeItemChunkIds);

        if ($chunkIds === []) {
            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Metadata batch job skipped because no valid chunk ids were provided.', [
                'knowledge_item_id' => $this->knowledgeItemId,
            ]);

            return;
        }

        $knowledgeDocument = KnowledgeItem::query()->find($this->knowledgeItemId);

        if (! $knowledgeDocument instanceof KnowledgeItem) {
            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Metadata batch job skipped because the knowledge document was not found.', [
                'knowledge_item_id' => $this->knowledgeItemId,
                'knowledge_item_chunk_ids' => $chunkIds,
            ]);

            return;
        }

        $chunks = KnowledgeItemChunk::query()
            ->where('knowledge_item_id', $knowledgeDocument->id)
            ->whereIn('id', $chunkIds)
            ->orderBy('chunk_index')
            ->get();

        $chunksForMetadata = [];
        $skippedCount = 0;

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Metadata batch job started.', [
            'knowledge_item_id' => $knowledgeDocument->id,
            'requested_chunk_count' => count($chunkIds),
            'loaded_chunk_count' => $chunks->count(),
            'knowledge_item_chunk_ids' => $chunkIds,
        ]);

        foreach ($chunks as $chunk) {
            if (! $chunk instanceof KnowledgeItemChunk) {
                continue;
            }

            $chunk->refresh();

            if ($this->chunkAlreadyHasGeneratedMetadata($chunk)) {
                $skippedCount++;

                Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Metadata batch job skipped chunk because metadata already exists.', [
                    'knowledge_item_id' => $knowledgeDocument->id,
                    'knowledge_item_chunk_id' => $chunk->id,
                    'chunk_index' => $chunk->chunk_index,
                    'metadata_status' => $chunk->metadata_status,
                ]);

                continue;
            }

            $chunksForMetadata[] = $chunk;
        }

        $generatedCount = $this->generateChunkMetadataBatch(
            $knowledgeDocument,
            $chunksForMetadata,
            $metadataGenerationService,
            $vocabularyCandidateService,
        );

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Metadata batch job completed.', [
            'knowledge_item_id' => $knowledgeDocument->id,
            'requested_chunk_count' => count($chunkIds),
            'generated_count' => $generatedCount,
            'skipped_count' => $skippedCount,
        ]);
    }

    /**
     * Purpose: Normalize queued chunk ids before loading the batch from the database.
     * Inputs: Raw chunk ids from the queued job payload.
     * Returns: Unique positive integer ids in their original order.
     * Side effects: None.
     *
     * @param array<int, mixed> $chunkIds
     * @return array<int, int>
     */
    private function normalizedChunkIds(array $chunkIds): array
    {
        $normalized = [];
        $seen = [];

        foreach ($chunkIds as $chunkId) {
            $id = (int) $chunkId;

            if ($id < 1 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = $id;
        }

        return $normalized;
    }

    /**
     * Purpose: Decide whether a chunk has already received generated metadata and can be skipped safely.
     * Inputs: One persisted knowledge chunk.
     * Returns: True only when final metadata fields already exist on the chunk.
     * Side effects: None.
     */
    private function chunkAlreadyHasGeneratedMetadata(KnowledgeItemChunk $chunk): bool
    {
        $metadataStatus = (string) $chunk->metadata_status;

        if ($metadataStatus !== KnowledgeItemChunk::METADATA_STATUS_AUTO_APPROVED) {
            return false;
        }

        return $this->cleanNullableString($chunk->ai_summary, 20000) !== null
            && $this->cleanNullableString($chunk->summary_for_retrieval, 20000) !== null
            && $this->cleanNullableString($chunk->topic, 191) !== null
            && $this->cleanNullableString($chunk->sub_topic, 191) !== null;
    }

    /**
     * Purpose: Generate and persist metadata for one batch of knowledge chunks.
     * Inputs: The parent knowledge document, a chunk batch, and the metadata/vocabulary services.
     * Returns: The number of chunks that were processed by the batch.
     * Side effects: Calls AI once for the batch, updates chunk metadata columns, writes logs, and syncs vocabulary candidates.
     *
     * @param array<int, KnowledgeItemChunk> $chunks
     */
    private function generateChunkMetadataBatch(
        KnowledgeItem $knowledgeDocument,
        array $chunks,
        KnowledgeChunkMetadataGenerationService $metadataGenerationService,
        KnowledgeChunkVocabularyCandidateService $vocabularyCandidateService,
    ): int {
        if ($chunks === []) {
            return 0;
        }

        $contentWordCounts = [];
        $chunkIds = [];
        $chunkIndexes = [];

        foreach ($chunks as $chunk) {
            $chunkContent = trim((string) $chunk->content);
            $contentWordCounts[(int) $chunk->id] = count(preg_split('/\s+/u', $chunkContent, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $chunkIds[] = (int) $chunk->id;
            $chunkIndexes[] = (int) $chunk->chunk_index;

            Log::info('[PROCYNIA][CHUNK_METADATA]', [
                'knowledge_item_id' => $knowledgeDocument->id,
                'chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'heading_path' => $chunk->heading_path,
                'content_word_count' => $contentWordCounts[(int) $chunk->id],
                'metadata_generation_started' => true,
                'background_job' => true,
                'batch_job' => true,
                'batch_size' => count($chunks),
            ]);
        }

        try {
            $metadataOutcomes = $metadataGenerationService->generateForChunks($knowledgeDocument, $chunks);
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Batch metadata generation failed in background job.', [
                'knowledge_item_id' => $knowledgeDocument->id,
                'knowledge_item_chunk_ids' => $chunkIds,
                'chunk_indexes' => $chunkIndexes,
                'error' => $throwable->getMessage(),
            ]);

            foreach ($chunks as $chunk) {
                $chunk->forceFill([
                    'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_FAILED,
                ])->save();
            }

            return 0;
        }

        $processedCount = 0;

        foreach ($chunks as $chunk) {
            $metadataOutcome = $metadataOutcomes[(int) $chunk->id] ?? null;

            if (! is_array($metadataOutcome)) {
                Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Batch metadata outcome missing in background job.', [
                    'knowledge_item_id' => $knowledgeDocument->id,
                    'knowledge_item_chunk_id' => $chunk->id,
                    'chunk_index' => $chunk->chunk_index,
                ]);

                $chunk->forceFill([
                    'metadata_status' => KnowledgeItemChunk::METADATA_STATUS_FAILED,
                ])->save();

                continue;
            }

            $this->persistGeneratedChunkMetadata(
                $knowledgeDocument,
                $chunk,
                $metadataOutcome,
                $vocabularyCandidateService,
                (int) ($contentWordCounts[(int) $chunk->id] ?? 0),
            );

            $processedCount++;
        }

        return $processedCount;
    }

    /**
     * Purpose: Persist generated metadata for one chunk after the batch AI response has been validated.
     * Inputs: The document, the chunk, the metadata outcome, the vocabulary service, and the content word count.
     * Returns: None.
     * Side effects: Updates the chunk metadata columns and syncs vocabulary candidates.
     */
    private function persistGeneratedChunkMetadata(
        KnowledgeItem $knowledgeDocument,
        KnowledgeItemChunk $chunk,
        array $metadataOutcome,
        KnowledgeChunkVocabularyCandidateService $vocabularyCandidateService,
        int $contentWordCount,
    ): void {
        $chunkKeywords = $this->normalizeExactKeywordList($chunk->keywords);
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
            'background_job' => true,
            'batch_job' => true,
        ]);

        $chunk->forceFill([
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
        ])->save();

        $chunk->refresh();

        Log::info('[PROCYNIA][KNOWLEDGE_CHUNKING] Chunk metadata generated in background batch job.', [
            'knowledge_item_id' => $knowledgeDocument->id,
            'knowledge_item_chunk_id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'has_topic' => trim((string) $chunk->topic) !== '',
            'has_sub_topic' => trim((string) $chunk->sub_topic) !== '',
            'keywords_count' => count($this->normalizeExactKeywordList($chunk->keywords) ?? []),
        ]);

        $vocabularyCandidateService->syncForChunk($knowledgeDocument, $chunk);
    }

    /**
     * Purpose: Normalize a nullable string value without changing its domain meaning.
     * Inputs: Any value and the maximum number of characters to keep.
     * Returns: A trimmed string or null when the value is empty.
     * Side effects: None.
     */
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
}
