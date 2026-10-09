<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an attempt's cost is final, and if not, why not.
 *
 *  - settled:    the actual cost is known (or estimated from a stale price/rate) and counts.
 *  - released:   the provider certainly did no work (a refused request); nothing is owed.
 *  - pending:    the provider may have worked (timeout, 5xx, a call still in flight or killed
 *                mid-call). The pre-call reservation is held; nothing is charged as settled.
 *  - unresolved: the provider did work, but its cost cannot be established automatically (no
 *                usage reported on success, or a model without a price). Reservation held.
 *
 * Only post-boundary rows (ledger_version >= 1) are derived here, by the same rules the meter now
 * applies. Legacy rows keep a null settlement and are never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->string('settlement_status', 16)->nullable()->after('cost_status');

            $table->index(['settlement_status', 'started_at'], 'ai_usage_attempts_settlement_index');
        });

        DB::statement(<<<'SQL'
            UPDATE ai_usage_attempts SET settlement_status = CASE
                WHEN cost_status IN ('known', 'estimated') THEN 'settled'
                WHEN status = 'started' OR cost_status = 'uncertain' OR status = 'uncertain' THEN 'pending'
                WHEN cost_status = 'unknown' AND (input_tokens IS NOT NULL OR output_tokens IS NOT NULL) THEN 'unresolved'
                WHEN status = 'success' THEN 'unresolved'
                WHEN status IN ('failed', 'timeout') AND failure_type IN ('timeout') THEN 'pending'
                WHEN failure_type LIKE 'http\_5%' OR failure_type = 'http_408' THEN 'pending'
                ELSE 'released'
            END
            WHERE ledger_version >= 1
        SQL);
    }

    public function down(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_attempts_settlement_index');
            $table->dropColumn('settlement_status');
        });
    }
};
