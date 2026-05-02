<?php

namespace App\Jobs;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemChunk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateKnowledgeChunkMetadataForDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const METADATA_BATCH_SIZE = 5;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int $knowledgeItemId,
    ) {
    }

    /**
     * Purpose: Split one knowledge document metadata run into smaller queue jobs that can run in parallel.
     * Inputs: The persisted knowledge document id from the upload flow.
     * Returns: None.
     * Side effects: Dispatches one metadata batch job per chunk id batch and writes orchestration logs.
     */
    public function handle(): void
    {
        $knowledgeDocument = KnowledgeItem::query()
            ->with(['chunks' => static fn ($query) => $query->orderBy('chunk_index')])
            ->find($this->knowledgeItemId);

        if (! $knowledgeDocument instanceof KnowledgeItem) {
            Log::warning('[PROCYNIA][KNOWLEDGE_METADATA] Metadata dispatcher skipped because the knowledge document was not found.', [
                'knowledge_item_id' => $this->knowledgeItemId,
            ]);

            return;
        }

        $chunkIds = [];

        foreach ($knowledgeDocument->chunks as $chunk) {
            if (! $chunk instanceof KnowledgeItemChunk) {
                continue;
            }

            $chunkIds[] = (int) $chunk->id;
        }

        $batchesDispatched = 0;

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Metadata dispatcher started.', [
            'knowledge_item_id' => $knowledgeDocument->id,
            'chunk_count' => count($chunkIds),
            'batch_size' => self::METADATA_BATCH_SIZE,
        ]);

        foreach (array_chunk($chunkIds, self::METADATA_BATCH_SIZE) as $chunkIdBatch) {
            if ($chunkIdBatch === []) {
                continue;
            }

            GenerateKnowledgeChunkMetadataBatch::dispatch((int) $knowledgeDocument->id, $chunkIdBatch);
            $batchesDispatched++;
        }

        Log::info('[PROCYNIA][KNOWLEDGE_METADATA] Metadata dispatcher completed.', [
            'knowledge_item_id' => $knowledgeDocument->id,
            'chunk_count' => count($chunkIds),
            'batch_size' => self::METADATA_BATCH_SIZE,
            'batches_dispatched' => $batchesDispatched,
        ]);
    }
}
