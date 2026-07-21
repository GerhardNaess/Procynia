<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('wiki:resync-wikilink-graph
    {--run-id= : Resync only this ingest run (omit to sweep every applied run)}
    {--apply : Persist the resync. Without this flag the command is read-only.}')]
#[Description('Rebuild every page\'s link_type=wikilink graph edges from its current version content — removes edges no longer present in the text, creates missing ones, refreshes changed ones.')]
class EnterpriseWikiResyncWikilinkGraph extends Command
{
    public function handle(
        EnterpriseWikiBuildPageLinksService $buildPageLinksService,
        EnterpriseWikiAppliedRunLintService $lintService,
    ): int {
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

        $totals = [
            'runs_checked' => 0,
            'pages_processed' => 0,
            'created' => 0,
            'updated' => 0,
            'stale_links_removed' => 0,
        ];

        $query = EnterpriseWikiIngestRun::query()
            ->where('maintainer_decision_status', EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED);

        if ($run !== null) {
            $query->where('id', $run->id);
        }

        $query->orderBy('id')->chunkById(50, function ($runs) use (&$totals, $apply, $buildPageLinksService, $lintService): void {
            foreach ($runs as $r) {
                $totals['runs_checked']++;

                // materializeWikilinksForRun() already wraps each page's write in its own
                // DB::transaction() — nesting it inside one more (via a savepoint) and rolling
                // that outer transaction back gives an exact, zero-duplicated-logic dry run.
                if ($apply) {
                    $result = $buildPageLinksService->materializeWikilinksForRun($r);
                } else {
                    DB::beginTransaction();
                    $result = $buildPageLinksService->materializeWikilinksForRun($r);
                    DB::rollBack();
                }

                $totals['pages_processed'] += $result['pages_processed'];
                $totals['created'] += $result['created'];
                $totals['updated'] += $result['updated'];
                $totals['stale_links_removed'] += $result['stale_links_removed'];

                $changed = ($result['created'] + $result['updated'] + $result['stale_links_removed']) > 0;

                if ($apply && $changed) {
                    $lintService->lint($r);
                }
            }
        });

        $this->info($apply
            ? '[WIKI_WIKILINK_GRAPH_RESYNC] Resync applied.'
            : '[WIKI_WIKILINK_GRAPH_RESYNC] Dry run — no changes were made.');
        $this->line(sprintf('  Runs checked:              %d', $totals['runs_checked']));
        $this->line(sprintf('  Pages processed:           %d', $totals['pages_processed']));
        $this->line(sprintf('  Links created%s:            %d', $apply ? '' : ' (would be)', $totals['created']));
        $this->line(sprintf('  Links updated%s:            %d', $apply ? '' : ' (would be)', $totals['updated']));
        $this->line(sprintf('  Stale links removed%s:      %d', $apply ? '' : ' (would be)', $totals['stale_links_removed']));

        if (! $apply) {
            $this->warn('Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
