<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual overrides of whether one control requirement applies to one supplier
 * (docs/supplier-assurance-v2-plan.md §5.5, §20.2): include, exclude, or clear — back to the rule.
 *
 * One row per human action, never a row per applying requirement: automatic applicability is
 * computed on read and stored nowhere. The override in force for (supplier, requirement) is the
 * latest row by created_at, then id; clear is a new row, never a delete.
 *
 * Append-only: SupplierRequirementOverride throws on update and delete, and a trigger refuses UPDATE
 * (except the foreign key nulling created_by_user_id when that user is deleted) and DELETE while the
 * customer exists.
 *
 * Supplier and requirement are referenced by (id, customer_id) with NO ACTION: an override can never
 * cross customers, and a supplier or requirement with overrides can never be deleted. The customer
 * going takes everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_requirement_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('requirement_id');
            $table->string('action');
            $table->text('reason');
            $table->string('requirement_title');
            $table->string('requirement_level');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('supplier_control_requirements');
            $table->index(['supplier_id', 'requirement_id', 'created_at', 'id']);
            $table->index('requirement_id');
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_requirement_overrides ADD CONSTRAINT supplier_requirement_overrides_values CHECK ('
            ."action IN ('include', 'exclude', 'clear')"
            ." AND requirement_level IN ('mandatory', 'important', 'standard')"
            .' AND length(btrim(reason)) > 0 AND length(btrim(requirement_title)) > 0)');

        $changed = implode("\n                    OR ", array_map(
            fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'customer_id', 'supplier_id', 'requirement_id', 'action', 'reason', 'requirement_title', 'requirement_level', 'created_at'],
        ));

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION supplier_requirement_overrides_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_requirement_overrides is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF {$changed}
                    OR (NEW.created_by_user_id IS NOT NULL AND NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id)
                THEN
                    RAISE EXCEPTION 'supplier_requirement_overrides is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_requirement_overrides_immutable
                BEFORE UPDATE OR DELETE ON supplier_requirement_overrides
                FOR EACH ROW EXECUTE FUNCTION supplier_requirement_overrides_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_requirement_overrides');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_requirement_overrides_immutable()');
        }
    }
};
