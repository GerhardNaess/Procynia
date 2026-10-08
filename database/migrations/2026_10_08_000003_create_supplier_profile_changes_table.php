<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a supplier's profile: every save, as it was made
 * (docs/supplier-assurance-v2-plan.md §4.4, §20.2).
 *
 * Each row holds the whole profile before and after as a snapshot — from_profile is null for the
 * first save — so «why did the DPA requirement not apply in March?» can be answered later without
 * reconstructing anything. The first save needs no begrunnelse; every later one does.
 *
 * Append-only: SupplierProfileChange throws on update and delete, and on PostgreSQL a trigger
 * refuses UPDATE (except the foreign key nulling changed_by_user_id when that user is deleted) and
 * DELETE while the customer exists — the supplier_criticality_changes pattern.
 *
 * The supplier is referenced by (supplier_id, customer_id) with NO ACTION: a row can never describe
 * another customer's supplier, and a supplier with profile history can never be deleted. The
 * customer going still takes both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_profile_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->jsonb('from_profile')->nullable();
            $table->jsonb('to_profile');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['supplier_id', 'changed_at', 'id']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_profile_changes ADD CONSTRAINT supplier_profile_changes_snapshots CHECK ('
            ."jsonb_typeof(to_profile) = 'object' AND (from_profile IS NULL OR jsonb_typeof(from_profile) = 'object'))");
        // The first save needs no begrunnelse; a change does. A begrunnelse that is given is never blank.
        DB::statement('ALTER TABLE supplier_profile_changes ADD CONSTRAINT supplier_profile_changes_reason CHECK ('
            .'(from_profile IS NULL OR reason IS NOT NULL) AND (reason IS NULL OR length(btrim(reason)) > 0))');

        $changed = implode("\n                    OR ", array_map(
            fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'customer_id', 'supplier_id', 'from_profile', 'to_profile', 'reason', 'changed_at'],
        ));

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION supplier_profile_changes_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_profile_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF {$changed}
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'supplier_profile_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_profile_changes_immutable
                BEFORE UPDATE OR DELETE ON supplier_profile_changes
                FOR EACH ROW EXECUTE FUNCTION supplier_profile_changes_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_profile_changes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_profile_changes_immutable()');
        }
    }
};
