<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slettede leverandører: one row per supplier that was deleted — who did it, when, and the supplier's
 * id and name as they were. Nothing else of the supplier is kept; the profile, documentation and
 * controls were never there to delete in the first place (Supplier::isDeletable()).
 *
 * Written only by SupplierLifecycleService::delete(), in the same transaction as the delete: no
 * deletion without its row, and no row without the deletion.
 *
 * supplier_id has NO foreign key: the row outlives the supplier, and must never be what stops it
 * being deleted. It is unique, since a supplier id is deleted once.
 *
 * Append-only. SupplierDeletion throws on update and delete, and on PostgreSQL a trigger holds the
 * same line below the model:
 *
 *  - UPDATE is refused, except the one change a foreign key makes on its own: nulling
 *    deleted_by_user_id when that user is deleted. The row stays.
 *  - DELETE is refused while the customer exists. Only the customer going takes the log with it,
 *    the same as every other history in Leverandøroppfølging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id')->unique();
            $table->string('supplier_name');
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at');

            $table->index(['customer_id', 'deleted_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_deletions ADD CONSTRAINT supplier_deletions_name CHECK (length(btrim(supplier_name)) > 0)');

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION supplier_deletions_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                            RAISE EXCEPTION 'supplier_deletions is history and cannot be deleted';
                        END IF;

                        RETURN OLD;
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                        OR NEW.supplier_name IS DISTINCT FROM OLD.supplier_name
                        OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                        OR (NEW.deleted_by_user_id IS NOT NULL AND NEW.deleted_by_user_id IS DISTINCT FROM OLD.deleted_by_user_id)
                    THEN
                        RAISE EXCEPTION 'supplier_deletions is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER supplier_deletions_immutable
                    BEFORE UPDATE OR DELETE ON supplier_deletions
                    FOR EACH ROW EXECUTE FUNCTION supplier_deletions_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_deletions');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_deletions_immutable()');
        }
    }
};
