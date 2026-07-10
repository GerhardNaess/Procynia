<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Source change detection and intelligent retry for escalated Enterprise Wiki ingest runs (8H-kjerne).
 *
 * Finds applied runs in `escalated` QA status whose source document has changed since
 * the last maintenance attempt and re-triggers QA. Idempotence is guaranteed by
 * `maintenance_source_hash`: a run is only retried when the current document hash
 * differs from the stored hash, preventing repeated retries on the same source version.
 */
class EnterpriseWikiMaintenanceCycleService
{
    public function __construct(
        private readonly EnterpriseWikiPostIngestQaService $qaService,
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

            return 'retried';
        } catch (\Throwable $e) {
            Log::error('[WIKI_MAINTENANCE] QA retry failed for run', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);

            return 'failed';
        }
    }
}
