<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 step 19 (docs/external-api-phase-0-plan.md §4.8, §2m): the database refuses an integration
 * identity (users.role = 'integration') in every column that names the person who took a human
 * decision.
 *
 * HumanDecisionGuard refuses integrations in the services; this is the same rule one layer down, so
 * no second way in — a job, a command, a future API path that forgets the guard — can record a
 * machine as the one who approved, assessed, accepted, closed or decided.
 *
 * - One shared check, assert_human_decision_actor(actor, column), and one trigger function that
 *   applies it to the columns named in the trigger's arguments.
 * - One trigger per table, named human_decision_actor, BEFORE INSERT OR UPDATE OF those columns.
 * - Only a value that is new is checked: a row whose actor is unchanged passes, and NULL always
 *   passes, so nullOnDelete keeps working and a decision copied to a new version keeps its person.
 * - Only role = 'integration' is refused. A NULL role is a person (older rows and fixtures).
 *
 * The list is the plan's §4.8 plus the decision logs added in steps 5–18 whose every write is a
 * guarded decision. Tables also written by ordinary register work (for example a case moving to
 * «Under arbeid») are not here: an integration may one day draft that work.
 *
 * tests/Feature/Security/HumanDecisionActorTriggerTest.php holds the same list and compares it with
 * the catalog.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    public const COLUMNS = [
        // Enterprise Wiki
        'enterprise_wiki_pages' => ['reviewed_by_user_id'],
        'enterprise_wiki_page_versions' => ['submitted_by_user_id', 'reviewer_user_id', 'qa_assigned_by_user_id'],
        'enterprise_wiki_claims' => ['approved_by_user_id', 'blocking_override_by_user_id'],
        'enterprise_wiki_claim_decisions' => ['decided_by_user_id'],
        'enterprise_wiki_page_version_document_owner_approvals' => ['decided_by_user_id', 'overridden_by_user_id'],
        'enterprise_wiki_document_owner_decisions' => ['decided_by_user_id'],
        'enterprise_wiki_page_review_events' => ['actor_user_id'],
        // Risiko
        'risk_acceptances' => ['accepted_by_user_id', 'revoked_by_user_id'],
        'risk_assessments' => ['assessed_by'],
        // Avvik og forbedringer
        'improvement_cases' => ['closed_by_user_id'],
        'improvement_action_verifications' => ['verified_by_user_id'],
        // Mål og KPI
        'kpi_measurements' => ['recorded_by_user_id', 'withdrawn_by_user_id'],
        'objectives' => ['closed_by_user_id'],
        'objective_status_changes' => ['changed_by_user_id'],
        'kpi_status_changes' => ['changed_by_user_id'],
        // Etterlevelse og revisjon
        'compliance_assessments' => ['assessed_by_user_id'],
        'compliance_audit_status_changes' => ['changed_by_user_id'],
        'compliance_audit_finding_changes' => ['changed_by_user_id'],
        'compliance_requirements' => ['applicability_set_by'],
        'compliance_requirement_applicability_changes' => ['changed_by_user_id'],
        // Leverandøroppfølging
        'supplier_assessments' => ['assessed_by_user_id'],
        'supplier_requirement_evaluations' => ['evaluated_by_user_id'],
        'supplier_requirement_overrides' => ['created_by_user_id'],
        'supplier_due_diligence_assessments' => ['assessed_by_user_id'],
        'supplier_criticality_changes' => ['changed_by_user_id'],
        'supplier_status_changes' => ['changed_by_user_id'],
        'supplier_control_requirement_status_changes' => ['changed_by_user_id'],
        'supplier_assurance_decisions' => ['decided_by_user_id'],
        // Ledelsens gjennomgang
        'management_reviews' => ['finalized_by_user_id'],
        'management_review_amendments' => ['created_by_user_id'],
        // Kvalitet
        'quality_process_revisions' => ['approved_by_user_id'],
        'quality_process_blueprints' => ['approved_by_user_id'],
        // Anbud
        'saved_notice_ai_requirements' => ['approved_by_user_id', 'rejected_by_user_id'],
        'saved_notice_status_changes' => ['changed_by_user_id'],
        'saved_notice_no_go_decisions' => ['closed_by_user_id', 'reopened_by_user_id'],
        // Tilgang (F4)
        'customer_access_changes' => ['actor_user_id'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_human_decision_actor(actor_id bigint, decision_column text) RETURNS void AS $$
            BEGIN
                IF actor_id IS NOT NULL AND EXISTS (SELECT 1 FROM users WHERE id = actor_id AND role = 'integration') THEN
                    RAISE EXCEPTION 'An integration cannot be recorded as the person who took a human decision (%).', decision_column
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_integration_decision_actor() RETURNS trigger AS $$
            DECLARE
                decision_column text;
                new_actor bigint;
                old_actor bigint;
            BEGIN
                FOREACH decision_column IN ARRAY TG_ARGV LOOP
                    new_actor := (to_jsonb(NEW) ->> decision_column)::bigint;
                    old_actor := CASE WHEN TG_OP = 'UPDATE' THEN (to_jsonb(OLD) ->> decision_column)::bigint END;

                    IF new_actor IS DISTINCT FROM old_actor THEN
                        PERFORM assert_human_decision_actor(new_actor, TG_TABLE_NAME || '.' || decision_column);
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        foreach (self::COLUMNS as $table => $columns) {
            $list = implode(', ', $columns);
            $arguments = implode(', ', array_map(static fn (string $column): string => "'{$column}'", $columns));

            DB::unprepared("DROP TRIGGER IF EXISTS human_decision_actor ON {$table}");
            DB::unprepared("CREATE TRIGGER human_decision_actor BEFORE INSERT OR UPDATE OF {$list} ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_integration_decision_actor({$arguments})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Every trigger of this name, not only the tables listed now: a table taken off the list
        // must not keep a trigger nothing removes.
        $tables = DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE t.tgname = 'human_decision_actor' AND n.nspname = current_schema()
            SQL);

        foreach ($tables as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS human_decision_actor ON {$table->name}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS reject_integration_decision_actor()');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_human_decision_actor(bigint, text)');
    }
};
