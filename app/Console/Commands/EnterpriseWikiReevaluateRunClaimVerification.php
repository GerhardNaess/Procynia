<?php

namespace App\Console\Commands;

use App\Models\EnterpriseWikiIngestRun;
use App\Services\EnterpriseWiki\EnterpriseWikiRunClaimVerificationReevaluationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:reevaluate-run-claim-verification {--run-id=} {--apply : Persist reclassifications. Without this flag the command is read-only.}')]
#[Description('Re-evaluate one Enterprise Wiki run\'s unsupported_generated_content claims with the current semantic (cross-language/paraphrase) verification logic.')]
class EnterpriseWikiReevaluateRunClaimVerification extends Command
{
    public function handle(EnterpriseWikiRunClaimVerificationReevaluationService $service): int
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
            ? "[WIKI_CLAIM_VERIFICATION_REEVAL] Applied re-evaluation for run [{$run->id}]."
            : "[WIKI_CLAIM_VERIFICATION_REEVAL] Read-only analysis for run [{$run->id}].");

        $this->line('  Content origin distribution before:');
        $this->renderDistribution($result['before']);

        if ($apply) {
            $this->line('  Content origin distribution after:');
            $this->renderDistribution($result['after']);
        }

        $this->newLine();
        $this->line(sprintf('  Claims checked (unsupported_generated_content): %d', $result['checked']));
        $this->line(sprintf('  Newly supported (cross-language/paraphrase):    %d', $result['newly_supported']));
        $this->line(sprintf('  Still partially supported:                      %d', $result['still_partially_supported']));
        $this->line(sprintf('  Still contradicted:                             %d', $result['still_contradicted']));
        $this->line(sprintf('  Still not supported:                            %d', $result['still_not_supported']));
        $this->line(sprintf('  Deterministic conflicts (overrode AI verdict):  %d', $result['deterministic_conflicts']));

        foreach ($result['skipped'] as $reason => $count) {
            $this->line(sprintf('  Skipped — %-40s %d', $reason, $count));
        }

        if ($result['candidates'] !== []) {
            $this->newLine();
            $this->table(
                ['Claim ID', 'Page ID', 'AI verdict', 'Final verdict', 'Deterministic override', 'Reason'],
                array_map(fn (array $c): array => [
                    $c['claim_id'],
                    $c['page_id'],
                    $c['ai_verdict'],
                    $c['final_verdict'],
                    $c['deterministic_override'] ? 'yes' : 'no',
                    mb_strimwidth($c['reason'], 0, 80, '…'),
                ], $result['candidates']),
            );
        }

        if (! $apply && $result['checked'] > 0) {
            $this->warn('No rows were changed. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $distribution
     */
    private function renderDistribution(array $distribution): void
    {
        foreach ($distribution as $origin => $count) {
            $this->line(sprintf('    %-32s %d', $origin, $count));
        }
    }
}
