<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avvik / forbedring → gjelder → Kvalitet-prosess / -aktivitet.
 *
 * The same shape as kpi_processes / kpi_activities: a case can concern a whole process and/or
 * concrete activities in processes. An activity link carries its process. Only ids are stored;
 * title, step label and role are read live from Kvalitet (QualityProcessContextReader).
 *
 * Tenant boundaries are held by the database: (improvement_case_id, customer_id) and
 * (quality_process_id, customer_id) reference the case's and the process's own pairs.
 *
 * THE LINK BELONGS TO THE CASE. Kvalitet never reads these tables, so deleting a process cascades
 * rather than restricts — a refusal would be a statement about cases the person may not see.
 * A link whose activity leaves the working flow is removed by QualityActivityLinkCleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_case_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('improvement_case_id');
            $table->unsignedBigInteger('quality_process_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['improvement_case_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_cases')->cascadeOnDelete();
            $table->foreign(['quality_process_id', 'customer_id'])->references(['id', 'customer_id'])->on('quality_items')->cascadeOnDelete();
            $table->unique(['improvement_case_id', 'quality_process_id']);
            $table->index('quality_process_id');
            $table->index('customer_id');
        });

        Schema::create('improvement_case_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('improvement_case_id');
            // The process whose flow the activity is a node in.
            $table->unsignedBigInteger('quality_process_id');
            // Matches the 80-character ceiling the blueprint rules validate node keys on.
            $table->string('activity_key', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['improvement_case_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_cases')->cascadeOnDelete();
            $table->foreign(['quality_process_id', 'customer_id'])->references(['id', 'customer_id'])->on('quality_items')->cascadeOnDelete();
            $table->unique(['improvement_case_id', 'quality_process_id', 'activity_key'], 'improvement_case_activities_unique');
            $table->index(['quality_process_id', 'activity_key']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_case_activities');
        Schema::dropIfExists('improvement_case_processes');
    }
};
