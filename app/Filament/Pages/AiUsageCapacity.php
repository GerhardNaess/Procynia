<?php

namespace App\Filament\Pages;

use App\Services\Ai\AiUsageReportingService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class AiUsageCapacity extends Page
{
    protected string $view = 'filament.pages.ai-usage-capacity';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = null;

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 5;

    public string $generatedAt = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $summaryCards = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $customerRows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $userRows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $operationRows = [];

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('procynia.ai_usage_capacity.navigation_label');
    }

    public function mount(AiUsageReportingService $service): void
    {
        $report = $service->report();

        $this->generatedAt = (string) $report['generated_at'];
        $this->summaryCards = $report['summary_cards'];
        $this->customerRows = $report['customers'];
        $this->userRows = $report['users'];
        $this->operationRows = $report['operations'];
    }

    public function getTitle(): string
    {
        return (string) __('procynia.ai_usage_capacity.page_title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('procynia.ai_usage_capacity.page_subtitle');
    }
}
