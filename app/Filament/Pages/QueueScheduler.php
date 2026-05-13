<?php

namespace App\Filament\Pages;

use App\Services\Operations\RuntimeStatusService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class QueueScheduler extends Page
{
    protected string $view = 'filament.pages.queue-scheduler';

    protected static ?string $navigationLabel = 'Queue and scheduler';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 3;

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
        return 'Queue and scheduler';
    }

    /**
     * Return the page description shown under the title.
     */
    public function getSubheading(): ?string
    {
        return 'Operational overview of queue workers and scheduled tasks.';
    }
}
