<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_extraction_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('saved_notice_id')
                ->constrained('saved_notices')
                ->cascadeOnDelete();
            $table->foreignId('saved_notice_ai_document_id')
                ->constrained('saved_notice_ai_documents')
                ->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('strategy', 32);
            $table->string('prompt_version', 64);
            $table->string('model', 255);
            $table->string('error_type', 64)->nullable();
            $table->longText('error_message')->nullable();
            $table->unsignedInteger('candidate_count')->nullable()->default(0);
            $table->unsignedInteger('persisted_requirement_count')->nullable()->default(0);
            $table->unsignedInteger('openai_call_count')->nullable()->default(0);
            $table->unsignedInteger('input_tokens_total')->nullable()->default(0);
            $table->unsignedInteger('output_tokens_total')->nullable()->default(0);
            $table->unsignedInteger('total_tokens_total')->nullable()->default(0);
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['saved_notice_ai_document_id', 'status'], 'requirement_extraction_runs_document_status_index');
            $table->index(['saved_notice_id', 'created_at'], 'requirement_extraction_runs_notice_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_extraction_runs');
    }
};
