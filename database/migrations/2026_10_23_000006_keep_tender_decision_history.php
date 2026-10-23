<?php

use App\Support\MigrationSchemaPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anbud: the tender decisions already recorded are kept by the database — phase 0, Anbud
 * (docs/external-api-phase-0-plan.md §2l).
 *
 * Both tables were guarded by nothing: SQL, or any future entry, could rewrite or delete them.
 * - saved_notice_no_go_decisions: the No-Go as decided (who, why, when) is never changed. A
 *   decision is reopened once (the reopen columns from empty to set), and a deleted user is nulled;
 *   nothing else.
 * - saved_notice_ai_requirement_revisions: who approved, rejected, restored and edited a
 *   requirement is never changed, except nulling a deleted user.
 *
 * Each is deleted only with what it belongs to: the case (and, for revisions, the requirement) or
 * the customer — the parent row is already gone when the cascade arrives. Deleting a requirement or
 * a case remains possible; whether it should be is a product question (A42), not this migration's.
 * No existing row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationSchemaPrecondition::requireTables(basename(__FILE__, '.php'), 'saved_notice_no_go_decisions', 'saved_notice_ai_requirement_revisions');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION saved_notice_no_go_decisions_keep_history() RETURNS trigger AS $$
            DECLARE
                reopening boolean;
                reopen_columns text[] := ARRAY['reopened_by_user_id', 'reopen_reason', 'reopened_at', 'reopened_from_archived_at', 'reopened_from_history_type'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM saved_notices WHERE id = OLD.saved_notice_id)
                    THEN
                        RAISE EXCEPTION 'saved_notice_no_go_decisions is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                reopening := OLD.reopened_at IS NULL AND NEW.reopened_at IS NOT NULL;

                IF (to_jsonb(NEW) - reopen_columns - 'closed_by_user_id' - 'updated_at')
                        IS DISTINCT FROM (to_jsonb(OLD) - reopen_columns - 'closed_by_user_id' - 'updated_at')
                    -- the decider: kept, or nulled when the user is deleted
                    OR (NEW.closed_by_user_id IS NOT NULL AND NEW.closed_by_user_id IS DISTINCT FROM OLD.closed_by_user_id)
                    -- reopened once, then never re-dated, re-argued or re-attributed
                    OR (NOT reopening AND (
                        NEW.reopen_reason IS DISTINCT FROM OLD.reopen_reason
                        OR NEW.reopened_at IS DISTINCT FROM OLD.reopened_at
                        OR NEW.reopened_from_archived_at IS DISTINCT FROM OLD.reopened_from_archived_at
                        OR NEW.reopened_from_history_type IS DISTINCT FROM OLD.reopened_from_history_type
                        OR (NEW.reopened_by_user_id IS NOT NULL AND NEW.reopened_by_user_id IS DISTINCT FROM OLD.reopened_by_user_id)
                    ))
                THEN
                    RAISE EXCEPTION 'saved_notice_no_go_decisions keeps the No-Go as decided; it can only be reopened, once';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saved_notice_no_go_decisions_keep_history
                BEFORE UPDATE OR DELETE ON saved_notice_no_go_decisions
                FOR EACH ROW EXECUTE FUNCTION saved_notice_no_go_decisions_keep_history();

            CREATE OR REPLACE FUNCTION saved_notice_ai_requirement_revisions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM saved_notices WHERE id = OLD.saved_notice_id)
                        AND EXISTS (SELECT 1 FROM saved_notice_ai_requirements WHERE id = OLD.saved_notice_ai_requirement_id)
                    THEN
                        RAISE EXCEPTION 'saved_notice_ai_requirement_revisions is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the person is the one change allowed.
                IF (to_jsonb(NEW) - 'changed_by_user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'changed_by_user_id')
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'saved_notice_ai_requirement_revisions is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saved_notice_ai_requirement_revisions_immutable
                BEFORE UPDATE OR DELETE ON saved_notice_ai_requirement_revisions
                FOR EACH ROW EXECUTE FUNCTION saved_notice_ai_requirement_revisions_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS saved_notice_ai_requirement_revisions_immutable ON saved_notice_ai_requirement_revisions;
            DROP FUNCTION IF EXISTS saved_notice_ai_requirement_revisions_immutable();
            DROP TRIGGER IF EXISTS saved_notice_no_go_decisions_keep_history ON saved_notice_no_go_decisions;
            DROP FUNCTION IF EXISTS saved_notice_no_go_decisions_keep_history();
            SQL);
    }
};
