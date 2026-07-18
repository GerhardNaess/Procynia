<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Source change detection, intelligent retry, and deep repair for escalated Enterprise Wiki ingest runs (8H-kjerne).
 *
 * Delfase 1: finds applied runs in `escalated` QA status whose source document has changed,
 * and re-triggers QA (intelligent retry). Idempotence is guaranteed by `maintenance_source_hash`.
 *
 * Delfase 2: if the run is still escalated after intelligent retry, attempts one targeted
 * deep repair of claims, source references, and page links via EnterpriseWikiDeepRepairService.
 * Idempotence for deep repair is guaranteed by `deep_repair_source_hash`.
 *
 * 8H-utvidelse: analyses immutable QA snapshots for threshold-based regressions and routes
 * repairable cases through the existing repair flow.
 */
class EnterpriseWikiMaintenanceCycleService
{
    public function __construct(
        private readonly EnterpriseWikiPostIngestQaService $qaService,
        private readonly EnterpriseWikiDeepRepairService $deepRepairService,
        private readonly EnterpriseWikiClaimContentRepairService $claimContentRepairService,
        private readonly EnterpriseWikiQaRegressionService $regressionService,
    ) {}

    /**
     * Run one maintenance cycle: find escalated runs with source changes and retry QA, and
     * attempt a bounded claim-content repair for runs stopped with qa_status=repair_required
     * (see EnterpriseWikiClaimContentRepairService — unlike the source-change retry above, this
     * is not gated on the source document having changed, since the defect is in the run's own
     * generated content, not the source).
     *
     * Returns a summary array:
     * - retried (int) — runs where QA was re-triggered
     * - skipped (int) — runs whose source has not changed since the last attempt
     * - failed  (int) — runs where the QA retry threw an unexpected error
     * - claim_content_repairs_attempted (int) — repair_required runs a repair was attempted for
     */
    public function run(): array
    {
        $runs = $this->findEscalatedWithDocumentSource();

        $retried = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($runs as $run) {
            $outcome = $this->processRun($run);

            match ($outcome) {
                'retried' => $retried++,
                'skipped' => $skipped++,
                'failed' => $failed++,
            };
        }

        $claimContentRepairsAttempted = $this->processClaimContentRepairs();

        $regressionSummary = $this->regressionService->processPendingSnapshots();

        Log::info('[WIKI_MAINTENANCE] Maintenance cycle complete', [
            'retried' => $retried,
            'skipped' => $skipped,
            'failed' => $failed,
            'claim_content_repairs_attempted' => $claimContentRepairsAttempted,
            'regressions' => $regressionSummary,
        ]);

        return [
            'retried' => $retried,
            'skipped' => $skipped,
            'failed' => $failed,
            'claim_content_repairs_attempted' => $claimContentRepairsAttempted,
        ];
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Attempt a bounded claim-content repair (EnterpriseWikiClaimContentRepairService) for every
     * applied run currently stopped with qa_status=repair_required — see
     * EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects()/
     * EnterpriseWikiDocumentFlowService::escalateRunForClaimIntegrityRepair(). The repair
     * service itself enforces MAX_CLAIM_CONTENT_REPAIR_ATTEMPTS and is a no-op once reached, so
     * repeatedly finding the same run across maintenance ticks is safe.
     *
     * @return int number of runs a repair attempt was made for
     */
    private function processClaimContentRepairs(): int
    {
        $runs = EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->where('qa_status', EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->where('claim_content_repair_attempt_count', '<', EnterpriseWikiIngestRun::MAX_CLAIM_CONTENT_REPAIR_ATTEMPTS)
            ->orderBy('id')
            ->get();

        $attempted = 0;

        foreach ($runs as $run) {
            try {
                $this->claimContentRepairService->attempt($run);
                $attempted++;
            } catch (\Throwable $e) {
                Log::error('[WIKI_MAINTENANCE] Claim-content repair attempt failed', [
                    'run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $attempted;
    }

    private function findEscalatedWithDocumentSource(): Collection
    {
        // qa_status=escalated alone is the correct signal here (not main `status` — decision-
        // only runs legitimately reach qa_status=escalated while their main `status` stays
        // `decision_only` forever). A run that failed earlier in the ordinary document flow
        // never reaches qa_status=escalated in the first place — see
        // EnterpriseWikiPostIngestQaService::scopeToRunsReadyForQa().
        return EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->where('qa_status', EnterpriseWikiIngestRun::QA_STATUS_ESCALATED)
            ->where('source_type', EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->orderBy('id')
            ->get();
    }

    private function processRun(EnterpriseWikiIngestRun $run): string
    {
        $document = EnterpriseWikiDocument::find($run->source_id);

        if ($document === null) {
            Log::warning('[WIKI_MAINTENANCE] Source document not found — skipping run', [
                'run_id' => $run->id,
                'source_id' => $run->source_id,
            ]);

            return 'skipped';
        }

        $currentHash = $document->file_hash_sha256;
        $previousHash = $run->maintenance_source_hash;

        if ($currentHash === null || $currentHash === $previousHash) {
            return 'skipped';
        }

        $run->update([
            'maintenance_triggered_at' => now(),
            'maintenance_source_hash' => $currentHash,
        ]);

        // $previousHash was captured before the update() call above, which otherwise mutates
        // maintenance_source_hash on this same model instance — logging $run->maintenance_source_hash
        // here would always show the new hash, making current/previous look identical even when
        // they genuinely differ (or, for a run never checked before, wrongly imply a "change"
        // from a real prior hash rather than from an absent one).
        Log::info(
            $previousHash === null
                ? '[WIKI_MAINTENANCE] First maintenance check for this run — triggering QA retry'
                : '[WIKI_MAINTENANCE] Source changed — triggering QA retry',
            [
                'run_id' => $run->id,
                'document_id' => $document->id,
                'current_hash' => $currentHash,
                'prev_hash' => $previousHash,
            ],
        );

        try {
            $this->qaService->runForRun($run->fresh(), retry: true);
        } catch (\Throwable $e) {
            Log::error('[WIKI_MAINTENANCE] QA retry failed for run', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        // If still escalated after intelligent retry, attempt targeted deep repair (delfase 2).
        $fresh = $run->fresh();

        if ($fresh->qa_status === EnterpriseWikiIngestRun::QA_STATUS_ESCALATED) {
            $this->deepRepairService->attempt($fresh, $currentHash);
        }

        return 'retried';
    }
}
