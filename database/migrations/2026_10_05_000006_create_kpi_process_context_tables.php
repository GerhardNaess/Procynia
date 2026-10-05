<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KPI → måler → Kvalitet-prosess / -aktivitet.
 *
 * The same shape as risk_processes / risk_activities: a KPI can measure a whole process
 * (kpi_processes) and/or concrete activities in processes (kpi_activities), any number of each. An
 * activity link carries its process, so it never needs a process link beside it. Nothing about the
 * process or the activity is copied — title, step label and role are read live from Kvalitet
 * (QualityProcessContextReader). That the process is a `process` item and the key a step in its
 * flow is checked by KpiQualityContextService before a row is written.
 *
 * Tenant boundaries are held by the database too: (kpi_id, customer_id) and
 * (quality_process_id, customer_id) reference the KPI's and the process's own pairs, so a link
 * between two customers cannot be written by any path. quality_items gets the (id, customer_id)
 * key that makes that reference possible.
 *
 * THE LINK BELONGS TO THE KPI. Kvalitet never reads these tables: no KPIs on a process, an
 * activity, in counts, search or Oversikt. Deleting the process therefore cascades rather than
 * restricts — a refusal would be a statement about KPIs the person may not see. Deleting the KPI
 * takes its links along.
 *
 * Activity keys are slugs of step labels. A link whose key leaves the working flow is removed by
 * QualityActivityLinkCleanup — the same hook that prunes risk_activities — so a later step with the
 * same label never inherits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_items', function (Blueprint $table): void {
            $table->unique(['id', 'customer_id']);
        });

        Schema::create('kpi_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('kpi_id');
            $table->unsignedBigInteger('quality_process_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['kpi_id', 'customer_id'])->references(['id', 'customer_id'])->on('kpis')->cascadeOnDelete();
            $table->foreign(['quality_process_id', 'customer_id'])->references(['id', 'customer_id'])->on('quality_items')->cascadeOnDelete();
            $table->unique(['kpi_id', 'quality_process_id']);
            $table->index('quality_process_id');
            $table->index('customer_id');
        });

        Schema::create('kpi_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('kpi_id');
            // The process whose flow the activity is a node in.
            $table->unsignedBigInteger('quality_process_id');
            // Matches the 80-character ceiling the blueprint rules validate node keys on.
            $table->string('activity_key', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['kpi_id', 'customer_id'])->references(['id', 'customer_id'])->on('kpis')->cascadeOnDelete();
            $table->foreign(['quality_process_id', 'customer_id'])->references(['id', 'customer_id'])->on('quality_items')->cascadeOnDelete();
            $table->unique(['kpi_id', 'quality_process_id', 'activity_key']);
            $table->index(['quality_process_id', 'activity_key']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_activities');
        Schema::dropIfExists('kpi_processes');

        Schema::table('quality_items', function (Blueprint $table): void {
            $table->dropUnique(['id', 'customer_id']);
        });
    }
};
