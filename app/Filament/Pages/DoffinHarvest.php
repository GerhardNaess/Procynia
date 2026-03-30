<?php

namespace App\Filament\Pages;

use App\Services\Doffin\SupplierLookupRunService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Provides manual Doffin harvest and supplier lookup actions for internal admins.
 */
class DoffinHarvest extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.doffin-harvest';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static ?string $navigationLabel = 'Doffin Harvest';

    protected static string|UnitEnum|null $navigationGroup = 'Doffin';

    protected static ?int $navigationSort = 1;

    /**
     * Form state for the page.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * The latest execution result shown in the UI.
     *
     * @var array<string, mixed>|null
     */
    public ?array $resultSummary = null;

    /**
     * The latest execution error shown in the UI.
     */
    public ?string $lastError = null;

    /**
     * The supplier lookup run uuid shown in the URL and reused across refreshes.
     */
    #[Url(as: 'supplier_lookup_run', except: '')]
    public string $supplierLookupRunUuid = '';

    /**
     * The latest supplier lookup run status shown in the UI.
     *
     * @var array<string, mixed>|null
     */
    public ?array $supplierLookupStatus = null;

    /**
     * Restrict the page to internal admins.
     */
    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    /**
     * Initialize the page form defaults.
     */
    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
            'supplier_name' => null,
            'types' => ['RESULT'],
        ]);

        $this->refreshSupplierLookupStatus();
    }

    /**
     * Build the page form schema.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('from')
                    ->label('From date')
                    ->required(),
                DatePicker::make('to')
                    ->label('To date')
                    ->required(),
                TextInput::make('supplier_name')
                    ->label('Supplier lookup')
                    ->placeholder('Enter a supplier name')
                    ->maxLength(255),
                Select::make('types')
                    ->label('Notice types')
                    ->options([
                        'RESULT' => 'Result',
                        'ANNOUNCEMENT_OF_CONCLUSION_OF_CONTRACT' => 'Announcement of conclusion of contract',
                    ])
                    ->multiple()
                    ->required(),
            ])
            ->columns(2)
            ->statePath('data');
    }

    /**
     * Purpose:
     * Block the old synchronous harvest path while the async supplier lookup path is stabilized.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Shows a failure notification instead of running a blocking harvest request.
     */
    public function runHarvest(): void
    {
        $this->handleFailure(new \RuntimeException(
            'The synchronous Doffin harvest is temporarily disabled. Use the async supplier lookup action only.'
        ));
    }

    /**
     * Execute a supplier lookup from the page form.
     */
    public function runSupplierLookup(): void
    {
        $this->lastError = null;

        try {
            if ($this->hasActiveSupplierLookupRun()) {
                throw new \RuntimeException('The current supplier lookup run is still active.');
            }

            $payload = $this->validatedPayload();

            if (trim((string) ($payload['supplier_name'] ?? '')) === '') {
                throw new \RuntimeException('Supplier lookup requires a supplier name.');
            }

            $run = app(SupplierLookupRunService::class)->startRun($payload, auth()->user());
            $this->supplierLookupRunUuid = $run->uuid;
            $this->refreshSupplierLookupStatus();

            Notification::make()
                ->title('Supplier lookup queued')
                ->success()
                ->body('The supplier lookup is running in the background. Progress will update automatically.')
                ->send();
        } catch (Throwable $throwable) {
            $this->handleFailure($throwable);
        }
    }

    /**
     * Purpose:
     * Refresh the supplier lookup status panel from the current run uuid.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Updates the Livewire status payload used by the page polling UI.
     */
    public function refreshSupplierLookupStatus(): void
    {
        $this->supplierLookupStatus = app(SupplierLookupRunService::class)
            ->statusPayloadForUuid($this->supplierLookupRunUuid);
    }

    /**
     * Purpose:
     * Determine whether the currently selected supplier lookup run is active.
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
    public function hasActiveSupplierLookupRun(): bool
    {
        return in_array((string) ($this->supplierLookupStatus['status'] ?? ''), [
            'queued',
            'preparing',
            'running',
        ], true);
    }

    /**
     * Purpose:
     * Format a supplier lookup ETA value for the status panel.
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
            return 'Calculating...';
        }

        if ($seconds <= 0) {
            return 'Less than 1 minute';
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
     * Build a sanitized execution payload from the form state.
     *
     * @return array<string, mixed>
     */
    private function validatedPayload(): array
    {
        $data = $this->form->getState();

        return [
            'from' => (string) Arr::get($data, 'from'),
            'to' => (string) Arr::get($data, 'to'),
            'supplier_name' => trim((string) Arr::get($data, 'supplier_name')),
            'types' => collect(Arr::wrap(Arr::get($data, 'types', ['RESULT'])))
                ->map(fn (mixed $type): string => trim((string) $type))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * Format a short success message for admin notifications.
     */
    private function successBody(array $result): string
    {
        $harvest = $result['harvest'] ?? [];
        $persistence = $result['persistence'] ?? [];

        return sprintf(
            'Processed %d notices and persisted %d notice rows.',
            (int) ($harvest['notices_seen'] ?? 0),
            (int) ($persistence['notices_persisted'] ?? 0),
        );
    }

    /**
     * Persist and display execution failures.
     */
    private function handleFailure(Throwable $throwable): void
    {
        $this->lastError = $throwable->getMessage();

        Notification::make()
            ->title('Doffin execution failed')
            ->danger()
            ->body($this->lastError)
            ->send();
    }
}
