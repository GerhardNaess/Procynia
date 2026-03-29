<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinWatchProfileInboxDiscoveryService;
use Illuminate\Console\Command;

class DoffinWatchInboxDiscover extends Command
{
    protected $signature = 'doffin:watch-inbox-discover';

    protected $description = 'Run nightly Doffin live discovery for all active watch profiles and upsert scoped inbox records.';

    public function handle(DoffinWatchProfileInboxDiscoveryService $service): int
    {
        $summary = $service->run();

        $this->info('Watch inbox discovery completed.');
        $this->line('profiles_processed: '.$summary['profiles_processed']);
        $this->line('profiles_failed: '.$summary['profiles_failed']);
        $this->line('records_seen: '.$summary['records_seen']);
        $this->line('records_created: '.$summary['records_created']);
        $this->line('records_updated: '.$summary['records_updated']);

        return $summary['profiles_failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
