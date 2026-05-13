<?php

namespace App\Filament\Pages;

use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class BackupRecovery extends Page
{
    protected string $view = 'filament.pages.operations-placeholder';

    protected static ?string $navigationLabel = 'Backup and recovery';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 5;

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
        return 'Backup and recovery';
    }

    /**
     * Return the page description shown under the title.
     */
    public function getSubheading(): ?string
    {
        return 'Placeholder for future backup status, restore checks, and recovery procedures.';
    }
}
