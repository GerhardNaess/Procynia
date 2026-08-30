<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable cost snapshot on the attempt ledger.
 *
 * Chosen over a parallel cost ledger because `ai_usage_attempts` already records exactly one row
 * per provider attempt, with the model, tokens and outcome — a second table would only be a
 * join away from the same facts and could drift from them. Snapshotting the price and rate that
 * were actually used means a later price correction cannot silently rewrite what an operational
 * decision was based on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            // known | estimated | unknown | uncertain. Never null once an attempt has finished,
            // and never a zero cost standing in for "we could not price this".
            $table->string('cost_status', 20)->nullable()->after('total_tokens');
            $table->decimal('cost_usd', 14, 6)->nullable()->after('cost_status');
            $table->decimal('cost_nok', 14, 4)->nullable()->after('cost_usd');

            // What the call reserved against the safety budget before it ran. Retained so an
            // uncertain outcome keeps holding a defensible figure instead of falling back to zero.
            $table->decimal('reserved_cost_nok', 14, 4)->nullable()->after('cost_nok');

            $table->foreignId('ai_model_price_id')->nullable()->after('reserved_cost_nok')
                ->constrained('ai_model_prices')->nullOnDelete();
            $table->string('price_currency', 10)->nullable()->after('ai_model_price_id');
            $table->decimal('price_input_per_1m', 14, 6)->nullable()->after('price_currency');
            $table->decimal('price_output_per_1m', 14, 6)->nullable()->after('price_input_per_1m');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('price_output_per_1m');
            $table->date('fx_rate_date')->nullable()->after('fx_rate');
            $table->string('price_state', 20)->nullable()->after('fx_rate_date');
            $table->string('fx_state', 20)->nullable()->after('price_state');

            $table->index(['customer_id', 'cost_status', 'started_at'], 'ai_usage_attempts_cost_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_attempts_cost_index');
            $table->dropConstrainedForeignId('ai_model_price_id');
            $table->dropColumn([
                'cost_status', 'cost_usd', 'cost_nok', 'reserved_cost_nok', 'price_currency',
                'price_input_per_1m', 'price_output_per_1m', 'fx_rate', 'fx_rate_date',
                'price_state', 'fx_state',
            ]);
        });
    }
};
