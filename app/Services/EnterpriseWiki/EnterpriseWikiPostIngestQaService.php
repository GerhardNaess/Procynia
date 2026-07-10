<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Services\Ai\Wiki\WikiSemanticQaAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-ingest QA orchestrator for applied Enterprise Wiki runs.
 *
 * Three-tier QA gate (8G-3 + 8G-4):
 *   Level 1 — Technical QA: artefacts exist with content (article + summary)
 *   Level 2 — Structural QA: lint findings, coverage metrics
 *   Level 3 — Semantic QA: AI review of generated content vs. extracted_text source
 *
 * QA status flow:
 *   null / pending → running
 *     → Level 1/2 gap found: repair_required → attempt repair → re-check
 *       → still failing: escalated
 *     → Level 1/2 passed → Level 3 (semantic QA, when AI enabled)
 *       → semantic pass: passed
 *       → semantic avvik (targeted_revision/full_regeneration): repair_required
 *       → semantic escalation or source missing: escalated
 *     → failed (unexpected exception during QA)
 *
 * The status transition from null/pending to 'running' is done via an atomic DB update
 * to prevent parallel runs for the same run ID.
 */
class EnterpriseWikiPostIngestQaService
{
    public function __construct(
        private readonly EnterpriseWikiCoverageService $coverageService,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiGenerateAppliedPagesService $generateService,
        private readonly EnterpriseWikiSemanticQaService $semanticQaService,
    ) {}

    /**
     * Run post-ingest QA for a single applied run.
     *
     * Returns the QA result array, or null if the run was skipped (already
     * running or already passed, or failed/escalated without $retry).
     *
     * @param  bool  $retry  When true, also claims runs in 'failed' or 'escalated' status.
     *
     * @throws \InvalidArgumentException if the run is not applied
     * @throws \Throwable on unexpected errors (run is marked failed before re-throw)
     */
    public function runForRun(EnterpriseWikiIngestRun $run, bool $retry = false): ?array
    {
        if ($run->maintainer_decision_status !== EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED) {
            throw new \InvalidArgumentException(
                "Run [{$run->id}] has maintainer_decision_status [{$run->maintainer_decision_status}] — only 'applied' runs can be QA-checked."
            );
        }

        // Atomic transition: set running only if eligible.
        // Without retry: null, pending, repair_required (stuck transient state).
        // With retry: also failed, escalated (explicit operator decision to retry).
        $eligibleStatuses = [
            EnterpriseWikiIngestRun::QA_STATUS_PENDING,
            EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
        ];

        if ($retry) {
            $eligibleStatuses[] = EnterpriseWikiIngestRun::QA_STATUS_FAILED;
            $eligibleStatuses[] = EnterpriseWikiIngestRun::QA_STATUS_ESCALATED;
        }

        $claimed = DB::table('enterprise_wiki_ingest_runs')
            ->where('id', $run->id)
            ->where(function ($q) use ($eligibleStatuses): void {
                $q->whereNull('qa_status')
                    ->orWhereIn('qa_status', $eligibleStatuses);
            })
            ->update([
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_RUNNING,
                'qa_started_at' => now(),
                'qa_attempt_count' => DB::raw('COALESCE(qa_attempt_count, 0) + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            // Already running or already passed — skip silently.
            return null;
        }

        $fresh = $run->fresh();

        try {
            return $this->executeQa($fresh);
        } catch (\Throwable $e) {
            $fresh->update([
                'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'qa_completed_at' => now(),
                'qa_last_error' => $e->getMessage(),
            ]);

            Log::error('[WIKI_QA] QA failed with unexpected error', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Find applied runs eligible for scheduled QA polling (null, pending, repair_required).
     *
     * Does NOT include 'failed' or 'escalated' — those require an explicit --retry decision.
     * Does not include 'running' (in progress) or 'passed' (complete).
     */
    public function findPendingRuns(): Collection
    {
        return EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->where(function ($q): void {
                $q->whereNull('qa_status')
                    ->orWhereIn('qa_status', [
                        EnterpriseWikiIngestRun::QA_STATUS_PENDING,
                        EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
                    ]);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Find applied runs eligible for explicit retry (null, pending, repair_required, failed, escalated).
     *
     * Use only when operator has explicitly requested a retry via --retry flag.
     */
    public function findRetryableRuns(): Collection
    {
        return EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->where(function ($q): void {
                $q->whereNull('qa_status')
                    ->orWhereIn('qa_status', [
                        EnterpriseWikiIngestRun::QA_STATUS_PENDING,
                        EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
                        EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                        EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
                    ]);
            })
            ->orderBy('id')
            ->get();
    }

    // =========================================================================
    // Internal QA execution
    // =========================================================================

    private function executeQa(EnterpriseWikiIngestRun $run): array
    {
        // ── Level 1 + 2: technical and structural QA ─────────────────────────

        $checks = $this->runChecks($run);
        $hasCriticalGap = $this->hasCriticalGap($checks);

        $repairAttempted = false;
        $repairResult = null;

        if ($hasCriticalGap) {
            $run->update(['qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED]);

            $repairAttempted = true;
            $repairResult = $this->attemptRepair($run);

            // Re-check after repair attempt.
            $checks = $this->runChecks($run);
            $hasCriticalGap = $this->hasCriticalGap($checks);
        }

        $coverageSummary = $this->computeCoverageSummary($run);
        $lintSummary = $this->computeLintSummary($run);

        // After lint has written findings to DB, check whether any open errors remain.
        // Lint warnings are stored in qa_result but do not block passed.
        $hasOpenLintErrors = $this->hasOpenLintErrors($run);

        $structuralFailed = $hasCriticalGap || $hasOpenLintErrors;

        // ── Level 3: semantic QA (8G-4) ──────────────────────────────────────
        // Only runs when AI is enabled and tech/structural QA passed.
        // When AI is not enabled, semantic QA is skipped and the run may still pass
        // based on levels 1 and 2 alone.

        $semanticQaResult = null;

        if (! $structuralFailed && WikiSemanticQaAiClient::isAvailable()) {
            $semanticQaResult = $this->semanticQaService->review($run);
        }

        // ── Determine final status ────────────────────────────────────────────

        $finalStatus = $this->resolveFinalStatus($structuralFailed, $semanticQaResult);

        $result = [
            'checks' => $checks,
            'repair_attempted' => $repairAttempted,
            'repair_result' => $repairResult,
            'coverage_summary' => $coverageSummary,
            'lint_summary' => $lintSummary,
            'open_lint_errors' => $hasOpenLintErrors,
            'semantic_qa' => $semanticQaResult,
        ];

        $run->update([
            'qa_status' => $finalStatus,
            'qa_completed_at' => now(),
            'qa_last_error' => null,
            'qa_result' => $result,
        ]);

        return $result;
    }

    private function resolveFinalStatus(bool $structuralFailed, ?array $semanticQaResult): string
    {
        if ($structuralFailed) {
            return EnterpriseWikiIngestRun::QA_STATUS_ESCALATED;
        }

        if ($semanticQaResult === null) {
            // Semantic QA was skipped (AI not enabled) — pass on tech/structural alone.
            return EnterpriseWikiIngestRun::QA_STATUS_PASSED;
        }

        // Explicit escalation from semantic QA (e.g. source missing or unassessable).
        if (! empty($semanticQaResult['escalated'])) {
            return EnterpriseWikiIngestRun::QA_STATUS_ESCALATED;
        }

        // Skipped result (e.g. source type not supported) — treat as passed.
        if (! empty($semanticQaResult['skipped'])) {
            return EnterpriseWikiIngestRun::QA_STATUS_PASSED;
        }

        if ($semanticQaResult['pass']) {
            return EnterpriseWikiIngestRun::QA_STATUS_PASSED;
        }

        // Semantic QA failed — map repair action to status.
        return match ($semanticQaResult['recommended_repair_action'] ?? '') {
            'targeted_revision', 'full_regeneration' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            default => EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        };
    }

    // =========================================================================
    // Checks
    // =========================================================================

    private function runChecks(EnterpriseWikiIngestRun $run): array
    {
        $pivotPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $pagesByType = $pivotPageIds->isNotEmpty()
            ? EnterpriseWikiPage::query()
                ->whereIn('id', $pivotPageIds)
                ->get()
                ->groupBy('page_type')
            : collect();

        $articlePages = $pagesByType->get(EnterpriseWikiPage::PAGE_TYPE_ARTICLE, collect());
        $summaryPages = $pagesByType->get(EnterpriseWikiPage::PAGE_TYPE_SUMMARY, collect());

        $articleExists = $articlePages->isNotEmpty();
        $summaryExists = $summaryPages->isNotEmpty();

        $articleHasContent = $articleExists
            && $this->anyPageHasCurrentContent($articlePages->pluck('id'));

        $summaryHasContent = $summaryExists
            && $this->anyPageHasCurrentContent($summaryPages->pluck('id'));

        return [
            'article_exists' => $articleExists,
            'summary_exists' => $summaryExists,
            'article_has_content' => $articleHasContent,
            'summary_has_content' => $summaryHasContent,
        ];
    }

    private function hasCriticalGap(array $checks): bool
    {
        return ! $checks['article_exists']
            || ! $checks['summary_exists']
            || ! $checks['article_has_content']
            || ! $checks['summary_has_content'];
    }

    private function anyPageHasCurrentContent(Collection $pageIds): bool
    {
        if ($pageIds->isEmpty()) {
            return false;
        }

        return DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->whereNotNull('content_markdown')
            ->where('content_markdown', '!=', '')
            ->exists();
    }

    private function hasOpenLintErrors(EnterpriseWikiIngestRun $run): bool
    {
        return EnterpriseWikiLintFinding::query()
            ->where('customer_id', $run->customer_id)
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->where('severity', EnterpriseWikiLintFinding::SEVERITY_ERROR)
            ->exists();
    }

    // =========================================================================
    // Repair
    // =========================================================================

    private function attemptRepair(EnterpriseWikiIngestRun $run): array
    {
        try {
            $generated = $this->generateService->generate($run);

            Log::info('[WIKI_QA] Repair generation completed', [
                'run_id' => $run->id,
                'generated' => $generated,
            ]);

            return ['success' => true, 'generated' => $generated];
        } catch (\Throwable $e) {
            Log::warning('[WIKI_QA] Repair generation failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Supplementary metrics
    // =========================================================================

    private function computeCoverageSummary(EnterpriseWikiIngestRun $run): array
    {
        try {
            $coverage = $this->coverageService->computeForCustomer($run->customer_id);
            $sc = $coverage['source_coverage'] ?? [];
            $cc = $coverage['claim_coverage'] ?? [];
            $lint = $coverage['lint'] ?? [];

            return [
                'gap_count' => count($sc['gaps'] ?? []),
                'claim_coverage_pct' => $cc['claim_coverage_pct'] ?? null,
                'open_errors' => (int) ($lint['open_errors'] ?? 0),
                'open_warnings' => (int) ($lint['open_warnings'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function computeLintSummary(EnterpriseWikiIngestRun $run): array
    {
        try {
            $result = $this->lintService->lint($run);

            return [
                'findings_created' => $result['findings_created'] ?? 0,
                'errors' => $result['errors'] ?? 0,
                'warnings' => $result['warnings'] ?? 0,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
