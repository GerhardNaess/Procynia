<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a control requirement's status: every Sett som utgått and Ta i bruk igjen, as it was
 * made — for catalogue requirements (supplier_id null) and requirements for one supplier alike.
 *
 *   active → retired   Sett som utgått
 *   retired → active   Ta i bruk igjen
 *
 * Neither asks for a begrunnelse, so none is stored. Changes made before this table existed are not
 * reconstructed: a requirement without rows here has no recorded status change.
 *
 * Append-only. SupplierControlRequirementStatusChange throws on update and delete, and on PostgreSQL
 * a trigger holds the same line below the model:
 *
 *  - UPDATE is refused, except the one change a foreign key makes on its own: nulling
 *    changed_by_user_id when that user is deleted. The row stays.
 *  - DELETE is refused while the customer exists. Only the customer going takes the history with
 *    it.
 *
 * The requirement is referenced by (requirement_id, customer_id) with NO ACTION, so a row can never
 * describe another customer's requirement, and a requirement with status history can never be
 * deleted — the product rule (it is retired instead). The supplier, for a requirement for one
 * supplier, likewise by (supplier_id, customer_id); such a supplier is never deletable anyway. The
 * customer going still takes all of them: NO ACTION is checked once that statement has finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_control_requirement_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('from_status');
            $table->string('to_status');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['requirement_id', 'customer_id'], 'scr_status_changes_requirement_foreign')
                ->references(['id', 'customer_id'])->on('supplier_control_requirements');
            $table->foreign(['supplier_id', 'customer_id'], 'scr_status_changes_supplier_foreign')
                ->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['requirement_id', 'changed_at'], 'scr_status_changes_requirement_index');
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_control_requirement_status_changes ADD CONSTRAINT scr_status_changes_transition CHECK ('
                ."(from_status = 'active' AND to_status = 'retired') OR (from_status = 'retired' AND to_status = 'active'))");

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION supplier_control_requirement_status_changes_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                            RAISE EXCEPTION 'supplier_control_requirement_status_changes is history and cannot be deleted';
                        END IF;

                        RETURN OLD;
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.requirement_id IS DISTINCT FROM OLD.requirement_id
                        OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                        OR NEW.from_status IS DISTINCT FROM OLD.from_status
                        OR NEW.to_status IS DISTINCT FROM OLD.to_status
                        OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                        OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'supplier_control_requirement_status_changes is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER supplier_control_requirement_status_changes_immutable
                    BEFORE UPDATE OR DELETE ON supplier_control_requirement_status_changes
                    FOR EACH ROW EXECUTE FUNCTION supplier_control_requirement_status_changes_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_control_requirement_status_changes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_control_requirement_status_changes_immutable()');
        }
    }
};
