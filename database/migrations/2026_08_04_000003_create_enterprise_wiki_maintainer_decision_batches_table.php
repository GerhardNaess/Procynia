<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_maintainer_decision_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_wiki_ingest_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('batch_number');
            $table->unsignedInteger('total_batches');
            $table->json('input_payload');
            $table->string('status')->default('pending');
            $table->string('lease_token')->nullable();
            $table->timestamp('leased_at')->nullable();
            $table->json('result_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['enterprise_wiki_ingest_run_id', 'batch_number'], 'ewmdb_run_batch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_maintainer_decision_batches');
    }
};
