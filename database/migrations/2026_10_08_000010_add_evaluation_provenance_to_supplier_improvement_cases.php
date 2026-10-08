<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Følg opp i Avvik og forbedringer» from a control (docs/supplier-assurance-v2-plan.md §7.1, §20
 * «Endringer i v1-tabeller», §23 phase 6): which control of a requirement a case was created from.
 * Nothing about the case is copied — it stays Avvik og forbedringer's.
 *
 *  - supplier_requirement_evaluation_id, nullable; existing rows stay null (no backfill, §17).
 *  - (supplier_requirement_evaluation_id, supplier_id) → supplier_requirement_evaluations(id,
 *    supplier_id), NO ACTION: always one of this supplier's controls. Controls are never deleted.
 *  - At most one provenance: an assessment or a control, never both. (Phase 7 adds the
 *    aktsomhetsvurdering as the third.)
 *  - Only a hand-off carries a provenance: the handoff_only check now also covers the control.
 *
 * down() drops the column and restores v1's checks; a case created from a control then reads as
 * created from the supplier, which it also was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_improvement_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_requirement_evaluation_id')->nullable()->after('supplier_assessment_id');

            $table->foreign(['supplier_requirement_evaluation_id', 'supplier_id'], 'supplier_improvement_cases_evaluation_fk')
                ->references(['id', 'supplier_id'])->on('supplier_requirement_evaluations');
            $table->index('supplier_requirement_evaluation_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_improvement_cases DROP CONSTRAINT supplier_improvement_cases_handoff_only');
        DB::statement("ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_handoff_only CHECK (origin = 'handoff' OR (supplier_assessment_id IS NULL AND supplier_requirement_evaluation_id IS NULL AND handoff_key IS NULL))");
        DB::statement('ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_one_provenance CHECK (num_nonnulls(supplier_assessment_id, supplier_requirement_evaluation_id) <= 1)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_improvement_cases DROP CONSTRAINT IF EXISTS supplier_improvement_cases_one_provenance');
            DB::statement('ALTER TABLE supplier_improvement_cases DROP CONSTRAINT IF EXISTS supplier_improvement_cases_handoff_only');
        }

        Schema::table('supplier_improvement_cases', function (Blueprint $table): void {
            $table->dropForeign('supplier_improvement_cases_evaluation_fk');
            $table->dropIndex(['supplier_requirement_evaluation_id']);
            $table->dropColumn('supplier_requirement_evaluation_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_improvement_cases ADD CONSTRAINT supplier_improvement_cases_handoff_only CHECK (origin = 'handoff' OR (supplier_assessment_id IS NULL AND handoff_key IS NULL))");
        }
    }
};
