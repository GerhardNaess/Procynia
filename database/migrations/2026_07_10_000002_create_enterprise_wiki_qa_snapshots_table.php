<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_qa_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('enterprise_wiki_ingest_run_id')
                ->constrained('enterprise_wiki_ingest_runs')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('customer_id');

            $table->string('qa_status', 50);
            $table->unsignedInteger('qa_attempt_count');
            $table->timestamp('snapshotted_at');

            // Technical + structural QA
            $table->boolean('technical_qa_passed')->default(false);
            $table->boolean('structural_qa_passed')->default(false);
            $table->boolean('open_lint_errors')->default(false);
            $table->unsignedSmallInteger('lint_error_count')->default(0);
            $table->unsignedSmallInteger('lint_warning_count')->default(0);

            // Semantic QA (8G-4)
            $table->boolean('semantic_qa_ran')->default(false);
            $table->boolean('semantic_pass')->nullable();
            $table->float('semantic_quality_score')->nullable();
            $table->float('semantic_coverage_score')->nullable();
            $table->float('semantic_factual_score')->nullable();
            $table->unsignedSmallInteger('semantic_missing_topics_count')->default(0);
            $table->unsignedSmallInteger('semantic_missing_key_facts_count')->default(0);
            $table->unsignedSmallInteger('semantic_unsupported_claims_count')->default(0);
            $table->string('semantic_source_hash', 64)->nullable();
            $table->unsignedBigInteger('semantic_page_version_id')->nullable();
            $table->string('semantic_model', 100)->nullable();
            $table->string('semantic_prompt_version', 20)->nullable();

            // Semantic repair (8G-5)
            $table->boolean('semantic_repair_attempted')->default(false);
            $table->boolean('semantic_repair_success')->nullable();
            $table->unsignedBigInteger('semantic_repair_previous_version_id')->nullable();
            $table->unsignedBigInteger('semantic_repair_new_version_id')->nullable();
            $table->string('semantic_repair_model', 100)->nullable();

            // Post-repair QA
            $table->boolean('semantic_post_repair_pass')->nullable();
            $table->float('semantic_post_repair_quality_score')->nullable();
            $table->float('semantic_post_repair_coverage_score')->nullable();
            $table->float('semantic_post_repair_factual_score')->nullable();

            $table->timestamps();

            // Idempotence: one snapshot per QA attempt
            $table->unique(['enterprise_wiki_ingest_run_id', 'qa_attempt_count']);

            // Trend queries
            $table->index(['customer_id', 'snapshotted_at']);
            $table->index(['customer_id', 'qa_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_qa_snapshots');
    }
};
