<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->foreignId('knowledge_item_version_id')
                ->nullable()
                ->after('knowledge_item_id')
                ->constrained('knowledge_item_versions')
                ->nullOnDelete();

            $table->index('knowledge_item_version_id', 'kic_version_id_index');
            $table->index(['knowledge_item_id', 'knowledge_item_version_id'], 'kic_item_version_index');
        });

        // Backfill existing chunks to version 1 of their knowledge item.
        // Only updates chunks that do not yet have a version assigned.
        DB::statement("
            UPDATE knowledge_item_chunks kic
            SET knowledge_item_version_id = kiv.id
            FROM knowledge_item_versions kiv
            WHERE kiv.knowledge_item_id = kic.knowledge_item_id
              AND kiv.version_no = 1
              AND kic.knowledge_item_version_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('knowledge_item_chunks', function (Blueprint $table): void {
            $table->dropForeign(['knowledge_item_version_id']);
            $table->dropIndex('kic_version_id_index');
            $table->dropIndex('kic_item_version_index');
            $table->dropColumn('knowledge_item_version_id');
        });
    }
};
