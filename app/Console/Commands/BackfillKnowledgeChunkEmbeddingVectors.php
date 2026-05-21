<?php

namespace App\Console\Commands;

use App\Models\KnowledgeItemChunk;
use App\Support\PgVector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('embeddings:backfill-vectors {--chunk-size=200 : Number of rows to process per batch.}')]
#[Description('Backfill pgvector embedding columns from the existing JSON embedding data.')]
class BackfillKnowledgeChunkEmbeddingVectors extends Command
{
    private const EXPECTED_DIMENSION = 1536;

    public function handle(): int
    {
        if (! Schema::hasColumn('knowledge_item_chunks', 'embedding_vector_pgvector')) {
            $this->error('The knowledge_item_chunks.embedding_vector_pgvector column is missing. Run the migration first.');

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk-size'));

        $summary = [
            'found' => 0,
            'updated' => 0,
            'skipped' => 0,
            'wrong_dimension' => 0,
        ];

        KnowledgeItemChunk::query()
            ->whereNotNull('embedding_vector')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($chunks) use (&$summary): void {
                foreach ($chunks as $chunk) {
                    $summary['found']++;

                    $embedding = is_array($chunk->embedding_vector) ? array_values($chunk->embedding_vector) : null;

                    if (! is_array($embedding) || $embedding === []) {
                        $summary['skipped']++;

                        continue;
                    }

                    if (count($embedding) !== self::EXPECTED_DIMENSION) {
                        $summary['wrong_dimension']++;

                        continue;
                    }

                    if (is_array($chunk->embedding_vector_pgvector) && $chunk->embedding_vector_pgvector === $embedding) {
                        $summary['skipped']++;

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
            '[PROCYNIA][EMBEDDINGS][BACKFILL_VECTORS] found=%d updated=%d skipped=%d wrong_dimension=%d',
            $summary['found'],
            $summary['updated'],
            $summary['skipped'],
            $summary['wrong_dimension'],
        );

        $this->info($message);

        return self::SUCCESS;
    }
}
