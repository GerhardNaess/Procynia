<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page generation only had generation_status (pending/running/completed/failed) plus a row lock
 * at claim time — no lease/token pair, unlike claim verification's
 * verification_claimed_at/verification_claim_token. Two concurrent
 * GenerateEnterpriseWikiAppliedPage jobs for the same run/page both pass the row-locked
 * "generated_page_version_id is still null" check (only the AI call separates them), so both
 * reach the AI client before the second one's write is discarded. generation_dispatched_at
 * (enqueue marker, mirrors verification_dispatched_at) and generation_claimed_at/
 * generation_claim_token (execution lease, mirrors verification_claimed_at/
 * verification_claim_token) close that gap the same way. generation_status gets a new
 * 'dispatched' value (App\Models\EnterpriseWikiIngestRunPage::GENERATION_STATUS_DISPATCHED) —
 * a plain string column, no DB-level enum/check constraint, so no migration is needed for the
 * new value itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->timestamp('generation_dispatched_at')->nullable()->after('generation_status');
            $table->timestamp('generation_claimed_at')->nullable()->after('generation_dispatched_at');
            $table->string('generation_claim_token')->nullable()->after('generation_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_run_pages', function (Blueprint $table): void {
            $table->dropColumn(['generation_dispatched_at', 'generation_claimed_at', 'generation_claim_token']);
        });
    }
};
