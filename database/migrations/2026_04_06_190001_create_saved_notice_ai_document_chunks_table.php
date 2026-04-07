<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_ai_document_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_ai_document_id')
                ->constrained('saved_notice_ai_documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('char_start');
            $table->unsignedInteger('char_end');
            $table->unsignedInteger('word_count')->default(0);
            $table->timestamps();

            $table->unique(['saved_notice_ai_document_id', 'chunk_index'], 'saved_notice_ai_document_chunks_unique_index');
            $table->index(['saved_notice_ai_document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_document_chunks');
    }
};
