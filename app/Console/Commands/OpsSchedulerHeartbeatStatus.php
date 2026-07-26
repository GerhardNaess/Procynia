<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Docker healthcheck for the scheduler container: exits 0 when `ops:scheduler-heartbeat` has
 * written a fresh timestamp recently (see OpsSchedulerHeartbeat, routes/console.php's
 * `everyMinute()` entry), 1 otherwise. Read-only — never writes data or dispatches a job.
 */
class OpsSchedulerHeartbeatStatus extends Command
{
    private const int STALE_THRESHOLD_SECONDS = 300;

    protected $signature = 'ops:scheduler-heartbeat-status';

    protected $description = 'Exit 0 when the scheduler heartbeat is fresh, 1 otherwise — used as the Docker healthcheck for the scheduler container.';

    public function handle(): int
    {
        $timestamp = Cache::get('ops.scheduler.heartbeat');

        if (! is_numeric($timestamp)) {
            $this->error('No scheduler heartbeat recorded.');

            return self::FAILURE;
        }

        $secondsSince = now()->timestamp - (int) $timestamp;

        if ($secondsSince > self::STALE_THRESHOLD_SECONDS) {
            $this->error("Scheduler heartbeat is stale ({$secondsSince}s old).");

            return self::FAILURE;
        }

        $this->line("Scheduler heartbeat is fresh ({$secondsSince}s old).");

        return self::SUCCESS;
    }
}
