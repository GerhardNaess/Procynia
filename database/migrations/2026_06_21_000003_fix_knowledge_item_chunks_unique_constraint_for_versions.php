<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The original (knowledge_item_id, chunk_index) unique constraint predates versioning.
// With multi-version support, the same chunk_index can appear in different versions of
// the same document. Drop the old constraint and replace it with (knowledge_item_version_id,
// chunk_index) so each version can independently number its chunks from zero.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->dropUnique('knowledge_item_chunks_knowledge_item_id_chunk_index_unique');
            $table->unique(['knowledge_item_version_id', 'chunk_index'], 'kic_version_chunk_index_unique');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->dropUnique('kic_version_chunk_index_unique');
            $table->unique(['knowledge_item_id', 'chunk_index'], 'knowledge_item_chunks_knowledge_item_id_chunk_index_unique');
        });
    }
};
