<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisjon 2.0: planning and documenting what was actually examined.
 *
 * PLANNING. purpose (Formål — why the audit is done) joins scope_description (Omfang — what it
 * covers). Both are optional free text: the omfang can be described in words, by the requirements in
 * scope, or both, so nobody has to write the same thing twice. Nothing is renamed or moved; every
 * existing scope_description is kept exactly as it was.
 *
 * KONTROLLHANDLINGER (compliance_audit_checks). What the auditor examined, how, and on what evidence:
 * a title (what was examined), an observation (what was actually looked at and seen) and optional
 * evidence references. A check is not a finding and says nothing about conformity by itself — the
 * auditor's observations and conclusion do. It is recorded, changed and deleted while the audit is
 * in progress, like a finding.
 *
 * One check may cover several requirements (compliance_audit_check_requirements), and needs none:
 * an audit examines processes and activities, rarely one clause at a time. A linked requirement
 * must be in the audit's scope — the key references the scope row itself, so a requirement cannot
 * be taken out of scope while a check still names it, and nothing outside the scope can be named.
 *
 * FINDINGS may name the check they came from (compliance_audit_findings.check_id). The key includes
 * the audit, so a finding can only point at a check of its own audit. NO ACTION: a check with
 * findings cannot be deleted, so a finding never loses where it came from. A handed-off finding's
 * check is frozen with the rest of it.
 *
 * Tenant boundaries are held by the database, as everywhere in the module: every key carries
 * customer_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_audits', function (Blueprint $table): void {
            $table->text('purpose')->nullable()->after('title');
            $table->text('scope_description')->nullable()->change();
        });

        Schema::create('compliance_audit_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id');
            $table->string('title');
            $table->text('observation');
            $table->text('evidence')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['audit_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_audits')->cascadeOnDelete();
            $table->unique(['id', 'audit_id', 'customer_id']);
            $table->index('audit_id');
            $table->index('customer_id');
        });

        Schema::create('compliance_audit_check_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id');
            $table->unsignedBigInteger('check_id');
            $table->unsignedBigInteger('requirement_id');
            $table->timestamps();

            $table->foreign(['check_id', 'audit_id', 'customer_id'])->references(['id', 'audit_id', 'customer_id'])->on('compliance_audit_checks')->cascadeOnDelete();
            // The scope row itself: only a requirement in scope, and it stays in scope while named.
            $table->foreign(['audit_id', 'requirement_id'])->references(['audit_id', 'requirement_id'])->on('compliance_audit_requirements');
            $table->unique(['check_id', 'requirement_id']);
            $table->index(['audit_id', 'requirement_id']);
            $table->index('customer_id');
        });

        Schema::table('compliance_audit_findings', function (Blueprint $table): void {
            $table->unsignedBigInteger('check_id')->nullable()->after('audit_id');
            $table->foreign(['check_id', 'audit_id', 'customer_id'])->references(['id', 'audit_id', 'customer_id'])->on('compliance_audit_checks');
            $table->index('check_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE compliance_audits DROP CONSTRAINT compliance_audits_text_check');
        DB::statement('ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_text_check CHECK (length(btrim(title)) > 0'
            .' AND (scope_description IS NULL OR length(btrim(scope_description)) > 0)'
            .' AND (purpose IS NULL OR length(btrim(purpose)) > 0))');
        DB::statement('ALTER TABLE compliance_audit_checks ADD CONSTRAINT compliance_audit_checks_text_check CHECK (length(btrim(title)) > 0 AND length(btrim(observation)) > 0 AND (evidence IS NULL OR length(btrim(evidence)) > 0))');

        DB::unprepared($this->handedOffFunction(withCheck: true));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared($this->handedOffFunction(withCheck: false));
        }

        Schema::table('compliance_audit_findings', function (Blueprint $table): void {
            $table->dropForeign(['check_id', 'audit_id', 'customer_id']);
            $table->dropIndex(['check_id']);
            $table->dropColumn('check_id');
        });

        Schema::dropIfExists('compliance_audit_check_requirements');
        Schema::dropIfExists('compliance_audit_checks');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE compliance_audits DROP CONSTRAINT compliance_audits_text_check');
        }

        // An audit planned with requirements alone has no omfang text; the old schema needs one.
        DB::table('compliance_audits')->whereNull('scope_description')->update(['scope_description' => DB::raw('COALESCE(purpose, title)')]);

        Schema::table('compliance_audits', function (Blueprint $table): void {
            $table->text('scope_description')->nullable(false)->change();
            $table->dropColumn('purpose');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_text_check CHECK (length(btrim(title)) > 0 AND length(btrim(scope_description)) > 0)');
        }
    }

    /**
     * The handed-off finding guard of 2026_10_06_000008, with check_id frozen too (or, going down,
     * exactly as it was).
     */
    private function handedOffFunction(bool $withCheck): string
    {
        $check = $withCheck ? "\n                OR NEW.check_id IS DISTINCT FROM OLD.check_id" : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION compliance_audit_findings_handed_off_immutable() RETURNS trigger AS \$\$
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
                    OR NEW.requirement_id IS DISTINCT FROM OLD.requirement_id{$check}
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
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
};
