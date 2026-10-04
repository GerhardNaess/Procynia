<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko → hører hjemme i → Kvalitet-prosess / -aktivitet.
 *
 * Two small link tables rather than one generic relation: a risk can concern a whole process
 * (risk_processes), and/or one concrete activity in a process (risk_activities). Nothing about the
 * process or the activity is copied — title, step label and role are read live from Kvalitet. Which
 * process or activity may be linked (same customer, a `process` item, a step in its flow) is checked
 * by RiskQualityContextService before a row is written.
 *
 * THE LINK BELONGS TO THE RISK, exactly as risk_controls does. Kvalitet never reads these tables:
 * no risks on a process, an activity, in counts, search or Oversikt. Deleting the process therefore
 * cascades rather than restricts — a refusal would be a statement about hidden risks.
 *
 * The activity is named by its key in the flow payload, on the same terms as
 * quality_activity_controls: an activity is a node in the blueprint, not a row. Unlike those rows,
 * a risk link whose key leaves the working flow is removed (see RiskQualityContextService::prune),
 * because keys are slugs of labels and a later step with the same label would otherwise silently
 * inherit the risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['risk_id', 'quality_item_id']);
            $table->index('quality_item_id');
            $table->index('customer_id');
        });

        Schema::create('risk_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            // The process whose flow the activity is a node in.
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();
            // Matches the 80-character ceiling the blueprint rules validate node keys on.
            $table->string('activity_key', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['risk_id', 'quality_item_id', 'activity_key']);
            $table->index(['quality_item_id', 'activity_key']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_activities');
        Schema::dropIfExists('risk_processes');
    }
};
