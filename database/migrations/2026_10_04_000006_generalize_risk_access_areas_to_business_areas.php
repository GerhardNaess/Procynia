<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tilgangsområder for Risiko become the customer's FAGOMRÅDER — a concept of the customer, not of
 * Risiko.
 *
 *  - Rolle      = what a person may do (customer_roles + their permission keys).
 *  - Fagområde  = where that applies («Beredskap», «Informasjonssikkerhet», «HR», «Økonomi»).
 *
 * Risiko is the first (and so far only) module that scopes by fagområde; Kvalitet and Wiki may
 * later. So the area table and the role link lose their risk_ prefix, and a risk keeps exactly one
 * primary fagområde.
 *
 * Renames, not copies: the ids — and therefore every existing risk, role link and FK — survive as
 * they are, so local data is not lost.
 *
 * «ALLE».
 *
 * customer_roles.all_business_areas is a real wildcard: a role with it reaches every fagområde the
 * customer has now and every one created later. It is deliberately not expanded into link rows,
 * which would freeze «all» to the areas that happened to exist when the box was ticked. While the
 * flag is set the role's explicit links are ignored (and cleared on save), so there is one answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('risk_access_areas', 'business_areas');
        Schema::rename('customer_role_risk_access_areas', 'customer_role_business_areas');

        Schema::table('customer_role_business_areas', function (Blueprint $table): void {
            $table->renameColumn('risk_access_area_id', 'business_area_id');
        });

        Schema::table('risks', function (Blueprint $table): void {
            $table->renameColumn('risk_access_area_id', 'business_area_id');
        });

        Schema::table('customer_roles', function (Blueprint $table): void {
            $table->boolean('all_business_areas')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('customer_roles', function (Blueprint $table): void {
            $table->dropColumn('all_business_areas');
        });

        Schema::table('risks', function (Blueprint $table): void {
            $table->renameColumn('business_area_id', 'risk_access_area_id');
        });

        Schema::table('customer_role_business_areas', function (Blueprint $table): void {
            $table->renameColumn('business_area_id', 'risk_access_area_id');
        });

        Schema::rename('customer_role_business_areas', 'customer_role_risk_access_areas');
        Schema::rename('business_areas', 'risk_access_areas');
    }
};
