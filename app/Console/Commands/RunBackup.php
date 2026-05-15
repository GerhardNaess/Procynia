<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Operations\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('procynia:backup')]
#[Description('Run a scheduled Procynia backup (database + storage). Respects backup_enabled setting.')]
class RunBackup extends Command
{
    public function handle(BackupService $service): int
    {
        try {
            $service->registerSchedulerHeartbeat();

            if (! $service->isEnabled()) {
                $this->line('[Procynia][Backup] Backup is disabled. Skipping.');

                return self::SUCCESS;
            }

            $this->line('[Procynia][Backup] Starting scheduled backup ...');

            $run = $service->runBackup(BackupRun::TYPE_SCHEDULED);

            if ($run->status === BackupRun::STATUS_SUCCESS) {
                $this->info(sprintf(
                    '[Procynia][Backup] Backup succeeded. run_id=%d duration=%ds db=%s',
                    $run->id,
                    (int) $run->duration_seconds,
                    $run->database_backup_path ?? '—',
                ));

                return self::SUCCESS;
            }

            $this->error(sprintf(
                '[Procynia][Backup] Backup failed. run_id=%d error=%s',
                $run->id,
                $run->error_message ?? '—',
            ));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('[Procynia][Backup] Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
