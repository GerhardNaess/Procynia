<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kravbiblioteket — standardised requirement packages (ISO 9001 first) that Procynia owns and every
 * customer can activate into its own register.
 *
 * compliance_frameworks / compliance_framework_nodes are Procynia's, not a customer's: no
 * customer_id, written only by ComplianceFrameworkLibraryImporter (through
 * ComplianceFrameworkLibrarySeeder). One row per edition — a new edition of a standard is a new
 * framework with its own key, never an edit of the old one, so no customer's register ever mixes
 * requirements from two editions.
 *
 * A node is a section (chapter or sub-clause, kind = section) or an individually assessable
 * requirement (kind = requirement, always a leaf). node_key is the stable identity, never derived
 * from the title. A node removed from the library is withdrawn (withdrawn_at), never deleted:
 * customer requirements may still point at it.
 *
 * Activation writes ordinary customer rows — a compliance_sources row carrying framework_id and one
 * compliance_requirements row per requirement node carrying framework_node_id — so assessments,
 * audits, Kvalitet links and Trenger oppmerksomhet work on them unchanged. The partial unique
 * indexes are what make activation idempotent: one source per (customer, framework), one
 * requirement per (customer, node).
 *
 * applicability is the customer's relevance judgement of a requirement (Gjelder / Ikke relevant /
 * Ikke vurdert), kept apart from both the node's normative character (applicability_rule) and the
 * compliance assessments. A requirement registered by hand simply applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_frameworks', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('catalog_key', 100);
            $table->string('reference', 100);
            $table->string('edition', 100);
            $table->string('name');
            $table->text('description');
            $table->text('scope_note')->nullable();
            $table->boolean('content_verified')->default(false);
            $table->text('verification_note')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('compliance_framework_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('framework_id')->constrained('compliance_frameworks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('compliance_framework_nodes');
            $table->string('node_key', 150)->unique();
            $table->string('clause', 50);
            $table->string('kind', 20);
            $table->unsignedSmallInteger('depth');
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('guidance')->nullable();
            $table->json('suggested_documentation')->nullable();
            $table->string('requirement_type', 50)->nullable();
            $table->string('documented_information', 20)->nullable();
            $table->string('applicability_rule', 20)->nullable();
            $table->json('related_modules')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['framework_id', 'clause']);
            $table->index(['framework_id', 'position']);
        });

        Schema::table('compliance_sources', function (Blueprint $table): void {
            $table->foreignId('framework_id')->nullable()->after('kind')->constrained('compliance_frameworks');
        });

        Schema::table('compliance_requirements', function (Blueprint $table): void {
            $table->foreignId('framework_node_id')->nullable()->after('source_id')->constrained('compliance_framework_nodes');
            $table->string('applicability', 20)->default('applies')->after('status');
            $table->text('applicability_rationale')->nullable()->after('applicability');
            $table->foreignId('applicability_set_by')->nullable()->after('applicability_rationale')->constrained('users')->nullOnDelete();
            $table->timestamp('applicability_set_at')->nullable()->after('applicability_set_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE compliance_framework_nodes ADD CONSTRAINT compliance_framework_nodes_kind_check CHECK (kind IN ('section', 'requirement'))");
            DB::statement("ALTER TABLE compliance_framework_nodes ADD CONSTRAINT compliance_framework_nodes_requirement_fields_check CHECK (kind = 'section' OR (description IS NOT NULL AND requirement_type IS NOT NULL AND applicability_rule IS NOT NULL))");
            DB::statement("ALTER TABLE compliance_framework_nodes ADD CONSTRAINT compliance_framework_nodes_applicability_rule_check CHECK (applicability_rule IS NULL OR applicability_rule IN ('mandatory', 'conditional'))");
            DB::statement("ALTER TABLE compliance_framework_nodes ADD CONSTRAINT compliance_framework_nodes_documented_information_check CHECK (documented_information IS NULL OR documented_information IN ('maintain', 'retain', 'both'))");
            DB::statement("ALTER TABLE compliance_requirements ADD CONSTRAINT compliance_requirements_applicability_check CHECK (applicability IN ('applies', 'not_applicable', 'not_assessed'))");
            DB::statement("ALTER TABLE compliance_requirements ADD CONSTRAINT compliance_requirements_not_applicable_rationale_check CHECK (applicability <> 'not_applicable' OR length(btrim(coalesce(applicability_rationale, ''))) > 0)");
        }

        // One activation per customer and framework; one requirement per customer and library node.
        DB::statement('CREATE UNIQUE INDEX compliance_sources_customer_framework_unique ON compliance_sources (customer_id, framework_id) WHERE framework_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX compliance_requirements_customer_node_unique ON compliance_requirements (customer_id, framework_node_id) WHERE framework_node_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS compliance_requirements_customer_node_unique');
        DB::statement('DROP INDEX IF EXISTS compliance_sources_customer_framework_unique');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE compliance_requirements DROP CONSTRAINT IF EXISTS compliance_requirements_not_applicable_rationale_check');
            DB::statement('ALTER TABLE compliance_requirements DROP CONSTRAINT IF EXISTS compliance_requirements_applicability_check');
        }

        Schema::table('compliance_requirements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('applicability_set_by');
            $table->dropConstrainedForeignId('framework_node_id');
            $table->dropColumn(['applicability', 'applicability_rationale', 'applicability_set_at']);
        });

        Schema::table('compliance_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('framework_id');
        });

        Schema::dropIfExists('compliance_framework_nodes');
        Schema::dropIfExists('compliance_frameworks');
    }
};
