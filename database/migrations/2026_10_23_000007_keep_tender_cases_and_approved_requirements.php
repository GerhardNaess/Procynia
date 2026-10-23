<?php

use App\Support\MigrationSchemaPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anbud: a case with decisions, and a requirement that has been approved, are never deleted — A42,
 * docs/external-api-phase-0-plan.md §2l.
 *
 * 2026_10_23_000006 kept the decision rows themselves, but each still went along when its
 * requirement or case was deleted. The rule now matches SavedNotice::isDeletable() and
 * SavedNoticeAiRequirement::hasBeenApproved():
 *
 * - saved_notice_ai_requirements: not deleted once approved (approval_status, approved_at or an
 *   «approve» revision). A requirement only rejected or never decided — extraction residue — is
 *   deleted as before, and staged rows of a running extraction are never approved.
 * - saved_notices: not deleted while it has a recorded status change, a No-Go decision, a
 *   requirement that has been approved, or AI usage counted against the customer
 *   (customer_ai_case_usages, which would otherwise go along by cascade and give the credit back).
 *
 * A case saved by mistake is deleted as before. The customer's own deletion takes everything
 * along: the customer row (and, for a requirement, the case row) is already gone when the cascade
 * arrives. No row is changed by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationSchemaPrecondition::requireTables(
            basename(__FILE__, '.php'),
            'saved_notices',
            'saved_notice_ai_requirements',
            'saved_notice_ai_requirement_revisions',
            'saved_notice_status_changes',
            'saved_notice_no_go_decisions',
            'customer_ai_case_usages',
        );

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION saved_notice_ai_requirements_keep_approved() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM saved_notices WHERE id = OLD.saved_notice_id)
                    AND (
                        OLD.approval_status = 'approved'
                        OR OLD.approved_at IS NOT NULL
                        OR EXISTS (
                            SELECT 1 FROM saved_notice_ai_requirement_revisions
                            WHERE saved_notice_ai_requirement_id = OLD.id AND change_type = 'approve'
                        )
                    )
                THEN
                    RAISE EXCEPTION 'requirement % has been approved and cannot be deleted; reject it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saved_notice_ai_requirements_keep_approved
                BEFORE DELETE ON saved_notice_ai_requirements
                FOR EACH ROW EXECUTE FUNCTION saved_notice_ai_requirements_keep_approved();

            CREATE OR REPLACE FUNCTION saved_notices_keep_decision_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND (
                        EXISTS (SELECT 1 FROM saved_notice_status_changes WHERE saved_notice_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM saved_notice_no_go_decisions WHERE saved_notice_id = OLD.id)
                        OR EXISTS (
                            SELECT 1 FROM saved_notice_ai_requirements
                            WHERE saved_notice_id = OLD.id AND (approval_status = 'approved' OR approved_at IS NOT NULL)
                        )
                        OR EXISTS (
                            SELECT 1 FROM saved_notice_ai_requirement_revisions
                            WHERE saved_notice_id = OLD.id AND change_type = 'approve'
                        )
                        OR EXISTS (SELECT 1 FROM customer_ai_case_usages WHERE saved_notice_id = OLD.id)
                    )
                THEN
                    RAISE EXCEPTION 'case % has decisions or AI usage and cannot be deleted; move it to history instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saved_notices_keep_decision_history
                BEFORE DELETE ON saved_notices
                FOR EACH ROW EXECUTE FUNCTION saved_notices_keep_decision_history();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS saved_notices_keep_decision_history ON saved_notices;
            DROP FUNCTION IF EXISTS saved_notices_keep_decision_history();
            DROP TRIGGER IF EXISTS saved_notice_ai_requirements_keep_approved ON saved_notice_ai_requirements;
            DROP FUNCTION IF EXISTS saved_notice_ai_requirements_keep_approved();
            SQL);
    }
};
