<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kvalitet: a control with registered evidence is retired, never deleted — finding A19
 * (docs/external-api-phase-0-plan.md §2f).
 *
 * Deleting a control cascaded its registered evidence (quality_item_documents, relation_type
 * evidence) — the record that the control was carried out. The application now refuses it
 * (QualityItemService::deletionBlockers()); this trigger holds the database to the same rule. The
 * control is set to «Utgått» instead, which keeps the evidence and closes it to new links.
 *
 * Links from Risiko and Etterlevelse do not block, as before: Kvalitet is not told about them
 * (RiskControlLinkTest, ComplianceQualityContextTest), and refusing on them would. Whether they
 * should is an open product decision (A19 in the plan). Placements on activities and tools are
 * Kvalitet's own structure and do not block either. The customer's own deletion takes everything
 * along, recognised as elsewhere: the customer row is already gone when the cascade arrives. No row
 * is changed by this migration.
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
            CREATE OR REPLACE FUNCTION quality_items_keep_control_history() RETURNS trigger AS $$
            BEGIN
                IF OLD.quality_type = 'control'
                    AND EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM quality_item_documents WHERE quality_item_id = OLD.id AND relation_type = 'evidence')
                THEN
                    RAISE EXCEPTION 'quality control % has registered evidence and cannot be deleted; retire it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quality_items_keep_control_history
                BEFORE DELETE ON quality_items
                FOR EACH ROW EXECUTE FUNCTION quality_items_keep_control_history();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS quality_items_keep_control_history ON quality_items;
            DROP FUNCTION IF EXISTS quality_items_keep_control_history();
            SQL);
    }
};
