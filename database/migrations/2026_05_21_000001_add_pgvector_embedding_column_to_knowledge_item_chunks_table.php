<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::statement('ALTER TABLE knowledge_item_chunks ADD COLUMN IF NOT EXISTS embedding_vector_pgvector vector(1536)');

        DB::statement(
            'CREATE INDEX IF NOT EXISTS knowledge_item_chunks_embedding_vector_pgvector_ivfflat_idx '
            .'ON knowledge_item_chunks USING ivfflat (embedding_vector_pgvector vector_cosine_ops) '
            .'WITH (lists = 10)',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS knowledge_item_chunks_embedding_vector_pgvector_ivfflat_idx');

        if (Schema::hasTable('knowledge_item_chunks')) {
            DB::statement('ALTER TABLE knowledge_item_chunks DROP COLUMN IF EXISTS embedding_vector_pgvector');
        }
    }
};
