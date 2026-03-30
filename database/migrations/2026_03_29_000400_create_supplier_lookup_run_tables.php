<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purpose:
     * Create supplier lookup run tables for queued background processing.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Creates supplier_lookup_runs and supplier_lookup_run_notices tables.
     */
    public function up(): void
    {
        Schema::create('supplier_lookup_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status')->default('queued');
            $table->date('source_from_date')->nullable();
            $table->date('source_to_date')->nullable();
            $table->string('supplier_query')->nullable();
            $table->json('notice_type_filters')->nullable();
            $table->string('resolved_winner_id')->nullable();
            $table->string('resolved_winner_label')->nullable();
            $table->string('batch_id')->nullable()->unique();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('matched_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->decimal('progress_percent', 5, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('estimated_seconds_remaining')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
            $table->index('finished_at');
        });

        Schema::create('supplier_lookup_run_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_lookup_run_id')->constrained('supplier_lookup_runs')->cascadeOnDelete();
            $table->string('notice_id');
            $table->string('status')->default('queued');
            $table->boolean('matched')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_lookup_run_id', 'notice_id'], 'supplier_lookup_run_notice_unique_pair');
            $table->index('status');
        });
    }

    /**
     * Purpose:
     * Drop supplier lookup run tables.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Removes supplier_lookup_run_notices and supplier_lookup_runs tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_lookup_run_notices');
        Schema::dropIfExists('supplier_lookup_runs');
    }
};
