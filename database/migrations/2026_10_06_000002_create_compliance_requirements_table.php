<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etterlevelse og revisjon — krav: one requirement the virksomhet must meet, from one kravkilde.
 *
 * Deliberately no compliance status, no current assessment and no next review date: those will be
 * derived from assessments, never stored here. review_interval_months is only how often the
 * requirement should be reassessed (none, 1, 3, 6 or 12).
 *
 * status is the lifecycle — active or retired (Utgått) — and never a form field;
 * ComplianceRequirementLifecycleService is the only writer, and every change is in
 * compliance_requirement_status_changes.
 *
 * The source is referenced by (source_id, customer_id), so a requirement can never point at another
 * customer's source. That key is NO ACTION rather than RESTRICT on purpose: a source in use cannot
 * be deleted, but a customer going takes its sources and requirements with it in one statement,
 * and NO ACTION is checked once that statement has finished.
 *
 * reference (e.g. «A.5.1») is optional, and unique within its source, ignoring case, when given.
 * owner_user_id is required by the forms but nulled when that user is deleted («Mangler ansvarlig»).
 *
 * (id, customer_id) is unique so that history rows, and later assessments and audit links,
 * reference the pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');
            $table->string('reference', 100)->nullable();
            $table->string('title');
            $table->text('requirement_text');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('review_interval_months')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['source_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_sources');
            $table->unique(['id', 'customer_id']);
            $table->index(['customer_id', 'status']);
            $table->index('source_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE compliance_requirements ADD CONSTRAINT compliance_requirements_status_check CHECK (status IN ('active', 'retired'))");
            DB::statement('ALTER TABLE compliance_requirements ADD CONSTRAINT compliance_requirements_review_interval_check CHECK (review_interval_months IS NULL OR review_interval_months IN (1, 3, 6, 12))');
            DB::statement('ALTER TABLE compliance_requirements ADD CONSTRAINT compliance_requirements_reference_check CHECK (reference IS NULL OR length(btrim(reference)) > 0)');
            DB::statement('ALTER TABLE compliance_requirements ADD CONSTRAINT compliance_requirements_text_check CHECK (length(btrim(title)) > 0 AND length(btrim(requirement_text)) > 0)');
            DB::statement('CREATE UNIQUE INDEX compliance_requirements_source_reference_unique ON compliance_requirements (source_id, lower(reference)) WHERE reference IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_requirements');
    }
};
