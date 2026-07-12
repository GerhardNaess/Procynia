<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Targeted deep repair of claims, source references, and page links for escalated runs (8H-kjerne delfase 2).
 *
 * Called after intelligent retry (delfase 1) still leaves a run escalated.
 * Diagnoses which structural components are missing, runs targeted repairs for those
 * components only, and re-evaluates the full QA pipeline.
 *
 * Idempotence: one deep repair attempt is allowed per (run, source_hash) pair.
 * The same unchanged source cannot trigger a second attempt until the document changes.
 *
 * Component repairs reuse existing idempotent services:
 * - Claims:            EnterpriseWikiExtractPageClaimsService::extract()
 * - Source references: EnterpriseWikiVerifyPageClaimsService::verify()
 * - Page links:        EnterpriseWikiBuildPageLinksService::build()
 *
 * After repairs, the full post-ingest QA pipeline is re-run via runForRun(retry: true).
 * Result is stored in deep_repair_result on the run for traceability.
 */
class EnterpriseWikiDeepRepairService
{
    public function __construct(
        private readonly EnterpriseWikiExtractPageClaimsService $claimsService,
        private readonly EnterpriseWikiVerifyPageClaimsService $verifyService,
        private readonly EnterpriseWikiBuildPageLinksService $linksService,
        private readonly EnterpriseWikiPostIngestQaService $qaService,
    ) {}

    /**
     * Attempt a targeted deep repair for an escalated run.
     *
     * Returns a result array with keys:
     * - attempted (bool)
     * - reason (string|null)          — why not attempted, or null on success
     * - source_hash (string|null)     — the document hash this repair was for
     * - diagnosis (array|null)        — which components were found missing
     * - components_repaired (string[]) — ['claims', 'source_references', 'page_links']
     * - qa_status (string|null)       — final QA status after re-evaluation
     *
     * Graceful failures (nothing to repair, QA error, component error) are captured in
     * deep_repair_result on the run. This method never throws.
     */
    public function attempt(EnterpriseWikiIngestRun $run, string $currentHash): array
    {
        // Deep repair is only ever called by EnterpriseWikiMaintenanceCycleService after a QA
        // retry has run and the run is still qa_status=escalated. A run that failed earlier in
        // the ordinary document flow (maintainer decision, apply, page generation, wikilink
        // validation/materialization) has qa_status=null and is excluded further upstream by
        // EnterpriseWikiPostIngestQaService::scopeToRunsReadyForQa() — its QA retry is a no-op,
        // so qa_status never becomes escalated and this method is never reached for it. No
        // separate `status` guard is added here: decision-only runs (source_type
        // enterprise_wiki_document, main `status` permanently `decision_only`) are a distinct,
        // legitimate run type that also relies on deep repair and must remain unaffected.

        // ── Idempotence ──────────────────────────────────────────────────────
        if ($run->deep_repair_source_hash === $currentHash) {
            Log::info('[WIKI_DEEP_REPAIR] Already attempted for this source hash — skipping', [
                'run_id' => $run->id,
                'hash'   => $currentHash,
            ]);

            return $this->result(attempted: false, reason: 'already_attempted_for_hash');
        }

        // ── Diagnosis ────────────────────────────────────────────────────────
        $diagnosis = $this->diagnose($run);

        $hasRepairables = $diagnosis['claims'] || $diagnosis['source_references'] || $diagnosis['page_links'];

        if (! $hasRepairables) {
            Log::info('[WIKI_DEEP_REPAIR] No claim/ref/link gaps found — nothing to repair', [
                'run_id' => $run->id,
            ]);

            return $this->result(
                attempted: false,
                reason: 'no_repairables',
                sourceHash: $currentHash,
                diagnosis: $diagnosis,
            );
        }

        // Stamp before repairs so idempotence holds even if repairs fail.
        $run->update([
            'deep_repair_attempted_at' => now(),
            'deep_repair_source_hash'  => $currentHash,
        ]);

        Log::info('[WIKI_DEEP_REPAIR] Starting deep repair', [
            'run_id'    => $run->id,
            'diagnosis' => $diagnosis,
        ]);

        // ── Targeted repairs ─────────────────────────────────────────────────
        $componentsRepaired = [];

        try {
            if ($diagnosis['claims']) {
                $this->claimsService->extract($run);
                $componentsRepaired[] = 'claims';
            }

            if ($diagnosis['source_references']) {
                $this->verifyService->verify($run);
                $componentsRepaired[] = 'source_references';
            }

            if ($diagnosis['page_links']) {
                $this->linksService->build($run);
                $componentsRepaired[] = 'page_links';
            }
        } catch (\Throwable $e) {
            Log::error('[WIKI_DEEP_REPAIR] Component repair failed', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);

            $errorResult = $this->result(
                attempted: true,
                reason: 'component_repair_failed',
                sourceHash: $currentHash,
                diagnosis: $diagnosis,
                componentsRepaired: $componentsRepaired,
                qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            );

            $run->update([
                'qa_status'          => EnterpriseWikiIngestRun::QA_STATUS_FAILED,
                'qa_last_error'      => '[DEEP_REPAIR] ' . $e->getMessage(),
                'deep_repair_result' => $errorResult,
            ]);

            return $errorResult;
        }

        // Store partial result before QA so the snapshot created inside runForRun
        // captures repair context (source_hash, diagnosis, components_repaired).
        $run->update([
            'deep_repair_result' => $this->result(
                attempted: true,
                sourceHash: $currentHash,
                diagnosis: $diagnosis,
                componentsRepaired: $componentsRepaired,
                qaStatus: null,
            ),
        ]);

        // ── QA re-evaluation ─────────────────────────────────────────────────
        try {
            $this->qaService->runForRun($run->fresh(), retry: true);
        } catch (\Throwable $e) {
            // runForRun already marks the run as failed and sets qa_last_error.
            Log::error('[WIKI_DEEP_REPAIR] QA re-evaluation after repair failed', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);

            $run->refresh();
            $repairResult = $this->result(
                attempted: true,
                reason: 'qa_re_evaluation_failed',
                sourceHash: $currentHash,
                diagnosis: $diagnosis,
                componentsRepaired: $componentsRepaired,
                qaStatus: $run->qa_status,
            );
            $run->update(['deep_repair_result' => $repairResult]);

            return $repairResult;
        }

        $run->refresh();
        $finalStatus = $run->qa_status;

        $repairResult = $this->result(
            attempted: true,
            sourceHash: $currentHash,
            diagnosis: $diagnosis,
            componentsRepaired: $componentsRepaired,
            qaStatus: $finalStatus,
        );

        $run->update(['deep_repair_result' => $repairResult]);

        Log::info('[WIKI_DEEP_REPAIR] Deep repair complete', [
            'run_id'              => $run->id,
            'components_repaired' => $componentsRepaired,
            'qa_status'           => $finalStatus,
        ]);

        return $repairResult;
    }

    // =========================================================================
    // Diagnosis
    // =========================================================================

    /**
     * Determine which structural components are missing for a run's pages.
     *
     * @return array{claims: bool, source_references: bool, page_links: bool}
     */
    private function diagnose(EnterpriseWikiIngestRun $run): array
    {
        $pivotPageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        if ($pivotPageIds->isEmpty()) {
            return ['claims' => false, 'source_references' => false, 'page_links' => false];
        }

        // Current version IDs for pages in this run
        $versionIds = DB::table('enterprise_wiki_page_versions')
            ->whereIn('enterprise_wiki_page_id', $pivotPageIds)
            ->where('is_current', true)
            ->pluck('id');

        // Claims: any current-version page has no claims extracted yet
        if ($versionIds->isEmpty()) {
            $claimsNeeded = false;
        } else {
            $versionsWithClaims = EnterpriseWikiClaim::query()
                ->whereIn('enterprise_wiki_page_version_id', $versionIds)
                ->distinct('enterprise_wiki_page_version_id')
                ->pluck('enterprise_wiki_page_version_id');

            $claimsNeeded = $versionsWithClaims->count() < $versionIds->count();
        }

        // Source references: any claim for a run page version has no source reference
        $claimIds = $versionIds->isNotEmpty()
            ? EnterpriseWikiClaim::query()
                ->whereIn('enterprise_wiki_page_version_id', $versionIds)
                ->pluck('id')
            : collect();

        if ($claimIds->isEmpty()) {
            $sourceRefsNeeded = false;
        } else {
            $claimsWithRef = EnterpriseWikiSourceReference::query()
                ->whereIn('enterprise_wiki_claim_id', $claimIds)
                ->distinct('enterprise_wiki_claim_id')
                ->pluck('enterprise_wiki_claim_id');

            $sourceRefsNeeded = $claimsWithRef->count() < $claimIds->count();
        }

        // Page links: any page in the run has no outbound links
        $linksNeeded = EnterpriseWikiPageLink::query()
            ->whereIn('from_page_id', $pivotPageIds)
            ->doesntExist();

        return [
            'claims'            => $claimsNeeded,
            'source_references' => $sourceRefsNeeded,
            'page_links'        => $linksNeeded,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function result(
        bool $attempted,
        ?string $reason = null,
        array $componentsRepaired = [],
        ?string $qaStatus = null,
        ?string $sourceHash = null,
        ?array $diagnosis = null,
    ): array {
        return [
            'attempted'           => $attempted,
            'reason'              => $reason,
            'source_hash'         => $sourceHash,
            'diagnosis'           => $diagnosis,
            'components_repaired' => $componentsRepaired,
            'qa_status'           => $qaStatus,
        ];
    }
}
