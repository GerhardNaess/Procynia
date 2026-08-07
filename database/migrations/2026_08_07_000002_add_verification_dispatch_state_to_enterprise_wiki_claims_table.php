<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A claim already has a completion checkpoint (verified_at) and a running/leased checkpoint
 * (verification_claimed_at + verification_claim_token). Neither can represent "a
 * VerifyEnterpriseWikiClaim job has been enqueued but has not yet started" — a claim sitting
 * unstarted in Redis looks identical to one that was never dispatched at all, which lets the
 * claim-verification recovery sentinel enqueue a second job for work that is already safely
 * queued. verification_dispatched_at closes that gap without adding a parallel status column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->timestamp('verification_dispatched_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->dropColumn('verification_dispatched_at');
        });
    }
};
