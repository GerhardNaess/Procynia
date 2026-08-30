<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of every administrative change to a customer's AI capacity for a period.
 *
 * `customer_ai_quota_periods.extra_credits` remains what the hot authorisation path reads, but it
 * is now a projection with exactly one writer: it is recomputed from the sum of these rows inside
 * the same locked transaction that inserts one. The ledger is the truth; the column is the cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ai_credit_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');

            // Signed: a positive grant and a negative correction are the same kind of event, and
            // both must survive in history rather than being applied by editing an earlier row.
            $table->integer('amount');
            $table->string('reason', 500);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'period_start'], 'customer_ai_credit_adjustment_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ai_credit_adjustments');
    }
};
