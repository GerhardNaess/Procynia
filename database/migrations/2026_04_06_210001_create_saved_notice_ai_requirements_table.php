<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_notice_ai_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')
                ->constrained('saved_notices')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_ai_document_id')
                ->constrained('saved_notice_ai_documents')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_ai_document_chunk_id')
                ->constrained('saved_notice_ai_document_chunks')
                ->cascadeOnDelete();
            $table->longText('requirement_text');
            $table->string('requirement_type', 32);
            $table->string('extraction_method', 32);
            $table->string('review_status', 32);
            $table->timestamps();

            $table->index(['saved_notice_id', 'saved_notice_ai_document_id'], 'saved_notice_ai_requirements_notice_document_index');
            $table->index(['saved_notice_ai_document_id', 'saved_notice_ai_document_chunk_id'], 'saved_notice_ai_requirements_document_chunk_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_ai_requirements');
    }
};
