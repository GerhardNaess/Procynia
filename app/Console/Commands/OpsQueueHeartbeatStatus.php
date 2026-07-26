<?php

namespace App\Console\Commands;

use App\Services\Operations\QueueHeartbeatHealthService;
use Illuminate\Console\Command;

/**
 * Docker healthcheck for a queue worker container: exits 0 when the given queue has processed a
 * fresh OpsQueueHeartbeatJob recently (see QueueHeartbeatHealthService — the same mechanism the
 * `/ops/health/queues/{queue}` HTTP endpoint uses), 1 otherwise. Read-only — never writes data or
 * dispatches a job — so it is safe to run on every healthcheck interval.
 *
 * Deliberately CLI-only rather than curling the HTTP endpoint: a queue worker container runs no
 * web server of its own, and the heartbeat cache (Redis) is what actually proves THIS container's
 * queue:work process is alive and consuming its queue, not just that some PHP process can boot.
 */
class OpsQueueHeartbeatStatus extends Command
{
    protected $signature = 'ops:queue-heartbeat-status {queue}';

    protected $description = 'Exit 0 when the given queue has a fresh heartbeat, 1 otherwise — used as the Docker healthcheck for queue worker containers.';

    public function handle(QueueHeartbeatHealthService $service): int
    {
        $queue = (string) $this->argument('queue');

        if (! $service->supports($queue)) {
            $this->error("Unknown queue [{$queue}].");

            return self::FAILURE;
        }

        $payload = $service->evaluate($queue);

        $this->line($payload['message']);

        return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
