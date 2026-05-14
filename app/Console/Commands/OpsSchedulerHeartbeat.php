<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class OpsSchedulerHeartbeat extends Command
{
    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Write a scheduler heartbeat timestamp to cache so the health endpoint can verify the scheduler is running.';

    public function handle(): int
    {
        Cache::put('ops.scheduler.heartbeat', now()->timestamp, now()->addMinutes(10));

        return self::SUCCESS;
    }
}
