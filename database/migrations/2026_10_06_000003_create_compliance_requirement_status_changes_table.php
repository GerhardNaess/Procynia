<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a requirement's status: every Sett som utgått and Gjenåpne, as it was made, each
 * with the reason it was given.
 *
 *   active → retired   Sett som utgått (begrunnelse required)
 *   retired → active   Gjenåpne (begrunnelse required)
 *
 * Append-only. ComplianceRequirementStatusChange throws on update and delete, and on PostgreSQL two
 * triggers hold the same line below the model:
 *
 *  - UPDATE is refused, except the one change a foreign key makes on its own: nulling
 *    changed_by_user_id when that user is deleted. The row stays.
 *  - DELETE is refused while the customer exists. That also means a requirement with history can
 *    never be deleted — the cascade from it reaches these rows and is refused — which is the
 *    product rule (a requirement that has been retired is handled through its lifecycle, never
 *    removed). Only the customer going takes the history with it.
 *
 * The requirement is referenced by (requirement_id, customer_id), so a row can never describe
 * another customer's requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_requirement_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requirement_id');
            $table->string('from_status');
            $table->string('to_status');
            $table->text('note');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements')->cascadeOnDelete();
            $table->index(['requirement_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE compliance_requirement_status_changes ADD CONSTRAINT compliance_requirement_status_changes_transition CHECK ('
                ."(from_status = 'active' AND to_status = 'retired') OR (from_status = 'retired' AND to_status = 'active'))");
            DB::statement('ALTER TABLE compliance_requirement_status_changes ADD CONSTRAINT compliance_requirement_status_changes_note CHECK (length(btrim(note)) > 0)');

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION compliance_requirement_status_changes_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                            RAISE EXCEPTION 'compliance_requirement_status_changes is history and cannot be deleted';
                        END IF;

                        RETURN OLD;
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.requirement_id IS DISTINCT FROM OLD.requirement_id
                        OR NEW.from_status IS DISTINCT FROM OLD.from_status
                        OR NEW.to_status IS DISTINCT FROM OLD.to_status
                        OR NEW.note IS DISTINCT FROM OLD.note
                        OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                        OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'compliance_requirement_status_changes is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER compliance_requirement_status_changes_immutable
                    BEFORE UPDATE OR DELETE ON compliance_requirement_status_changes
                    FOR EACH ROW EXECUTE FUNCTION compliance_requirement_status_changes_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_requirement_status_changes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS compliance_requirement_status_changes_immutable()');
        }
    }
};
