<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A34 (docs/external-api-phase-0-plan.md §2i), in the existing history pattern.
 *
 * compliance_audit_status_changes.conclusion: the conclusion each Fullfør was given, on the
 * completion's own immutable row. A reopened audit is edited as before; what it concluded each time
 * stays. The latest completion of an audit that is completed now gets the audit's conclusion — a
 * completed audit cannot be edited, so it is the one it was completed with. Earlier completions of
 * a reopened audit were never kept and stay empty.
 *
 * compliance_audit_findings: a finding that was there when the audit was completed (created at or
 * before a completion) is never deleted while the customer exists. It can still be corrected.
 *
 * compliance_audit_finding_changes: each correction of such a finding — the values before, who and
 * when. Append-only.
 *
 * Every history table refuses UPDATE and DELETE while the customer exists; only a deleted user's
 * foreign key may become NULL. OR REPLACE: migrate:fresh drops tables but leaves functions behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_audit_status_changes', function (Blueprint $table): void {
            $table->text('conclusion')->nullable();
        });

        Schema::create('compliance_audit_finding_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id');
            $table->foreignId('finding_id')->constrained('compliance_audit_findings')->cascadeOnDelete();
            $table->jsonb('previous');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['finding_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Before the trigger knows the column: the conclusion the completed audits were completed with.
        DB::statement(<<<'SQL'
            UPDATE compliance_audit_status_changes AS change
            SET conclusion = audit.conclusion
            FROM compliance_audits AS audit
            WHERE audit.id = change.audit_id
              AND audit.status = 'completed'
              AND change.to_status = 'completed'
              AND change.id = (
                  SELECT max(latest.id) FROM compliance_audit_status_changes AS latest
                  WHERE latest.audit_id = change.audit_id AND latest.to_status = 'completed'
              )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compliance_audit_status_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'compliance_audit_status_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.audit_id IS DISTINCT FROM OLD.audit_id
                    OR NEW.from_status IS DISTINCT FROM OLD.from_status
                    OR NEW.to_status IS DISTINCT FROM OLD.to_status
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.conclusion IS DISTINCT FROM OLD.conclusion
                    OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'compliance_audit_status_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION compliance_audit_findings_keep_completed() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id)
                    AND EXISTS (
                        SELECT 1 FROM compliance_audit_status_changes
                        WHERE audit_id = OLD.audit_id
                          AND to_status = 'completed'
                          AND changed_at >= OLD.created_at
                    )
                THEN
                    RAISE EXCEPTION 'compliance audit finding % was part of a completed audit and cannot be deleted', OLD.id;
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER compliance_audit_findings_keep_completed
                BEFORE DELETE ON compliance_audit_findings
                FOR EACH ROW EXECUTE FUNCTION compliance_audit_findings_keep_completed();

            CREATE OR REPLACE FUNCTION compliance_audit_finding_changes_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'compliance_audit_finding_changes is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.audit_id IS DISTINCT FROM OLD.audit_id
                    OR NEW.finding_id IS DISTINCT FROM OLD.finding_id
                    OR NEW.previous IS DISTINCT FROM OLD.previous
                    OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                    OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                THEN
                    RAISE EXCEPTION 'compliance_audit_finding_changes is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER compliance_audit_finding_changes_immutable
                BEFORE UPDATE OR DELETE ON compliance_audit_finding_changes
                FOR EACH ROW EXECUTE FUNCTION compliance_audit_finding_changes_immutable();

            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS compliance_audit_findings_keep_completed ON compliance_audit_findings;
                DROP FUNCTION IF EXISTS compliance_audit_findings_keep_completed();
                DROP TRIGGER IF EXISTS compliance_audit_finding_changes_immutable ON compliance_audit_finding_changes;
                DROP FUNCTION IF EXISTS compliance_audit_finding_changes_immutable();

                CREATE OR REPLACE FUNCTION compliance_audit_status_changes_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                            RAISE EXCEPTION 'compliance_audit_status_changes is history and cannot be deleted';
                        END IF;

                        RETURN OLD;
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.audit_id IS DISTINCT FROM OLD.audit_id
                        OR NEW.from_status IS DISTINCT FROM OLD.from_status
                        OR NEW.to_status IS DISTINCT FROM OLD.to_status
                        OR NEW.reason IS DISTINCT FROM OLD.reason
                        OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                        OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'compliance_audit_status_changes is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);
        }

        Schema::dropIfExists('compliance_audit_finding_changes');

        // The trigger no longer looks at the column, so it can go.
        Schema::table('compliance_audit_status_changes', function (Blueprint $table): void {
            $table->dropColumn('conclusion');
        });
    }
};
