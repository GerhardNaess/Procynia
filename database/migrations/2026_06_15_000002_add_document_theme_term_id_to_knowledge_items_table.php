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
            $table->foreignId('document_theme_term_id')
                ->nullable()
                ->constrained('knowledge_metadata_terms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_items')) {
            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_theme_term_id');
        });
    }
};
