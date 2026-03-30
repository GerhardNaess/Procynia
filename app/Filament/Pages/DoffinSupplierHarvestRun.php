<?php

namespace App\Filament\Pages;

use App\Models\DoffinSupplierHarvestRun as DoffinSupplierHarvestRunModel;
use App\Models\DoffinSupplierHarvestRunNotice;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Purpose:
 * Provide a dedicated monitor page for one asynchronous Doffin supplier harvest run.
 *
 * Inputs:
 * A supplier harvest run UUID from the route.
 *
 * Returns:
 * A Filament admin page that renders persisted run progress, metrics, and activity.
 *
 * Side effects:
 * Reads supplier harvest run data from the database and polls while the run is active.
 */
class DoffinSupplierHarvestRun extends Page
{
    protected string $view = 'filament.pages.doffin-supplier-harvest-run';

    protected static ?string $slug = 'doffin-supplier-harvest/{runUuid}';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static ?string $navigationLabel = 'Supplier Harvest Run';

    protected static string|UnitEnum|null $navigationGroup = 'Doffin';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Purpose:
     * Store the current supplier harvest run UUID from the route.
     *
     * Inputs:
     * Route parameter values.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Identifies which harvest run should be rendered and refreshed.
     */
    public string $runUuid = '';

    /**
     * Purpose:
     * Store the normalized persisted payload for the selected harvest run.
     *
     * Inputs:
     * Persisted run and per-notice progress values.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Drives the rendered monitor state on the page.
     *
     * @var array<string, mixed>|null
     */
    public ?array $runStatus = null;

    /**
     * Purpose:
     * Store the most recent page-level failure message.
     *
     * Inputs:
     * Failure message strings.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Shows a visible error state in the page if refresh fails.
     */
    public ?string $lastError = null;

    /**
     * Purpose:
     * Restrict the page to internal admins.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * bool
     *
     * Side effects:
     * None.
     */
    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    /**
     * Purpose:
     * Return a short browser and page title for the monitor.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * string|Htmlable
     *
     * Side effects:
     * None.
     */
    public function getTitle(): string | Htmlable
    {
        return 'Harvest Run';
    }

    /**
     * Purpose:
     * Build breadcrumbs back to the supplier harvest start page.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * array<string, string>
     *
     * Side effects:
     * None.
     */
    public function getBreadcrumbs(): array
    {
        return [
            DoffinSupplierHarvest::getUrl() => 'Supplier Harvest',
            static::getUrl(['runUuid' => $this->runUuid]) => 'Harvest Run',
        ];
    }

    /**
     * Purpose:
     * Load the selected harvest run when the page mounts.
     *
     * Inputs:
     * Route parameter run UUID.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Loads the current run payload or aborts with 404 if the UUID is unknown.
     */
    public function mount(string $runUuid): void
    {
        $this->runUuid = trim($runUuid);
        $this->refreshRunStatus();

        abort_if($this->runStatus === null, 404);
    }

    /**
     * Purpose:
     * Refresh the persisted run payload used by the monitor page.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Updates the rendered phase, metrics, and activity data.
     */
    public function refreshRunStatus(): void
    {
        $this->lastError = null;

        $payload = $this->statusPayloadForUuid($this->runUuid);

        if ($payload === null) {
            $this->lastError = 'The supplier harvest run could not be found.';

            return;
        }

        $this->runStatus = $payload;
    }

    /**
     * Purpose:
     * Determine whether the run page should keep polling for updates.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * bool
     *
     * Side effects:
     * None.
     */
    public function shouldPoll(): bool
    {
        return in_array((string) ($this->runStatus['status'] ?? ''), [
            DoffinSupplierHarvestRunModel::STATUS_QUEUED,
            DoffinSupplierHarvestRunModel::STATUS_PREPARING,
            DoffinSupplierHarvestRunModel::STATUS_RUNNING,
        ], true);
    }

    /**
     * Purpose:
     * Map persisted run state to the hero content shown on the monitor.
     *
     * Inputs:
     * Current normalized run payload.
     *
     * Returns:
     * array<string, string>
     *
     * Side effects:
     * None.
     */
    public function phasePayload(): array
    {
        $status = (string) ($this->runStatus['status'] ?? '');
        $totalItems = (int) ($this->runStatus['total_items'] ?? 0);
        $errorMessage = trim((string) ($this->runStatus['error_message'] ?? ''));

        return match (true) {
            $status === DoffinSupplierHarvestRunModel::STATUS_FAILED => [
                'title' => 'Failed',
                'subtitle' => $errorMessage !== ''
                    ? $errorMessage
                    : 'The supplier harvest did not complete successfully.',
                'badge' => 'Failed',
                'badge_classes' => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200',
            ],
            $status === DoffinSupplierHarvestRunModel::STATUS_COMPLETED => [
                'title' => 'Completed',
                'subtitle' => $totalItems > 0
                    ? 'Supplier harvest finished successfully.'
                    : 'No notices matched the selected date range.',
                'badge' => 'Completed',
                'badge_classes' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
            ],
            $status === DoffinSupplierHarvestRunModel::STATUS_RUNNING && $totalItems > 0 => [
                'title' => 'Processing notices',
                'subtitle' => 'Harvesting suppliers from Doffin notices.',
                'badge' => 'Running',
                'badge_classes' => 'bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-200',
            ],
            default => [
                'title' => 'Preparing notices',
                'subtitle' => 'Building the notice list for the selected date range.',
                'badge' => 'Preparing',
                'badge_classes' => 'bg-gray-100 text-gray-700 ring-1 ring-inset ring-gray-200',
            ],
        };
    }

    /**
     * Purpose:
     * Build a lightweight activity summary from existing run fields.
     *
     * Inputs:
     * Current normalized run payload.
     *
     * Returns:
     * array<int, array{message: string, time: string}>
     *
     * Side effects:
     * None.
     */
    public function activityItems(): array
    {
        if ($this->runStatus === null) {
            return [];
        }

        $items = [];
        $totalItems = (int) ($this->runStatus['total_items'] ?? 0);
        $processedItems = (int) ($this->runStatus['processed_items'] ?? 0);
        $harvestedSuppliers = (int) ($this->runStatus['harvested_suppliers'] ?? 0);
        $status = (string) ($this->runStatus['status'] ?? '');
        $runningNoticeId = $this->runStatus['running_notice_id'] ?? null;

        $items[] = [
            'message' => 'Run created',
            'time' => $this->formatTimestamp($this->runStatus['created_at'] ?? null),
        ];

        if (in_array($status, [
            DoffinSupplierHarvestRunModel::STATUS_QUEUED,
            DoffinSupplierHarvestRunModel::STATUS_PREPARING,
        ], true)) {
            $items[] = [
                'message' => 'Notice discovery in progress',
                'time' => $this->formatTimestamp($this->runStatus['last_heartbeat_at'] ?? $this->runStatus['created_at'] ?? null),
            ];
        }

        if ($totalItems > 0) {
            $items[] = [
                'message' => number_format($totalItems) . ' notices discovered',
                'time' => $this->formatTimestamp($this->runStatus['started_at'] ?? $this->runStatus['last_heartbeat_at'] ?? null),
            ];
        }

        if ($status === DoffinSupplierHarvestRunModel::STATUS_RUNNING && $totalItems > 0) {
            $items[] = [
                'message' => 'Notice processing started',
                'time' => $this->formatTimestamp($this->runStatus['started_at'] ?? null),
            ];
        }

        if ($runningNoticeId) {
            $items[] = [
                'message' => "Processing notice {$runningNoticeId}",
                'time' => $this->formatTimestamp($this->runStatus['last_heartbeat_at'] ?? null),
            ];
        }

        if ($processedItems > 0) {
            $items[] = [
                'message' => number_format($processedItems) . ' notices processed',
                'time' => $this->formatTimestamp($this->runStatus['last_heartbeat_at'] ?? $this->runStatus['finished_at'] ?? null),
            ];
        }

        if ($harvestedSuppliers > 0) {
            $items[] = [
                'message' => number_format($harvestedSuppliers) . ' suppliers harvested',
                'time' => $this->formatTimestamp($this->runStatus['last_heartbeat_at'] ?? $this->runStatus['finished_at'] ?? null),
            ];
        }

        if ($status === DoffinSupplierHarvestRunModel::STATUS_COMPLETED) {
            $items[] = [
                'message' => 'Harvest completed',
                'time' => $this->formatTimestamp($this->runStatus['finished_at'] ?? null),
            ];
        }

        if ($status === DoffinSupplierHarvestRunModel::STATUS_FAILED) {
            $items[] = [
                'message' => 'Harvest failed',
                'time' => $this->formatTimestamp($this->runStatus['finished_at'] ?? $this->runStatus['last_heartbeat_at'] ?? null),
            ];
        }

        return collect($items)
            ->filter(fn (array $item): bool => $item['time'] !== '-')
            ->unique(fn (array $item): string => $item['message'] . '|' . $item['time'])
            ->values()
            ->all();
    }

    /**
     * Purpose:
     * Format persisted ETA seconds for the metric grid.
     *
     * Inputs:
     * Estimated seconds remaining.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    public function etaLabel(?int $seconds): string
    {
        if ($seconds === null) {
            return 'Not available yet';
        }

        if ($seconds === 0) {
            return '0';
        }

        if ($seconds < 0) {
            return 'Less than 1 min';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        if ($hours === 0 && $minutes === 0) {
            $parts[] = "{$remainingSeconds}s";
        }

        return implode(' ', $parts);
    }

    /**
     * Purpose:
     * Format a timestamp for the metric cards and activity feed.
     *
     * Inputs:
     * ISO-8601 timestamp string.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    public function formatTimestamp(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        return Carbon::parse($value)->format('Y-m-d H:i');
    }

    /**
     * Purpose:
     * Format a timestamp for the hero "last update" label.
     *
     * Inputs:
     * ISO-8601 timestamp string.
     *
     * Returns:
     * string
     *
     * Side effects:
     * None.
     */
    public function relativeTimestamp(?string $value): string
    {
        if (blank($value)) {
            return 'Not available yet';
        }

        $timestamp = Carbon::parse($value);

        if ($timestamp->isToday()) {
            return 'Today ' . $timestamp->format('H:i');
        }

        if ($timestamp->isYesterday()) {
            return 'Yesterday ' . $timestamp->format('H:i');
        }

        return $timestamp->format('Y-m-d H:i');
    }

    /**
     * Purpose:
     * Build a normalized supplier harvest status payload from persisted run data.
     *
     * Inputs:
     * Harvest run UUID.
     *
     * Returns:
     * array<string, mixed>|null
     *
     * Side effects:
     * Reads run and per-notice rows from the database.
     */
    private function statusPayloadForUuid(string $uuid): ?array
    {
        $normalizedUuid = trim($uuid);

        if ($normalizedUuid === '') {
            return null;
        }

        $run = DoffinSupplierHarvestRunModel::query()
            ->where('uuid', $normalizedUuid)
            ->first();

        if (! $run instanceof DoffinSupplierHarvestRunModel) {
            return null;
        }

        $processedItems = max(
            (int) $run->processed_items,
            DoffinSupplierHarvestRunNotice::query()
                ->where('doffin_supplier_harvest_run_id', $run->id)
                ->whereIn('status', [
                    DoffinSupplierHarvestRunNotice::STATUS_COMPLETED,
                    DoffinSupplierHarvestRunNotice::STATUS_FAILED,
                ])
                ->count(),
        );
        $totalItems = max(
            (int) $run->total_items,
            DoffinSupplierHarvestRunNotice::query()
                ->where('doffin_supplier_harvest_run_id', $run->id)
                ->count(),
        );
        $failedItems = max(
            (int) $run->failed_items,
            DoffinSupplierHarvestRunNotice::query()
                ->where('doffin_supplier_harvest_run_id', $run->id)
                ->where('status', DoffinSupplierHarvestRunNotice::STATUS_FAILED)
                ->count(),
        );
        $harvestedSuppliers = max(
            (int) $run->harvested_suppliers,
            (int) DoffinSupplierHarvestRunNotice::query()
                ->where('doffin_supplier_harvest_run_id', $run->id)
                ->where('status', DoffinSupplierHarvestRunNotice::STATUS_COMPLETED)
                ->sum('supplier_count'),
        );
        $runningNoticeId = DoffinSupplierHarvestRunNotice::query()
            ->where('doffin_supplier_harvest_run_id', $run->id)
            ->where('status', DoffinSupplierHarvestRunNotice::STATUS_RUNNING)
            ->orderByDesc('updated_at')
            ->value('notice_id');

        return [
            'run_uuid' => $run->uuid,
            'status' => $run->status,
            'source_from_date' => $run->source_from_date?->toDateString(),
            'source_to_date' => $run->source_to_date?->toDateString(),
            'notice_type_filters' => $run->notice_type_filters ?? [],
            'total_items' => $totalItems,
            'processed_items' => $processedItems,
            'harvested_suppliers' => $harvestedSuppliers,
            'failed_items' => $failedItems,
            'progress_percent' => (float) ($run->progress_percent ?? 0),
            'estimated_seconds_remaining' => $run->estimated_seconds_remaining,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'last_heartbeat_at' => $run->last_heartbeat_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'error_message' => $run->error_message,
            'running_notice_id' => $runningNoticeId,
            'is_terminal' => $run->isTerminal(),
        ];
    }
}
