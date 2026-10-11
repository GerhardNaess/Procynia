<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kravbiblioteket — a third normative character for library requirements: control.
 *
 * ISO/IEC 27001 Annex A controls are not requirements every organisation must meet; each is
 * selected or left out in the risk treatment, and both choices are justified in the Statement of
 * Applicability. applicability_rule = 'control' marks such a node. The customer's choice is the
 * same applicability column as before (applies = inkludert, not_applicable = utelatt), so nothing
 * else in the schema changes. See ComplianceFrameworkNode::RULE_CONTROL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE compliance_framework_nodes DROP CONSTRAINT IF EXISTS compliance_framework_nodes_applicability_rule_check');
        DB::statement("ALTER TABLE compliance_framework_nodes ADD CONSTRAINT compliance_framework_nodes_applicability_rule_check CHECK (applicability_rule IS NULL OR applicability_rule IN ('mandatory', 'conditional', 'control'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // NOT VALID: control nodes already imported stay readable; only new rows are held to the old rule.
        DB::statement('ALTER TABLE compliance_framework_nodes DROP CONSTRAINT IF EXISTS compliance_framework_nodes_applicability_rule_check');
        DB::statement("ALTER TABLE compliance_framework_nodes ADD CONSTRAINT compliance_framework_nodes_applicability_rule_check CHECK (applicability_rule IS NULL OR applicability_rule IN ('mandatory', 'conditional')) NOT VALID");
    }
};
