<?php

namespace App\Console\Commands;

use App\Services\EnterpriseWiki\EnterpriseWikiClaimIntegrityRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wiki:repair-claim-integrity {--customer-id=} {--apply : Persist the classifications. Without this flag the command is dry-run only.}')]
#[Description('Classify existing Enterprise Wiki claims as source-based, best-practice, or internal generation errors.')]
class EnterpriseWikiRepairClaimIntegrity extends Command
{
    public function handle(EnterpriseWikiClaimIntegrityRepairService $service): int
    {
        $customerId = $this->option('customer-id') !== null
            ? (int) $this->option('customer-id')
            : null;
        $apply = (bool) $this->option('apply');

        $result = $service->repair($customerId > 0 ? $customerId : null, $apply);

        $this->info($apply ? '[WIKI_CLAIM_REPAIR] Applied classifications.' : '[WIKI_CLAIM_REPAIR] Dry-run only.');
        $this->line(sprintf('  Customer scope:      %s', $customerId > 0 ? (string) $customerId : 'all'));
        $this->line(sprintf('  Claims checked:      %d', $result['checked']));
        $this->line(sprintf('  Source-based:        %d', $result['source_based']));
        $this->line(sprintf('  Best-practice:       %d', $result['best_practice']));
        $this->line(sprintf('  Unsupported content: %d', $result['unsupported_generated_content']));
        $this->line(sprintf('  Internal errors:     %d', $result['internal_error']));
        $this->line(sprintf('  Wrong version:       %d', $result['wrong_version']));
        $this->line(sprintf('  Missing anchor:      %d', $result['missing_anchor']));
        $this->line(sprintf('  Unchanged/unknown:   %d', $result['unchanged']));

        if (! $apply) {
            $this->warn('No rows were changed. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
