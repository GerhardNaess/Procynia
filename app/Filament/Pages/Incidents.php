<?php

namespace App\Filament\Pages;

use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Incidents extends Page
{
    protected string $view = 'filament.pages.operations-placeholder';

    protected static ?string $navigationLabel = 'Incidents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Restrict the page to internal admins.
     */
    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    /**
     * Return the page title shown in Filament.
     */
    public function getTitle(): string
    {
        return 'Incidents';
    }

    /**
     * Return the page description shown under the title.
     */
    public function getSubheading(): ?string
    {
        return 'Placeholder for future incident logs, escalation notes, and follow-up actions.';
    }
}
