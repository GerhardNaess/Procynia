<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('enterprise_wiki_page_id')->nullable()->constrained('enterprise_wiki_pages')->nullOnDelete();
            $table->string('trigger_type')->default('manual');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('source_hash', 64)->nullable();
            $table->string('status')->default('queued');
            $table->string('model_used')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_estimate_nok', 10, 4)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('status');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_ingest_runs');
    }
};
