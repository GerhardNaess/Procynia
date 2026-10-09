<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-customer override of the shared AI capacity: AI units included per billing period.
 *
 * Null means "follow the plan" (config/procynia_plans.php `included_ai_units`, a monthly figure
 * scaled to the period's length). A value is the negotiated amount for one whole billing period —
 * the enterprise case — and wins over the plan. Kept nullable on purpose, unlike
 * `included_ai_credits`, so an explicit 0 can never be mistaken for "not set".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedInteger('included_ai_units')->nullable()->after('included_ai_credits');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('included_ai_units');
        });
    }
};
