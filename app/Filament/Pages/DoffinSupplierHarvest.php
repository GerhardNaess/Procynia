<?php

namespace App\Filament\Pages;

use App\Services\Doffin\DoffinSupplierHarvestService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Throwable;
use UnitEnum;

/**
 * Purpose:
 * Provide a dedicated admin page for asynchronous Doffin supplier harvesting.
 *
 * Inputs:
 * User-selected date range and optional notice type filters from the page form.
 *
 * Returns:
 * A Filament admin page that starts supplier harvest runs and redirects to the dedicated run monitor.
 *
 * Side effects:
 * Creates new supplier harvest runs and dispatches asynchronous jobs.
 */
class DoffinSupplierHarvest extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.doffin-supplier-harvest';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static ?string $navigationLabel = 'Supplier Harvest';

    protected static string|UnitEnum|null $navigationGroup = 'Doffin';

    protected static ?int $navigationSort = 2;

    /**
     * Purpose:
     * Store the page form state.
     *
     * Inputs:
     * Livewire form field values.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Persists temporary page state between requests.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

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
     * Shows a visible error state in the page.
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
     * Return a short page title for the supplier harvest start page.
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
        return 'Start Harvest';
    }

    /**
     * Purpose:
     * Initialize the page with sensible defaults and any existing run status.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Fills form defaults.
     */
    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
            'types' => ['RESULT'],
        ]);
    }

    /**
     * Purpose:
     * Build the supplier harvest form schema.
     *
     * Inputs:
     * Filament schema builder.
     *
     * Returns:
     * Schema
     *
     * Side effects:
     * Defines the rendered page form fields.
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
     * Start a new asynchronous Doffin supplier harvest run from the page.
     *
     * Inputs:
     * Current form state.
     *
     * Returns:
     * mixed
     *
     * Side effects:
     * Creates a queued run, dispatches the prepare job, and redirects to the dedicated run monitor page.
     */
    public function startSupplierHarvest(): mixed
    {
        $this->lastError = null;

        try {
            $payload = $this->validatedPayload();
            $run = app(DoffinSupplierHarvestService::class)->startRun($payload, auth()->user());

            Notification::make()
                ->title('Supplier harvest queued')
                ->success()
                ->body('The supplier harvest is running in the background. The run page will open now.')
                ->send();

            return $this->redirect(DoffinSupplierHarvestRun::getUrl([
                'runUuid' => $run->uuid,
            ]), navigate: true);
        } catch (Throwable $throwable) {
            $this->handleFailure($throwable);
        }
    }

    /**
     * Purpose:
     * Build a sanitized payload for the supplier harvest service.
     *
     * Inputs:
     * Current form state.
     *
     * Returns:
     * array<string, mixed>
     *
     * Side effects:
     * None.
     */
    private function validatedPayload(): array
    {
        $data = $this->form->getState();

        return [
            'from' => (string) Arr::get($data, 'from'),
            'to' => (string) Arr::get($data, 'to'),
            'types' => collect(Arr::wrap(Arr::get($data, 'types', ['RESULT'])))
                ->map(fn (mixed $type): string => trim((string) $type))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * Purpose:
     * Persist and display page-level failures.
     *
     * Inputs:
     * Thrown exception or error.
     *
     * Returns:
     * None.
     *
     * Side effects:
     * Updates the visible error state and sends an admin notification.
     */
    private function handleFailure(Throwable $throwable): void
    {
        $this->lastError = $throwable->getMessage();

        Notification::make()
            ->title('Supplier harvest failed')
            ->danger()
            ->body($this->lastError)
            ->send();
    }
}
