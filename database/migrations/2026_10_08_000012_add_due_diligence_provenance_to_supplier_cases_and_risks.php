<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Følg opp i Avvik og forbedringer» and «Opprett risiko» from an aktsomhetsvurdering
 * (docs/supplier-assurance-v2-plan.md §11.2, §20 «Endringer i v1-tabeller», §23 phase 7): which
 * assessment a case or a risk was created from. Nothing about the case or the risk is copied — they
 * stay their own module's.
 *
 *  - supplier_due_diligence_assessment_id on both, nullable; existing rows stay null (no backfill, §17).
 *  - (supplier_due_diligence_assessment_id, supplier_id) → supplier_due_diligence_assessments(id,
 *    supplier_id), NO ACTION: always one of this supplier's assessments, which are never deleted.
 *  - supplier_improvement_cases: at most one provenance of the three (leverandørvurdering, control,
 *    aktsomhetsvurdering), and only a hand-off carries one.
 *  - supplier_risks: only a risk created from the supplier carries one, never a linked risk.
 *
 * down() drops the columns and restores phase 6's checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_improvement_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_due_diligence_assessment_id')->nullable()->after('supplier_requirement_evaluation_id');

            $table->foreign(['supplier_due_diligence_assessment_id', 'supplier_id'], 'supplier_improvement_cases_due_diligence_fk')
                ->references(['id', 'supplier_id'])->on('supplier_due_diligence_assessments');
            $table->index('supplier_due_diligence_assessment_id');
        });

        Schema::table('supplier_risks', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_due_diligence_assessment_id')->nullable()->after('risk_id');

            $table->foreign(['supplier_due_diligence_assessment_id', 'supplier_id'], 'supplier_risks_due_diligence_fk')
                ->references(['id', 'supplier_id'])->on('supplier_due_diligence_assessments');
            $table->index('supplier_due_diligence_assessment_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_improvement_cases DROP CONSTRAINT supplier_improvement_cases_handoff_only');
        DB::statement('ALTER TABLE supplier_improvement_cases DROP CONSTRAINT supplier_improvement_cases_one_provenance');
        DB::statement("ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_handoff_only CHECK (origin = 'handoff' OR (supplier_assessment_id IS NULL AND supplier_requirement_evaluation_id IS NULL AND supplier_due_diligence_assessment_id IS NULL AND handoff_key IS NULL))");
        DB::statement('ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_one_provenance CHECK (num_nonnulls(supplier_assessment_id, supplier_requirement_evaluation_id, supplier_due_diligence_assessment_id) <= 1)');
        DB::statement("ALTER TABLE supplier_risks ADD CONSTRAINT supplier_risks_due_diligence_created_only CHECK (supplier_due_diligence_assessment_id IS NULL OR origin = 'created_from_supplier')");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_risks DROP CONSTRAINT IF EXISTS supplier_risks_due_diligence_created_only');
            DB::statement('ALTER TABLE supplier_improvement_cases DROP CONSTRAINT IF EXISTS supplier_improvement_cases_one_provenance');
            DB::statement('ALTER TABLE supplier_improvement_cases DROP CONSTRAINT IF EXISTS supplier_improvement_cases_handoff_only');
        }

        Schema::table('supplier_risks', function (Blueprint $table): void {
            $table->dropForeign('supplier_risks_due_diligence_fk');
            $table->dropIndex(['supplier_due_diligence_assessment_id']);
            $table->dropColumn('supplier_due_diligence_assessment_id');
        });

        Schema::table('supplier_improvement_cases', function (Blueprint $table): void {
            $table->dropForeign('supplier_improvement_cases_due_diligence_fk');
            $table->dropIndex(['supplier_due_diligence_assessment_id']);
            $table->dropColumn('supplier_due_diligence_assessment_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_handoff_only CHECK (origin = 'handoff' OR (supplier_assessment_id IS NULL AND supplier_requirement_evaluation_id IS NULL AND handoff_key IS NULL))");
            DB::statement('ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_one_provenance CHECK (num_nonnulls(supplier_assessment_id, supplier_requirement_evaluation_id) <= 1)');
        }
    }
};
