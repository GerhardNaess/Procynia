<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionBlockProvenanceRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:repair-page-version-block-provenance
    {--run-id= : Repair only this ingest run (omit to sweep every applied run)}
    {--apply : Persist the repair. Without this flag the command is read-only.}')]
#[Description('Reconstruct content_blocks_json for a page\'s current version from an earlier version\'s blocks, and re-link unanchored claims to the correct block — never edits content_markdown, never creates a new version, never guesses at an ambiguous match.')]
class EnterpriseWikiRepairPageVersionBlockProvenance extends Command
{
    public function handle(EnterpriseWikiPageVersionBlockProvenanceRepairService $service): int
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
            ? '[WIKI_BLOCK_PROVENANCE_REPAIR] Repair applied.'
            : '[WIKI_BLOCK_PROVENANCE_REPAIR] Dry run — no changes were made.');
        $this->line(sprintf('  Page versions checked:                %d', $result['page_versions_checked']));
        $this->line(sprintf('  Page versions repaired%s: %d', $apply ? '' : ' (would be)', $result['page_versions_repaired']));
        $this->line(sprintf('  Page versions skipped (already had blocks): %d', $result['page_versions_skipped_already_has_blocks']));
        $this->line(sprintf('  Page versions skipped (no prior blocks):    %d', $result['page_versions_skipped_no_prior_blocks']));
        $this->line(sprintf('  Page versions skipped (ambiguous mapping):  %d', $result['page_versions_skipped_ambiguous']));

        if ($result['ambiguous_page_ids'] !== []) {
            $this->line('    Ambiguous page IDs: '.implode(', ', $result['ambiguous_page_ids']));
        }

        $this->newLine();
        $this->line(sprintf('  Claims checked:               %d', $result['claims_checked']));
        $this->line(sprintf('  Claims linked%s: %d', $apply ? '' : ' (would be)', $result['claims_linked']));
        $this->line(sprintf('  Claims already linked:        %d', $result['claims_already_linked']));
        $this->line(sprintf('  Claims still ambiguous:       %d', $result['claims_ambiguous']));

        if (! $apply) {
            $this->warn('Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
