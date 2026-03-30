<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purpose:
     * Create isolated run tracking tables for asynchronous Doffin supplier harvesting.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Creates doffin_supplier_harvest_runs and doffin_supplier_harvest_run_notices.
     */
    public function up(): void
    {
        Schema::create('doffin_supplier_harvest_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status')->default('queued');
            $table->date('source_from_date')->nullable();
            $table->date('source_to_date')->nullable();
            $table->json('notice_type_filters')->nullable();
            $table->string('batch_id')->nullable()->unique();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('harvested_suppliers')->default(0);
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

        Schema::create('doffin_supplier_harvest_run_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doffin_supplier_harvest_run_id')
                ->constrained('doffin_supplier_harvest_runs')
                ->cascadeOnDelete();
            $table->string('notice_id');
            $table->string('status')->default('queued');
            $table->unsignedInteger('supplier_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['doffin_supplier_harvest_run_id', 'notice_id'],
                'doffin_supplier_harvest_run_notice_unique_pair',
            );
            $table->index('status');
        });
    }

    /**
     * Purpose:
     * Drop the isolated Doffin supplier harvest run tables.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Removes doffin_supplier_harvest_run_notices and doffin_supplier_harvest_runs.
     */
    public function down(): void
    {
        Schema::dropIfExists('doffin_supplier_harvest_run_notices');
        Schema::dropIfExists('doffin_supplier_harvest_runs');
    }
};
