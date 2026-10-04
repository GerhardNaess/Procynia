<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko — risk assessments as history, not as fields on the risk.
 *
 * Every assessment is its own row and is never changed afterwards. A wrong assessment is corrected
 * by registering a new one, so the record of who judged what, when and why stays intact. That is
 * why likelihood and consequence are not columns on risks.
 *
 * ACCESS IS THE RISK'S.
 *
 * An assessment has no tilgangsområde of its own. It is reached only through its risk, and so only
 * by someone RiskAccessService lets see that risk. customer_id is denormalised for the same reason
 * as elsewhere in Risiko: a tenant guard can read it without a join, and a mismatch is visible as
 * bad data.
 *
 * WHAT IS NOT STORED.
 *
 * Score and level (Lav, Moderat, Høy, Svært høy) follow deterministically from likelihood and
 * consequence, so they are computed by RiskScoringPolicy rather than stored as a second truth.
 * criteria_key records which criteria the values were given under, so that customer-specific
 * criteria can arrive later without re-reading old assessments through new rules.
 *
 * Residual risk is optional, but never half-given: both values or neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // The history belongs to the risk; deleting the risk (its own permission) takes it along.
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');
            $table->text('rationale');
            $table->string('criteria_key');
            $table->unsignedSmallInteger('inherent_likelihood');
            $table->unsignedSmallInteger('inherent_consequence');
            $table->unsignedSmallInteger('residual_likelihood')->nullable();
            $table->unsignedSmallInteger('residual_consequence')->nullable();

            $table->index(['risk_id', 'assessed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_inherent_range CHECK (inherent_likelihood BETWEEN 1 AND 5 AND inherent_consequence BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_residual_range CHECK ((residual_likelihood IS NULL AND residual_consequence IS NULL) OR (residual_likelihood BETWEEN 1 AND 5 AND residual_consequence BETWEEN 1 AND 5))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
