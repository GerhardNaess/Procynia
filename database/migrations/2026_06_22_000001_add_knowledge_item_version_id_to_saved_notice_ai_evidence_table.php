<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notice_ai_evidence', function (Blueprint $table): void {
            $table->foreignId('knowledge_item_version_id')
                ->nullable()
                ->after('knowledge_item_chunk_id')
                ->constrained('knowledge_item_versions')
                ->nullOnDelete();

            $table->index('knowledge_item_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notice_ai_evidence', function (Blueprint $table): void {
            $table->dropForeign(['knowledge_item_version_id']);
            $table->dropIndex(['knowledge_item_version_id']);
            $table->dropColumn('knowledge_item_version_id');
        });
    }
};
