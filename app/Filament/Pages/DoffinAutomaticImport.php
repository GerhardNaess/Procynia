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

    protected static ?string $navigationLabel = 'Doffin automatisk import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 6;

    public array $data = [];

    /**
     * @var array<string, bool|string|null>
     */
    public array $statusSummary = [];

    public ?string $lastError = null;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public function getTitle(): string
    {
        return 'Doffin automatisk import';
    }

    public function getSubheading(): ?string
    {
        return 'Styr om den planlagte Doffin-importen får kjøre i dette miljøet.';
    }

    public function mount(): void
    {
        $this->loadState();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Planlagt import')
                    ->schema([
                        Toggle::make('scheduled_import_enabled')
                            ->label('Aktiver planlagt import')
                            ->helperText('Krever miljøbryter, admin-bryter og gyldig Doffin API-konfigurasjon.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function loadState(): void
    {
        $service = app(DoffinImportControlService::class);
        $setting = $service->getSetting();

        $this->statusSummary = $service->statusSummary();
        $this->form->fill([
            'scheduled_import_enabled' => $setting->scheduled_import_enabled,
        ]);
    }

    public function save(): void
    {
        $this->lastError = null;

        try {
            $data = $this->form->getState();
            $enabled = (bool) Arr::get($data, 'scheduled_import_enabled', false);

            app(DoffinImportControlService::class)->setScheduledImportEnabled($enabled, auth()->user());
            $this->loadState();

            Notification::make()
                ->title('Doffin automatisk import oppdatert')
                ->success()
                ->body($enabled ? 'Planlagt import er slått på.' : 'Planlagt import er slått av.')
                ->send();
        } catch (Throwable $throwable) {
            $this->handleFailure($throwable);
        }
    }

    private function handleFailure(Throwable $throwable): void
    {
        $this->lastError = $throwable->getMessage();

        Notification::make()
            ->title('Doffin automatisk import feilet')
            ->danger()
            ->body($this->lastError)
            ->send();
    }
}
