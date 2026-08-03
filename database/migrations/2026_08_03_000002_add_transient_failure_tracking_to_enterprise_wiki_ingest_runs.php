<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            // Wiki run-592: whether the run's most recent failure classifies as transient
            // (EnterpriseWikiTransientFailureClassifier — cURL timeout, connection reset, 429/5xx)
            // — set by EnterpriseWikiDocumentFlowService::markRunFailed(). Null for a run that has
            // never failed, or whose failure predates this field. The single field
            // EnterpriseWikiMaintainerDecisionFailureRecoveryService checks before allowing the
            // "Prøv beslutningsfasen på nytt" action.
            $table->boolean('transient_failure')->nullable()->after('failed_phase');

            // How many times performMaintainerDecision() has actually been attempted for this run
            // (the original attempt plus any user-triggered recovery attempts) — audit/observability
            // only, shown in the Kjøringer UI; not used as a hard cap in this task.
            $table->unsignedTinyInteger('maintainer_decision_attempt_count')->default(0)->after('transient_failure');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_ingest_runs', function (Blueprint $table): void {
            $table->dropColumn(['transient_failure', 'maintainer_decision_attempt_count']);
        });
    }
};
