<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a tiltak's status: every Start, Fullfør, Avbryt and Gjenåpne, as it was made. The
 * same arrangement as improvement_case_status_changes.
 *
 * improvement_actions.status and completed_* say where the tiltak stands now; this table says how
 * it got there. A reopening clears the completion on the tiltak, so the completion it undoes — «Hva
 * ble gjort?», who and when — survives only here. That is why a row is never changed or deleted by
 * the application (ImprovementActionStatusChange throws), and why every change but Start must say
 * something.
 *
 *   planned → in_progress                 Start tiltak (no note needed)
 *   planned | in_progress → completed     Fullfør tiltak («Hva ble gjort?» required)
 *   planned | in_progress → cancelled     Avbryt tiltak (begrunnelse required)
 *   completed | cancelled → planned       Gjenåpne tiltak (begrunnelse required)
 *
 * On PostgreSQL a trigger refuses any UPDATE except the foreign key nulling a deleted author.
 * (improvement_action_id, customer_id) references the tiltak's own pair. A tiltak with history is
 * never deletable through the product (ImprovementAction::isDeletable()); the cascade is there for
 * the customer going.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_action_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('improvement_action_id');
            $table->string('from_status');
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['improvement_action_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_actions')->cascadeOnDelete();
            $table->index(['improvement_action_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE improvement_action_status_changes ADD CONSTRAINT improvement_action_status_changes_transition CHECK ('
                ."(from_status = 'planned' AND to_status IN ('in_progress', 'completed', 'cancelled'))"
                ." OR (from_status = 'in_progress' AND to_status IN ('completed', 'cancelled'))"
                ." OR (from_status IN ('completed', 'cancelled') AND to_status = 'planned'))");
            // Every change but Start tiltak completes, ends or undoes something, so it says what or why.
            DB::statement("ALTER TABLE improvement_action_status_changes ADD CONSTRAINT improvement_action_status_changes_note CHECK (to_status = 'in_progress' OR length(btrim(coalesce(note, ''))) > 0)");

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION improvement_action_status_changes_immutable() RETURNS trigger AS $$
                BEGIN
                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.improvement_action_id IS DISTINCT FROM OLD.improvement_action_id
                        OR NEW.from_status IS DISTINCT FROM OLD.from_status
                        OR NEW.to_status IS DISTINCT FROM OLD.to_status
                        OR NEW.note IS DISTINCT FROM OLD.note
                        OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                        OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'improvement_action_status_changes is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER improvement_action_status_changes_immutable
                    BEFORE UPDATE ON improvement_action_status_changes
                    FOR EACH ROW EXECUTE FUNCTION improvement_action_status_changes_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_action_status_changes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS improvement_action_status_changes_immutable()');
        }
    }
};
