<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Avvik og forbedringer: history is never lost — phase 0 step 10 (docs/external-api-phase-0-plan.md §2d).
 *
 * The history tables were guarded against UPDATE only. A DELETE in SQL removed history rows, and
 * deleting a case or a tiltak — in SQL, or through Eloquent past ImprovementCase::isDeletable() —
 * cascaded its whole history away; the models' guards never run for a cascade.
 *
 * The application's own rule is unchanged: a case is deleted only when still open with no status
 * change and no tiltak (and nothing pointing at it), a tiltak only when still planned with no status
 * change. These triggers hold the database to the history part of that rule:
 *
 * - improvement_cases: not deleted while it has a status change or a tiltak.
 * - improvement_actions: not deleted while it has a status change or a verification.
 * - improvement_case_status_changes, improvement_action_status_changes,
 *   improvement_action_verifications: never deleted on their own, only with their case or tiltak.
 *
 * The customer's own deletion takes everything along, recognised as elsewhere: the customer row is
 * already gone when the cascade arrives. No row is changed by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION improvement_cases_keep_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND (
                        EXISTS (SELECT 1 FROM improvement_case_status_changes WHERE improvement_case_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM improvement_actions WHERE improvement_case_id = OLD.id)
                    )
                THEN
                    RAISE EXCEPTION 'improvement case % has history or tiltak and cannot be deleted; cancel it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER improvement_cases_keep_history
                BEFORE DELETE ON improvement_cases
                FOR EACH ROW EXECUTE FUNCTION improvement_cases_keep_history();

            CREATE OR REPLACE FUNCTION improvement_actions_keep_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM improvement_cases WHERE id = OLD.improvement_case_id)
                    AND (
                        EXISTS (SELECT 1 FROM improvement_action_status_changes WHERE improvement_action_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM improvement_action_verifications WHERE improvement_action_id = OLD.id)
                    )
                THEN
                    RAISE EXCEPTION 'improvement action % has history and cannot be deleted; cancel it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER improvement_actions_keep_history
                BEFORE DELETE ON improvement_actions
                FOR EACH ROW EXECUTE FUNCTION improvement_actions_keep_history();

            CREATE OR REPLACE FUNCTION improvement_case_status_changes_keep() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM improvement_cases WHERE id = OLD.improvement_case_id)
                THEN
                    RAISE EXCEPTION 'improvement_case_status_changes is history and cannot be deleted';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER improvement_case_status_changes_keep
                BEFORE DELETE ON improvement_case_status_changes
                FOR EACH ROW EXECUTE FUNCTION improvement_case_status_changes_keep();

            CREATE OR REPLACE FUNCTION improvement_action_history_keep() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM improvement_actions WHERE id = OLD.improvement_action_id)
                THEN
                    RAISE EXCEPTION '% is history and cannot be deleted', TG_TABLE_NAME;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER improvement_action_status_changes_keep
                BEFORE DELETE ON improvement_action_status_changes
                FOR EACH ROW EXECUTE FUNCTION improvement_action_history_keep();

            CREATE TRIGGER improvement_action_verifications_keep
                BEFORE DELETE ON improvement_action_verifications
                FOR EACH ROW EXECUTE FUNCTION improvement_action_history_keep();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS improvement_action_verifications_keep ON improvement_action_verifications;
            DROP TRIGGER IF EXISTS improvement_action_status_changes_keep ON improvement_action_status_changes;
            DROP FUNCTION IF EXISTS improvement_action_history_keep();
            DROP TRIGGER IF EXISTS improvement_case_status_changes_keep ON improvement_case_status_changes;
            DROP FUNCTION IF EXISTS improvement_case_status_changes_keep();
            DROP TRIGGER IF EXISTS improvement_actions_keep_history ON improvement_actions;
            DROP FUNCTION IF EXISTS improvement_actions_keep_history();
            DROP TRIGGER IF EXISTS improvement_cases_keep_history ON improvement_cases;
            DROP FUNCTION IF EXISTS improvement_cases_keep_history();
            SQL);
    }
};
