<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A KPI's startverdi, and «prosentvis endring» as a unit of its own.
 *
 * STARTVERDI. «30 % økning fra dagens nivå» is a målverdi computed from where the KPI starts:
 * baseline_value on baseline_date. The computed målverdi is stored where every target lives —
 * target_min for an increase, target_max for a decrease — so the policy, the snapshot on each
 * measurement and every reader keep working on one target. The change in per cent is never stored:
 * it is derived from the startverdi and the målverdi (KpiGrowth). A startverdi therefore comes with
 * exactly one bound, a date and a positive value. Both columns are null for every existing KPI,
 * which keeps its meaning unchanged.
 *
 * PROSENTVIS ENDRING. «percent» is a share (0–100 %), checked by the application (KpiUnitRules), not
 * the database, so an existing KPI whose numbers fall outside it is shown for manual correction
 * instead of being refused. A KPI that measures change in per cent — which may be negative or above
 * 100 — is «percent_change».
 *
 * Rolling back turns every «percent_change» KPI back into «percent»; both are written in per cent, so
 * no number changes meaning, but the distinction is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpis', function (Blueprint $table): void {
            $table->decimal('baseline_value', 20, 4)->nullable()->after('tolerance');
            $table->date('baseline_date')->nullable()->after('baseline_value');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE kpis DROP CONSTRAINT kpis_unit_check');
        DB::statement("ALTER TABLE kpis ADD CONSTRAINT kpis_unit_check CHECK (unit IN ('percent', 'percent_change', 'count', 'number', 'currency', 'hours', 'days'))");

        $checks = [
            'kpis_baseline_complete_check' => '(baseline_value IS NULL) = (baseline_date IS NULL)',
            'kpis_baseline_positive_check' => 'baseline_value IS NULL OR baseline_value > 0',
            'kpis_baseline_bound_check' => 'baseline_value IS NULL OR ((target_min IS NULL) <> (target_max IS NULL))',
        ];

        foreach ($checks as $name => $condition) {
            DB::statement("ALTER TABLE kpis ADD CONSTRAINT {$name} CHECK ({$condition})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['kpis_baseline_complete_check', 'kpis_baseline_positive_check', 'kpis_baseline_bound_check'] as $name) {
                DB::statement("ALTER TABLE kpis DROP CONSTRAINT IF EXISTS {$name}");
            }

            DB::statement('ALTER TABLE kpis DROP CONSTRAINT kpis_unit_check');
            DB::table('kpis')->where('unit', 'percent_change')->update(['unit' => 'percent']);
            DB::statement("ALTER TABLE kpis ADD CONSTRAINT kpis_unit_check CHECK (unit IN ('percent', 'count', 'number', 'currency', 'hours', 'days'))");
        }

        Schema::table('kpis', function (Blueprint $table): void {
            $table->dropColumn(['baseline_value', 'baseline_date']);
        });
    }
};
