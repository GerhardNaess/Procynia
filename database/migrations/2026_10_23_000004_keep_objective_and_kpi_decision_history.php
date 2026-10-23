<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mål og KPI: a closing or a retirement is never deleted with its record — A40,
 * docs/external-api-phase-0-plan.md §2k.
 *
 * 2026_10_23_000003 kept measurements: a KPI with one, or an objective whose KPIs had one, could not
 * be deleted. An objective closed as Nådd, Ikke nådd or Avbrutt, or a KPI that had been retired,
 * could still be deleted while it had no measurements, and its status history went along by
 * cascade. Closing and retiring are decisions (A39), so the rule now matches Objective::isDeletable()
 * and Kpi::isDeletable():
 *
 * - kpis: not deleted while it has a measurement or a status change.
 * - objectives: not deleted while it has a status change. Its KPIs' history keeps it too, through
 *   the cascade to kpis.
 *
 * Creating and editing leave no history, so an unused objective or KPI is deleted as before. The
 * customer's own deletion takes everything along: the customer row is already gone when the cascade
 * arrives. No row is changed by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kpis_keep_measurement_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND (
                        EXISTS (SELECT 1 FROM kpi_measurements WHERE kpi_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM kpi_status_changes WHERE kpi_id = OLD.id)
                    )
                THEN
                    RAISE EXCEPTION 'KPI % has measurements or a status change and cannot be deleted; retire it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION objectives_keep_decision_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM objective_status_changes WHERE objective_id = OLD.id)
                THEN
                    RAISE EXCEPTION 'objective % has been closed and cannot be deleted; keep it closed instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER objectives_keep_decision_history
                BEFORE DELETE ON objectives
                FOR EACH ROW EXECUTE FUNCTION objectives_keep_decision_history();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Back to the rule of 2026_10_23_000003: measurements only.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS objectives_keep_decision_history ON objectives;
            DROP FUNCTION IF EXISTS objectives_keep_decision_history();

            CREATE OR REPLACE FUNCTION kpis_keep_measurement_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (SELECT 1 FROM kpi_measurements WHERE kpi_id = OLD.id)
                THEN
                    RAISE EXCEPTION 'KPI % has measurements and cannot be deleted; retire it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }
};
