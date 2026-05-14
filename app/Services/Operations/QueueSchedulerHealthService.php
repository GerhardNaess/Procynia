<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;

class QueueSchedulerHealthService
{
    private const int STALE_THRESHOLD_SECONDS = 300;

    /**
     * @return array{ok: bool, scheduler: string, queue: string}
     */
    public function evaluate(): array
    {
        $schedulerOk = $this->isFresh('ops.scheduler.heartbeat');
        $queueOk = $this->isFresh('ops.queue.heartbeat');

        return [
            'ok' => $schedulerOk && $queueOk,
            'scheduler' => $schedulerOk ? 'ok' : 'stale',
            'queue' => $queueOk ? 'ok' : 'stale',
        ];
    }

    private function isFresh(string $cacheKey): bool
    {
        $timestamp = Cache::get($cacheKey);

        if ($timestamp === null) {
            return false;
        }

        return (now()->timestamp - (int) $timestamp) <= self::STALE_THRESHOLD_SECONDS;
    }
}
