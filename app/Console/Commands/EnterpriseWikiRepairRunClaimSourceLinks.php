<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\EnterpriseWiki\EnterpriseWikiRepairRunClaimSourceLinksService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:repair-run-claim-source-links
    {--run-id= : Ingest run to repair claims for}
    {--claim-ids= : Comma-separated claim IDs to repair (required, never a broad sweep)}
    {--supplement=* : Optional page_version_id:block_key:source_element_key entries to add a missing source element to an existing block before matching}
    {--apply : Persist the repair. Without this flag the command is read-only.}')]
#[Description("Relink specific Enterprise Wiki claims to their correct existing content_block_key and restore that block's source references. Never guesses when more than one block matches.")]
class EnterpriseWikiRepairRunClaimSourceLinks extends Command
{
    public function handle(EnterpriseWikiRepairRunClaimSourceLinksService $service): int
    {
        $runId = $this->option('run-id');

        if ($runId === null || ! is_numeric($runId)) {
            $this->error('--run-id is required.');

            return self::FAILURE;
        }

        $run = EnterpriseWikiIngestRun::query()->find((int) $runId);

        if ($run === null) {
            $this->error("Run [{$runId}] not found.");

            return self::FAILURE;
        }

        $claimIdsOption = (string) $this->option('claim-ids');

        if (trim($claimIdsOption) === '') {
            $this->error('--claim-ids is required (comma-separated claim IDs).');

            return self::FAILURE;
        }

        $claimIds = array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $claimIdsOption),
        ), static fn (int $id): bool => $id > 0));

        if ($claimIds === []) {
            $this->error('--claim-ids did not contain any valid claim IDs.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        foreach ((array) $this->option('supplement') as $supplement) {
            $parts = explode(':', (string) $supplement, 3);

            if (count($parts) !== 3 || ! is_numeric($parts[0])) {
                $this->error("Invalid --supplement value [{$supplement}]. Expected page_version_id:block_key:source_element_key.");

                return self::FAILURE;
            }

            [$versionId, $blockKey, $sourceElementKey] = $parts;
            $version = EnterpriseWikiPageVersion::query()->find((int) $versionId);

            if ($version === null) {
                $this->error("Page version [{$versionId}] not found for --supplement.");

                return self::FAILURE;
            }

            $result = $service->addMissingSourceElement($version, $blockKey, $sourceElementKey, $apply);
            $this->line(sprintf(
                '  Supplement %s:%s:%s — %s',
                $versionId,
                $blockKey,
                $sourceElementKey,
                $result['reason'],
            ));
        }

        $result = $service->repair($run, $claimIds, $apply);

        $this->info($apply
            ? "[WIKI_CLAIM_SOURCE_LINK_REPAIR] Applied repair for run [{$run->id}]."
            : "[WIKI_CLAIM_SOURCE_LINK_REPAIR] Read-only analysis for run [{$run->id}].");

        $this->line(sprintf('  Claims requested:          %d', count($claimIds)));
        $this->line(sprintf('  Relinked:                  %d', $result['relinked']));
        $this->line(sprintf('  Source references created: %d', $result['references_created']));
        $this->line(sprintf('  Unchanged (already ok):    %d', $result['unchanged']));
        $this->line(sprintf('  Ambiguous (not repaired):  %d', $result['ambiguous']));
        $this->line(sprintf('  No match (not repaired):   %d', $result['no_match']));
        $this->line(sprintf('  Not found in this run:     %d', $result['not_found']));

        if ($result['results'] !== []) {
            $this->newLine();
            $this->table(
                ['Claim ID', 'Page ID', 'Status', 'Block key', 'Details'],
                array_map(function (array $r): array {
                    $details = match ($r['status']) {
                        'ambiguous' => 'matches: '.implode(', ', $r['matched_block_keys'] ?? []),
                        'relinked' => ($r['block_key_changed'] ?? false ? 'block_key set; ' : '').
                            (($r['new_source_element_keys'] ?? []) !== [] ? 'new refs: '.implode(', ', $r['new_source_element_keys']) : 'no new refs'),
                        default => '',
                    };

                    return [
                        $r['claim_id'],
                        $r['page_id'] ?? '—',
                        $r['status'],
                        $r['block_key'] ?? '—',
                        $details,
                    ];
                }, $result['results']),
            );
        }

        if (! $apply) {
            $this->warn('No rows were changed. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
