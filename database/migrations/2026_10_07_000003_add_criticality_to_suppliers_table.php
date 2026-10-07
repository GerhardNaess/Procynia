<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leverandøroppfølging phase 3 — how important the supplier is to the business
 * (docs/supplier-management-v1-plan.md §4.2).
 *
 * The current classification lives on the supplier: one of three levels the user chooses
 * (standard/important/critical — Standard, Viktig, Kritisk), the review interval in months, and the
 * four ja/nei answers the choice was made on. Nothing is computed from the answers; they are the
 * basis the user decided on, kept so the choice can be checked later. Every later change is a row
 * in supplier_criticality_changes, written in the same transaction.
 *
 * Nullable, all together: a supplier registered before phase 3 has not been classified yet. A new
 * supplier is classified when it is registered. Either everything is there or nothing is.
 *
 * Viktig and Kritisk require an interval; only Standard may be without (plan §4.2), so an important
 * supplier cannot drop out of follow-up for want of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('criticality')->nullable()->after('note');
            $table->unsignedSmallInteger('review_interval_months')->nullable()->after('criticality');
            $table->boolean('processes_personal_data')->nullable()->after('review_interval_months');
            $table->boolean('has_system_access')->nullable()->after('processes_personal_data');
            $table->boolean('supports_critical_delivery')->nullable()->after('has_system_access');
            $table->boolean('hard_to_replace')->nullable()->after('supports_critical_delivery');

            $table->index(['customer_id', 'criticality']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_criticality_check CHECK (criticality IN ('standard', 'important', 'critical'))");
            DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_review_interval_check CHECK (review_interval_months IN (6, 12, 24, 36))');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_criticality_interval_check CHECK (criticality = 'standard' OR review_interval_months IS NOT NULL)");
            DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_criticality_complete_check CHECK ('
                .'(criticality IS NULL AND review_interval_months IS NULL AND processes_personal_data IS NULL AND has_system_access IS NULL'
                .' AND supports_critical_delivery IS NULL AND hard_to_replace IS NULL)'
                .' OR (criticality IS NOT NULL AND processes_personal_data IS NOT NULL AND has_system_access IS NOT NULL'
                .' AND supports_critical_delivery IS NOT NULL AND hard_to_replace IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['suppliers_criticality_check', 'suppliers_review_interval_check', 'suppliers_criticality_interval_check', 'suppliers_criticality_complete_check'] as $constraint) {
                DB::statement("ALTER TABLE suppliers DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'criticality']);
            $table->dropColumn([
                'criticality',
                'review_interval_months',
                'processes_personal_data',
                'has_system_access',
                'supports_critical_delivery',
                'hard_to_replace',
            ]);
        });
    }
};
