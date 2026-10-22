<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kvalitet: an approved process version is never lost — phase 0 step 12 (docs/external-api-phase-0-plan.md §2f).
 *
 * QualityProcessRevision refused update and delete in the model, but the model's guards never run
 * for SQL or for a cascade, and quality_process_revisions cascades from quality_items: deleting a
 * process — in SQL, or through QualityItemService — took every approved version with it.
 *
 * The application's rule is now that a process with an approved version is retired («Utgått»), not
 * deleted (QualityItemService::deleteItem). These triggers hold the database to it:
 *
 * - quality_items: a process is not deleted while it has an approved version.
 * - quality_process_revisions: never changed, and never deleted on its own. The one change allowed
 *   is the foreign key letting go of a deleted user (approved_by_user_id set to null); the name of
 *   the person who approved stays in approved_by_name.
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
            CREATE OR REPLACE FUNCTION quality_items_keep_approved_versions() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM quality_process_revisions WHERE quality_item_id = OLD.id)
                THEN
                    RAISE EXCEPTION 'quality item % has an approved process version and cannot be deleted; retire it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quality_items_keep_approved_versions
                BEFORE DELETE ON quality_items
                FOR EACH ROW EXECUTE FUNCTION quality_items_keep_approved_versions();

            CREATE OR REPLACE FUNCTION quality_process_revisions_keep() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.approved_by_user_id IS NULL
                        AND (to_jsonb(NEW) - 'approved_by_user_id') = (to_jsonb(OLD) - 'approved_by_user_id')
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'quality_process_revisions is an approved version and cannot be changed';
                END IF;

                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM quality_items WHERE id = OLD.quality_item_id)
                THEN
                    RAISE EXCEPTION 'quality_process_revisions is an approved version and cannot be deleted';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quality_process_revisions_keep
                BEFORE UPDATE OR DELETE ON quality_process_revisions
                FOR EACH ROW EXECUTE FUNCTION quality_process_revisions_keep();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS quality_process_revisions_keep ON quality_process_revisions;
            DROP FUNCTION IF EXISTS quality_process_revisions_keep();
            DROP TRIGGER IF EXISTS quality_items_keep_approved_versions ON quality_items;
            DROP FUNCTION IF EXISTS quality_items_keep_approved_versions();
            SQL);
    }
};
