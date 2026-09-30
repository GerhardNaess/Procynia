<?php

namespace App\Services\OpportunitySources;

use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\Doffin\DoffinWatchProfileInboxDiscoveryService;
use App\Services\Ted\TedSourceAdapter;
use App\Services\Ted\TedWatchProfileInboxDiscoveryService;
use Illuminate\Support\Facades\Log;

/**
 * Runs watch discovery once per source Procynia can discover from.
 *
 * It was built with one source and one worker, for the moment there would be two: without a
 * boundary here, a second register taking part in the nightly sweep would have been decided by
 * omission, inside a command that only ever knew how to call Doffin. That moment has arrived, and
 * the change it needed was a line.
 *
 * Both registers now have a worker, so `sources_without_worker` is empty — which does not make it
 * pointless. It is what separates "this source's discovery has not been built" from "this source
 * ran and found nothing", and the next register registered will be reported by it on the day it is
 * registered rather than on the day somebody notices.
 *
 * A worker deciding it may not run — a switch that is off, a register with no credentials — is not
 * the same thing. That is a run with status 'skipped' and a reason, and it belongs to the worker.
 */
class WatchProfileInboxDiscoveryCoordinator
{
    public function __construct(
        private readonly OpportunitySourceRegistry $sources,
        private readonly DoffinWatchProfileInboxDiscoveryService $doffinDiscovery,
        private readonly TedWatchProfileInboxDiscoveryService $tedDiscovery,
    ) {}

    /**
     * @return array{
     *     sources_run: list<string>,
     *     sources_without_worker: list<string>,
     *     runs: array<string, array<string, mixed>>
     * }
     */
    public function run(?int $watchProfileId = null, string $trigger = 'manual'): array
    {
        $sourcesRun = [];
        $sourcesWithoutWorker = [];
        $runs = [];

        foreach ($this->sources->keys() as $sourceKey) {
            $worker = $this->workerFor($sourceKey);

            if ($worker === null) {
                $sourcesWithoutWorker[] = $sourceKey;

                Log::info('[Procynia][watch-inbox] Registered source has no watch discovery worker.', [
                    'source' => $sourceKey,
                    'trigger' => $trigger,
                ]);

                continue;
            }

            $sourcesRun[] = $sourceKey;
            $runs[$sourceKey] = $worker($watchProfileId, $trigger);
        }

        return [
            'sources_run' => $sourcesRun,
            'sources_without_worker' => $sourcesWithoutWorker,
            'runs' => $runs,
        ];
    }

    /**
     * The discovery worker for one source, or null when none is built.
     *
     * @return null|callable(?int, string): array<string, mixed>
     */
    private function workerFor(string $sourceKey): ?callable
    {
        return match ($sourceKey) {
            DoffinSourceAdapter::SOURCE_KEY => fn (?int $watchProfileId, string $trigger): array => $this->doffinDiscovery->run($watchProfileId, $trigger),
            TedSourceAdapter::SOURCE_KEY => fn (?int $watchProfileId, string $trigger): array => $this->tedDiscovery->run($watchProfileId, $trigger),
            default => null,
        };
    }
}
