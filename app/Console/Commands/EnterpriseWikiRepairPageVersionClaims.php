<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionClaimRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:repair-page-version-claims
    {--run-id= : Repair only this ingest run (omit to sweep every applied run)}
    {--apply : Persist the repair. Without this flag the command is read-only.}')]
#[Description('Re-extract and re-verify claims for a page whose current version has zero claims because a repair replaced it without re-syncing claims.')]
class EnterpriseWikiRepairPageVersionClaims extends Command
{
    public function handle(EnterpriseWikiPageVersionClaimRepairService $service): int
    {
        $runIdOption = $this->option('run-id');
        $run = null;

        if ($runIdOption !== null && $runIdOption !== '') {
            if (! is_numeric($runIdOption)) {
                $this->error('--run-id must be numeric.');

                return self::FAILURE;
            }

            $run = EnterpriseWikiIngestRun::query()->find((int) $runIdOption);

            if ($run === null) {
                $this->error("Run [{$runIdOption}] not found.");

                return self::FAILURE;
            }
        }

        $apply = (bool) $this->option('apply');
        $result = $service->repair($run, $apply);

        $this->info($apply
            ? '[WIKI_PAGE_VERSION_CLAIM_REPAIR] Repair applied.'
            : '[WIKI_PAGE_VERSION_CLAIM_REPAIR] Dry run — no changes were made.');
        $this->line(sprintf('  Runs checked:            %d', $result['runs_checked']));
        $this->line(sprintf('  Pages checked:           %d', $result['pages_checked']));
        $this->line(sprintf('  Pages missing claims%s: %d', $apply ? ' (resynced)' : ' (would resync)', $result['pages_missing_claims']));
        $this->line(sprintf('  Pages already synced:    %d', $result['pages_already_synced']));

        if (! $apply) {
            $this->warn('Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
