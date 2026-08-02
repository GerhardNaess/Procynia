<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiRunBestPracticeReevaluationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:reevaluate-run-best-practice-claims {--run-id=} {--apply : Persist reclassifications. Without this flag the command is read-only.}')]
#[Description('Re-evaluate one Enterprise Wiki run\'s unsupported_generated_content claims for legitimate best-practice suggestions, using stored block metadata only.')]
class EnterpriseWikiReevaluateRunBestPracticeClaims extends Command
{
    public function handle(EnterpriseWikiRunBestPracticeReevaluationService $service): int
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

        $apply = (bool) $this->option('apply');
        $result = $service->reevaluate($run, $apply);

        $this->info($apply
            ? "[WIKI_BEST_PRACTICE_REEVAL] Applied reclassifications for run [{$run->id}]."
            : "[WIKI_BEST_PRACTICE_REEVAL] Read-only analysis for run [{$run->id}].");
        $this->line(sprintf('  Claims checked (unsupported_generated_content): %d', $result['checked']));
        $this->line(sprintf('  Eligible for best_practice:                     %d', $result['eligible']));
        $this->line(sprintf('  Reclassified:                                   %d', $result['reclassified']));
        $this->line(sprintf('  Skipped — no matching best_practice block:      %d', $result['skipped_no_matching_best_practice_block']));
        $this->line(sprintf('  Skipped — block missing best_practice_reason:   %d', $result['skipped_missing_best_practice_reason']));
        $this->line(sprintf('  Skipped — text not a genuine recommendation:    %d', $result['skipped_not_genuine_recommendation']));
        $this->line(sprintf('  Skipped — already authoritatively verified:     %d', $result['skipped_authoritative']));

        if ($result['candidates'] !== []) {
            $this->newLine();
            $this->table(
                ['Claim ID', 'Page ID', 'Block key', 'Claim text'],
                array_map(fn (array $c): array => [
                    $c['claim_id'],
                    $c['page_id'],
                    $c['block_key'] ?? '—',
                    mb_strimwidth($c['claim_text'], 0, 100, '…'),
                ], $result['candidates']),
            );
        }

        if (! $apply && $result['eligible'] > 0) {
            $this->warn('No rows were changed. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
