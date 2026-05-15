<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class OpsQueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $queueName = 'default')
    {
        $this->onQueue($queueName);
    }

    public function handle(): void
    {
        $now = now();
        $heartbeatAt = $now->timestamp;

        Cache::put($this->heartbeatCacheKey($this->queueName), $heartbeatAt, $now->addMinutes(10));

        if ($this->queueName === 'default') {
            Cache::put('ops.queue.heartbeat', $heartbeatAt, $now->addMinutes(10));
        }
    }

    /**
     * Purpose: Build the per-queue heartbeat cache key.
     * Inputs: The queue name the heartbeat belongs to.
     * Returns: A cache key string for the queue heartbeat.
     * Side effects: None.
     */
    private function heartbeatCacheKey(string $queueName): string
    {
        return 'ops.queue.heartbeat.'.$queueName;
    }
}
