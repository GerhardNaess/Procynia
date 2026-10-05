<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KPI — how progress towards an objective is measured.
 *
 * A KPI belongs to exactly one objective and has no business_area_id: its fagområde, and with it who
 * can reach it, is always the objective's. objective_id is required, and (objective_id, customer_id)
 * references the objective's own pair, so the database itself refuses a KPI under another
 * customer's objective. Deleting an objective takes its KPIs along; there are no measurements yet.
 *
 * Målverdi: target_min and/or target_max, at least one, min <= max; one tolerance >= 0 outside the
 * target. Exact decimals (numeric(20,4)), never floats — a value on the tolerance edge must land on
 * the same side every time.
 *
 * Unit: currency carries a currency_code and nothing else does; only count and number carry a free
 * unit_label. frequency null means no fixed reporting rhythm. reporting_grace_days is how long after
 * a period ends a measurement is still on time.
 *
 * owner_user_id is optional and nulled when the user is deleted; the objective's owner then answers
 * for the KPI. That fallback is presentation, never copied into this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objectives', function (Blueprint $table): void {
            $table->unique(['id', 'customer_id']);
        });

        Schema::create('kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('objective_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unit');
            $table->string('unit_label')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->decimal('target_min', 20, 4)->nullable();
            $table->decimal('target_max', 20, 4)->nullable();
            $table->decimal('tolerance', 20, 4)->nullable();
            $table->string('frequency')->nullable();
            $table->unsignedInteger('reporting_grace_days')->default(7);
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['objective_id', 'customer_id'])
                ->references(['id', 'customer_id'])
                ->on('objectives')
                ->cascadeOnDelete();
            $table->index(['customer_id', 'objective_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $checks = [
                'kpis_status_check' => "status IN ('active', 'retired')",
                'kpis_unit_check' => "unit IN ('percent', 'count', 'number', 'currency', 'hours', 'days')",
                'kpis_frequency_check' => "frequency IS NULL OR frequency IN ('weekly', 'monthly', 'quarterly', 'yearly')",
                'kpis_target_bound_check' => 'target_min IS NOT NULL OR target_max IS NOT NULL',
                'kpis_target_order_check' => 'target_min IS NULL OR target_max IS NULL OR target_min <= target_max',
                'kpis_tolerance_check' => 'tolerance IS NULL OR tolerance >= 0',
                'kpis_grace_days_check' => 'reporting_grace_days >= 0',
                'kpis_currency_check' => "(unit = 'currency') = (currency_code IS NOT NULL)",
                'kpis_currency_code_check' => "currency_code IS NULL OR currency_code ~ '^[A-Z]{3}$'",
                'kpis_unit_label_check' => "unit_label IS NULL OR unit IN ('count', 'number')",
            ];

            foreach ($checks as $name => $condition) {
                DB::statement("ALTER TABLE kpis ADD CONSTRAINT {$name} CHECK ({$condition})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpis');

        Schema::table('objectives', function (Blueprint $table): void {
            $table->dropUnique(['id', 'customer_id']);
        });
    }
};
