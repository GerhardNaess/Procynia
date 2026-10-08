<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontroller — a person's conclusion about one control requirement for one supplier
 * (docs/supplier-assurance-v2-plan.md §8, §20.2): Dokumentert, Delvis dokumentert, Mangler or
 * Midlertidig akseptert (until a date), always with a begrunnelse, who controlled it, the control
 * date (evaluated_on, never in the future) and when it was registered (recorded_at).
 *
 * A snapshot of what the person was looking at: the requirement's title, level and theme, the
 * «Gjelder fordi …» text, the supplier's name and criticality — so the history reads the same after
 * any of them change. The documentation used is supplier_requirement_evaluation_documents.
 *
 * Append-only: a mistake is corrected with a new control. SupplierRequirementEvaluation throws on
 * update and delete, and a trigger refuses UPDATE (except the foreign key nulling
 * evaluated_by_user_id when that user is deleted) and DELETE while the customer exists. The control
 * in force is the latest evaluated_on, then the highest id.
 *
 * «Dokumentert needs documentation» and «the requirement applies to the supplier» read other rows
 * and are SupplierRequirementEvaluationService's, checked with the supplier locked.
 *
 * Supplier and requirement by (id, customer_id), NO ACTION: never across customers, and neither can
 * be deleted while a control refers to it. unique (id, supplier_id) is for provenance later (§20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_requirement_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('requirement_id');
            $table->string('status');
            $table->text('rationale');
            $table->date('accepted_until')->nullable();
            $table->date('evaluated_on');
            $table->foreignId('evaluated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->string('requirement_title');
            $table->string('requirement_level');
            $table->string('requirement_theme');
            $table->text('applicability_reason');
            $table->string('supplier_name');
            $table->string('criticality')->nullable();

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('supplier_control_requirements');
            $table->unique(['id', 'customer_id']);
            $table->unique(['id', 'supplier_id']);
            $table->index(['supplier_id', 'requirement_id', 'evaluated_on', 'id']);
            $table->index('requirement_id');
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_requirement_evaluations ADD CONSTRAINT supplier_requirement_evaluations_values CHECK ('
            ."status IN ('documented', 'partially_documented', 'missing', 'temporarily_accepted')"
            ." AND (status = 'temporarily_accepted') = (accepted_until IS NOT NULL)"
            ." AND requirement_level IN ('mandatory', 'important', 'standard')"
            ." AND requirement_theme IN ('human_rights', 'labour_conditions', 'environment', 'information_security', 'privacy', 'quality', 'continuity', 'ethics', 'financial')"
            ." AND (criticality IS NULL OR criticality IN ('standard', 'important', 'critical'))"
            .' AND length(btrim(rationale)) > 0 AND length(btrim(requirement_title)) > 0'
            .' AND length(btrim(applicability_reason)) > 0 AND length(btrim(supplier_name)) > 0)');

        $changed = implode("\n                    OR ", array_map(
            fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'customer_id', 'supplier_id', 'requirement_id', 'status', 'rationale', 'accepted_until', 'evaluated_on', 'recorded_at',
                'requirement_title', 'requirement_level', 'requirement_theme', 'applicability_reason', 'supplier_name', 'criticality'],
        ));

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION supplier_requirement_evaluations_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_requirement_evaluations is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF {$changed}
                    OR (NEW.evaluated_by_user_id IS NOT NULL AND NEW.evaluated_by_user_id IS DISTINCT FROM OLD.evaluated_by_user_id)
                THEN
                    RAISE EXCEPTION 'supplier_requirement_evaluations is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_requirement_evaluations_immutable
                BEFORE UPDATE OR DELETE ON supplier_requirement_evaluations
                FOR EACH ROW EXECUTE FUNCTION supplier_requirement_evaluations_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_requirement_evaluations');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_requirement_evaluations_immutable()');
        }
    }
};
