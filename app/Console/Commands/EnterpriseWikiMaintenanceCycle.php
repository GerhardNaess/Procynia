<?php

namespace App\Console\Commands;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'wiki:maintenance-cycle')]
class EnterpriseWikiMaintenanceCycle extends Command
{
    protected $signature = 'wiki:maintenance-cycle';

    protected $description = 'Detect source changes, regression snapshots, and retry QA for escalated Enterprise Wiki ingest runs.';

    public function handle(EnterpriseWikiMaintenanceCycleService $service): int
    {
        $summary = $service->run();

        $this->line("[WIKI_MAINTENANCE] Retried: {$summary['retried']}, Skipped: {$summary['skipped']}, Failed: {$summary['failed']}.");
        $this->line('[WIKI_MAINTENANCE] Verification-incomplete recovery — candidates: '.
            "{$summary['verification_recovery_candidates']}, resumed: {$summary['verification_recovery_resumed']}.");

        return self::SUCCESS;
    }
}
