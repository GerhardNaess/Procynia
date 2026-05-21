<?php

namespace App\Console\Commands;

use App\Models\KnowledgeItemChunk;
use App\Support\PgVector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('knowledge:backfill-pgvector-embeddings {--chunk-size=200 : Number of rows to process per batch.}')]
#[Description('Backfill pgvector embedding columns from the existing JSON embedding data.')]
class BackfillKnowledgeChunkEmbeddingVectors extends Command
{
    private const EXPECTED_DIMENSION = 1536;

    /**
     * Purpose: Backfill pgvector embeddings from existing JSON embedding vectors.
     * Inputs: The optional --chunk-size flag.
     * Returns: An Artisan exit code.
     * Side effects: Updates knowledge_item_chunks.embedding_vector_pgvector in batches.
     */
    public function handle(): int
    {
        if (! Schema::hasColumn('knowledge_item_chunks', 'embedding_vector_pgvector')) {
            $this->error('The knowledge_item_chunks.embedding_vector_pgvector column is missing. Run the migration first.');

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk-size'));
        $summary = [
            'processed' => 0,
            'updated' => 0,
            'skipped_missing_embedding' => 0,
            'skipped_invalid_dimensions' => 0,
            'skipped_already_backfilled' => 0,
        ];

        KnowledgeItemChunk::query()
            ->select([
                'id',
                'embedding_vector',
                'embedding_vector_pgvector',
            ])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($chunks) use (&$summary): void {
                foreach ($chunks as $chunk) {
                    $summary['processed']++;

                    if (! is_null($chunk->embedding_vector_pgvector)) {
                        $summary['skipped_already_backfilled']++;

                        continue;
                    }

                    if (! is_array($chunk->embedding_vector) || $chunk->embedding_vector === []) {
                        $summary['skipped_missing_embedding']++;

                        continue;
                    }

                    $embedding = array_values($chunk->embedding_vector);

                    if (count($embedding) !== self::EXPECTED_DIMENSION) {
                        $summary['skipped_invalid_dimensions']++;

                        continue;
                    }

                    DB::table('knowledge_item_chunks')
                        ->where('id', $chunk->id)
                        ->update([
                            'embedding_vector_pgvector' => PgVector::literal($embedding),
                        ]);

                    $summary['updated']++;
                }
            });

        $message = sprintf(
            '[PROCYNIA][EMBEDDINGS][PGVECTOR_BACKFILL] processed=%d updated=%d skipped_missing_embedding=%d skipped_invalid_dimensions=%d skipped_already_backfilled=%d',
            $summary['processed'],
            $summary['updated'],
            $summary['skipped_missing_embedding'],
            $summary['skipped_invalid_dimensions'],
            $summary['skipped_already_backfilled'],
        );

        $this->info($message);

        return self::SUCCESS;
    }
}
