<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 64);
            $table->string('operation_key', 128);
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->foreignId('enterprise_wiki_ingest_run_id')->nullable()->constrained('enterprise_wiki_ingest_runs')->nullOnDelete();
            $table->string('job_id', 128)->nullable();
            $table->string('request_correlation_id', 128)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('deployment_name', 128)->nullable();
            $table->string('provider_region', 64)->nullable();
            $table->string('endpoint', 32);
            $table->string('model', 128);
            $table->string('status', 20);
            $table->string('failure_type', 64)->nullable();
            $table->string('provider_request_id', 128)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'started_at']);
            $table->index(['provider', 'model', 'started_at']);
            $table->index(['enterprise_wiki_ingest_run_id', 'started_at']);
            $table->index(['operation_key', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_attempts');
    }
};
