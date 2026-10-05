<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KPI measurements — the results, as they were registered. History, never edited.
 *
 * A period is (period_start, period_end): a calendar week, month, quarter or year for a KPI with a
 * frequency, or one day (start = end) for a KPI without. A correction is a new row for the same
 * period; the newest row that is not withdrawn is the one that counts (KpiMeasurementResolver).
 * A withdrawal sets withdrawn_* once, with a reason, and is the only change a row ever takes.
 *
 * target_min, target_max and tolerance are a snapshot of the KPI's målverdi when the value was
 * registered, so the history can say what the target was then. Exact decimals, the same
 * numeric(20,4) as the KPI.
 *
 * (kpi_id, customer_id) references the KPI's own pair, so the database refuses a measurement under
 * another customer's KPI. Deleting a KPI takes its measurements along — the application only deletes
 * a KPI without any (Kpi::isDeletable()), but customer and E2E cleanup must still be able to remove
 * everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpis', function (Blueprint $table): void {
            $table->unique(['id', 'customer_id']);
        });

        Schema::create('kpi_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('kpi_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('value', 20, 4);
            $table->text('comment')->nullable();
            $table->decimal('target_min', 20, 4)->nullable();
            $table->decimal('target_max', 20, 4)->nullable();
            $table->decimal('tolerance', 20, 4)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('withdrawal_reason')->nullable();

            $table->foreign(['kpi_id', 'customer_id'])
                ->references(['id', 'customer_id'])
                ->on('kpis')
                ->cascadeOnDelete();
            $table->index(['kpi_id', 'period_end', 'period_start']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $checks = [
                'kpi_measurements_period_check' => 'period_start <= period_end',
                'kpi_measurements_target_bound_check' => 'target_min IS NOT NULL OR target_max IS NOT NULL',
                'kpi_measurements_target_order_check' => 'target_min IS NULL OR target_max IS NULL OR target_min <= target_max',
                'kpi_measurements_tolerance_check' => 'tolerance IS NULL OR tolerance >= 0',
                // A withdrawal is complete or absent, and always says why.
                'kpi_measurements_withdrawal_check' => '(withdrawn_at IS NULL) = (withdrawal_reason IS NULL)'
                    .' AND (withdrawal_reason IS NULL OR length(btrim(withdrawal_reason)) > 0)'
                    .' AND (withdrawn_at IS NOT NULL OR withdrawn_by_user_id IS NULL)',
            ];

            foreach ($checks as $name => $condition) {
                DB::statement("ALTER TABLE kpi_measurements ADD CONSTRAINT {$name} CHECK ({$condition})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_measurements');

        Schema::table('kpis', function (Blueprint $table): void {
            $table->dropUnique(['id', 'customer_id']);
        });
    }
};
