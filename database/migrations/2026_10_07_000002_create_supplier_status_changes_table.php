<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a supplier's status: every Ta i bruk, Avslutt and Gjenåpne, as it was made.
 *
 *   onboarding → active   Ta i bruk           (no begrunnelse)
 *   onboarding → ended    Avslutt leverandør  begrunnelse required
 *   active → ended        Avslutt leverandør  begrunnelse required
 *   ended → active        Gjenåpne leverandør begrunnelse required
 *
 * The status a supplier was registered with is on the supplier itself, not here; a supplier with
 * no row here has never changed status.
 *
 * Append-only. SupplierStatusChange throws on update and delete, and on PostgreSQL a trigger holds
 * the same line below the model:
 *
 *  - UPDATE is refused, except the one change a foreign key makes on its own: nulling
 *    changed_by_user_id when that user is deleted. The row stays.
 *  - DELETE is refused while the customer exists. Only the customer going takes the history with
 *    it.
 *
 * The supplier is referenced by (supplier_id, customer_id) with NO ACTION, so a row can never
 * describe another customer's supplier, and a supplier with history can never be deleted — the
 * product rule (it is ended instead). The customer going still takes both: NO ACTION is checked
 * once that statement has finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->string('from_status');
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['supplier_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_status_changes ADD CONSTRAINT supplier_status_changes_transition CHECK ('
                ."(from_status = 'onboarding' AND to_status = 'active')"
                ." OR (from_status = 'onboarding' AND to_status = 'ended')"
                ." OR (from_status = 'active' AND to_status = 'ended')"
                ." OR (from_status = 'ended' AND to_status = 'active'))");
            // Ta i bruk is the one change without a begrunnelse; every other one says why.
            DB::statement('ALTER TABLE supplier_status_changes ADD CONSTRAINT supplier_status_changes_reason CHECK ('
                ."(from_status = 'onboarding' AND to_status = 'active') OR (reason IS NOT NULL AND length(btrim(reason)) > 0))");

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION supplier_status_changes_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                            RAISE EXCEPTION 'supplier_status_changes is history and cannot be deleted';
                        END IF;

                        RETURN OLD;
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                        OR NEW.from_status IS DISTINCT FROM OLD.from_status
                        OR NEW.to_status IS DISTINCT FROM OLD.to_status
                        OR NEW.reason IS DISTINCT FROM OLD.reason
                        OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                        OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'supplier_status_changes is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER supplier_status_changes_immutable
                    BEFORE UPDATE OR DELETE ON supplier_status_changes
                    FOR EACH ROW EXECUTE FUNCTION supplier_status_changes_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_status_changes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_status_changes_immutable()');
        }
    }
};
