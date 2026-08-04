<?php

namespace App\Support\EnterpriseWiki;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class EnterpriseWikiQueueTrace
{
    public static function log(string $event, array $context = [], bool $includeDatabaseTime = false, bool $includeRedisTime = false): void
    {
        $queueConnection = (string) config('queue.default', 'sync');
        $redisConnection = (string) config('queue.connections.redis.connection', 'default');

        $payload = array_merge([
            'event' => $event,
            'php_now' => self::formatTimestamp(CarbonImmutable::now('UTC')),
            'queue_connection' => $queueConnection,
            'redis_connection' => $redisConnection,
            'database_connection' => (string) config('database.default', 'unknown'),
        ], $context);

        if ($includeDatabaseTime) {
            $payload['database_now'] = self::databaseNow();
        }

        if ($includeRedisTime) {
            $payload['redis_now'] = self::redisNow($redisConnection);
        }

        Log::info('[PROCYNIA][WIKI_QUEUE_TRACE] '.$event, $payload);
    }

    private static function databaseNow(): ?string
    {
        try {
            $driver = DB::connection()->getDriverName();
            $sql = match ($driver) {
                'sqlite' => "select strftime('%Y-%m-%dT%H:%M:%fZ', 'now') as current_time",
                'sqlsrv' => 'select sysutcdatetime() as current_time',
                default => 'select current_timestamp(3) as current_time',
            };

            $row = DB::selectOne($sql);
            $value = is_object($row) ? ($row->current_time ?? null) : null;

            return is_string($value) && $value !== ''
                ? self::normalizeTimestamp($value)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function redisNow(string $connectionName): ?string
    {
        try {
            $value = Redis::connection($connectionName)->command('time');

            if (! is_array($value) || count($value) < 2) {
                return null;
            }

            $seconds = (int) ($value[0] ?? 0);
            $microseconds = (int) ($value[1] ?? 0);

            return self::formatTimestamp(
                CarbonImmutable::createFromTimestampUTC($seconds)->setMicrosecond($microseconds)
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function normalizeTimestamp(string $value): string
    {
        return self::formatTimestamp(CarbonImmutable::parse($value, 'UTC'));
    }

    private static function formatTimestamp(CarbonImmutable $timestamp): string
    {
        return $timestamp->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
