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
 */
class EnterpriseWikiMaintenanceCycleService
{
    public function __construct(
        private readonly EnterpriseWikiPostIngestQaService $qaService,
        private readonly EnterpriseWikiDeepRepairService $deepRepairService,
    ) {}

    /**
     * Run one maintenance cycle: find escalated runs with source changes and retry QA.
     *
     * Returns a summary array:
     * - retried (int) — runs where QA was re-triggered
     * - skipped (int) — runs whose source has not changed since the last attempt
     * - failed  (int) — runs where the QA retry threw an unexpected error
     */
    public function run(): array
    {
        $runs = $this->findEscalatedWithDocumentSource();

        $retried = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($runs as $run) {
            $outcome = $this->processRun($run);

            match ($outcome) {
                'retried' => $retried++,
                'skipped' => $skipped++,
                'failed'  => $failed++,
            };
        }

        Log::info('[WIKI_MAINTENANCE] Maintenance cycle complete', [
            'retried' => $retried,
            'skipped' => $skipped,
            'failed'  => $failed,
        ]);

        return compact('retried', 'skipped', 'failed');
    }

    // =========================================================================
    // Internal
    // =========================================================================

    private function findEscalatedWithDocumentSource(): Collection
    {
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
                'run_id'      => $run->id,
                'source_id'   => $run->source_id,
            ]);

            return 'skipped';
        }

        $currentHash = $document->file_hash_sha256;

        if ($currentHash === null || $currentHash === $run->maintenance_source_hash) {
            return 'skipped';
        }

        $run->update([
            'maintenance_triggered_at' => now(),
            'maintenance_source_hash'  => $currentHash,
        ]);

        Log::info('[WIKI_MAINTENANCE] Source changed — triggering QA retry', [
            'run_id'       => $run->id,
            'document_id'  => $document->id,
            'current_hash' => $currentHash,
            'prev_hash'    => $run->maintenance_source_hash,
        ]);

        try {
            $this->qaService->runForRun($run->fresh(), retry: true);
        } catch (\Throwable $e) {
            Log::error('[WIKI_MAINTENANCE] QA retry failed for run', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
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
