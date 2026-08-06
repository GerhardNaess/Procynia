<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a page's claim pipeline in sync whenever a repair path writes a NEW current
 * EnterpriseWikiPageVersion — without that repair path needing to know anything about claims
 * itself.
 *
 * A claim is keyed to a specific enterprise_wiki_page_version_id
 * (EnterpriseWikiClaim.enterprise_wiki_page_version_id). Creating a new current version does not
 * move or duplicate the old version's claims — they remain a correct historical record of the
 * superseded version. What must happen instead is that the new version gets its own extraction/
 * verification pass, exactly as if it had just been generated for the first time.
 *
 * EnterpriseWikiIngestRunPage.claims_extracted_at is the only checkpoint gating extraction
 * (EnterpriseWikiExtractPageClaimsService::extract() skips any row where it is already set).
 * EnterpriseWikiClaimContentRepairService established the pattern this service generalizes for
 * every other repair path: clear the checkpoint on the page's pivot row(s), then call
 * extract()/verify() for the owning run(s) — both are whole-run methods gated by that same
 * checkpoint, so calling them again only reprocesses the page(s) just cleared; every
 * already-synced page in the run is an untouched no-op.
 *
 * Deliberately does not also call EnterpriseWikiClaimSourceReconciliationService — that service
 * reconciles orphaned claims against a newly-ingested DOCUMENT (its own, separate checkpoint is
 * per claim+document, triggered by ReconcileEnterpriseWikiClaimSourcesForDocument on document
 * ingest), not per page version. EnterpriseWikiVerifyPageClaimsService already attaches source
 * references directly as part of verification — the same scope EnterpriseWikiClaimContentRepairService
 * (the one pre-existing precedent for "new version -> re-sync claims") uses: extract, then verify,
 * nothing more.
 */
class EnterpriseWikiPageVersionClaimSyncService
{
    public function __construct(
        private readonly EnterpriseWikiExtractPageClaimsService $extractService,
        private readonly EnterpriseWikiVerifyPageClaimsService $verifyService,
    ) {}

    /**
     * Clears the claim-extraction checkpoint on every ingest-run pivot row for this page. A page
     * normally belongs to the one run that generated it, but is not assumed to — a page can be an
     * incremental-relink candidate belonging to a different run than the one currently repairing
     * it, so every pivot row referencing it is reset.
     *
     * @return list<int> distinct ingest run ids that now need syncRuns() to actually
     *                   re-extract/verify this page's claims
     */
    public function markPageForResync(EnterpriseWikiPage $page): array
    {
        $pivots = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->get(['id', 'enterprise_wiki_ingest_run_id']);

        if ($pivots->isEmpty()) {
            return [];
        }

        EnterpriseWikiIngestRunPage::query()
            ->whereIn('id', $pivots->pluck('id'))
            ->update([
                'claims_extracted_at' => null,
                'claims_claimed_at' => null,
                'claims_claim_token' => null,
            ]);

        return $pivots->pluck('enterprise_wiki_ingest_run_id')->unique()->values()->all();
    }

    /**
     * @param  list<int>  $runIds
     */
    public function syncRuns(array $runIds): void
    {
        foreach (array_unique($runIds) as $runId) {
            $run = EnterpriseWikiIngestRun::query()->find($runId);

            if ($run === null) {
                continue;
            }

            if ($run->isAwaitingHumanAction()) {
                continue;
            }

            try {
                $this->syncRun($run);
            } catch (Throwable $e) {
                Log::error('[WIKI_PAGE_VERSION_CLAIM_SYNC] Sync failed for run.', [
                    'run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Re-runs extraction and verification for a run. Both only act on pivot rows / claims whose
     * checkpoint is unset, so calling this after markPageForResync() reprocesses exactly the
     * page(s) just marked.
     *
     * @return array{extraction: array, verification: array}
     */
    public function syncRun(EnterpriseWikiIngestRun $run): array
    {
        if ($run->isAwaitingHumanAction()) {
            return [
                'extraction' => [
                    'pages' => 0,
                    'claims' => 0,
                    'skipped' => 0,
                    'busy' => 0,
                    'capped_pages' => 0,
                ],
                'verification' => [
                    'pages' => 0,
                    'claims' => 0,
                    'references' => 0,
                    'skipped' => 0,
                    'no_support' => 0,
                    'busy' => 0,
                    'reused' => 0,
                ],
            ];
        }

        $extraction = $this->extractService->extract($run);
        $verification = $this->verifyService->verify($run);

        return [
            'extraction' => $extraction,
            'verification' => $verification,
        ];
    }
}
