<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Services\Ai\Commercial\AiCaseProfitabilityService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Purpose: Internal AI profitability dashboard for Procynia Super Admin.
 * Inputs: Selected customer and date range filters.
 * Returns: A mapped profitability analysis from AiCaseProfitabilityService.
 * Side effects: Reads profitability data on page load and filter changes only.
 */
class AiProfitability extends Page
{
    protected string $view = 'filament.pages.ai-profitability';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'AI-lønnsomhet';

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 7;

    public string $selectedCustomerId = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $periodLabel = '';

    public string $pageContextTitle = 'Alle kunder samlet';

    /**
     * @var array<string, mixed>
     */
    public array $summary = [];

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
    public array $caseRows = [];

    /**
     * @var array<int, string>
     */
    public array $customerOptions = [];

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return 'AI-lønnsomhet';
    }

    public function getTitle(): string
    {
        return 'AI-lønnsomhet';
    }

    public function getSubheading(): ?string
    {
        return 'Estimert lønnsomhet per kunde og case · Kun Super Admin';
    }

    public function mount(AiCaseProfitabilityService $service): void
    {
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
        $this->customerOptions = $this->buildCustomerOptions();
        $this->loadData($service);
    }

    public function updatedSelectedCustomerId(): void
    {
        $this->loadData(app(AiCaseProfitabilityService::class));
    }

    public function updatedDateFrom(): void
    {
        $this->loadData(app(AiCaseProfitabilityService::class));
    }

    public function updatedDateTo(): void
    {
        $this->loadData(app(AiCaseProfitabilityService::class));
    }

    public function applyFilters(): void
    {
        $this->loadData(app(AiCaseProfitabilityService::class));
    }

    public function resetFilters(): void
    {
        $this->selectedCustomerId = '';
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
        $this->loadData(app(AiCaseProfitabilityService::class));
    }

    /**
     * Purpose: Reload the profitability analysis for the current filters.
     * Inputs: The profitability service dependency.
     * Returns: None.
     * Side effects: Updates public component state only.
     */
    private function loadData(AiCaseProfitabilityService $service): void
    {
        $from = Carbon::parse($this->dateFrom ?: now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($this->dateTo ?: now()->toDateString())->endOfDay();
        $customerId = $this->selectedCustomerId !== '' ? (int) $this->selectedCustomerId : null;

        $selectedCustomer = $customerId !== null
            ? Customer::query()->select(['id', 'name'])->find($customerId)
            : null;

        $report = $service->analyze($from, $to, $customerId);

        $this->periodLabel = $from->format('d.m.Y').' – '.$to->format('d.m.Y');
        $this->pageContextTitle = $selectedCustomer?->name ?? 'Alle kunder samlet';
        $this->summary = $report['summary'];
        $this->customerRows = $report['customers'];
        $this->caseRows = $report['cases'];
        $this->summaryCards = $this->buildSummaryCards();
    }

    /**
     * Purpose: Build the visible summary cards from the loaded profitability report.
     * Inputs: None.
     * Returns: A list of summary card payloads.
     * Side effects: None.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSummaryCards(): array
    {
        $customerCount = count($this->customerRows);
        $caseCount = count($this->caseRows);
        $missingRevenueCount = collect($this->caseRows)
            ->where('revenue_status', 'missing')
            ->count();
        $partialOrMissingCostCount = collect($this->caseRows)
            ->filter(fn (array $row): bool => in_array($row['cost_status'] ?? 'missing', ['missing', 'partial'], true))
            ->count();

        return [
            [
                'label' => 'Allokert inntekt',
                'value' => $this->moneyValue(
                    $this->summary['allocated_revenue_nok'] ?? null,
                    (string) ($this->summary['revenue_status'] ?? 'missing'),
                ),
                'note' => $this->summaryNote('revenue', (string) ($this->summary['revenue_status'] ?? 'missing'), $this->summary['allocated_revenue_nok'] ?? null),
                'tone' => $this->summaryTone((string) ($this->summary['revenue_status'] ?? 'missing')),
            ],
            [
                'label' => 'Intern AI-kost',
                'value' => $this->moneyValue(
                    $this->summary['internal_cost_nok'] ?? null,
                    (string) ($this->summary['cost_status'] ?? 'missing'),
                ),
                'note' => $this->summaryNote('cost', (string) ($this->summary['cost_status'] ?? 'missing'), $this->summary['internal_cost_nok'] ?? null),
                'tone' => $this->summaryTone((string) ($this->summary['cost_status'] ?? 'missing')),
            ],
            [
                'label' => 'Dekningsbidrag',
                'value' => $this->moneyValue(
                    $this->summary['contribution_margin_nok'] ?? null,
                    $this->summaryMarginStatus(),
                ),
                'note' => $this->summaryNote('margin', $this->summaryMarginStatus(), $this->summary['contribution_margin_nok'] ?? null),
                'tone' => $this->summaryTone($this->summaryMarginStatus()),
            ],
            [
                'label' => 'Margin %',
                'value' => $this->percentValue($this->summary['margin_percent'] ?? null),
                'note' => $this->summaryNote('margin', $this->summaryMarginStatus(), $this->summary['margin_percent'] ?? null),
                'tone' => $this->summaryTone($this->summaryMarginStatus()),
            ],
            [
                'label' => 'Kunder',
                'value' => $customerCount.' kunder',
                'note' => 'AI-aktive kunder i valgt periode',
                'tone' => 'primary',
            ],
            [
                'label' => 'AI-caser',
                'value' => $caseCount.' AI-case',
                'note' => 'AI-aktive case-aktiveringer i valgt periode',
                'tone' => 'primary',
            ],
            [
                'label' => 'Manglende inntekt',
                'value' => $missingRevenueCount.' rader',
                'note' => 'Rader med manglende prisgrunnlag',
                'tone' => $missingRevenueCount > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Ufullstendig kost',
                'value' => $partialOrMissingCostCount.' rader',
                'note' => 'Rader med manglende eller delvis kostgrunnlag',
                'tone' => $partialOrMissingCostCount > 0 ? 'warning' : 'success',
            ],
        ];
    }

    /**
     * Purpose: Resolve the combined summary status for margin-related values.
     * Inputs: None.
     * Returns: ok, partial, or missing.
     * Side effects: None.
     */
    public function summaryMarginStatus(): string
    {
        $revenueStatus = (string) ($this->summary['revenue_status'] ?? 'missing');
        $costStatus = (string) ($this->summary['cost_status'] ?? 'missing');

        if ($revenueStatus === 'ok' && $costStatus === 'ok') {
            return 'ok';
        }

        if ($revenueStatus === 'missing' && $costStatus === 'missing' && count($this->caseRows) === 0) {
            return 'missing';
        }

        return 'partial';
    }

    /**
     * Purpose: Resolve the margin status for a single customer or case row.
     * Inputs: The row-specific revenue and cost statuses.
     * Returns: ok, partial, or missing.
     * Side effects: None.
     */
    public function rowMarginStatus(string $revenueStatus, string $costStatus): string
    {
        if ($revenueStatus === 'ok' && $costStatus === 'ok') {
            return 'ok';
        }

        if ($revenueStatus === 'missing' && $costStatus === 'missing') {
            return 'missing';
        }

        return 'partial';
    }

    /**
     * Purpose: Format a monetary amount for display.
     * Inputs: A numeric value or null.
     * Returns: A human-readable amount string.
     * Side effects: None.
     */
    public function moneyValue(mixed $value, string $status = 'ok'): string
    {
        if ($status === 'missing' || ! is_numeric($value)) {
            return 'Ikke beregnet';
        }

        if ($status === 'partial' && (float) $value === 0.0) {
            return 'Delvis beregnet';
        }

        return '≈ '.number_format((float) $value, 0, ',', ' ').' kr';
    }

    /**
     * Purpose: Format a percentage for display.
     * Inputs: A numeric value or null.
     * Returns: A human-readable percentage string.
     * Side effects: None.
     */
    public function percentValue(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Ikke beregnet';
        }

        return number_format((float) $value, 2, ',', ' ').' %';
    }

    /**
     * Purpose: Resolve a short note for a profitability metric.
     * Inputs: The metric kind, the status and the underlying amount.
     * Returns: A human-readable note for the card or row.
     * Side effects: None.
     */
    public function summaryNote(string $kind, string $status, mixed $value): string
    {
        return match ($status) {
            'ok' => match ($kind) {
                'revenue' => 'Allokert fra AI-case',
                'cost' => 'Intern AI-kost',
                'margin' => 'Beregnet fra inntekt og kost',
                default => 'Beregnet',
            },
            'partial' => 'Delvis beregnet',
            default => match ($kind) {
                'revenue' => 'Mangler prisgrunnlag',
                'cost' => 'Mangler kostgrunnlag',
                'margin' => 'Ikke beregnet',
                default => 'Ikke beregnet',
            },
        };
    }

    /**
     * Purpose: Resolve a badge tone for a profitability status.
     * Inputs: A status value.
     * Returns: A Tailwind color token understood by the Blade view.
     * Side effects: None.
     */
    public function summaryTone(string $status): string
    {
        return match ($status) {
            'ok' => 'success',
            'partial' => 'warning',
            default => 'danger',
        };
    }

    /**
     * Purpose: Render a canonical status badge label.
     * Inputs: A profitability status value.
     * Returns: A lower-case status label.
     * Side effects: None.
     */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => 'ok',
            'partial' => 'partial',
            default => 'missing',
        };
    }

    /**
     * Purpose: Build the customer selector options for the sidebar filter.
     * Inputs: None.
     * Returns: A list of id/name pairs.
     * Side effects: Executes a read-only customer query.
     *
     * @return array<int, string>
     */
    private function buildCustomerOptions(): array
    {
        return Customer::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
