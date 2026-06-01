<?php

namespace App\Filament\Pages;

use App\Models\AiTokenEvent;
use App\Models\Customer;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Purpose: Internal Super Admin overview of AI token usage per customer.
 * Inputs: Selected year/month filter via Livewire public properties.
 * Returns: Aggregated token data from ai_token_events for the selected period.
 * Side effects: Runs DB aggregate queries on page load and on filter change.
 * Access: Internal Procynia Super Admin only (customer_id = null, role = super_admin).
 */
class AiTokenUsage extends Page
{
    protected string $view = 'filament.pages.ai-token-usage';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'AI-tokenforbruk';

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 6;

    public int $selectedYear = 0;

    public int $selectedMonth = 0;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $customerRows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $operationRows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $modelRows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $recentEvents = [];

    public string $periodLabel = '';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return 'AI-tokenforbruk';
    }

    public function getTitle(): string
    {
        return 'AI-tokenforbruk';
    }

    public function getSubheading(): ?string
    {
        return 'Intern Procynia-oversikt · Kun for Super Admin';
    }

    public function mount(): void
    {
        $this->selectedYear = (int) now()->format('Y');
        $this->selectedMonth = (int) now()->format('n');
        $this->loadData();
    }

    public function updatedSelectedYear(): void
    {
        $this->loadData();
    }

    public function updatedSelectedMonth(): void
    {
        $this->loadData();
    }

    private function loadData(): void
    {
        $year = $this->selectedYear > 0 ? $this->selectedYear : (int) now()->format('Y');
        $month = $this->selectedMonth > 0 ? $this->selectedMonth : (int) now()->format('n');

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();

        $this->periodLabel = $periodStart->translatedFormat('F Y');

        $customerNames = Customer::query()
            ->select(['id', 'name'])
            ->pluck('name', 'id')
            ->all();

        $customerAgg = AiTokenEvent::query()
            ->select([
                'customer_id',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(input_tokens) as total_input_tokens'),
                DB::raw('SUM(output_tokens) as total_output_tokens'),
                DB::raw('SUM(total_tokens) as total_tokens_sum'),
            ])
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->groupBy('customer_id')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->get();

        $this->customerRows = $customerAgg->map(function (object $row) use ($customerNames): array {
            return [
                'customer_id'         => (int) $row->customer_id,
                'customer_name'       => $customerNames[$row->customer_id] ?? '(ukjent kunde)',
                'event_count'         => (int) $row->event_count,
                'total_input_tokens'  => (int) $row->total_input_tokens,
                'total_output_tokens' => (int) $row->total_output_tokens,
                'total_tokens_sum'    => (int) $row->total_tokens_sum,
            ];
        })->values()->all();

        $operationAgg = AiTokenEvent::query()
            ->select([
                'operation_key',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(input_tokens) as total_input_tokens'),
                DB::raw('SUM(output_tokens) as total_output_tokens'),
                DB::raw('SUM(total_tokens) as total_tokens_sum'),
            ])
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->groupBy('operation_key')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->get();

        $this->operationRows = $operationAgg->map(static function (object $row): array {
            return [
                'operation_key'       => (string) $row->operation_key,
                'event_count'         => (int) $row->event_count,
                'total_input_tokens'  => (int) $row->total_input_tokens,
                'total_output_tokens' => (int) $row->total_output_tokens,
                'total_tokens_sum'    => (int) $row->total_tokens_sum,
            ];
        })->values()->all();

        $modelAgg = AiTokenEvent::query()
            ->select([
                'model',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(input_tokens) as total_input_tokens'),
                DB::raw('SUM(output_tokens) as total_output_tokens'),
                DB::raw('SUM(total_tokens) as total_tokens_sum'),
            ])
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->groupBy('model')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->get();

        $this->modelRows = $modelAgg->map(static function (object $row): array {
            return [
                'model'               => (string) $row->model,
                'event_count'         => (int) $row->event_count,
                'total_input_tokens'  => (int) $row->total_input_tokens,
                'total_output_tokens' => (int) $row->total_output_tokens,
                'total_tokens_sum'    => (int) $row->total_tokens_sum,
            ];
        })->values()->all();

        $userNames = DB::table('users')
            ->select(['id', 'name'])
            ->pluck('name', 'id')
            ->all();

        $recentEvents = AiTokenEvent::query()
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $this->recentEvents = $recentEvents->map(function (AiTokenEvent $event) use ($customerNames, $userNames): array {
            return [
                'id'            => $event->id,
                'created_at'    => $event->created_at?->format('d.m.Y H:i'),
                'customer_name' => $customerNames[$event->customer_id] ?? '(ukjent)',
                'user_name'     => $event->user_id ? ($userNames[$event->user_id] ?? '(ukjent)') : '—',
                'operation_key' => $event->operation_key,
                'model'         => $event->model,
                'input_tokens'  => $event->input_tokens,
                'output_tokens' => $event->output_tokens,
                'total_tokens'  => $event->total_tokens,
            ];
        })->values()->all();
    }

    /**
     * Purpose: Build the list of selectable years for the month filter.
     * Inputs: None.
     * Returns: Descending year list starting from the current year.
     * Side effects: None.
     *
     * @return array<int, int>
     */
    public function selectableYears(): array
    {
        $currentYear = (int) now()->format('Y');

        return range($currentYear, $currentYear - 2);
    }

    /**
     * Purpose: Build the month name map for the month filter select.
     * Inputs: None.
     * Returns: Associative array of month number to Norwegian month name.
     * Side effects: None.
     *
     * @return array<int, string>
     */
    public function selectableMonths(): array
    {
        return [
            1 => 'Januar', 2 => 'Februar', 3 => 'Mars', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
    }
}
