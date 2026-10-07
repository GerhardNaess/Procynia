<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Krav som gjelder leverandøren (docs/supplier-management-v1-plan.md §7.3, §10): which requirements
 * in Etterlevelse og revisjon apply to a supplier, and nothing about them. A row says «this
 * requirement applies to this supplier» — not that the supplier meets it or breaks it. The
 * requirement's text, kravkilde, owner, lifecycle and etterlevelsesvurderinger stay in Etterlevelse
 * og revisjon and are read through ComplianceAccessService when the supplier page is shown — never
 * copied here. There is no status column: v1 has no supplier-specific requirement status.
 *
 * A supplier may have any number of requirements, and a requirement may apply to any number of
 * suppliers; a requirement is listed once per supplier.
 *
 * Keys:
 *  - (supplier_id, customer_id) → suppliers, NO ACTION: never another customer's supplier, and a
 *    supplier with requirements is ended, not deleted.
 *  - (compliance_requirement_id, customer_id) → compliance_requirements, CASCADE: never another
 *    customer's requirement, and a requirement Etterlevelse og revisjon allows to be deleted takes
 *    the row with it (its delete rule is unchanged). compliance_requirements already has
 *    unique(id, customer_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_compliance_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('compliance_requirement_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->foreign(['compliance_requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements')->cascadeOnDelete();
            $table->unique(['supplier_id', 'compliance_requirement_id']);
            $table->index('compliance_requirement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_compliance_requirements');
    }
};
