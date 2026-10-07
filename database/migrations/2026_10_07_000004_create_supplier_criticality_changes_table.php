<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a supplier's criticality: every «Vurder kritikalitet» / «Endre kritikalitet», as it
 * was made (docs/supplier-management-v1-plan.md §4.2, §10).
 *
 * Each row holds the classification before and after — level, review interval and the four ja/nei
 * answers, each its own column so an old row reads without interpreting free text — and the
 * begrunnelse, which is always required. from_* are all empty when the supplier had not been
 * classified before (registered before phase 3). The classification a supplier was registered with
 * is on the supplier itself, not here; the oldest row's from_* says what it was.
 *
 * Append-only, exactly like supplier_status_changes: SupplierCriticalityChange throws on update and
 * delete, and on PostgreSQL a trigger refuses UPDATE (except the foreign key nulling
 * changed_by_user_id when that user is deleted) and DELETE while the customer exists.
 *
 * The supplier is referenced by (supplier_id, customer_id) with NO ACTION: a row can never describe
 * another customer's supplier, and a supplier with criticality history can never be deleted — it is
 * ended instead. The customer going still takes both.
 */
return new class extends Migration
{
    private const ANSWERS = ['processes_personal_data', 'has_system_access', 'supports_critical_delivery', 'hard_to_replace'];

    public function up(): void
    {
        Schema::create('supplier_criticality_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->string('from_criticality')->nullable();
            $table->string('to_criticality');
            $table->unsignedSmallInteger('from_review_interval_months')->nullable();
            $table->unsignedSmallInteger('to_review_interval_months')->nullable();

            foreach (self::ANSWERS as $answer) {
                $table->boolean("from_{$answer}")->nullable();
                $table->boolean("to_{$answer}");
            }

            $table->text('reason');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['supplier_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $fromAnswersNull = implode(' AND ', array_map(fn (string $answer): string => "from_{$answer} IS NULL", self::ANSWERS));
        $fromAnswersSet = implode(' AND ', array_map(fn (string $answer): string => "from_{$answer} IS NOT NULL", self::ANSWERS));

        DB::statement("ALTER TABLE supplier_criticality_changes ADD CONSTRAINT supplier_criticality_changes_values CHECK (to_criticality IN ('standard', 'important', 'critical')"
            ." AND (from_criticality IS NULL OR from_criticality IN ('standard', 'important', 'critical'))"
            .' AND (to_review_interval_months IS NULL OR to_review_interval_months IN (6, 12, 24, 36))'
            .' AND (from_review_interval_months IS NULL OR from_review_interval_months IN (6, 12, 24, 36)))');
        // The same rule as on the supplier, before and after.
        DB::statement('ALTER TABLE supplier_criticality_changes ADD CONSTRAINT supplier_criticality_changes_interval CHECK ('
            ."(to_criticality = 'standard' OR to_review_interval_months IS NOT NULL)"
            ." AND (from_criticality IS NULL OR from_criticality = 'standard' OR from_review_interval_months IS NOT NULL))");
        // Not classified before means nothing before; classified means the whole basis.
        DB::statement('ALTER TABLE supplier_criticality_changes ADD CONSTRAINT supplier_criticality_changes_from_complete CHECK ('
            ."(from_criticality IS NULL AND from_review_interval_months IS NULL AND {$fromAnswersNull})"
            ." OR (from_criticality IS NOT NULL AND {$fromAnswersSet}))");
        DB::statement('ALTER TABLE supplier_criticality_changes ADD CONSTRAINT supplier_criticality_changes_reason CHECK (length(btrim(reason)) > 0)');

        $columns = ['id', 'customer_id', 'supplier_id', 'from_criticality', 'to_criticality', 'from_review_interval_months', 'to_review_interval_months', 'reason', 'changed_at'];

        foreach (self::ANSWERS as $answer) {
            $columns[] = "from_{$answer}";
            $columns[] = "to_{$answer}";
        }

        $changed = implode("\n                    OR ", array_map(fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}", $columns));

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION supplier_criticality_changes_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_criticality_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF {$changed}
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'supplier_criticality_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_criticality_changes_immutable
                BEFORE UPDATE OR DELETE ON supplier_criticality_changes
                FOR EACH ROW EXECUTE FUNCTION supplier_criticality_changes_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_criticality_changes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_criticality_changes_immutable()');
        }
    }
};
