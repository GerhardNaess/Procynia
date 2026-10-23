<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mål og KPI: measurements and status history are never lost — phase 0 step 15
 * (docs/external-api-phase-0-plan.md §2k).
 *
 * The history was guarded by the models only (KpiMeasurement, ObjectiveStatusChange,
 * KpiStatusChange). SQL that bypasses them changed or deleted history rows, and deleting a KPI or an
 * objective — in SQL, or through Eloquent past isDeletable() — cascaded its measurements away; the
 * models' guards never run for a cascade.
 *
 * The application's own rule is unchanged: a KPI with a measurement, withdrawn or not, is retired and
 * never deleted (Kpi::isDeletable()), and an objective is deleted only while none of its KPIs has one
 * (Objective::isDeletable()). These triggers hold the database to the same rule:
 *
 * - kpis: not deleted while it has a measurement. An objective's deletion reaches its KPIs by
 *   cascade, so an objective whose KPIs carry measurements cannot be deleted either.
 * - kpi_measurements: never deleted on its own, only with its KPI or customer. Changed only by being
 *   withdrawn, once (the withdrawal columns from empty to set), and by nulling a deleted user — the
 *   same rules as KpiMeasurement::booted(), now also for SQL that bypasses the model.
 * - objective_status_changes, kpi_status_changes: never changed, except nulling a deleted user;
 *   deleted only with their objective or KPI.
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

            CREATE TRIGGER kpis_keep_measurement_history
                BEFORE DELETE ON kpis
                FOR EACH ROW EXECUTE FUNCTION kpis_keep_measurement_history();

            CREATE OR REPLACE FUNCTION kpi_measurements_immutable() RETURNS trigger AS $$
            DECLARE
                withdrawing boolean;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM kpis WHERE id = OLD.kpi_id)
                    THEN
                        RAISE EXCEPTION 'kpi_measurements is history and cannot be deleted; withdraw the measurement instead';
                    END IF;

                    RETURN OLD;
                END IF;

                withdrawing := OLD.withdrawn_at IS NULL AND NEW.withdrawn_at IS NOT NULL;

                IF (to_jsonb(NEW) - ARRAY['recorded_by_user_id', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['recorded_by_user_id', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason'])
                    -- the people: kept, or nulled when the user is deleted
                    OR (NEW.recorded_by_user_id IS NOT NULL AND NEW.recorded_by_user_id IS DISTINCT FROM OLD.recorded_by_user_id)
                    OR (NOT withdrawing AND NEW.withdrawn_by_user_id IS NOT NULL AND NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id)
                    -- withdrawn once, never un-withdrawn, re-dated or re-argued
                    OR (NOT withdrawing AND NEW.withdrawn_at IS DISTINCT FROM OLD.withdrawn_at)
                    OR (NOT withdrawing AND NEW.withdrawal_reason IS DISTINCT FROM OLD.withdrawal_reason)
                THEN
                    RAISE EXCEPTION 'kpi_measurements can only be withdrawn, once; register a correction instead';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER kpi_measurements_immutable
                BEFORE UPDATE OR DELETE ON kpi_measurements
                FOR EACH ROW EXECUTE FUNCTION kpi_measurements_immutable();

            CREATE OR REPLACE FUNCTION objective_status_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM objectives WHERE id = OLD.objective_id)
                    THEN
                        RAISE EXCEPTION 'objective_status_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the person is the one change allowed.
                IF (to_jsonb(NEW) - 'changed_by_user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'changed_by_user_id')
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'objective_status_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER objective_status_changes_immutable
                BEFORE UPDATE OR DELETE ON objective_status_changes
                FOR EACH ROW EXECUTE FUNCTION objective_status_changes_immutable();

            CREATE OR REPLACE FUNCTION kpi_status_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM kpis WHERE id = OLD.kpi_id)
                    THEN
                        RAISE EXCEPTION 'kpi_status_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the person is the one change allowed.
                IF (to_jsonb(NEW) - 'changed_by_user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'changed_by_user_id')
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'kpi_status_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER kpi_status_changes_immutable
                BEFORE UPDATE OR DELETE ON kpi_status_changes
                FOR EACH ROW EXECUTE FUNCTION kpi_status_changes_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS kpi_status_changes_immutable ON kpi_status_changes;
            DROP FUNCTION IF EXISTS kpi_status_changes_immutable();
            DROP TRIGGER IF EXISTS objective_status_changes_immutable ON objective_status_changes;
            DROP FUNCTION IF EXISTS objective_status_changes_immutable();
            DROP TRIGGER IF EXISTS kpi_measurements_immutable ON kpi_measurements;
            DROP FUNCTION IF EXISTS kpi_measurements_immutable();
            DROP TRIGGER IF EXISTS kpis_keep_measurement_history ON kpis;
            DROP FUNCTION IF EXISTS kpis_keep_measurement_history();
            SQL);
    }
};
