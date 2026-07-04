<?php

namespace App\Filament\Pages;

use App\Services\Doffin\DoffinImportControlService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Throwable;
use UnitEnum;

class DoffinAutomaticImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.doffin-automatic-import';

    protected static ?string $navigationLabel = 'Doffin automatisering';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 6;

    public array $data = [];

    /**
     * @var array<string, array<string, bool|string|null>>
     */
    public array $statusSummaries = [];

    public ?string $lastError = null;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public function getTitle(): string
    {
        return 'Doffin automatisering';
    }

    public function getSubheading(): ?string
    {
        return 'Styr planlagt Doffin-batchimport og watch inbox discovery i dette miljøet.';
    }

    public function mount(): void
    {
        $this->loadState();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Doffin batch-import')
                    ->schema([
                        Toggle::make('scheduled_import_enabled')
                            ->label('Aktiver planlagt batch-import')
                            ->helperText('Krever miljøbryter, admin-bryter og gyldig Doffin API-konfigurasjon.'),
                    ]),
                Section::make('Doffin overvåkningsprofiler / watch inbox discovery')
                    ->schema([
                        Toggle::make('watch_inbox_discovery_enabled')
                            ->label('Aktiver watch inbox discovery')
                            ->helperText('Krever miljøbryter, admin-bryter og gyldig lokal/test Doffin API-konfigurasjon.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function loadState(): void
    {
        $service = app(DoffinImportControlService::class);
        $setting = $service->getSetting();

        $this->statusSummaries = $service->automationStatusSummary();
        $this->form->fill([
            'scheduled_import_enabled' => $setting->scheduled_import_enabled,
            'watch_inbox_discovery_enabled' => $setting->watch_inbox_discovery_enabled,
        ]);
    }

    public function save(): void
    {
        $this->lastError = null;

        try {
            $data = $this->form->getState();
            $scheduledImportEnabled = (bool) Arr::get($data, 'scheduled_import_enabled', false);
            $watchInboxDiscoveryEnabled = (bool) Arr::get($data, 'watch_inbox_discovery_enabled', false);

            $service = app(DoffinImportControlService::class);
            $service->setScheduledImportEnabled($scheduledImportEnabled, auth()->user());
            $service->setWatchInboxDiscoveryEnabled($watchInboxDiscoveryEnabled, auth()->user());
            $this->loadState();

            Notification::make()
                ->title('Doffin automatisering oppdatert')
                ->success()
                ->body('Planlagte Doffin-brytere er lagret.')
                ->send();
        } catch (Throwable $throwable) {
            $this->handleFailure($throwable);
        }
    }

    private function handleFailure(Throwable $throwable): void
    {
        $this->lastError = $throwable->getMessage();

        Notification::make()
            ->title('Doffin automatisering feilet')
            ->danger()
            ->body($this->lastError)
            ->send();
    }
}
