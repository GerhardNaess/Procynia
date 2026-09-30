<?php

namespace App\Console\Commands;

use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\Doffin\DoffinWatchInboxDigestService;
use App\Services\OpportunitySources\WatchProfileInboxDiscoveryCoordinator;
use App\Services\Ted\TedSourceAdapter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The nightly watch sweep.
 *
 * Still named for Doffin, because the scheduler, the runbooks and the operators all know it by
 * that name, and renaming a scheduled command is a migration of its own. What it does is no longer
 * Doffin-only: it asks the coordinator to run every registered source that has a worker, reports
 * each one separately, and hands every new record — whichever register found it — to the same
 * digest.
 */
class DoffinWatchInboxDiscover extends Command
{
    protected $signature = 'doffin:watch-inbox-discover {--trigger=manual}';

    protected $description = 'Run the nightly watch discovery sweep for all active watch profiles, across every registered opportunity source, and upsert scoped inbox records.';

    public function handle(
        WatchProfileInboxDiscoveryCoordinator $coordinator,
        DoffinWatchInboxDigestService $digestService,
    ): int {
        $trigger = $this->resolveTrigger();

        $this->line('Starting Doffin watch inbox discovery.');
        $this->line("trigger: {$trigger}");

        $result = $coordinator->run(null, $trigger);
        $summary = $result['runs'][DoffinSourceAdapter::SOURCE_KEY] ?? null;

        if ($summary === null) {
            $this->error('Doffin is not a registered opportunity source; nothing was discovered.');

            return self::FAILURE;
        }

        foreach ($result['sources_without_worker'] as $sourceKey) {
            $this->warn("Registered source \"{$sourceKey}\" has no watch discovery worker and was not run.");
        }

        if (($summary['status'] ?? null) === 'skipped') {
            $this->info('Doffin watch inbox discovery skipped.');
            $this->line((string) ($summary['skip_reason_label'] ?? 'Watch inbox discovery is disabled.'));
        } else {
            $this->info('Doffin watch inbox discovery completed.');
            $this->line('profiles_processed: '.$summary['profiles_processed']);
            $this->line('profiles_failed: '.$summary['profiles_failed']);
            $this->line('records_seen: '.$summary['records_seen']);
            $this->line('records_created: '.$summary['records_created']);
            $this->line('records_updated: '.$summary['records_updated']);
        }

        $tedSummary = $result['runs'][TedSourceAdapter::SOURCE_KEY] ?? null;

        if ($tedSummary !== null) {
            $this->reportTed($tedSummary);
        }

        // Every source's new records go into the same digest. A recipient gets one message about
        // what turned up last night, not one per register — the inbox has never been organised by
        // where a notice came from, and the digest should not be either.
        $digestSummary = $digestService->createAlertsForCreatedRecordIds($this->createdRecordIds($result['runs']));

        $this->line('digest_records_considered: '.$digestSummary['records_considered']);
        $this->line('digest_watch_profiles_involved: '.$digestSummary['watch_profiles_involved']);
        $this->line('digest_records_skipped_no_recipient: '.$digestSummary['records_skipped_no_recipient']);
        $this->line('digest_recipients_total: '.$digestSummary['recipients_total']);
        $this->line('digest_alerts_created: '.$digestSummary['alerts_created']);
        $this->line('digest_alerts_failed: '.$digestSummary['alerts_failed']);

        $profilesFailed = collect($result['runs'])->sum(fn (array $run): int => (int) ($run['profiles_failed'] ?? 0));

        return $profilesFailed > 0 || $digestSummary['alerts_failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @param array<string, mixed> $summary */
    private function reportTed(array $summary): void
    {
        if (($summary['status'] ?? null) === 'skipped') {
            $this->info('TED watch inbox discovery skipped.');
            $this->line((string) ($summary['skip_reason_label'] ?? 'TED watch discovery is disabled.'));

            return;
        }

        $this->info('TED watch inbox discovery completed.');
        $this->line('ted_profiles_processed: '.$summary['profiles_processed']);
        $this->line('ted_profiles_failed: '.$summary['profiles_failed']);
        $this->line('ted_records_seen: '.$summary['records_seen']);
        $this->line('ted_records_created: '.$summary['records_created']);
        $this->line('ted_records_updated: '.$summary['records_updated']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $runs
     * @return list<int>
     */
    private function createdRecordIds(array $runs): array
    {
        return collect($runs)
            ->flatMap(fn (array $run): array => $run['created_record_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
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
