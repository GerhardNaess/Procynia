<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RuntimeStatusService
{
    /**
     * Build a deterministic runtime snapshot for the operations pages.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $failedJobsCount = $this->failedJobsCount();

        return [
            'app_env' => (string) config('app.env', app()->environment()),
            'app_debug' => (bool) config('app.debug', false),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'database' => $this->databaseStatus(),
            'redis' => $this->redisStatus(),
            'queue' => $this->queueStatus($failedJobsCount),
            'cache_driver' => (string) config('cache.default', 'file'),
            'session_driver' => (string) config('session.driver', 'file'),
            'app_url' => (string) config('app.url', ''),
            'uptime' => $this->uptimeStatus(),
            'failed_jobs_count' => $failedJobsCount,
            'scheduler' => $this->schedulerStatus(),
        ];
    }

    /**
     * Determine whether the database connection is reachable.
     *
     * @return array<string, mixed>
     */
    private function databaseStatus(): array
    {
        $connectionName = DB::getDefaultConnection();
        $connectionConfig = (array) config("database.connections.{$connectionName}", []);

        try {
            DB::connection()->getPdo();

            return [
                'available' => true,
                'status_label' => 'Connected',
                'connection' => $connectionName,
                'driver' => (string) ($connectionConfig['driver'] ?? $connectionName),
                'database' => (string) ($connectionConfig['database'] ?? ''),
                'error_message' => null,
            ];
        } catch (Throwable $throwable) {
            return [
                'available' => false,
                'status_label' => 'Unavailable',
                'connection' => $connectionName,
                'driver' => (string) ($connectionConfig['driver'] ?? $connectionName),
                'database' => (string) ($connectionConfig['database'] ?? ''),
                'error_message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * Determine whether the Redis connection is reachable.
     *
     * @return array<string, mixed>
     */
    private function redisStatus(): array
    {
        $connectionName = 'default';
        $connectionConfig = (array) config("database.redis.{$connectionName}", []);

        try {
            Redis::connection($connectionName)->ping();

            return [
                'available' => true,
                'status_label' => 'Connected',
                'connection' => $connectionName,
                'host' => (string) ($connectionConfig['host'] ?? 'n/a'),
                'database' => (string) ($connectionConfig['database'] ?? ''),
                'error_message' => null,
            ];
        } catch (Throwable $throwable) {
            return [
                'available' => false,
                'status_label' => 'Unavailable',
                'connection' => $connectionName,
                'host' => (string) ($connectionConfig['host'] ?? 'n/a'),
                'database' => (string) ($connectionConfig['database'] ?? ''),
                'error_message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * Summarize queue configuration and known queue names used by the app.
     *
     * @return array<string, mixed>
     */
    private function queueStatus(int $failedJobsCount): array
    {
        $connectionName = (string) config('queue.default', 'sync');
        $connectionConfig = (array) config("queue.connections.{$connectionName}", []);

        return [
            'connection' => $connectionName,
            'driver' => (string) ($connectionConfig['driver'] ?? $connectionName),
            'queue' => (string) ($connectionConfig['queue'] ?? 'default'),
            'known_queues' => $this->knownQueues(),
            'failed_jobs_count' => $failedJobsCount,
        ];
    }

    /**
     * Resolve the queue names currently used in the application code.
     *
     * @return array<int, string>
     */
    private function knownQueues(): array
    {
        return array_values(array_unique(array_filter([
            'default',
            'ai-requirements',
            'supplier-harvests',
            'supplier-lookups',
        ])));
    }

    /**
     * Count failed jobs in the canonical failed jobs table.
     */
    private function failedJobsCount(): int
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Build the current scheduler status and next due tasks.
     *
     * @return array<string, mixed>
     */
    private function schedulerStatus(): array
    {
        try {
            $exitCode = Artisan::call('schedule:list', ['--json' => true, '--next' => true]);
            $output = trim((string) Artisan::output());
            $tasks = [];

            if ($exitCode === 0 && $output !== '') {
                $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    foreach ($decoded as $task) {
                        if (! is_array($task)) {
                            continue;
                        }

                        $tasks[] = [
                            'expression' => (string) ($task['expression'] ?? ''),
                            'command' => (string) ($task['command'] ?? ''),
                            'description' => $task['description'] !== null ? (string) $task['description'] : null,
                            'next_due_date' => (string) ($task['next_due_date'] ?? ''),
                            'next_due_date_human' => (string) ($task['next_due_date_human'] ?? ''),
                            'timezone' => (string) ($task['timezone'] ?? ''),
                            'has_mutex' => (bool) ($task['has_mutex'] ?? false),
                        ];
                    }
                }
            }

            return [
                'available' => true,
                'status_label' => $tasks === [] ? 'No scheduled tasks configured' : 'Configured',
                'task_count' => count($tasks),
                'tasks' => $tasks,
                'error_message' => null,
            ];
        } catch (Throwable $throwable) {
            return [
                'available' => false,
                'status_label' => 'Unavailable',
                'task_count' => 0,
                'tasks' => [],
                'error_message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * Read system uptime when the runtime exposes it.
     *
     * @return array<string, mixed>
     */
    private function uptimeStatus(): array
    {
        $path = '/proc/uptime';

        if (! is_readable($path)) {
            return [
                'available' => false,
                'seconds' => null,
                'label' => 'Not available on this platform',
            ];
        }

        $contents = trim((string) file_get_contents($path));
        $parts = preg_split('/\s+/', $contents) ?: [];
        $seconds = isset($parts[0]) ? (int) floor((float) $parts[0]) : null;

        if ($seconds === null || $seconds < 0) {
            return [
                'available' => false,
                'seconds' => null,
                'label' => 'Not available',
            ];
        }

        return [
            'available' => true,
            'seconds' => $seconds,
            'label' => $this->formatDuration($seconds),
        ];
    }

    /**
     * Format a duration in seconds as a readable label.
     */
    private function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' day'.($days === 1 ? '' : 's');
        }

        if ($hours > 0) {
            $parts[] = $hours.' hour'.($hours === 1 ? '' : 's');
        }

        if ($minutes > 0) {
            $parts[] = $minutes.' minute'.($minutes === 1 ? '' : 's');
        }

        if ($parts === []) {
            $parts[] = $seconds.' second'.($seconds === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }
}
