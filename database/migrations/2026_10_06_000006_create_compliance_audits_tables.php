<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etterlevelse og revisjon — revisjoner: a planned review of whether the virksomhet actually
 * complies, internal or external, with one person responsible for it inside the virksomhet.
 *
 * scope_description is the authoritative scope, and required. The requirements and Kvalitet
 * processes linked to the audit (next migration) support it with structure and navigation; they
 * never replace it.
 *
 * status is the lifecycle — planned, in_progress, completed, cancelled — and never a form field.
 * ComplianceAuditLifecycleService is the only writer, and every change is in
 * compliance_audit_status_changes:
 *
 *   planned     → in_progress   Start revisjon
 *   in_progress → completed     Fullfør revisjon (konklusjon required)
 *   planned     → cancelled     Avbryt revisjon (begrunnelse required)
 *   in_progress → cancelled     Avbryt revisjon (begrunnelse required)
 *   completed   → in_progress   Gjenåpne revisjon (begrunnelse required)
 *
 * A completed audit always carries its conclusion — the database holds that line too. There is no
 * actual start or end date: the status history says when each transition happened.
 *
 * planned_end_date is required (it is what a later «forfalt» signal will be measured against);
 * planned_start_date is optional, and never after the end.
 *
 * responsible_user_id is required by the forms but nulled when that user is deleted. auditor_name
 * is free text for an external auditor or audit firm; there is no auditor register in v1.
 *
 * The status history follows the requirement history exactly: append-only, refused by trigger on
 * UPDATE (except the author FK being nulled) and on DELETE while the customer exists — so an audit
 * that has ever moved can never be deleted, only cancelled.
 *
 * (id, customer_id) is unique so that history rows and scope links reference the pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('audit_type');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auditor_name')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date');
            $table->text('scope_description');
            $table->text('conclusion')->nullable();
            $table->string('status')->default('planned');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'customer_id']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('compliance_audit_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id');
            $table->string('from_status');
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->foreign(['audit_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_audits')->cascadeOnDelete();
            $table->index(['audit_id', 'changed_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_status_check CHECK (status IN ('planned', 'in_progress', 'completed', 'cancelled'))");
            DB::statement("ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_type_check CHECK (audit_type IN ('internal', 'external'))");
            DB::statement('ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_text_check CHECK (length(btrim(title)) > 0 AND length(btrim(scope_description)) > 0)');
            DB::statement('ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_dates_check CHECK (planned_start_date IS NULL OR planned_end_date >= planned_start_date)');
            DB::statement("ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_conclusion_check CHECK (status <> 'completed' OR (conclusion IS NOT NULL AND length(btrim(conclusion)) > 0))");

            DB::statement('ALTER TABLE compliance_audit_status_changes ADD CONSTRAINT compliance_audit_status_changes_transition CHECK ('
                ."(from_status = 'planned' AND to_status = 'in_progress')"
                ." OR (from_status = 'in_progress' AND to_status = 'completed')"
                ." OR (from_status = 'planned' AND to_status = 'cancelled')"
                ." OR (from_status = 'in_progress' AND to_status = 'cancelled')"
                ." OR (from_status = 'completed' AND to_status = 'in_progress'))");
            // Avbryt and Gjenåpne always say why; Start and Fullfør may.
            DB::statement('ALTER TABLE compliance_audit_status_changes ADD CONSTRAINT compliance_audit_status_changes_reason CHECK ('
                ."(to_status <> 'cancelled' AND from_status <> 'completed') OR (reason IS NOT NULL AND length(btrim(reason)) > 0))");

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
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
                        OR NEW.changed_at IS DISTINCT FROM OLD.changed_at
                        OR (NEW.changed_by_user_id IS NOT NULL AND NEW.changed_by_user_id IS DISTINCT FROM OLD.changed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'compliance_audit_status_changes is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER compliance_audit_status_changes_immutable
                    BEFORE UPDATE OR DELETE ON compliance_audit_status_changes
                    FOR EACH ROW EXECUTE FUNCTION compliance_audit_status_changes_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_audit_status_changes');
        Schema::dropIfExists('compliance_audits');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS compliance_audit_status_changes_immutable()');
        }
    }
};
