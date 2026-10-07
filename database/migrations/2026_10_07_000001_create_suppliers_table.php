<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leverandøroppfølging — the supplier: one row per supplier as a company or party, never one per
 * contract, service, requirement, risk or document (docs/supplier-management-v1-plan.md §4.1).
 *
 * Customer-wide: no business_area_id. The master data is ordinary, editable data while the
 * supplier is not ended.
 *
 * status is the lifecycle — onboarding (Under vurdering), active (Aktiv) or ended (Avsluttet) —
 * and never a form field after registration; SupplierLifecycleService is the only writer of a
 * change, and every change is in supplier_status_changes.
 *
 * organization_number is optional (a foreign supplier has no Norwegian one) and unique within the
 * customer when given — the simplest guard against registering the same party twice.
 * owner_user_id is required by the forms but nulled when that user is deleted («Mangler
 * ansvarlig»).
 *
 * Criticality and the review interval are not here yet; they arrive with phase 3.
 *
 * (id, customer_id) is unique so that history rows, and later assessments, documents and links,
 * reference the pair and can never describe another customer's supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('organization_number', 50)->nullable();
            $table->string('category');
            $table->text('deliverable_description');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('onboarding');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'customer_id']);
            $table->index(['customer_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_check CHECK (status IN ('onboarding', 'active', 'ended'))");
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_category_check CHECK (category IN ('it_cloud', 'consulting', 'goods', 'construction', 'transport_logistics', 'operations_facilities', 'other'))");
            DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_text_check CHECK (length(btrim(name)) > 0 AND length(btrim(deliverable_description)) > 0)');
            DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_organization_number_check CHECK (organization_number IS NULL OR length(btrim(organization_number)) > 0)');
            DB::statement('CREATE UNIQUE INDEX suppliers_customer_organization_number_unique ON suppliers (customer_id, organization_number) WHERE organization_number IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
