<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_token_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_key', 64);
            $table->string('model', 128);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->foreignId('saved_notice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('saved_notice_ai_document_id')->nullable()->constrained('saved_notice_ai_documents')->nullOnDelete();
            $table->foreignId('requirement_extraction_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('knowledge_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['operation_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_token_events');
    }
};
