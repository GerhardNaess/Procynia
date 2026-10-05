<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effektverifisering: whether a completed tiltak actually worked. Effekt bekreftet (effective) or
 * Ikke effektivt (not_effective), always with a comment.
 *
 * A verification judges ONE completion, not the tiltak in general. A tiltak can be completed,
 * verified, reopened and completed again; the first verification still belongs to the first
 * completion. completion_status_change_id names the improvement_action_status_changes row that set
 * the tiltak to completed, and the composite foreign key (completion_status_change_id,
 * improvement_action_id, customer_id) makes the database refuse a row pointing at another tiltak's
 * or another customer's change. The trigger below refuses one pointing at anything but a completion.
 *
 * Append-only: a new judgement of the same completion is a new row, and the newest (verified_at, id)
 * is the current one. Nothing is changed or deleted by the application
 * (ImprovementActionVerification throws), and on PostgreSQL a trigger refuses any UPDATE except the
 * foreign key nulling a deleted verifier. The cascades are there for the tiltak or the customer
 * going — a tiltak with history is never deletable through the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The target of the composite key below. id alone is already unique, so this adds no rule.
        Schema::table('improvement_action_status_changes', function (Blueprint $table): void {
            $table->unique(['id', 'improvement_action_id', 'customer_id'], 'improvement_action_status_changes_identity_unique');
        });

        Schema::create('improvement_action_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('improvement_action_id');
            $table->unsignedBigInteger('completion_status_change_id');
            $table->string('result');
            $table->text('note');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at');

            $table->foreign(['improvement_action_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_actions')->cascadeOnDelete();
            $table->foreign(['completion_status_change_id', 'improvement_action_id', 'customer_id'], 'improvement_action_verifications_completion_fk')
                ->references(['id', 'improvement_action_id', 'customer_id'])->on('improvement_action_status_changes')->cascadeOnDelete();
            $table->index(['completion_status_change_id', 'verified_at']);
            $table->index('improvement_action_id');
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE improvement_action_verifications ADD CONSTRAINT improvement_action_verifications_result_check CHECK (result IN ('effective', 'not_effective'))");
            DB::statement('ALTER TABLE improvement_action_verifications ADD CONSTRAINT improvement_action_verifications_note_check CHECK (length(btrim(note)) > 0)');

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION improvement_action_verifications_completion() RETURNS trigger AS $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM improvement_action_status_changes
                        WHERE id = NEW.completion_status_change_id AND to_status = 'completed'
                    ) THEN
                        RAISE EXCEPTION 'improvement_action_verifications judge a completion only';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER improvement_action_verifications_completion
                    BEFORE INSERT ON improvement_action_verifications
                    FOR EACH ROW EXECUTE FUNCTION improvement_action_verifications_completion();

                CREATE OR REPLACE FUNCTION improvement_action_verifications_immutable() RETURNS trigger AS $$
                BEGIN
                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.improvement_action_id IS DISTINCT FROM OLD.improvement_action_id
                        OR NEW.completion_status_change_id IS DISTINCT FROM OLD.completion_status_change_id
                        OR NEW.result IS DISTINCT FROM OLD.result
                        OR NEW.note IS DISTINCT FROM OLD.note
                        OR NEW.verified_at IS DISTINCT FROM OLD.verified_at
                        OR (NEW.verified_by_user_id IS NOT NULL AND NEW.verified_by_user_id IS DISTINCT FROM OLD.verified_by_user_id)
                    THEN
                        RAISE EXCEPTION 'improvement_action_verifications is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER improvement_action_verifications_immutable
                    BEFORE UPDATE ON improvement_action_verifications
                    FOR EACH ROW EXECUTE FUNCTION improvement_action_verifications_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_action_verifications');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS improvement_action_verifications_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS improvement_action_verifications_completion()');
        }

        Schema::table('improvement_action_status_changes', function (Blueprint $table): void {
            $table->dropUnique('improvement_action_status_changes_identity_unique');
        });
    }
};
