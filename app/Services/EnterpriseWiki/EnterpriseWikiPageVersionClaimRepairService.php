<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Repairs existing applied runs whose current page version was replaced (by
 * EnterpriseWikiLinkSemanticRepairService, EnterpriseWikiIncrementalRelinkService, or
 * EnterpriseWikiArticleSummaryLinkRepairService) before those services re-synced claims for the
 * new version — the exact drift EnterpriseWikiAppliedRunLintService reports as
 * CODE_PAGE_WITHOUT_CLAIMS.
 *
 * Only ever touches a page whose CURRENT version has zero claims AND whose claim-extraction
 * checkpoint predates that version (or was never set) — a page that already has claims on its
 * current version, or was already resynced and genuinely produced none, is left completely
 * untouched, so a repeat run is a no-op that makes no further AI calls. Never regenerates page
 * content: it only clears the claim-extraction checkpoint
 * (EnterpriseWikiPageVersionClaimSyncService::markPageForResync()) and lets the existing
 * extraction/verification pipeline run against the unmodified current content_markdown, exactly
 * as it would for a brand-new page — a page that genuinely has no extractable facts still
 * legitimately ends up with zero claims and its lint finding correctly stays open.
 */
class EnterpriseWikiPageVersionClaimRepairService
{
    public function __construct(
        private readonly EnterpriseWikiPageVersionClaimSyncService $claimSyncService,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
    ) {}

    /**
     * @return array{
     *     runs_checked: int,
     *     pages_checked: int,
     *     pages_missing_claims: int,
     *     pages_already_synced: int,
     * }
     */
    public function repair(?EnterpriseWikiIngestRun $onlyRun, bool $apply): array
    {
        $result = [
            'runs_checked' => 0,
            'pages_checked' => 0,
            'pages_missing_claims' => 0,
            'pages_already_synced' => 0,
        ];

        if ($onlyRun?->isTerminal() === true || $onlyRun?->isAwaitingHumanAction() === true) {
            return $result;
        }

        $query = EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED)
            ->nonTerminal();

        if ($onlyRun !== null) {
            $query->where('id', $onlyRun->id);
        }

        $query->whereNotIn('status', [
            EnterpriseWikiIngestRun::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
            EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
        ]);

        $query->orderBy('id')->chunkById(50, function ($runs) use (&$result, $apply): void {
            foreach ($runs as $run) {
                if ($run->isTerminal() || $run->isAwaitingHumanAction()) {
                    continue;
                }

                $this->repairRun($run, $apply, $result);
            }
        });

        return $result;
    }

    /**
     * @param  array<string, int>  $result
     */
    private function repairRun(EnterpriseWikiIngestRun $run, bool $apply, array &$result): void
    {
        if ($run->isAwaitingHumanAction()) {
            return;
        }

        $result['runs_checked']++;

        $pivotRows = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page.currentVersion')
            ->get();

        $affectedRunIds = [];
        $anyMissing = false;

        foreach ($pivotRows as $row) {
            $page = $row->page;
            $version = $page?->currentVersion;

            if ($page === null || $version === null || trim((string) $version->content_markdown) === '') {
                continue;
            }

            $result['pages_checked']++;

            $hasClaims = EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->exists();

            // A page can legitimately have zero extractable facts — once extraction has
            // genuinely run against THIS version (checkpoint no older than the version itself)
            // and still found nothing, that is a correct terminal state, not drift. Only a
            // checkpoint that predates the current version (or was never set at all despite the
            // run being applied) indicates the version was swapped out from under it.
            $alreadyProcessedForCurrentVersion = $row->claims_extracted_at !== null
                && $row->claims_extracted_at->greaterThanOrEqualTo($version->created_at);

            if ($hasClaims || $alreadyProcessedForCurrentVersion) {
                $result['pages_already_synced']++;

                continue;
            }

            $result['pages_missing_claims']++;
            $anyMissing = true;

            if ($apply) {
                $affectedRunIds = array_merge($affectedRunIds, $this->claimSyncService->markPageForResync($page));
            }
        }

        if (! $apply || ! $anyMissing) {
            return;
        }

        try {
            $this->claimSyncService->syncRuns($affectedRunIds);
        } catch (Throwable $e) {
            Log::error('[WIKI_PAGE_VERSION_CLAIM_REPAIR] Claim resync failed.', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        foreach (array_unique($affectedRunIds) as $runId) {
            $affectedRun = EnterpriseWikiIngestRun::query()->find($runId);

            if ($affectedRun !== null) {
                $this->lintService->lint($affectedRun);
            }
        }
    }
}
