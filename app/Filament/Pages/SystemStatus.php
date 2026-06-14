<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Services\Operations\QueueSchedulerHealthService;
use App\Services\Operations\RuntimeStatusService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnitEnum;

class SystemStatus extends Page
{
    use HasAdminPageHelp;

    protected string $view = 'filament.pages.system-status';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    /**
     * @var array{ok: bool, scheduler: string, queue: string}
     */
    public array $health = ['ok' => false, 'scheduler' => 'stale', 'queue' => 'stale'];

    /**
     * Recent failed jobs for the detail table (no payload, no full stack trace).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $failedJobs = [];

    public static function getNavigationLabel(): string
    {
        return (string) __('procynia.system_status.navigation_label');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearFailedJobs')
                ->label((string) __('procynia.system_status.actions.clear_failed_jobs'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading((string) __('procynia.system_status.actions.clear_failed_jobs_confirm'))
                ->modalDescription(function (): string {
                    $count = (int) ($this->snapshot['failed_jobs_count'] ?? 0);
                    $countLine = __('procynia.system_status.messages.clear_failed_jobs_count', ['count' => $count]);
                    $description = __('procynia.system_status.messages.clear_failed_jobs_description');

                    return $countLine."\n\n".$description;
                })
                ->modalSubmitActionLabel((string) __('procynia.system_status.actions.clear_failed_jobs_confirm'))
                ->modalCancelActionLabel((string) __('procynia.system_status.actions.cancel'))
                ->visible(fn (): bool => (int) ($this->snapshot['failed_jobs_count'] ?? 0) > 0)
                ->action(fn () => $this->handleClearFailedJobs()),

            $this->buildPageHelpAction(
                static::fetchPageHelp('admin.system_status')
            ),
        ];
    }

    public function deleteFailedJobAction(): Action
    {
        return Action::make('deleteFailedJob')
            ->label((string) __('procynia.system_status.actions.delete_failed_job'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading((string) __('procynia.system_status.messages.delete_failed_job_title'))
            ->modalDescription((string) __('procynia.system_status.messages.delete_failed_job_description'))
            ->modalSubmitActionLabel((string) __('procynia.system_status.actions.delete_failed_job_confirm'))
            ->modalCancelActionLabel((string) __('procynia.system_status.actions.cancel'))
            ->action(function (array $arguments): void {
                $this->handleDeleteFailedJob($arguments);
            });
    }

    /**
     * Open the confirmation modal for a specific failed job row.
     */
    public function promptDeleteFailedJob(int|string $failedJobId): void
    {
        $this->mountAction('deleteFailedJob', [
            'failedJobId' => $failedJobId,
        ]);
    }

    public function handleClearFailedJobs(): void
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $count = (int) DB::table($table)->count();

        DB::table($table)->delete();

        Log::info('SystemStatus: failed_jobs table cleared by admin.', [
            'deleted_count' => $count,
            'user' => auth()->id(),
        ]);

        $this->refreshRuntimeState();

        Notification::make()
            ->title((string) __('procynia.system_status.messages.failed_jobs_cleared'))
            ->success()
            ->send();
    }

    /**
     * Delete one failed job row from the canonical failed jobs table.
     *
     * @param array<string, mixed> $arguments
     */
    public function handleDeleteFailedJob(array $arguments): void
    {
        $failedJobId = data_get($arguments, 'failedJobId');

        if (! filled($failedJobId)) {
            $this->refreshRuntimeState();

            Notification::make()
                ->title((string) __('procynia.system_status.messages.failed_job_not_found'))
                ->info()
                ->send();

            return;
        }

        $table = (string) config('queue.failed.table', 'failed_jobs');
        $job = DB::table($table)
            ->where('id', $failedJobId)
            ->first(['id', 'connection', 'queue', 'failed_at']);

        if ($job === null) {
            $this->refreshRuntimeState();

            Notification::make()
                ->title((string) __('procynia.system_status.messages.failed_job_not_found'))
                ->info()
                ->send();

            return;
        }

        $deleted = DB::table($table)
            ->where('id', $failedJobId)
            ->delete();

        if ($deleted < 1) {
            $this->refreshRuntimeState();

            Notification::make()
                ->title((string) __('procynia.system_status.messages.failed_job_not_found'))
                ->info()
                ->send();

            return;
        }

        Log::info('SystemStatus: failed job row deleted by admin.', [
            'failed_job_id' => $job->id,
            'connection' => $job->connection,
            'queue' => $job->queue,
            'failed_at' => $job->failed_at,
            'user' => auth()->id(),
        ]);

        $this->refreshRuntimeState();

        Notification::make()
            ->title((string) __('procynia.system_status.messages.failed_job_deleted'))
            ->success()
            ->send();
    }

    public function refreshRuntimeState(): void
    {
        $service = app(RuntimeStatusService::class);
        $this->snapshot = $service->snapshot();
        $this->failedJobs = $service->recentFailedJobs(10);

        try {
            $this->health = app(QueueSchedulerHealthService::class)->evaluate();
        } catch (Throwable) {
            $this->health = ['ok' => false, 'scheduler' => 'stale', 'queue' => 'stale'];
        }
    }

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public function mount(): void
    {
        $service = app(RuntimeStatusService::class);
        $this->snapshot = $service->snapshot();
        $this->failedJobs = $service->recentFailedJobs(10);

        try {
            $this->health = app(QueueSchedulerHealthService::class)->evaluate();
        } catch (Throwable) {
            $this->health = ['ok' => false, 'scheduler' => 'stale', 'queue' => 'stale'];
        }
    }

    public function getTitle(): string
    {
        return (string) __('procynia.system_status.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('procynia.system_status.subtitle');
    }
}
