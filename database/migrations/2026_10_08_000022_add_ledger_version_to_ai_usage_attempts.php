<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The usage-integrity boundary of the attempt ledger.
 *
 * Every attempt written from here on carries the ledger version it was recorded under. A row
 * without one was written before attribution, operation naming, cached/reasoning tokens, the
 * corrected gpt-5 price and failed-call cost semantics existed, and is legacy: kept for debugging,
 * trend analysis and operator history, never used as the economic basis for customer capacity.
 *
 * A per-row marker rather than a date: a cutoff timestamp differs per environment (whenever each
 * one was deployed), while the version is stamped by the code that wrote the row. Old rows are not
 * backfilled or rewritten — the ledger is append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ledger_version')->nullable()->after('attribution');

            $table->index(['ledger_version', 'attribution', 'started_at'], 'ai_usage_attempts_trusted_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_attempts_trusted_index');
            $table->dropColumn('ledger_version');
        });
    }
};
