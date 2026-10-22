<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Risk history is never lost — decision B3, docs/external-api-phase-0-plan.md §4.6.
 *
 * Until now a deleted risk took its assessments, acceptances and tiltak along through ON DELETE
 * CASCADE, and the models' own guards never ran for a cascade (finding O2). The application now
 * refuses (Risk::booted(), RiskLifecycleService); these triggers make the database refuse too:
 *
 * - risks: a risk with an assessment, an acceptance (revoked ones included) or a tiltak cannot be
 *   deleted. It is closed instead. The customer's own deletion is the one exception — its
 *   history goes with it — recognised as in supplier_assurance_decisions: the customer row is
 *   already gone when the cascade reaches the risk.
 * - risk_assessments: never changed; deleted only with its risk or customer. The one change
 *   allowed is nulling a deleted assessor (nullOnDelete).
 * - risk_acceptances: changed only by being revoked, once (revoked_at and revoked_by_user_id from
 *   empty to set), and by nulling a deleted user; deleted only with its risk, assessment or customer.
 *   The same rules as RiskAcceptance::booted(), now also for SQL that bypasses the model.
 *
 * No row is changed by this migration. Existing assessments and acceptances stay as they are.
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
            CREATE OR REPLACE FUNCTION risks_keep_decision_history() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND (
                        EXISTS (SELECT 1 FROM risk_assessments WHERE risk_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM risk_acceptances WHERE risk_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM risk_treatment_actions WHERE risk_id = OLD.id)
                    )
                THEN
                    RAISE EXCEPTION 'risk % has assessments, acceptances or tiltak and cannot be deleted; close it instead', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER risks_keep_decision_history
                BEFORE DELETE ON risks
                FOR EACH ROW EXECUTE FUNCTION risks_keep_decision_history();

            CREATE OR REPLACE FUNCTION risk_assessments_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM risks WHERE id = OLD.risk_id)
                    THEN
                        RAISE EXCEPTION 'risk_assessments is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                -- nullOnDelete of the assessor is the one change allowed.
                IF (to_jsonb(NEW) - 'assessed_by') IS DISTINCT FROM (to_jsonb(OLD) - 'assessed_by')
                    OR (NEW.assessed_by IS NOT NULL AND NEW.assessed_by IS DISTINCT FROM OLD.assessed_by)
                THEN
                    RAISE EXCEPTION 'risk_assessments is history and cannot be changed; register a new assessment';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER risk_assessments_immutable
                BEFORE UPDATE OR DELETE ON risk_assessments
                FOR EACH ROW EXECUTE FUNCTION risk_assessments_immutable();

            CREATE OR REPLACE FUNCTION risk_acceptances_immutable() RETURNS trigger AS $$
            DECLARE
                revoking boolean;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                        AND EXISTS (SELECT 1 FROM risks WHERE id = OLD.risk_id)
                        AND EXISTS (SELECT 1 FROM risk_assessments WHERE id = OLD.assessment_id)
                    THEN
                        RAISE EXCEPTION 'risk_acceptances is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                revoking := OLD.revoked_at IS NULL AND NEW.revoked_at IS NOT NULL;

                IF (to_jsonb(NEW) - ARRAY['accepted_by_user_id', 'revoked_by_user_id', 'revoked_at', 'updated_at'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['accepted_by_user_id', 'revoked_by_user_id', 'revoked_at', 'updated_at'])
                    -- the people: kept, or nulled when the user is deleted
                    OR (NEW.accepted_by_user_id IS NOT NULL AND NEW.accepted_by_user_id IS DISTINCT FROM OLD.accepted_by_user_id)
                    OR (NOT revoking AND NEW.revoked_by_user_id IS NOT NULL AND NEW.revoked_by_user_id IS DISTINCT FROM OLD.revoked_by_user_id)
                    -- revoked once, never un-revoked or re-dated
                    OR (NOT revoking AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at)
                    OR (NOT revoking AND NEW.updated_at IS DISTINCT FROM OLD.updated_at)
                THEN
                    RAISE EXCEPTION 'risk_acceptances can only be revoked, once; register a new acceptance instead';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER risk_acceptances_immutable
                BEFORE UPDATE OR DELETE ON risk_acceptances
                FOR EACH ROW EXECUTE FUNCTION risk_acceptances_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS risk_acceptances_immutable ON risk_acceptances;
            DROP FUNCTION IF EXISTS risk_acceptances_immutable();
            DROP TRIGGER IF EXISTS risk_assessments_immutable ON risk_assessments;
            DROP FUNCTION IF EXISTS risk_assessments_immutable();
            DROP TRIGGER IF EXISTS risks_keep_decision_history ON risks;
            DROP FUNCTION IF EXISTS risks_keep_decision_history();
            SQL);
    }
};
