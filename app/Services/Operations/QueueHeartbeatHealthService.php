<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class QueueHeartbeatHealthService
{
    private const int STALE_THRESHOLD_SECONDS = 300;

    private const array SUPPORTED_QUEUES = [
        'supplier-harvests',
        'supplier-lookups',
        'ai-requirements',
        'enterprise-wiki',
        'enterprise-wiki-reconciliation',
        'enterprise-wiki-pages',
        'default',
    ];

    /**
     * Purpose: Determine whether a specific queue has processed a fresh heartbeat job recently.
     * Inputs: The queue name to inspect.
     * Returns: A normalized health payload for the requested queue.
     * Side effects: Reads a cache heartbeat written by the corresponding queue worker.
     *
     * @return array<string, mixed>
     */
    public function evaluate(string $queue): array
    {
        $checkedAt = now();
        $heartbeatAt = $this->heartbeatTimestamp($queue);

        if ($heartbeatAt === null) {
            return [
                'status' => 'fail',
                'queue' => $queue,
                'connection' => 'redis',
                'last_processed_at' => null,
                'seconds_since_last_processed' => null,
                'checked_at' => $checkedAt->toIso8601String(),
                'message' => 'No heartbeat recorded for queue.',
            ];
        }

        $lastProcessedAt = CarbonImmutable::createFromTimestampUTC($heartbeatAt);
        $secondsSinceLastProcessed = max(0, $checkedAt->timestamp - $heartbeatAt);
        $isFresh = $secondsSinceLastProcessed <= self::STALE_THRESHOLD_SECONDS;

        return [
            'status' => $isFresh ? 'ok' : 'fail',
            'queue' => $queue,
            'connection' => 'redis',
            'last_processed_at' => $lastProcessedAt->toIso8601String(),
            'seconds_since_last_processed' => $secondsSinceLastProcessed,
            'checked_at' => $checkedAt->toIso8601String(),
            'message' => $isFresh
                ? 'Queue heartbeat is fresh.'
                : 'Queue heartbeat is older than 300 seconds.',
        ];
    }

    /**
     * Purpose: Determine whether the requested queue is monitored by Procynia.
     * Inputs: The queue name to validate.
     * Returns: True when the queue has a dedicated health endpoint.
     * Side effects: None.
     */
    public function supports(string $queue): bool
    {
        return in_array($queue, self::SUPPORTED_QUEUES, true);
    }

    /**
     * Purpose: Resolve the per-queue heartbeat cache timestamp.
     * Inputs: The queue name to inspect.
     * Returns: The cached heartbeat timestamp, or null when no heartbeat exists.
     * Side effects: Reads from the cache store.
     */
    private function heartbeatTimestamp(string $queue): ?int
    {
        $value = Cache::get($this->heartbeatCacheKey($queue));

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Purpose: Build the per-queue heartbeat cache key.
     * Inputs: The queue name.
     * Returns: The cache key for the queue heartbeat.
     * Side effects: None.
     */
    private function heartbeatCacheKey(string $queue): string
    {
        return 'ops.queue.heartbeat.'.$queue;
    }
}
