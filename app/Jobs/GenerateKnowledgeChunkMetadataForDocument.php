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

class GenerateKnowledgeChunkMetadataForDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;

    public function __construct(
        public readonly int $knowledgeItemId,
    ) {
    }

    /**
     * Purpose: Generate AI metadata for all chunks belonging to one knowledge document after upload has completed.
     * Inputs: Metadata and vocabulary services resolved by the Laravel container.
     * Returns: None.
     * Side effects: Calls the AI metadata service, updates chunk metadata fields, and creates vocabulary candidates.
     */
    public function handle(
        KnowledgeChunkMetadataGenerationService $metadataGenerationService,
        KnowledgeChunkVocabularyCandidateService $vocabularyCandidateService,
    ): void {
        $knowledgeDocument = KnowledgeItem::query()
            ->with(['chunks' => static fn ($query) => $query->orderBy('chunk_index')])
            ->find($this->knowledgeItemId);

        if (! $knowledgeDocument instanceof KnowledgeItem) {
            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Metadata job skipped because the knowledge document was not found.', [
                'knowledge_item_id' => $this->knowledgeItemId,
            ]);

            return;
        }

        foreach ($knowledgeDocument->chunks as $chunk) {
            if (! $chunk instanceof KnowledgeItemChunk) {
                continue;
            }

            $chunk->refresh();

            if ($this->chunkAlreadyHasGeneratedMetadata($chunk)) {
                continue;
            }

            $this->generateChunkMetadata($knowledgeDocument, $chunk, $metadataGenerationService, $vocabularyCandidateService);
        }
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

        if (! in_array($metadataStatus, ['auto_approved', 'pending_review'], true)) {
            return false;
        }

        return $this->cleanNullableString($chunk->ai_summary, 20000) !== null
            || $this->cleanNullableString($chunk->summary_for_retrieval, 20000) !== null
            || $this->cleanNullableString($chunk->topic, 191) !== null
            || $this->cleanNullableString($chunk->sub_topic, 191) !== null;
    }

    /**
     * Purpose: Generate and persist metadata for one knowledge chunk.
     * Inputs: The parent knowledge document, one chunk, and the metadata/vocabulary services.
     * Returns: None.
     * Side effects: Calls AI, updates chunk metadata columns, writes logs, and syncs vocabulary candidates.
     */
    private function generateChunkMetadata(
        KnowledgeItem $knowledgeDocument,
        KnowledgeItemChunk $chunk,
        KnowledgeChunkMetadataGenerationService $metadataGenerationService,
        KnowledgeChunkVocabularyCandidateService $vocabularyCandidateService,
    ): void {
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
            'background_job' => true,
        ]);

        try {
            $metadataOutcome = $metadataGenerationService->generateForChunk($knowledgeDocument, $chunk);
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Metadata generation failed in background job.', [
                'knowledge_item_id' => $knowledgeDocument->id,
                'knowledge_item_chunk_id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'error' => $throwable->getMessage(),
            ]);

            $chunk->forceFill([
                'metadata_status' => 'failed',
            ])->save();

            return;
        }

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

        Log::info('[PROCYNIA][KNOWLEDGE_CHUNKING] Chunk metadata generated in background job.', [
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
