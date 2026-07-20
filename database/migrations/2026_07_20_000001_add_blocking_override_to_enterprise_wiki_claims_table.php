<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            // Separates "is this important" (severity, unchanged) from "does this stop the run/
            // page from being finished" (blocking). Null means no authorized user has decided yet
            // — the system's own suggested blocking state (EnterpriseWikiClaimFindingExplainer)
            // applies. true/false is an explicit, recorded human decision (see
            // enterprise_wiki_claim_decisions for the full audit trail of who/when/why).
            $table->boolean('blocking_override')->nullable()->after('generation_issue');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->dropColumn('blocking_override');
        });
    }
};
