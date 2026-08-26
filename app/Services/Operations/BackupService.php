<?php

namespace App\Services\Operations;

use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BackupService
{
    /**
     * Recorded on a BackupRun that was skipped because this runtime does not support the legacy
     * Compose mechanism. Not an error: the run never started.
     */
    public const LEGACY_DISABLED_REASON = 'Legacy Compose backup is disabled for this runtime (PROCYNIA_LEGACY_BACKUP_ENABLED=false). Azure PostgreSQL automated backup and point-in-time restore apply instead.';

    public function __construct(
        private readonly LegacyBackupProcessRunner $processRunner = new LegacyBackupProcessRunner,
    ) {}

    public function getSetting(): BackupSetting
    {
        $setting = BackupSetting::first();

        if ($setting === null) {
            $setting = BackupSetting::create(['backup_enabled' => false]);
        }

        return $setting;
    }

    /**
     * Has an operator switched backup on? This is the database flag, and it says nothing about
     * whether this runtime can actually execute a backup.
     */
    public function isEnabled(): bool
    {
        return $this->getSetting()->backup_enabled;
    }

    /**
     * Does this runtime support the legacy Compose backup mechanism at all?
     *
     * Separate from isEnabled() on purpose: a database migrated to Azure can carry
     * backup_enabled = true, and this guard has to stop the Compose script regardless.
     * See config/procynia.php for the full rationale.
     */
    public function legacyBackupIsSupported(): bool
    {
        return (bool) config('procynia.backup.legacy_enabled', true);
    }

    public function enableBackup(User $user): void
    {
        $setting = $this->getSetting();
        $setting->backup_enabled = true;
        $setting->last_started_at = now();
        $setting->updated_by = $user->id;
        $setting->save();

        Log::info('[Procynia][Backup] Backup enabled by admin.', ['user_id' => $user->id]);
    }

    public function disableBackup(User $user): void
    {
        $setting = $this->getSetting();
        $setting->backup_enabled = false;
        $setting->last_stopped_at = now();
        $setting->updated_by = $user->id;
        $setting->save();

        Log::info('[Procynia][Backup] Backup disabled by admin.', ['user_id' => $user->id]);
    }

    public function registerSchedulerHeartbeat(): void
    {
        $setting = $this->getSetting();
        $setting->last_scheduler_heartbeat_at = now();
        $setting->saveQuietly();
    }

    /**
     * Execute a backup run (scheduled or manual).
     * Creates a BackupRun record, runs the shell script, and records the result.
     */
    public function runBackup(string $type = BackupRun::TYPE_SCHEDULED, ?User $user = null): BackupRun
    {
        // The runtime guard comes first, and applies to every trigger type. The database flag below
        // only short-circuits scheduled runs, so without this a manual backup from the admin panel
        // would still shell out to `docker compose` in a runtime that has no Docker.
        if (! $this->legacyBackupIsSupported()) {
            Log::info('[Procynia][Backup] Legacy Compose backup is disabled for this runtime. Skipping.', [
                'type' => $type,
                'triggered_by_user_id' => $user?->id,
            ]);

            return BackupRun::create([
                'type' => $type,
                'status' => BackupRun::STATUS_SKIPPED,
                'started_at' => now(),
                'finished_at' => now(),
                'duration_seconds' => 0,
                'error_message' => self::LEGACY_DISABLED_REASON,
                'triggered_by' => $user !== null ? ($user->name ?: 'admin') : 'runtime-guard',
                'triggered_by_user_id' => $user?->id,
            ]);
        }

        $setting = $this->getSetting();

        if (! $setting->backup_enabled && $type === BackupRun::TYPE_SCHEDULED) {
            return BackupRun::create([
                'type' => $type,
                'status' => BackupRun::STATUS_SKIPPED,
                'started_at' => now(),
                'finished_at' => now(),
                'duration_seconds' => 0,
                'triggered_by' => 'scheduler',
                'triggered_by_user_id' => null,
            ]);
        }

        $directory = $this->resolveDirectory($setting);

        $triggeredBy = $user !== null
            ? ($user->name ?: 'admin')
            : ($type === BackupRun::TYPE_SCHEDULED ? 'scheduler' : 'system');

        $run = BackupRun::create([
            'type' => $type,
            'status' => BackupRun::STATUS_RUNNING,
            'started_at' => now(),
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $user?->id,
        ]);

        try {
            $scriptPath = base_path('scripts/backup-production.sh');

            $process = $this->processRunner->run($scriptPath, $directory, base_path());

            $finished = now();
            $duration = (int) max(0, $run->started_at->diffInSeconds($finished));

            if (! $process->isSuccessful()) {
                $errorOutput = trim($process->getErrorOutput() ?: $process->getOutput());
                $run->update([
                    'status' => BackupRun::STATUS_FAILED,
                    'finished_at' => $finished,
                    'duration_seconds' => $duration,
                    'error_message' => Str::limit($errorOutput, 2000),
                ]);

                Log::error('[Procynia][Backup] Backup run failed.', [
                    'run_id' => $run->id,
                    'type' => $type,
                    'exit_code' => $process->getExitCode(),
                ]);

                return $run->refresh();
            }

            [$dbPath, $storagePath, $dbBytes, $storageBytes] = $this->findCreatedFiles($directory, $run->started_at);

            $run->update([
                'status' => BackupRun::STATUS_SUCCESS,
                'finished_at' => $finished,
                'duration_seconds' => $duration,
                'database_backup_path' => $dbPath,
                'storage_backup_path' => $storagePath,
                'database_backup_size_bytes' => $dbBytes,
                'storage_backup_size_bytes' => $storageBytes,
            ]);

            Log::info('[Procynia][Backup] Backup run succeeded.', [
                'run_id' => $run->id,
                'type' => $type,
                'duration_seconds' => $duration,
                'db_path' => $dbPath,
            ]);

            return $run->refresh();
        } catch (Throwable $e) {
            $finished = now();
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'finished_at' => $finished,
                'duration_seconds' => (int) max(0, $run->started_at->diffInSeconds($finished)),
                'error_message' => Str::limit($e->getMessage(), 2000),
            ]);

            Log::error('[Procynia][Backup] Backup run threw exception.', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return $run->refresh();
        }
    }

    /**
     * List backup files from the configured directory.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBackupFiles(): array
    {
        $directory = $this->resolveDirectory($this->getSetting());

        if (! is_dir($directory)) {
            return [];
        }

        $patterns = [
            $directory.'/procynia_db_*.sql',
            $directory.'/procynia_storage_*.tar.gz',
        ];

        $files = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                $stat = @stat($path);
                $files[] = [
                    'path' => $path,
                    'filename' => basename($path),
                    'size_bytes' => $stat !== false ? (int) $stat['size'] : null,
                    'modified_at' => $stat !== false ? Carbon::createFromTimestamp((int) $stat['mtime'])->format('d.m.Y H:i') : null,
                    'modified_at_timestamp' => $stat !== false ? (int) $stat['mtime'] : 0,
                ];
            }
        }

        usort($files, fn (array $a, array $b): int => $b['modified_at_timestamp'] <=> $a['modified_at_timestamp']);

        return array_slice($files, 0, 50);
    }

    /**
     * Evaluate the current backup status for the system status page.
     *
     * @return array<string, mixed>
     */
    public function evaluateStatus(): array
    {
        try {
            $setting = $this->getSetting();
            $directory = $this->resolveDirectory($setting);
            $directoryExists = is_dir($directory);

            $lastSuccess = BackupRun::query()
                ->where('status', BackupRun::STATUS_SUCCESS)
                ->orderByDesc('finished_at')
                ->first(['finished_at', 'type']);

            $lastFailed = BackupRun::query()
                ->where('status', BackupRun::STATUS_FAILED)
                ->orderByDesc('finished_at')
                ->first(['finished_at', 'type']);

            $lastRun = BackupRun::query()
                ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED])
                ->orderByDesc('finished_at')
                ->first(['status', 'finished_at']);

            $fileCount = count($this->listBackupFiles());

            $rpoHours = (int) config('procynia.backup.rpo_hours', 1);
            $heartbeatStale = (int) config('procynia.backup.scheduler_heartbeat_stale_seconds', 3600);

            $lastSuccessAt = $lastSuccess?->finished_at;
            $lastFailedAt = $lastFailed?->finished_at;
            $heartbeatAt = $setting->last_scheduler_heartbeat_at;

            $backupOverdue = $setting->backup_enabled
                && ($lastSuccessAt === null || $lastSuccessAt->lt(now()->subHours($rpoHours)));

            $noHeartbeat = $setting->backup_enabled
                && ($heartbeatAt === null || $heartbeatAt->lt(now()->subSeconds($heartbeatStale)));

            $lastRunFailed = $lastRun?->status === BackupRun::STATUS_FAILED;

            $warnings = [];

            if (! $this->legacyBackupIsSupported()) {
                // A database migrated to Azure can still carry backup_enabled = true. Reporting it as
                // "overdue" or "no heartbeat" would be misleading: nothing is broken, this runtime
                // simply does not run the legacy mechanism. Say that once, and say nothing else.
                $warnings[] = 'legacy_backup_disabled';
            } elseif (! $setting->backup_enabled) {
                $warnings[] = 'backup_stopped';
            } else {
                if ($noHeartbeat) {
                    $warnings[] = 'no_scheduler_heartbeat';
                }

                if ($backupOverdue) {
                    $warnings[] = 'backup_overdue';
                }

                if ($lastRunFailed) {
                    $warnings[] = 'last_run_failed';
                }

                if (! $directoryExists) {
                    $warnings[] = 'directory_missing';
                } elseif ($fileCount === 0) {
                    $warnings[] = 'no_files';
                }
            }

            return [
                'enabled' => $setting->backup_enabled,
                'legacy_backup_supported' => $this->legacyBackupIsSupported(),
                'directory' => $directory,
                'directory_exists' => $directoryExists,
                'last_success_at' => $lastSuccessAt?->toIso8601String(),
                'last_success_at_human' => $lastSuccessAt?->diffForHumans(),
                'last_failed_at' => $lastFailedAt?->toIso8601String(),
                'last_failed_at_human' => $lastFailedAt?->diffForHumans(),
                'last_scheduler_heartbeat_at' => $heartbeatAt?->toIso8601String(),
                'last_scheduler_heartbeat_at_human' => $heartbeatAt?->diffForHumans(),
                'file_count' => $fileCount,
                'warnings' => $warnings,
                'ok' => $warnings === [],
            ];
        } catch (Throwable) {
            return [
                'enabled' => false,
                'legacy_backup_supported' => $this->legacyBackupIsSupported(),
                'directory' => '',
                'directory_exists' => false,
                'last_success_at' => null,
                'last_success_at_human' => null,
                'last_failed_at' => null,
                'last_failed_at_human' => null,
                'last_scheduler_heartbeat_at' => null,
                'last_scheduler_heartbeat_at_human' => null,
                'file_count' => 0,
                'warnings' => [],
                'ok' => true,
            ];
        }
    }

    private function resolveDirectory(BackupSetting $setting): string
    {
        if (filled($setting->backup_directory)) {
            return rtrim((string) $setting->backup_directory, '/');
        }

        return rtrim((string) config('procynia.backup.directory', '/backup/procynia'), '/');
    }

    /**
     * Find backup files created since the run started.
     *
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?int}
     */
    private function findCreatedFiles(string $directory, Carbon $since): array
    {
        $sinceTimestamp = $since->timestamp;
        $dbPath = null;
        $storagePath = null;
        $dbBytes = null;
        $storageBytes = null;

        foreach (glob($directory.'/procynia_db_*.sql') ?: [] as $path) {
            $mtime = (int) (@filemtime($path) ?: 0);
            if ($mtime >= $sinceTimestamp) {
                $dbPath = $path;
                $dbBytes = (int) (@filesize($path) ?: 0);
            }
        }

        foreach (glob($directory.'/procynia_storage_*.tar.gz') ?: [] as $path) {
            $mtime = (int) (@filemtime($path) ?: 0);
            if ($mtime >= $sinceTimestamp) {
                $storagePath = $path;
                $storageBytes = (int) (@filesize($path) ?: 0);
            }
        }

        return [$dbPath, $storagePath, $dbBytes ?: null, $storageBytes ?: null];
    }
}
