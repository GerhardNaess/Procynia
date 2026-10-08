<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aktsomhetsvurderinger — a person's documented assessment of human rights, working conditions and
 * environment in a supplier's supply chain (docs/supplier-assurance-v2-plan.md §11, §20.2). Not a
 * score: six areas, each Lav, Forhøyet, Høy or Ukjent, chosen one by one; what was mapped
 * (supply_chain_description) and investigated (investigation_summary); and a conclusion the person
 * chooses — never computed from the areas — with a begrunnelse.
 *
 * review_interval_months (6, 12 or 24) gives the next assessment: assessed_on + the interval, computed
 * on read (plan §14), never stored. assessed_on is the day of the assessment, never in the future;
 * recorded_at when it was registered.
 *
 * The snapshot is what the plan names: the supplier's name, criticality, high_risk_categories (null
 * when the profile did not answer it) and production_outside_eea as they were, so an old assessment
 * reads the same after the supplier changes.
 *
 * Append-only: a new assessment is a new row. SupplierDueDiligenceAssessment throws on update and
 * delete, and a trigger refuses UPDATE (except the foreign key nulling assessed_by_user_id when that
 * user is deleted) and DELETE while the customer exists. The assessment in force is the latest
 * assessed_on, then the highest id.
 *
 * Supplier by (id, customer_id), NO ACTION: never across customers, and a supplier with an assessment
 * cannot be deleted. unique(id, supplier_id) lets a case or a risk name the assessment it came from.
 */
return new class extends Migration
{
    private const AREAS = [
        'child_labour_risk',
        'forced_labour_risk',
        'working_conditions_risk',
        'discrimination_risk',
        'freedom_of_association_risk',
        'environment_risk',
    ];

    public function up(): void
    {
        Schema::create('supplier_due_diligence_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');

            foreach (self::AREAS as $area) {
                $table->string($area);
            }

            $table->text('supply_chain_description')->nullable();
            $table->text('investigation_summary')->nullable();
            $table->string('conclusion');
            $table->text('rationale');
            $table->unsignedSmallInteger('review_interval_months');
            $table->date('assessed_on');
            $table->foreignId('assessed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->string('supplier_name');
            $table->string('criticality')->nullable();
            $table->jsonb('high_risk_categories')->nullable();
            $table->string('production_outside_eea')->nullable();

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->unique(['id', 'supplier_id']);
            $table->index(['supplier_id', 'assessed_on', 'id']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $levels = implode(' AND ', array_map(
            fn (string $area): string => "{$area} IN ('low', 'elevated', 'high', 'unknown')",
            self::AREAS,
        ));

        DB::statement('ALTER TABLE supplier_due_diligence_assessments ADD CONSTRAINT supplier_due_diligence_assessments_values CHECK ('
            .$levels
            ." AND conclusion IN ('no_significant_risk', 'monitor', 'measures_required')"
            .' AND review_interval_months IN (6, 12, 24)'
            .' AND (supply_chain_description IS NULL OR length(btrim(supply_chain_description)) > 0)'
            .' AND (investigation_summary IS NULL OR length(btrim(investigation_summary)) > 0)'
            ." AND (criticality IS NULL OR criticality IN ('standard', 'important', 'critical'))"
            ." AND (high_risk_categories IS NULL OR jsonb_typeof(high_risk_categories) = 'array')"
            ." AND (production_outside_eea IS NULL OR production_outside_eea IN ('yes', 'no', 'unknown'))"
            .' AND length(btrim(rationale)) > 0 AND length(btrim(supplier_name)) > 0)');

        $changed = implode("\n                    OR ", array_map(
            fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'customer_id', 'supplier_id', ...self::AREAS, 'supply_chain_description', 'investigation_summary',
                'conclusion', 'rationale', 'review_interval_months', 'assessed_on', 'recorded_at', 'supplier_name',
                'criticality', 'high_risk_categories', 'production_outside_eea'],
        ));

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION supplier_due_diligence_assessments_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_due_diligence_assessments is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF {$changed}
                    OR (NEW.assessed_by_user_id IS NOT NULL AND NEW.assessed_by_user_id IS DISTINCT FROM OLD.assessed_by_user_id)
                THEN
                    RAISE EXCEPTION 'supplier_due_diligence_assessments is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_due_diligence_assessments_immutable
                BEFORE UPDATE OR DELETE ON supplier_due_diligence_assessments
                FOR EACH ROW EXECUTE FUNCTION supplier_due_diligence_assessments_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_due_diligence_assessments');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_due_diligence_assessments_immutable()');
        }
    }
};
