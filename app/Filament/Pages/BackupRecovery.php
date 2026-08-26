<?php

namespace App\Filament\Pages;

use App\Models\BackupRun;
use App\Services\Operations\BackupService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

class BackupRecovery extends Page
{
    protected string $view = 'filament.pages.backup-recovery';

    protected static ?string $navigationLabel = 'Sikkerhetskopi og gjenoppretting';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed> */
    public array $statusSummary = [];

    /** @var array<int, array<string, mixed>> */
    public array $recentRuns = [];

    /** @var array<int, array<string, mixed>> */
    public array $backupFiles = [];

    public bool $backupEnabled = false;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public function getTitle(): string
    {
        return (string) __('procynia.backup_recovery.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('procynia.backup_recovery.subtitle');
    }

    public function mount(): void
    {
        $this->loadState();
    }

    public function loadState(): void
    {
        $service = app(BackupService::class);
        $this->statusSummary = $service->evaluateStatus();
        $this->backupEnabled = (bool) ($this->statusSummary['enabled'] ?? false);
        $this->recentRuns = $this->buildRecentRuns();
        $this->backupFiles = $service->listBackupFiles();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('enableBackup')
                ->label((string) __('procynia.backup_recovery.actions.enable_backup'))
                ->icon(Heroicon::OutlinedPlayCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading((string) __('procynia.backup_recovery.actions.enable_heading'))
                ->modalDescription((string) __('procynia.backup_recovery.actions.enable_description'))
                ->modalSubmitActionLabel((string) __('procynia.backup_recovery.actions.confirm'))
                ->modalCancelActionLabel((string) __('procynia.backup_recovery.actions.cancel'))
                ->visible(fn (): bool => ! $this->backupEnabled)
                ->action(fn () => $this->handleEnableBackup()),

            Action::make('disableBackup')
                ->label((string) __('procynia.backup_recovery.actions.disable_backup'))
                ->icon(Heroicon::OutlinedStopCircle)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading((string) __('procynia.backup_recovery.actions.disable_heading'))
                ->modalDescription((string) __('procynia.backup_recovery.actions.disable_description'))
                ->modalSubmitActionLabel((string) __('procynia.backup_recovery.actions.confirm'))
                ->modalCancelActionLabel((string) __('procynia.backup_recovery.actions.cancel'))
                ->visible(fn (): bool => $this->backupEnabled)
                ->action(fn () => $this->handleDisableBackup()),

            Action::make('manualBackup')
                ->label((string) __('procynia.backup_recovery.actions.manual_backup'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                // Not offered where the runtime cannot execute the Compose script. BackupService
                // refuses it regardless; this just avoids showing a button that cannot work.
                ->visible(fn (): bool => app(BackupService::class)->legacyBackupIsSupported())
                ->requiresConfirmation()
                ->modalHeading((string) __('procynia.backup_recovery.actions.manual_heading'))
                ->modalDescription((string) __('procynia.backup_recovery.actions.manual_description'))
                ->modalSubmitActionLabel((string) __('procynia.backup_recovery.actions.confirm'))
                ->modalCancelActionLabel((string) __('procynia.backup_recovery.actions.cancel'))
                ->action(fn () => $this->handleManualBackup()),

            Action::make('refresh')
                ->label((string) __('procynia.backup_recovery.actions.refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->loadState()),
        ];
    }

    public function handleEnableBackup(): void
    {
        try {
            $user = Auth::user();
            app(BackupService::class)->enableBackup($user);
            $this->loadState();
            Notification::make()
                ->title((string) __('procynia.backup_recovery.messages.backup_enabled_success'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function handleDisableBackup(): void
    {
        try {
            $user = Auth::user();
            app(BackupService::class)->disableBackup($user);
            $this->loadState();
            Notification::make()
                ->title((string) __('procynia.backup_recovery.messages.backup_disabled_success'))
                ->warning()
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function handleManualBackup(): void
    {
        try {
            $user = Auth::user();
            $run = app(BackupService::class)->runBackup(BackupRun::TYPE_MANUAL, $user);
            $this->loadState();

            if ($run->status === BackupRun::STATUS_SUCCESS) {
                Notification::make()
                    ->title((string) __('procynia.backup_recovery.messages.manual_success'))
                    ->success()
                    ->send();
            } elseif ($run->status === BackupRun::STATUS_SKIPPED) {
                // Nothing ran and nothing broke — reporting this in red would be wrong.
                Notification::make()
                    ->title((string) __('procynia.backup_recovery.messages.manual_skipped'))
                    ->body(Str::limit((string) ($run->error_message ?? ''), 300))
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title((string) __('procynia.backup_recovery.messages.manual_failed'))
                    ->body(Str::limit((string) ($run->error_message ?? ''), 200))
                    ->danger()
                    ->send();
            }
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentRuns(): array
    {
        try {
            return BackupRun::query()
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (BackupRun $run): array => [
                    'id' => $run->id,
                    'type' => $run->type,
                    'type_label' => (string) __('procynia.backup_recovery.types.'.$run->type),
                    'status' => $run->status,
                    'status_label' => (string) __('procynia.backup_recovery.statuses.'.$run->status),
                    'started_at' => $run->started_at?->format('d.m.Y H:i'),
                    'finished_at' => $run->finished_at?->format('d.m.Y H:i'),
                    'duration_seconds' => $run->duration_seconds,
                    'database_backup_path' => $run->database_backup_path ? basename((string) $run->database_backup_path) : null,
                    'storage_backup_path' => $run->storage_backup_path ? basename((string) $run->storage_backup_path) : null,
                    'database_backup_size_bytes' => $run->database_backup_size_bytes,
                    'storage_backup_size_bytes' => $run->storage_backup_size_bytes,
                    'error_message' => $run->error_message,
                    'triggered_by' => $run->triggered_by,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
