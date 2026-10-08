<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ai_usage_attempts` becomes the primary usage source, so it has to hold what the provider bills
 * on and who the call was for — on the same row, not in a parallel ledger.
 *
 *  - cached_input_tokens: the part of input_tokens served from the provider's prompt cache. It is
 *    included in input_tokens and billed at the model's cached-input rate.
 *  - reasoning_tokens: the part of output_tokens a reasoning model spent thinking. Included in
 *    output_tokens and billed as output; stored because it explains a large output figure.
 *  - attribution: customer | system | unattributed. Null only on rows written before it existed.
 *  - price_cached_input_per_1m: the cached-input rate frozen with the rest of the price snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('cached_input_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('output_tokens');
            $table->string('attribution', 16)->nullable()->after('user_id');
            $table->decimal('price_cached_input_per_1m', 14, 6)->nullable()->after('price_input_per_1m');

            $table->index(['customer_id', 'feature', 'started_at'], 'ai_usage_attempts_customer_feature_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_attempts_customer_feature_index');
            $table->dropColumn(['cached_input_tokens', 'reasoning_tokens', 'attribution', 'price_cached_input_per_1m']);
        });
    }
};
