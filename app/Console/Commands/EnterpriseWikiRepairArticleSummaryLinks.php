<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiArticleSummaryLinkRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:repair-article-summary-links
    {--run-id= : Repair only this ingest run (omit to sweep every applied run)}
    {--apply : Persist the repair. Without this flag the command is read-only.}')]
#[Description('Add the missing mutual [[wikilink]] between an article page and its paired summary page, for existing unambiguous pairs only.')]
class EnterpriseWikiRepairArticleSummaryLinks extends Command
{
    public function handle(EnterpriseWikiArticleSummaryLinkRepairService $service): int
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
            ? '[WIKI_ARTICLE_SUMMARY_LINKS] Repair applied.'
            : '[WIKI_ARTICLE_SUMMARY_LINKS] Dry run — no changes were made.');
        $this->line(sprintf('  Runs checked:                    %d', $result['runs_checked']));
        $this->line(sprintf('  Runs skipped (no article/summary): %d', $result['runs_skipped_no_pair']));
        $this->line(sprintf('  Runs skipped (ambiguous pair):   %d', $result['runs_skipped_ambiguous']));
        $this->line(sprintf('  Pages linked%s: %d', $apply ? '' : ' (would be)', $result['pages_linked']));
        $this->line(sprintf('  Pages already linked:            %d', $result['pages_already_linked']));
        $this->line(sprintf('  Pages skipped (conflicting link): %d', $result['pages_skipped_conflicting_link']));

        if (! $apply) {
            $this->warn('Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
