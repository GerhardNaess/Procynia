<?php

namespace App\Services\OpportunitySources;

use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\Doffin\DoffinWatchProfileInboxDiscoveryService;
use Illuminate\Support\Facades\Log;

/**
 * Runs watch discovery once per source Procynia can discover from.
 *
 * Today that is one source, and the whole class is four lines of dispatch. It exists anyway, for
 * one reason: the moment a second adapter is registered, something has to decide whether it takes
 * part in the nightly sweep — and without a boundary that decision would be made by omission, in
 * a command that only ever knew how to call Doffin.
 *
 * WHY THE WORKER IS STILL SOURCE-SPECIFIC.
 *
 * DoffinWatchProfileInboxDiscoveryService looks generic — it scores against NormalizedNotice and
 * upserts source-aware records — but the filters it builds are Doffin's vocabulary:
 * `publication_period`, `keywords_mode`, `status => 'ACTIVE'`, a CPV list joined with commas.
 * OpportunitySourceAdapter::search() takes an untyped array, so that vocabulary is the contract in
 * practice. Making the worker source-neutral therefore means designing a source-neutral filter
 * contract, which is a real piece of design and belongs with the second adapter that would give it
 * its second data point — not invented ahead of one.
 *
 * So the worker stays Doffin's, and this names that fact instead of hiding it: a registered source
 * with no discovery worker is reported, not silently skipped. That is the difference between
 * "TED discovery is not built yet" and "TED discovery ran and found nothing".
 */
class WatchProfileInboxDiscoveryCoordinator
{
    public function __construct(
        private readonly OpportunitySourceRegistry $sources,
        private readonly DoffinWatchProfileInboxDiscoveryService $doffinDiscovery,
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
     * A match rather than a lookup table: there is one entry, and a table of one would only make
     * the single case harder to read.
     *
     * @return null|callable(?int, string): array<string, mixed>
     */
    private function workerFor(string $sourceKey): ?callable
    {
        return match ($sourceKey) {
            DoffinSourceAdapter::SOURCE_KEY => fn (?int $watchProfileId, string $trigger): array => $this->doffinDiscovery->run($watchProfileId, $trigger),
            default => null,
        };
    }
}
