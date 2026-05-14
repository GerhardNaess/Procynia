<?php

namespace App\Filament\Pages;

use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Monitoring extends Page
{
    protected string $view = 'filament.pages.monitoring';

    protected static ?string $navigationLabel = 'Overvåkning';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 2;

    public string $uptimeKumaUrl = '';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public function mount(): void
    {
        $this->uptimeKumaUrl = config('services.uptime_kuma.url', '');
    }

    public function getTitle(): string
    {
        return 'Overvåkning';
    }

    public function getSubheading(): ?string
    {
        return 'Procynia Admin som inngang til oppetidsovervåkning med Uptime Kuma.';
    }
}
