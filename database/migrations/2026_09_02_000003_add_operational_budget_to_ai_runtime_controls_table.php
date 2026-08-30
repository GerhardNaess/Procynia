<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global operational budget on the existing singleton platform control row.
 *
 * Reused rather than given its own table: this row already is "the platform's runtime AI safety
 * state", and keeping the manual emergency stop and the automatic budget ceiling together makes
 * their relationship — both stop everything, for different reasons — impossible to miss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runtime_controls', function (Blueprint $table): void {
            $table->boolean('operational_budget_enabled')->default(false)->after('global_ai_stop');
            $table->decimal('global_daily_nok_limit', 14, 2)->nullable()->after('operational_budget_enabled');
            $table->decimal('global_monthly_nok_limit', 14, 2)->nullable()->after('global_daily_nok_limit');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runtime_controls', function (Blueprint $table): void {
            $table->dropColumn(['operational_budget_enabled', 'global_daily_nok_limit', 'global_monthly_nok_limit']);
        });
    }
};
