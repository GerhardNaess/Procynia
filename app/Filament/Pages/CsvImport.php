<?php

namespace App\Filament\Pages;

use App\Models\CpvCode;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use UnitEnum;

/**
 * Provides a small admin page for importing the canonical CPV CSV catalog.
 */
class CsvImport extends Page
{
    protected string $view = 'filament.pages.csv-import';

    protected static ?string $navigationLabel = 'CSV Import';

    protected static string|UnitEnum|null $navigationGroup = 'Imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

    /**
     * The current number of CPV rows stored in the catalog.
     */
    public int $catalogCount = 0;

    /**
     * The most recent command output shown in the UI.
     */
    public ?string $lastOutput = null;

    /**
     * The most recent command error shown in the UI.
     */
    public ?string $lastError = null;

    /**
     * Restrict the page to internal admins.
     */
    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    /**
     * Initialize the current catalog count.
     */
    public function mount(): void
    {
        $this->refreshCatalogCount();
    }

    /**
     * Execute the canonical CSV import command.
     */
    public function runImport(): void
    {
        $this->lastError = null;
        $this->lastOutput = null;

        try {
            $exitCode = Artisan::call('cpv:import-catalog');
            $this->lastOutput = trim(Artisan::output());
            $this->refreshCatalogCount();

            if ($exitCode !== 0) {
                throw new \RuntimeException($this->lastOutput !== '' ? $this->lastOutput : 'The CSV import command failed.');
            }

            Notification::make()
                ->title('CSV import completed')
                ->success()
                ->body($this->lastOutput !== '' ? $this->lastOutput : 'The CPV CSV import completed successfully.')
                ->send();
        } catch (Throwable $throwable) {
            $this->lastError = $throwable->getMessage();
            $this->refreshCatalogCount();

            Notification::make()
                ->title('CSV import failed')
                ->danger()
                ->body($this->lastError)
                ->send();
        }
    }

    /**
     * Refresh the CPV catalog row count shown in the page.
     */
    private function refreshCatalogCount(): void
    {
        $this->catalogCount = CpvCode::query()->count();
    }
}
