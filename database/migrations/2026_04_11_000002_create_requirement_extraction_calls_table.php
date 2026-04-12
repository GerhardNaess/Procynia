<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_extraction_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requirement_extraction_run_id')
                ->constrained('requirement_extraction_runs')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_id')
                ->constrained('saved_notices')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_ai_document_id')
                ->constrained('saved_notice_ai_documents')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_ai_document_chunk_id')
                ->nullable()
                ->constrained('saved_notice_ai_document_chunks')
                ->nullOnDelete();
            $table->string('status', 32);
            $table->string('strategy', 32);
            $table->string('prompt_version', 64)->nullable();
            $table->string('model', 255)->nullable();
            $table->string('request_id', 255)->nullable();
            $table->string('response_id', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->string('error_type', 64)->nullable();
            $table->longText('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index('requirement_extraction_run_id', 'requirement_extraction_calls_run_index');
            $table->index('request_id', 'requirement_extraction_calls_request_id_index');
            $table->index('response_id', 'requirement_extraction_calls_response_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_extraction_calls');
    }
};
