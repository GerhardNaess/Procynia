<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisjonsfunn: what a revisjon found — an avvik (nonconformity), an observasjon or a
 * forbedringsmulighet (opportunity) — with a title, a description and, optionally, the requirement,
 * the Kvalitet process and the Kvalitet control it concerns.
 *
 * A finding is an observation, not a follow-up system. It has no status, severity, frist, owner,
 * tiltak or verification of its own: following it up happens in Avvik og forbedringer, by handing it
 * off explicitly to one ImprovementCase. improvement_case_id, handed_off_at and handed_off_by_user_id
 * record that hand-off, and the relation is the only provenance — nothing about the audit is copied
 * onto the case.
 *
 * TENANT BOUNDARIES are held by the database: every reference is (id, customer_id), so a finding can
 * never point at another customer's audit, requirement, process, control or case.
 *
 * WHAT HAPPENS WHEN THE OTHER SIDE GOES.
 *
 *  - Audit: cascade. An audit with findings has been started, so it has history and can never be
 *    deleted anyway; only a customer going takes it.
 *  - Requirement: NO ACTION, as for the audit's scope. A requirement a finding is about is part of
 *    what was audited; ComplianceRequirement::isDeletable() says so first. (NO ACTION rather than
 *    RESTRICT so a customer going still takes everything in one statement.)
 *  - Kvalitet process and control: SET NULL on that column only. The finding's text never
 *    disappears because Kvalitet removed something, and Kvalitet never has to know about audits.
 *  - Improvement case: NO ACTION. A case created from a finding cannot be deleted —
 *    ImprovementCase::isDeletable() says so first — so the hand-off can never dangle. A case is
 *    cancelled instead, which is its normal ending anyway.
 *
 * That quality_process_id is a `process` and control_item_id a `control` is a rule the keys cannot
 * express; a trigger holds it, after the service has already checked.
 *
 * A HANDED-OFF FINDING IS FROZEN for good, even if the audit is reopened: a second trigger refuses
 * any change to it except the database itself nulling a reference whose row went, and refuses
 * deleting it while the customer exists. improvement_case_id is unique: one finding, at most one
 * case; one case, at most one finding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_audit_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id');
            $table->string('finding_type');
            $table->string('title');
            $table->text('description');
            $table->unsignedBigInteger('requirement_id')->nullable();
            $table->unsignedBigInteger('quality_process_id')->nullable();
            $table->unsignedBigInteger('control_item_id')->nullable();
            $table->unsignedBigInteger('improvement_case_id')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->foreignId('handed_off_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['audit_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_audits')->cascadeOnDelete();
            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements');
            $table->foreign(['improvement_case_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_cases');
            $table->unique('improvement_case_id');
            $table->index('audit_id');
            $table->index('requirement_id');
            $table->index('quality_process_id');
            $table->index('control_item_id');
            // What the coming revisjons-Attention asks: nonconformities not handed off.
            $table->index(['customer_id', 'finding_type', 'improvement_case_id'], 'compliance_audit_findings_attention_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // SET NULL on the Kvalitet column alone (PostgreSQL 15+): a plain SET NULL on a composite key
        // would null customer_id too.
        DB::statement('ALTER TABLE compliance_audit_findings ADD CONSTRAINT compliance_audit_findings_process_foreign '
            .'FOREIGN KEY (quality_process_id, customer_id) REFERENCES quality_items (id, customer_id) ON DELETE SET NULL (quality_process_id)');
        DB::statement('ALTER TABLE compliance_audit_findings ADD CONSTRAINT compliance_audit_findings_control_foreign '
            .'FOREIGN KEY (control_item_id, customer_id) REFERENCES quality_items (id, customer_id) ON DELETE SET NULL (control_item_id)');

        DB::statement("ALTER TABLE compliance_audit_findings ADD CONSTRAINT compliance_audit_findings_type_check CHECK (finding_type IN ('nonconformity', 'observation', 'opportunity'))");
        DB::statement('ALTER TABLE compliance_audit_findings ADD CONSTRAINT compliance_audit_findings_text_check CHECK (length(btrim(title)) > 0 AND length(btrim(description)) > 0)');
        // Handed off means both: which case, and when. handed_off_by_user_id is left out on purpose —
        // it is nulled when that user is deleted.
        DB::statement('ALTER TABLE compliance_audit_findings ADD CONSTRAINT compliance_audit_findings_handoff_check CHECK ((improvement_case_id IS NULL) = (handed_off_at IS NULL))');

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compliance_audit_findings_quality_kind() RETURNS trigger AS $$
            BEGIN
                IF NEW.quality_process_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM quality_items WHERE id = NEW.quality_process_id AND customer_id = NEW.customer_id AND quality_type = 'process'
                ) THEN
                    RAISE EXCEPTION 'compliance_audit_findings.quality_process_id must be a Kvalitet process';
                END IF;

                IF NEW.control_item_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM quality_items WHERE id = NEW.control_item_id AND customer_id = NEW.customer_id AND quality_type = 'control'
                ) THEN
                    RAISE EXCEPTION 'compliance_audit_findings.control_item_id must be a Kvalitet control';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER compliance_audit_findings_quality_kind
                BEFORE INSERT OR UPDATE OF quality_process_id, control_item_id ON compliance_audit_findings
                FOR EACH ROW EXECUTE FUNCTION compliance_audit_findings_quality_kind();

            CREATE OR REPLACE FUNCTION compliance_audit_findings_handed_off_immutable() RETURNS trigger AS $$
            BEGIN
                IF OLD.improvement_case_id IS NULL THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'a handed-off compliance audit finding cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.audit_id IS DISTINCT FROM OLD.audit_id
                    OR NEW.finding_type IS DISTINCT FROM OLD.finding_type
                    OR NEW.title IS DISTINCT FROM OLD.title
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.requirement_id IS DISTINCT FROM OLD.requirement_id
                    OR NEW.improvement_case_id IS DISTINCT FROM OLD.improvement_case_id
                    OR NEW.handed_off_at IS DISTINCT FROM OLD.handed_off_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR NEW.updated_at IS DISTINCT FROM OLD.updated_at
                    -- Only ever nulled, by the database, when the row they point at goes.
                    OR (NEW.quality_process_id IS NOT NULL AND NEW.quality_process_id IS DISTINCT FROM OLD.quality_process_id)
                    OR (NEW.control_item_id IS NOT NULL AND NEW.control_item_id IS DISTINCT FROM OLD.control_item_id)
                    OR (NEW.handed_off_by_user_id IS NOT NULL AND NEW.handed_off_by_user_id IS DISTINCT FROM OLD.handed_off_by_user_id)
                    OR (NEW.created_by IS NOT NULL AND NEW.created_by IS DISTINCT FROM OLD.created_by)
                    OR (NEW.updated_by IS NOT NULL AND NEW.updated_by IS DISTINCT FROM OLD.updated_by)
                THEN
                    RAISE EXCEPTION 'a handed-off compliance audit finding cannot be changed';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER compliance_audit_findings_handed_off_immutable
                BEFORE UPDATE OR DELETE ON compliance_audit_findings
                FOR EACH ROW EXECUTE FUNCTION compliance_audit_findings_handed_off_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_audit_findings');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS compliance_audit_findings_handed_off_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS compliance_audit_findings_quality_kind()');
        }
    }
};
