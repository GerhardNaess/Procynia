<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinWatchProfileInboxDiscoveryService;
use App\Services\Doffin\DoffinWatchInboxDigestService;
use Illuminate\Console\Command;
use RuntimeException;

class DoffinWatchInboxDiscover extends Command
{
    protected $signature = 'doffin:watch-inbox-discover {--trigger=manual}';

    protected $description = 'Run nightly Doffin live discovery for all active watch profiles and upsert scoped inbox records.';

    public function handle(
        DoffinWatchProfileInboxDiscoveryService $service,
        DoffinWatchInboxDigestService $digestService,
    ): int
    {
        $trigger = $this->resolveTrigger();

        $this->line('Starting Doffin watch inbox discovery.');
        $this->line("trigger: {$trigger}");

        $summary = $service->run(null, $trigger);

        if (($summary['status'] ?? null) === 'skipped') {
            $this->info('Doffin watch inbox discovery skipped.');
            $this->line((string) ($summary['skip_reason_label'] ?? 'Watch inbox discovery is disabled.'));

            return self::SUCCESS;
        }

        $digestSummary = $digestService->createAlertsForCreatedRecordIds($summary['created_record_ids'] ?? []);

        $this->info('Doffin watch inbox discovery completed.');
        $this->line('profiles_processed: '.$summary['profiles_processed']);
        $this->line('profiles_failed: '.$summary['profiles_failed']);
        $this->line('records_seen: '.$summary['records_seen']);
        $this->line('records_created: '.$summary['records_created']);
        $this->line('records_updated: '.$summary['records_updated']);
        $this->line('digest_records_considered: '.$digestSummary['records_considered']);
        $this->line('digest_watch_profiles_involved: '.$digestSummary['watch_profiles_involved']);
        $this->line('digest_records_skipped_no_recipient: '.$digestSummary['records_skipped_no_recipient']);
        $this->line('digest_recipients_total: '.$digestSummary['recipients_total']);
        $this->line('digest_alerts_created: '.$digestSummary['alerts_created']);
        $this->line('digest_alerts_failed: '.$digestSummary['alerts_failed']);

        return $summary['profiles_failed'] > 0 || $digestSummary['alerts_failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function resolveTrigger(): string
    {
        $trigger = (string) $this->option('trigger');

        if (! in_array($trigger, ['manual', 'scheduler'], true)) {
            throw new RuntimeException('The --trigger option must be either manual or scheduler.');
        }

        return $trigger;
    }
}
