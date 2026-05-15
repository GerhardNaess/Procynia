<?php

namespace App\Services\Operations;

use App\Services\Operations\BackupService;
use Illuminate\Support\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use DateTimeZone;
use Cron\CronExpression;
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
            'generated_at' => now()->toIso8601String(),
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
            'backup' => $this->backupStatus(),
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
     * Return safe display data for recent failed jobs, newest first.
     *
     * Sensitive payload contents and full stack traces are never returned.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentFailedJobs(int $limit = 10): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        try {
            $rows = DB::table($table)
                ->orderByDesc('failed_at')
                ->limit($limit)
                ->get(['id', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

            return $rows->map(function (object $job): array {
                $payload = json_decode((string) $job->payload, true) ?? [];
                $displayName = (string) ($payload['displayName'] ?? $payload['job'] ?? 'Unknown');

                $exceptionLines = explode("\n", (string) $job->exception, 2);
                $errorFirstLine = Str::limit(trim($exceptionLines[0] ?? ''), 120) ?: '—';

                $failedAt = filled($job->failed_at) ? Carbon::parse($job->failed_at) : null;

                return [
                    'id' => $job->id,
                    'display_name' => $displayName,
                    'queue' => (string) $job->queue,
                    'connection' => (string) $job->connection,
                    'failed_at' => $failedAt?->format('d.m.Y H:i') ?? '—',
                    'failed_at_human' => $failedAt?->diffForHumans() ?? '—',
                    'error_first_line' => $errorFirstLine,
                ];
            })->all();
        } catch (Throwable) {
            return [];
        }
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
     * Return a compact backup status summary for the system status cockpit.
     *
     * @return array<string, mixed>
     */
    private function backupStatus(): array
    {
        try {
            return app(BackupService::class)->evaluateStatus();
        } catch (Throwable) {
            return ['ok' => true, 'enabled' => false, 'warnings' => []];
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
            $referenceTime = CarbonImmutable::now();

            if ($exitCode === 0 && $output !== '') {
                $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    foreach ($decoded as $task) {
                        if (! is_array($task)) {
                            continue;
                        }

                        $tasks[] = $this->normalizeSchedulerTask($task, $referenceTime);
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
     * Normalize a single scheduler task into a deterministic runtime payload.
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function normalizeSchedulerTask(array $task, CarbonImmutable $referenceTime): array
    {
        $expression = (string) ($task['expression'] ?? '');
        $timezoneName = $this->schedulerTimezone((string) ($task['timezone'] ?? config('app.timezone', 'UTC')));
        $referenceInTimezone = $referenceTime->setTimezone($timezoneName);
        $nextRunAt = null;
        $previousRunAt = null;
        $cycleDurationSeconds = (int) ($task['repeat_seconds'] ?? 0);

        try {
            $cron = CronExpression::factory($expression);
            $referenceDateTime = $referenceInTimezone->toDateTimeImmutable();

            $nextRunAt = CarbonImmutable::instance(
                $cron->getNextRunDate($referenceDateTime, 0, false, $timezoneName),
            );
            $previousRunAt = CarbonImmutable::instance(
                $cron->getPreviousRunDate($referenceDateTime, 0, false, $timezoneName),
            );
        } catch (Throwable) {
            $nextRunAt = filled($task['next_due_date'] ?? null)
                ? CarbonImmutable::parse((string) $task['next_due_date'])
                : null;
        }

        if ($cycleDurationSeconds <= 0 && $nextRunAt !== null && $previousRunAt !== null) {
            $cycleDurationSeconds = (int) max(0, $previousRunAt->diffInSeconds($nextRunAt));
        }

        $progressRatio = null;

        if ($cycleDurationSeconds > 0 && $previousRunAt !== null) {
            $elapsedSeconds = max(0, $previousRunAt->diffInSeconds($referenceTime));
            $progressRatio = max(0.0, min(1.0, $elapsedSeconds / $cycleDurationSeconds));
        }

        $nextRunLabel = $nextRunAt !== null
            ? $this->formatCountdownLabel($referenceTime, $nextRunAt)
            : (string) __('procynia.system_status.scheduler.unavailable');

        return [
            'task_name' => (string) ($task['description'] ?? $task['command'] ?? ''),
            'command' => (string) ($task['command'] ?? ''),
            'description' => $task['description'] !== null ? (string) $task['description'] : null,
            'expression' => $expression,
            'timezone' => $timezoneName,
            'has_mutex' => (bool) ($task['has_mutex'] ?? false),
            'previous_run_at_iso' => $previousRunAt?->toIso8601String(),
            'next_run_at_iso' => $nextRunAt?->toIso8601String(),
            'cycle_duration_seconds' => $cycleDurationSeconds,
            'progress_ratio' => $progressRatio,
            'next_run_at_human' => $nextRunLabel,
            'next_due_date' => (string) ($task['next_due_date'] ?? ''),
            'next_due_date_human' => $nextRunLabel,
            'repeat_seconds' => (int) ($task['repeat_seconds'] ?? 0),
            'environments' => array_values((array) ($task['environments'] ?? [])),
        ];
    }

    private function schedulerTimezone(string $timezoneName): string
    {
        try {
            new DateTimeZone($timezoneName);

            return $timezoneName;
        } catch (Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    private function formatCountdownLabel(CarbonImmutable $referenceTime, CarbonImmutable $nextRunAt): string
    {
        $remainingSeconds = max(0, $referenceTime->diffInSeconds($nextRunAt));

        if ($remainingSeconds === 0) {
            return (string) __('procynia.system_status.scheduler.now');
        }

        if ($remainingSeconds < 60) {
            return __('procynia.system_status.scheduler.in_prefix').' '.$this->schedulerDurationLabel($remainingSeconds, 'second', 'seconds');
        }

        $remainingMinutes = (int) ceil($remainingSeconds / 60);

        if ($remainingSeconds < 3600) {
            return __('procynia.system_status.scheduler.in_prefix').' '.$this->schedulerDurationLabel($remainingMinutes, 'minute', 'minutes');
        }

        $remainingHours = (int) ceil($remainingSeconds / 3600);

        if ($remainingSeconds < 86400) {
            return __('procynia.system_status.scheduler.in_prefix').' '.$this->schedulerDurationLabel($remainingHours, 'hour', 'hours');
        }

        $remainingDays = (int) ceil($remainingSeconds / 86400);

        return __('procynia.system_status.scheduler.in_prefix').' '.$this->schedulerDurationLabel($remainingDays, 'day', 'days');
    }

    private function schedulerDurationLabel(int $value, string $singularKey, string $pluralKey): string
    {
        $unit = $value === 1
            ? (string) __('procynia.system_status.scheduler.'.$singularKey)
            : (string) __('procynia.system_status.scheduler.'.$pluralKey);

        return $value.' '.$unit;
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
