<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lockable running total per budget scope and window.
 *
 * An aggregate rather than a SUM over `ai_usage_attempts` because enforcement has to be
 * concurrency-safe: ten simultaneous calls must not all read the same balance and all decide
 * there is room. A single row per scope and window can be taken FOR UPDATE; a SUM cannot.
 * The attempt ledger remains the auditable detail behind these totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_operational_budget_periods', function (Blueprint $table): void {
            $table->id();

            // 'global' or 'customer'; customer_id is null for the platform scope.
            $table->string('scope', 16);
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('window', 16);
            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('committed_nok', 16, 4)->default(0);
            $table->decimal('reserved_nok', 16, 4)->default(0);
            $table->unsignedInteger('unknown_cost_count')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'customer_id', 'window', 'period_start'], 'ai_operational_budget_period_unique');
            $table->index(['scope', 'window', 'period_start'], 'ai_operational_budget_period_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operational_budget_periods');
    }
};
