<?php

use App\Support\MigrationSchemaPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anbud: a case from before status changes were recorded is kept too, when the case itself shows
 * that a person worked on it — P2, docs/external-api-phase-0-plan.md §2l.
 *
 * 2026_10_23_000007 kept a case with recorded history (status change rows, a No-Go, an approved
 * requirement, AI usage). Older cases have none of those rows, so the rule now also reads what the
 * case row itself shows, as SavedNotice::isDeletable() does. Nothing is reconstructed:
 * - a bid status other than discovered: only a person's transition sets one;
 * - a registered submission (bid_submissions);
 * - an outcome recorded when it was moved to history: won, lost or no_go. «aborted» is left out,
 *   because 2026_04_06_000001 also set it on old rows without a person choosing it.
 *
 * Only the trigger function is replaced; down() puts back the 000007 version. No row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationSchemaPrecondition::requireTables(basename(__FILE__, '.php'), 'saved_notices', 'bid_submissions');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared($this->function(<<<'SQL'
                        COALESCE(OLD.bid_status, 'discovered') <> 'discovered'
                        OR OLD.history_type IN ('won', 'lost', 'no_go')
                        OR EXISTS (SELECT 1 FROM bid_submissions WHERE saved_notice_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM saved_notice_status_changes WHERE saved_notice_id = OLD.id)
            SQL, 'case % has decisions, recorded work or AI usage and cannot be deleted; move it to history instead'));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared($this->function(<<<'SQL'
                        EXISTS (SELECT 1 FROM saved_notice_status_changes WHERE saved_notice_id = OLD.id)
            SQL, 'case % has decisions or AI usage and cannot be deleted; move it to history instead'));
    }

    /** The 000007 function, with the given first conditions and message. */
    private function function(string $firstConditions, string $message): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION saved_notices_keep_decision_history() RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND (
            {$firstConditions}
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
                    RAISE EXCEPTION '{$message}', OLD.id;
                END IF;

                RETURN OLD;
            END;
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
};
