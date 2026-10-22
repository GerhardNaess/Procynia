<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kvalitet: a process or control another module points at is retired, never deleted — findings
 * A19 and A28, decided 2026-10-22 (docs/external-api-phase-0-plan.md §2h).
 *
 * Risiko, Mål og KPI, Avvik og forbedringer and Etterlevelse og revisjon link to Kvalitet's
 * processes (and their activities) and controls. Deleting the item cascaded those links away, or
 * nulled the item on an audit finding. The application now refuses it with a general message
 * (QualityItemService::deletionBlockers()); this trigger holds the database to the same list, so no
 * other way in — SQL, a job, a future API — can do it either. The item is set to «Utgått» instead.
 *
 * Deleting the linking record in its own module (a risk, a requirement, a case) is that module's
 * decision and still takes its link along. The customer's own deletion takes everything along,
 * recognised as elsewhere: the customer row is already gone when the cascade arrives. No row is
 * changed by this migration.
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
            CREATE OR REPLACE FUNCTION quality_items_keep_cross_module_links() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                    RETURN OLD;
                END IF;

                IF OLD.quality_type = 'process' AND (
                    EXISTS (SELECT 1 FROM risk_processes WHERE quality_item_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM risk_activities WHERE quality_item_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM kpi_processes WHERE quality_process_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM kpi_activities WHERE quality_process_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM improvement_case_processes WHERE quality_process_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM improvement_case_activities WHERE quality_process_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM compliance_requirement_processes WHERE quality_process_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM compliance_audit_processes WHERE quality_process_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM compliance_audit_findings WHERE quality_process_id = OLD.id)
                ) THEN
                    RAISE EXCEPTION 'quality item % is in use elsewhere and cannot be deleted; retire it instead', OLD.id;
                END IF;

                IF OLD.quality_type = 'control' AND (
                    EXISTS (SELECT 1 FROM risk_controls WHERE quality_item_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM compliance_requirement_controls WHERE control_item_id = OLD.id)
                    OR EXISTS (SELECT 1 FROM compliance_audit_findings WHERE control_item_id = OLD.id)
                ) THEN
                    RAISE EXCEPTION 'quality item % is in use elsewhere and cannot be deleted; retire it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quality_items_keep_cross_module_links
                BEFORE DELETE ON quality_items
                FOR EACH ROW EXECUTE FUNCTION quality_items_keep_cross_module_links();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS quality_items_keep_cross_module_links ON quality_items;
            DROP FUNCTION IF EXISTS quality_items_keep_cross_module_links();
            SQL);
    }
};
