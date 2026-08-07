<?php

namespace App\Support\EnterpriseWiki;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EnterpriseWikiQueueReservationTrace
{
    public static function logDispatch(JobQueued $event): void
    {
        if (! self::isEnterpriseWikiQueue($event->connectionName, $event->queue)) {
            return;
        }

        $payload = self::safePayload($event);
        $queueState = self::queueState($event->connectionName, $event->queue);

        self::log('dispatch_enqueued', array_merge($queueState, [
            'timestamp' => self::timestamp(),
            'producer_pid' => getmypid(),
            'producer_queue_connection' => $queueState['queue_connection'],
            'producer_redis_connection' => $queueState['redis_connection'],
            'producer_redis_prefix' => $queueState['redis_prefix'],
            'producer_full_queue_key' => $queueState['full_queue_key'],
            'job_id' => $event->id,
            'job_uuid' => $payload['uuid'] ?? null,
            'delay_seconds' => $event->delay,
            'payload_created_at_epoch' => $payload['createdAt'] ?? null,
            'payload_available_at_epoch' => self::payloadAvailableAt($payload),
        ]));
    }

    /**
     * JobPopping fires on every queue-worker poll attempt — often every ~3 seconds per worker,
     * whether or not anything is actually queued. This is diagnostic polling noise, not a real
     * Wiki event (a real reservation, dispatch, state transition, recovery, or failure is always
     * logged elsewhere in this class or the job/service that experienced it, unconditionally).
     * Silently no-ops unless services.enterprise_wiki.queue_reservation_trace_debug is explicitly
     * enabled — including skipping the Redis pendingSize/delayedSize/reservedSize lookups
     * queueState() performs, which otherwise ran on every empty cycle purely to feed this log.
     */
    public static function logReservationCycle(JobPopping $event): void
    {
        if (! config('services.enterprise_wiki.queue_reservation_trace_debug')) {
            return;
        }

        if (! self::isEnterpriseWikiQueue($event->connectionName, $event->queue)) {
            return;
        }

        $queueState = self::queueState($event->connectionName, $event->queue);

        self::log('reservation_cycle', array_merge($queueState, [
            'timestamp' => self::timestamp(),
            'worker_pid' => getmypid(),
            'worker_queue_connection' => $queueState['queue_connection'],
            'worker_redis_connection' => $queueState['redis_connection'],
            'worker_redis_prefix' => $queueState['redis_prefix'],
            'worker_full_queue_key' => $queueState['full_queue_key'],
            'available_jobs' => $queueState['available_jobs'],
            'delayed_jobs' => $queueState['delayed_jobs'],
            'reserved_jobs' => $queueState['reserved_jobs'],
            'queue_empty' => $queueState['available_jobs'] === 0,
        ]), debug: true);
    }

    public static function logReservation(JobPopped $event): void
    {
        if (! self::isEnterpriseWikiQueue($event->connectionName, $event->job?->getQueue())) {
            return;
        }

        $queueState = self::queueState($event->connectionName, $event->job?->getQueue());
        $payload = self::safePayload($event->job);
        $command = self::safeCommand($payload);

        self::log('job_reserved', array_merge($queueState, [
            'timestamp' => self::timestamp(),
            'worker_pid' => getmypid(),
            'worker_queue_connection' => $queueState['queue_connection'],
            'worker_redis_connection' => $queueState['redis_connection'],
            'worker_redis_prefix' => $queueState['redis_prefix'],
            'worker_full_queue_key' => $queueState['full_queue_key'],
            'job_id' => $event->job?->getJobId(),
            'job_uuid' => $event->job?->uuid(),
            'run_id' => self::resolveRunId($command),
            'payload_queue_connection' => $event->connectionName,
            'payload_redis_connection' => $queueState['redis_connection'],
            'payload_redis_prefix' => $queueState['redis_prefix'],
            'payload_queue_name' => $event->job?->getQueue(),
            'payload_full_queue_key' => $queueState['full_queue_key'],
            'payload_created_at_epoch' => $payload['createdAt'] ?? null,
            'payload_available_at_epoch' => self::payloadAvailableAt($payload),
            'available_for_ms' => self::availableForMilliseconds($payload),
        ]));
    }

    private static function isEnterpriseWikiQueue(?string $connectionName, ?string $queueName): bool
    {
        return $connectionName === 'redis' && $queueName !== null && str_contains($queueName, 'enterprise-wiki');
    }

    private static function queueState(string $connectionName, ?string $queueName): array
    {
        $queueName = $queueName ?? 'enterprise-wiki';
        $redisConnectionName = (string) config("queue.connections.{$connectionName}.connection", 'default');
        $redisPrefix = (string) config("database.redis.{$redisConnectionName}.prefix", '');
        $fullQueueKey = $redisPrefix.'queues:'.$queueName;
        $queue = app('queue')->connection($connectionName);

        if (! $queue instanceof RedisQueue) {
            return [
                'queue_connection' => $connectionName,
                'redis_connection' => $redisConnectionName,
                'redis_prefix' => $redisPrefix,
                'queue_name' => $queueName,
                'full_queue_key' => $fullQueueKey,
                'available_jobs' => null,
                'delayed_jobs' => null,
                'reserved_jobs' => null,
            ];
        }

        try {
            return [
                'queue_connection' => $connectionName,
                'redis_connection' => $redisConnectionName,
                'redis_prefix' => $redisPrefix,
                'queue_name' => $queueName,
                'full_queue_key' => $fullQueueKey,
                'available_jobs' => $queue->pendingSize($queueName),
                'delayed_jobs' => $queue->delayedSize($queueName),
                'reserved_jobs' => $queue->reservedSize($queueName),
            ];
        } catch (Throwable) {
            return [
                'queue_connection' => $connectionName,
                'redis_connection' => $redisConnectionName,
                'redis_prefix' => $redisPrefix,
                'queue_name' => $queueName,
                'full_queue_key' => $fullQueueKey,
                'available_jobs' => null,
                'delayed_jobs' => null,
                'reserved_jobs' => null,
            ];
        }
    }

    private static function payloadAvailableAt(array $payload): ?int
    {
        if (! isset($payload['createdAt'])) {
            return null;
        }

        return (int) $payload['createdAt'] + (int) ($payload['delay'] ?? 0);
    }

    private static function availableForMilliseconds(array $payload): ?int
    {
        $availableAt = self::payloadAvailableAt($payload);

        if ($availableAt === null) {
            return null;
        }

        return (int) max(0, round((microtime(true) - $availableAt) * 1000));
    }

    private static function safePayload(mixed $eventOrJob): array
    {
        if ($eventOrJob instanceof JobQueued) {
            try {
                $payload = $eventOrJob->payload();

                return is_array($payload) ? $payload : [];
            } catch (Throwable) {
                return [];
            }
        } elseif ($eventOrJob instanceof JobPopped) {
            $job = $eventOrJob->job;
        } else {
            $job = $eventOrJob;
        }

        if ($job === null || ! method_exists($job, 'payload')) {
            return [];
        }

        try {
            $payload = $job->payload();

            return is_array($payload) ? $payload : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function safeCommand(array $payload): mixed
    {
        $command = $payload['data']['command'] ?? null;

        if (! is_string($command) || $command === '') {
            return null;
        }

        try {
            $value = @unserialize($command, ['allowed_classes' => true]);

            return is_object($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveRunId(mixed $command): ?int
    {
        if (! is_object($command)) {
            return null;
        }

        if (property_exists($command, 'runId')) {
            return (int) $command->runId;
        }

        return null;
    }

    private static function timestamp(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * $debug=true routes reservation_cycle to Log::debug() instead of Log::info() — it is only
     * ever reached when the explicit debug gate in logReservationCycle() is already on, so this
     * is purely about keeping it at the correct log level, not a second gate. job_reserved and
     * dispatch_enqueued (real events) always pass $debug=false and stay at Log::info().
     */
    private static function log(string $event, array $context, bool $debug = false): void
    {
        $message = '[PROCYNIA][WIKI_QUEUE_RESERVATION_TRACE] '.$event;
        $payload = array_merge(['event' => $event], $context);

        if ($debug) {
            Log::debug($message, $payload);

            return;
        }

        Log::info($message, $payload);
    }
}
