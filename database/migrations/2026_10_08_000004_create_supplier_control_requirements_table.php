<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrollkrav: what the customer requires of its suppliers and how it is controlled
 * (docs/supplier-assurance-v2-plan.md §5.1, §6.6, §20.2). Current state, mutable with
 * supplier.assure; a requirement can be retired and is deleted only while unused.
 *
 * supplier_id null = a catalogue requirement, applied to suppliers by its rule (applies_when, a list
 * of groups over SupplierProfilePredicates — never an expression language). supplier_id set = a
 * requirement for that one supplier, which always applies to it and has no rule.
 *
 * Which requirements apply to which supplier is NEVER stored here or anywhere: it is computed on
 * every read by SupplierRequirementApplicability. Only the human overrides are stored
 * (supplier_requirement_overrides).
 *
 * The optional anchor in Etterlevelse og revisjon references (compliance_requirement_id,
 * customer_id), so it can never point into another customer, and ON DELETE SET NULL on the id
 * column alone (PostgreSQL 15+) leaves the control requirement with its basis_text when the
 * compliance requirement goes. No compliance field is copied here: a copied title would be shown to
 * people without compliance.view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_control_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('guidance')->nullable();
            $table->string('theme');
            $table->string('level');
            $table->string('control_point');
            $table->unsignedSmallInteger('control_interval_months')->nullable();
            $table->jsonb('applies_when')->default('[]');
            $table->jsonb('accepted_document_types')->default('[]');
            $table->text('basis_text')->nullable();
            $table->unsignedBigInteger('compliance_requirement_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('status')->default('active');
            $table->string('template_key')->nullable();
            $table->string('template_item_key')->nullable();
            $table->string('template_version')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'customer_id']);
            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['customer_id', 'status']);
            $table->index('supplier_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // SET NULL on the id column only: customer_id is NOT NULL and stays.
        DB::statement('ALTER TABLE supplier_control_requirements ADD CONSTRAINT supplier_control_requirements_compliance_requirement_foreign '
            .'FOREIGN KEY (compliance_requirement_id, customer_id) REFERENCES compliance_requirements (id, customer_id) '
            .'ON DELETE SET NULL (compliance_requirement_id)');
        DB::statement('CREATE INDEX supplier_control_requirements_compliance_requirement_id_index ON supplier_control_requirements (compliance_requirement_id)');
        DB::statement('CREATE UNIQUE INDEX supplier_control_requirements_template_item_unique ON supplier_control_requirements (customer_id, template_item_key) WHERE template_item_key IS NOT NULL');

        DB::statement('ALTER TABLE supplier_control_requirements ADD CONSTRAINT supplier_control_requirements_codes CHECK ('
            ."theme IN ('human_rights', 'labour_conditions', 'environment', 'information_security', 'privacy', 'quality', 'continuity', 'ethics', 'financial')"
            ." AND level IN ('mandatory', 'important', 'standard')"
            ." AND control_point IN ('before_contract', 'ongoing', 'on_change')"
            ." AND status IN ('active', 'retired')"
            .' AND (control_interval_months IS NULL OR control_interval_months IN (3, 6, 12, 24, 36)))');
        DB::statement('ALTER TABLE supplier_control_requirements ADD CONSTRAINT supplier_control_requirements_texts CHECK ('
            .'length(btrim(title)) > 0'
            .' AND (description IS NULL OR length(btrim(description)) > 0)'
            .' AND (guidance IS NULL OR length(btrim(guidance)) > 0)'
            .' AND (basis_text IS NULL OR length(btrim(basis_text)) > 0))');
        // The predicate names inside are checked by the service; the database checks the shape.
        DB::statement('ALTER TABLE supplier_control_requirements ADD CONSTRAINT supplier_control_requirements_lists CHECK ('
            ."jsonb_typeof(applies_when) = 'array' AND jsonb_typeof(accepted_document_types) = 'array')");
        // A requirement for one supplier always applies to it: it has no rule.
        DB::statement('ALTER TABLE supplier_control_requirements ADD CONSTRAINT supplier_control_requirements_supplier_rule CHECK ('
            ."supplier_id IS NULL OR applies_when = '[]'::jsonb)");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_control_requirements');
    }
};
