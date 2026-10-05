<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a case's status: every Start behandling, Lukk, Avbryt and Gjenåpne, as it was made.
 *
 * improvement_cases.status and its closed_* columns say where the case stands now; this table says
 * how it got there. A reopening clears the ending on the case, so the ending it undoes survives only
 * here — which is why a row is never changed or deleted by the application
 * (ImprovementCaseStatusChange throws), and why every change but Start behandling must say why.
 *
 * Only the transitions of the lifecycle exist, and the database names them:
 *
 *   open → in_progress            Start behandling (no note needed)
 *   open | in_progress → closed   Lukk (resultat required)
 *   open | in_progress → cancelled  Avbryt (begrunnelse required)
 *   closed | cancelled → open     Gjenåpne (begrunnelse required)
 *
 * changed_at is the only time column: a row is written once. changed_by_user_id is nulled when that
 * user is deleted; the row stays. On PostgreSQL a trigger refuses any other UPDATE, so the history
 * is protected below the model as well — nulling the author by that foreign key is the one change
 * it lets through. Deleting the case is only allowed before it has any history
 * (ImprovementCase::isDeletable()); the cascade is there for the customer going.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_case_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('improvement_case_id')->constrained('improvement_cases')->cascadeOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['improvement_case_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE improvement_case_status_changes ADD CONSTRAINT improvement_case_status_changes_transition CHECK ('
                ."(from_status = 'open' AND to_status IN ('in_progress', 'closed', 'cancelled'))"
                ." OR (from_status = 'in_progress' AND to_status IN ('closed', 'cancelled'))"
                ." OR (from_status IN ('closed', 'cancelled') AND to_status = 'open'))");
            // Every change but Start behandling ends or undoes a decision, so it says why.
            DB::statement("ALTER TABLE improvement_case_status_changes ADD CONSTRAINT improvement_case_status_changes_note CHECK (to_status = 'in_progress' OR length(btrim(coalesce(note, ''))) > 0)");

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION improvement_case_status_changes_immutable() RETURNS trigger AS $$
                BEGIN
                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.improvement_case_id IS DISTINCT FROM OLD.improvement_case_id
                        OR NEW.from_status IS DISTINCT FROM OLD.from_status
                        OR NEW.to_status IS DISTINCT FROM OLD.to_status
                        OR NEW.note IS DISTINCT FROM OLD.note
                        OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                        OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'improvement_case_status_changes is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER improvement_case_status_changes_immutable
                    BEFORE UPDATE ON improvement_case_status_changes
                    FOR EACH ROW EXECUTE FUNCTION improvement_case_status_changes_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_case_status_changes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS improvement_case_status_changes_immutable()');
        }
    }
};
