<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leverandørvurdering — how the supplier performs now (docs/supplier-management-v1-plan.md §4.3,
 * §10). Something else than criticality (how important the supplier is) and than risk (what could
 * go wrong); it never changes the criticality, and the criticality never changes it.
 *
 * One row per assessment actually made: four fixed criteria, each good/acceptable/poor/
 * not_relevant (Bra, Akseptabelt, Svakt, Ikke relevant); the overall result the assessor chose —
 * never computed from the criteria; a required begrunnelse; the day it applies to (assessed_on,
 * not in the future — checked by validation) and when it was recorded. A snapshot of the supplier's
 * name, criticality and review interval at the time keeps an old assessment readable after either
 * changes. criticality is null in the snapshot when the supplier had not been classified yet.
 *
 * The current assessment is the one with the latest assessed_on, then the highest id. The next
 * review is computed on read (SupplierReviewSchedule) and never stored.
 *
 * Append-only, like the supplier's status and criticality history: SupplierAssessment throws on
 * update and delete, and on PostgreSQL a trigger refuses UPDATE (except the foreign key nulling
 * assessed_by_user_id when that user is deleted) and DELETE while the customer exists. A mistaken
 * assessment is corrected by registering a new one.
 *
 * The supplier is referenced by (supplier_id, customer_id) with NO ACTION: a row can never describe
 * another customer's supplier, and an assessed supplier can never be deleted — it is ended instead.
 */
return new class extends Migration
{
    private const CRITERIA = ['quality_rating', 'delivery_rating', 'security_rating', 'compliance_rating'];

    public function up(): void
    {
        Schema::create('supplier_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->date('assessed_on');
            $table->foreignId('assessed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            foreach (self::CRITERIA as $criterion) {
                $table->string($criterion);
            }

            $table->string('overall_result');
            $table->text('rationale');
            $table->string('supplier_name');
            $table->string('criticality')->nullable();
            $table->unsignedSmallInteger('review_interval_months')->nullable();
            $table->timestamp('recorded_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['supplier_id', 'assessed_on']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $ratings = implode(' AND ', array_map(fn (string $criterion): string => "{$criterion} IN ('good', 'acceptable', 'poor', 'not_relevant')", self::CRITERIA));

        DB::statement("ALTER TABLE supplier_assessments ADD CONSTRAINT supplier_assessments_values CHECK ({$ratings}"
            ." AND overall_result IN ('satisfactory', 'partially_satisfactory', 'unsatisfactory')"
            ." AND (criticality IS NULL OR criticality IN ('standard', 'important', 'critical'))"
            .' AND (review_interval_months IS NULL OR review_interval_months IN (6, 12, 24, 36)))');
        DB::statement('ALTER TABLE supplier_assessments ADD CONSTRAINT supplier_assessments_text CHECK (length(btrim(rationale)) > 0 AND length(btrim(supplier_name)) > 0)');

        $columns = ['id', 'customer_id', 'supplier_id', 'assessed_on', ...self::CRITERIA, 'overall_result', 'rationale', 'supplier_name', 'criticality', 'review_interval_months', 'recorded_at'];
        $changed = implode("\n                    OR ", array_map(fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}", $columns));

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION supplier_assessments_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_assessments is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF {$changed}
                    OR (NEW.assessed_by_user_id IS NOT NULL AND NEW.assessed_by_user_id IS DISTINCT FROM OLD.assessed_by_user_id)
                THEN
                    RAISE EXCEPTION 'supplier_assessments is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_assessments_immutable
                BEFORE UPDATE OR DELETE ON supplier_assessments
                FOR EACH ROW EXECUTE FUNCTION supplier_assessments_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_assessments');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_assessments_immutable()');
        }
    }
};
