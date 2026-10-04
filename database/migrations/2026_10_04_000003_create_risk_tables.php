<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko — the first increment: who may see which risk, and the risk object itself.
 *
 * WHY NO ROLE TABLE HERE.
 *
 * What a person may *do* in Risiko is the customer's own roles (customer_roles) holding keys from
 * CustomerPermissionCatalog — risk.view, risk.create, risk.edit, risk.delete — exactly as Kvalitet
 * and Enterprise Wiki do. Nothing in this migration adds a second way to grant an action.
 *
 * WHAT IS NEW IS *WHICH* RISKS.
 *
 * Risks are sensitive in a way quality documents are not: a beredskap risk, an HR risk and an
 * informasjonssikkerhet risk are routinely read by different people. So the customer defines
 * tilgangsområder (risk_access_areas), and attaches them to the same customer roles that carry the
 * permissions (customer_role_risk_access_areas). A role therefore says "these actions, on risks in
 * these areas", and a user's access is the union of what their active roles say — evaluated per
 * role, so a role that reads Økonomi and another that edits HR never add up to editing Økonomi.
 * See App\Services\Risk\RiskAccessService.
 *
 * Every risk has exactly one primary area. The FK is restrict, not cascade: deleting an area must
 * never silently delete risks, and nulling it would turn a scoped risk into one nobody's scope
 * covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_access_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // The customer's own word for the area — «Beredskap», «Informasjonssikkerhet».
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'name']);
        });

        Schema::create('customer_role_risk_access_areas', function (Blueprint $table): void {
            $table->id();

            // Denormalised for the same reason as on customer_user_roles: the tenant guard reads it
            // without a join, and a cross-tenant row is visible as bad data.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_role_id')->constrained('customer_roles')->cascadeOnDelete();
            $table->foreignId('risk_access_area_id')->constrained('risk_access_areas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_role_id', 'risk_access_area_id']);
            $table->index(['customer_id', 'risk_access_area_id']);
        });

        Schema::create('risks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_access_area_id')->constrained('risk_access_areas')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'risk_access_area_id']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risks');
        Schema::dropIfExists('customer_role_risk_access_areas');
        Schema::dropIfExists('risk_access_areas');
    }
};
