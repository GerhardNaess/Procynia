<?php

namespace App\Filament\Pages;

use App\Services\Operations\RuntimeStatusService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SystemStatus extends Page
{
    protected string $view = 'filament.pages.system-status';

    protected static ?string $navigationLabel = 'System status';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 1;

    /**
     * Current runtime snapshot shown on the page.
     *
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    /**
     * Restrict the page to internal admins.
     */
    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    /**
     * Load the current runtime snapshot.
     */
    public function mount(): void
    {
        $this->snapshot = app(RuntimeStatusService::class)->snapshot();
    }

    /**
     * Return the page title shown in Filament.
     */
    public function getTitle(): string
    {
        return 'System status';
    }

    /**
     * Return the page description shown under the title.
     */
    public function getSubheading(): ?string
    {
        return 'Current Laravel runtime snapshot and service health.';
    }
}
