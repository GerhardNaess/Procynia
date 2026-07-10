<?php

namespace App\Console\Commands;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintenanceCycleService;
use Illuminate\Console\Command;

#[\Illuminate\Console\Attribute\AsCommand(name: 'wiki:maintenance-cycle')]
class EnterpriseWikiMaintenanceCycle extends Command
{
    protected $signature = 'wiki:maintenance-cycle';

    protected $description = 'Detect source changes and retry QA for escalated Enterprise Wiki ingest runs.';

    public function handle(EnterpriseWikiMaintenanceCycleService $service): int
    {
        $summary = $service->run();

        $this->line("[WIKI_MAINTENANCE] Retried: {$summary['retried']}, Skipped: {$summary['skipped']}, Failed: {$summary['failed']}.");

        return self::SUCCESS;
    }
}
