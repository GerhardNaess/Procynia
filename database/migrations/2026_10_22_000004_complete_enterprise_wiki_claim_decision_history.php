<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the three audit gaps in A4 (docs/external-api-phase-0-plan.md §2a).
 *
 * enterprise_wiki_claim_decisions
 * - Says who acted: actor_type 'user' (decided_by_user_id) or 'system' (system_process names the
 *   rule, initiated_by_user_id the person whose action set it off, if any). A system event is never
 *   attributed to a person it did not come from, and no placeholder user exists.
 * - decided_by_user_id was NOT NULL with cascadeOnDelete: deleting a user deleted their decisions.
 *   It is nullable with nullOnDelete now, like the document-owner history.
 * - Append-only in the database: a trigger refuses UPDATE and DELETE, except nulling a deleted
 *   user and the cascade from the claim itself (a deleted version, page or customer).
 * - Backfill: one system event (history_backfill) for every claim whose current approval status or
 *   blocking differs from what its history last recorded — a system reset or a version copy that
 *   wrote nothing — so the history ends where the current state is.
 *
 * enterprise_wiki_source_references
 * - linked_by_type 'user' | 'system' | 'unrecorded', linked_by_user_id, linked_by_process and
 *   linked_at. Rows from before this migration are 'unrecorded' with linked_at = created_at: who made
 *   them was never stored, and the migration does not guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_claim_decisions', function (Blueprint $table): void {
            $table->dropForeign(['decided_by_user_id']);
        });

        Schema::table('enterprise_wiki_claim_decisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('decided_by_user_id')->nullable()->change();
            $table->foreign('decided_by_user_id', 'ewcd_decided_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->string('actor_type', 16)->default('user')->after('decided_by_user_id');
            $table->string('system_process', 64)->nullable()->after('actor_type');
            $table->foreignId('initiated_by_user_id')->nullable()->after('system_process')
                ->constrained('users', indexName: 'ewcd_initiated_by_fk')->nullOnDelete();
        });

        Schema::table('enterprise_wiki_source_references', function (Blueprint $table): void {
            $table->string('linked_by_type', 16)->default('unrecorded');
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users', indexName: 'ewsr_linked_by_fk')->nullOnDelete();
            $table->string('linked_by_process', 64)->nullable();
            $table->timestamp('linked_at')->nullable()->useCurrent();
        });

        DB::table('enterprise_wiki_source_references')->update(['linked_at' => DB::raw('created_at')]);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->backfillStateWithoutHistory();

        DB::statement("ALTER TABLE enterprise_wiki_claim_decisions ADD CONSTRAINT ewcd_actor_check CHECK (
            actor_type IN ('user', 'system')
            AND (actor_type = 'system') = (system_process IS NOT NULL)
            AND (actor_type = 'user' OR decided_by_user_id IS NULL)
            AND (actor_type = 'system' OR initiated_by_user_id IS NULL)
        )");

        DB::statement("ALTER TABLE enterprise_wiki_source_references ADD CONSTRAINT ewsr_linked_by_check CHECK (
            linked_by_type IN ('user', 'system', 'unrecorded')
            AND (linked_by_type = 'system') = (linked_by_process IS NOT NULL)
            AND (linked_by_type = 'user' OR linked_by_user_id IS NULL)
        )");

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enterprise_wiki_claim_decisions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM enterprise_wiki_claims WHERE id = OLD.enterprise_wiki_claim_id) THEN
                        RAISE EXCEPTION 'enterprise_wiki_claim_decisions is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the deciding or initiating user is the one change allowed.
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.enterprise_wiki_claim_id IS DISTINCT FROM OLD.enterprise_wiki_claim_id
                    OR NEW.actor_type IS DISTINCT FROM OLD.actor_type
                    OR NEW.system_process IS DISTINCT FROM OLD.system_process
                    OR NEW.decision_type IS DISTINCT FROM OLD.decision_type
                    OR NEW.previous_state::text IS DISTINCT FROM OLD.previous_state::text
                    OR NEW.new_state::text IS DISTINCT FROM OLD.new_state::text
                    OR NEW.comment IS DISTINCT FROM OLD.comment
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (NEW.decided_by_user_id IS NOT NULL AND NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id)
                    OR (NEW.initiated_by_user_id IS NOT NULL AND NEW.initiated_by_user_id IS DISTINCT FROM OLD.initiated_by_user_id)
                THEN
                    RAISE EXCEPTION 'enterprise_wiki_claim_decisions is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER enterprise_wiki_claim_decisions_immutable
                BEFORE UPDATE OR DELETE ON enterprise_wiki_claim_decisions
                FOR EACH ROW EXECUTE FUNCTION enterprise_wiki_claim_decisions_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS enterprise_wiki_claim_decisions_immutable ON enterprise_wiki_claim_decisions');
            DB::unprepared('DROP FUNCTION IF EXISTS enterprise_wiki_claim_decisions_immutable()');
            DB::statement('ALTER TABLE enterprise_wiki_claim_decisions DROP CONSTRAINT IF EXISTS ewcd_actor_check');
            DB::statement('ALTER TABLE enterprise_wiki_source_references DROP CONSTRAINT IF EXISTS ewsr_linked_by_check');
        }

        Schema::table('enterprise_wiki_source_references', function (Blueprint $table): void {
            $table->dropForeign('ewsr_linked_by_fk');
            $table->dropColumn(['linked_by_type', 'linked_by_user_id', 'linked_by_process', 'linked_at']);
        });

        // The old column cannot hold an event without a person: system events and events whose
        // user was deleted go, as they could not have existed before this migration.
        DB::table('enterprise_wiki_claim_decisions')->whereNull('decided_by_user_id')->delete();

        Schema::table('enterprise_wiki_claim_decisions', function (Blueprint $table): void {
            $table->dropForeign('ewcd_initiated_by_fk');
            $table->dropForeign('ewcd_decided_by_fk');
            $table->dropColumn(['actor_type', 'system_process', 'initiated_by_user_id']);
        });

        Schema::table('enterprise_wiki_claim_decisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('decided_by_user_id')->nullable(false)->change();
            $table->foreign('decided_by_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    private function backfillStateWithoutHistory(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO enterprise_wiki_claim_decisions
                (enterprise_wiki_claim_id, decided_by_user_id, actor_type, system_process, decision_type, previous_state, new_state, comment, created_at)
            SELECT c.id, NULL, 'system', 'history_backfill', 'approval_status',
                   CASE WHEN l.id IS NULL THEN NULL ELSE json_build_object('approval_status', l.new_state->>'approval_status') END,
                   json_build_object('approval_status', c.approval_status, 'approved_by_user_id', c.approved_by_user_id, 'approved_at', c.approved_at),
                   NULL, CURRENT_TIMESTAMP
            FROM enterprise_wiki_claims c
            LEFT JOIN LATERAL (
                SELECT d.id, d.new_state FROM enterprise_wiki_claim_decisions d
                WHERE d.enterprise_wiki_claim_id = c.id AND d.decision_type = 'approval_status'
                ORDER BY d.created_at DESC, d.id DESC LIMIT 1
            ) l ON TRUE
            WHERE (l.id IS NULL AND c.approval_status <> 'pending')
               OR (l.id IS NOT NULL AND (l.new_state->>'approval_status') IS DISTINCT FROM c.approval_status)
            ORDER BY c.id
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO enterprise_wiki_claim_decisions
                (enterprise_wiki_claim_id, decided_by_user_id, actor_type, system_process, decision_type, previous_state, new_state, comment, created_at)
            SELECT c.id, NULL, 'system', 'history_backfill', 'blocking_override',
                   CASE WHEN l.id IS NULL THEN NULL ELSE json_build_object('blocking_override', l.new_state->'blocking_override') END,
                   json_build_object('blocking_override', c.blocking_override),
                   NULL, CURRENT_TIMESTAMP
            FROM enterprise_wiki_claims c
            LEFT JOIN LATERAL (
                SELECT d.id, d.new_state FROM enterprise_wiki_claim_decisions d
                WHERE d.enterprise_wiki_claim_id = c.id AND d.decision_type = 'blocking_override'
                ORDER BY d.created_at DESC, d.id DESC LIMIT 1
            ) l ON TRUE
            WHERE (l.id IS NULL AND c.blocking_override IS NOT NULL)
               OR (l.id IS NOT NULL AND (l.new_state->>'blocking_override') IS DISTINCT FROM c.blocking_override::text)
            ORDER BY c.id
            SQL);
    }
};
