<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->foreignId('document_category_id')
                ->nullable()
                ->constrained('knowledge_document_categories')
                ->nullOnDelete();
            $table->foreignId('document_topic_id')
                ->nullable()
                ->constrained('knowledge_document_topics')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_topic_id');
            $table->dropConstrainedForeignId('document_category_id');
        });
    }
};
