<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_qa_regressions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('enterprise_wiki_qa_snapshot_id')
                ->constrained('enterprise_wiki_qa_snapshots')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('baseline_enterprise_wiki_qa_snapshot_id')->nullable();
            $table->foreignId('enterprise_wiki_ingest_run_id')
                ->constrained('enterprise_wiki_ingest_runs')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            $table->string('source_hash', 64)->nullable();
            $table->string('page_type_signature', 255);

            $table->json('comparison_context');
            $table->json('current_metrics');
            $table->json('baseline_metrics')->nullable();
            $table->json('metric_deltas')->nullable();
            $table->json('thresholds');
            $table->json('regression_signals')->nullable();

            $table->string('regression_classification', 50);
            $table->string('maintenance_action', 50);
            $table->string('analysis_status', 50);
            $table->timestamp('analysis_started_at')->nullable();
            $table->timestamp('analysis_completed_at')->nullable();
            $table->timestamp('repair_attempted_at')->nullable();
            $table->json('repair_result')->nullable();
            $table->string('final_status', 50)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique('enterprise_wiki_qa_snapshot_id');
            $table->index(['customer_id', 'analysis_completed_at']);
            $table->index(['customer_id', 'source_type', 'source_id']);
            $table->index(['customer_id', 'regression_classification']);
            $table->index(['customer_id', 'analysis_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_qa_regressions');
    }
};
