<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Models\AdminPageHelp;
use App\Models\AiModelPrice;
use App\Models\CustomerAiCaseUsage;
use App\Models\AiTokenEvent;
use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\RequirementExtractionRun;
use App\Services\Ai\Pricing\AiTokenCostEstimator;
use App\Services\Billing\BillingEntitlementService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Purpose: Comprehensive internal AI usage dashboard for Procynia Super Admin.
 * Inputs: Livewire public properties (customer, period, function, trend grouping).
 * Returns: Aggregated data from ai_usage_events, ai_token_events, customer_ai_case_usages and customers.
 * Side effects: Runs DB aggregate queries on page load and whenever any filter changes.
 * Access: Internal Procynia Super Admin only (customer_id = null, role = super_admin).
 */
class AiForbruk extends Page
{
    use HasAdminPageHelp;
    protected string $view = 'filament.pages.ai-forbruk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'AI-forbruk';

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 4;

    public ?AdminPageHelp $pageHelp = null;

    // --- Filters ---
    public string $selectedCustomerId = '';
    public string $periodPreset = 'last30';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $functionFilter = '';
    public string $trendGrouping = 'day';

    // --- Derived display values ---
    public string $periodLabel = '';
    public string $pageContextTitle = 'Alle kunder samlet';

    // --- KPI data ---
    /** @var array<string, mixed> */
    public array $kpi = [];

    // --- Rows ---
    /** @var array<int, array<string, mixed>> */
    public array $customerList = [];

    /** @var array<int, array<string, mixed>> */
    public array $functionRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $customerCapacityRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $userRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $trendRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $customerTokenRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $modelTokenRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $recentEvents = [];

    /** @var array<int, array<string, mixed>> */
    public array $alerts = [];

    // --- Cost summary ---
    public ?float $totalCostNok = null;
    public string $totalCostStatus = AiTokenCostEstimator::RESULT_MISSING;

    // --- Chart SVG point strings ---
    public string $operationsChartPoints = '';
    public string $blockedChartPoints = '';
    public string $tokensChartPoints = '';

    // --- Chart axis helpers ---
    public int $operationsChartMax = 0;
    public int $tokensChartMax = 0;

    /** @var array<int, string> */
    public array $operationsChartLabels = [];

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->buildPageHelpAction($this->pageHelp),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'AI-forbruk';
    }

    public function getTitle(): string
    {
        return 'AI-forbruk';
    }

    public function getSubheading(): ?string
    {
        return 'Faktisk AI-forbruk, tokenforbruk og estimert intern AI-kost · Kun Super Admin';
    }

    public function mount(): void
    {
        $this->pageHelp = static::fetchPageHelp('admin.billing.ai_usage', 'admin.ai_forbruk');

        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo   = now()->toDateString();
        $this->customerList = $this->buildCustomerList();
        $this->loadData();
    }

    public function updatedSelectedCustomerId(): void { $this->loadData(); }
    public function updatedPeriodPreset(): void
    {
        $this->applyPresetDates();
        $this->loadData();
    }
    public function updatedFunctionFilter(): void { $this->loadData(); }
    public function updatedTrendGrouping(): void { $this->loadData(); }

    public function applyFilters(): void
    {
        $this->loadData();
    }

    public function resetFilters(): void
    {
        $this->selectedCustomerId = '';
        $this->periodPreset       = 'last30';
        $this->functionFilter     = '';
        $this->trendGrouping      = 'day';
        $this->applyPresetDates();
        $this->loadData();
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    private function loadData(): void
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to   = Carbon::parse($this->dateTo)->endOfDay();

        $this->periodLabel = $from->format('d.m.Y').' – '.$to->format('d.m.Y');

        $selectedCustomer = $this->selectedCustomerId !== ''
            ? Customer::query()->find((int) $this->selectedCustomerId)
            : null;

        $this->pageContextTitle = $selectedCustomer?->name ?? 'Alle kunder samlet';

        $periodDays = (int) $from->diffInDays($to) + 1;
        $prevFrom   = $from->copy()->subDays($periodDays);
        $prevTo     = $from->copy()->subDay();

        $this->kpi = $this->buildKpi($from, $to, $prevFrom, $prevTo);

        $rateMap = $this->buildPriceRateMap($from, $to);

        $this->functionRows = $this->buildFunctionRows($from, $to, $rateMap);
        $this->customerCapacityRows = $this->buildCustomerCapacityRows($from, $to);
        $this->userRows = $this->buildUserRows($from, $to, $rateMap);
        $this->trendRows = $this->buildTrendRows($from, $to, $rateMap);
        $this->customerTokenRows = $this->buildCustomerTokenRows($from, $to);
        $this->modelTokenRows = $this->buildModelTokenRows($from, $to);
        $this->recentEvents = $this->buildRecentEvents($from, $to);
        $this->alerts = $this->buildAlerts($from, $to);
        $this->buildTotalCost($from, $to);
        $this->buildCharts($from, $to);
    }

    // -------------------------------------------------------------------------
    // KPI
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildKpi(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $cur  = $this->aggregateUsageEvents($from, $to);
        $prev = $this->aggregateUsageEvents($prevFrom, $prevTo);

        $curTokens  = $this->aggregateTokenEvents($from, $to);
        $prevTokens = $this->aggregateTokenEvents($prevFrom, $prevTo);

        $curOps  = (int) ($cur->total_operations ?? 0);
        $prevOps = (int) ($prev->total_operations ?? 0);

        $curBlocked  = (int) ($cur->blocked ?? 0);
        $prevBlocked = (int) ($prev->blocked ?? 0);

        $curTok  = (int) ($curTokens->total_tokens ?? 0);
        $prevTok = (int) ($prevTokens->total_tokens ?? 0);

        $curAvg  = $curOps  > 0 ? (int) round($curTok / $curOps)   : 0;
        $prevAvg = $prevOps > 0 ? (int) round($prevTok / $prevOps)  : 0;

        $activatedCases = $this->countActivatedCases($from, $to);
        $totalCapacity  = $this->totalCapacity();

        return [
            'operations'       => $curOps,
            'blocked'          => $curBlocked,
            'tokens'           => $curTok,
            'avg_tokens'       => $curAvg,
            'activated_cases'  => $activatedCases,
            'capacity'         => $totalCapacity,
            'capacity_pct'     => $totalCapacity > 0 ? min(100, (int) round($activatedCases / $totalCapacity * 100)) : 0,
            'trend_operations' => $this->trendPct($curOps, $prevOps),
            'trend_blocked'    => $this->trendPct($curBlocked, $prevBlocked),
            'trend_tokens'     => $this->trendPct($curTok, $prevTok),
            'trend_avg'        => $this->trendPct($curAvg, $prevAvg),
        ];
    }

    private function aggregateUsageEvents(Carbon $from, Carbon $to): object
    {
        $q = AiUsageEvent::query()
            ->whereBetween('created_at', [$from, $to]);

        if ($this->selectedCustomerId !== '') {
            $q->where('customer_id', (int) $this->selectedCustomerId);
        }
        if ($this->functionFilter !== '') {
            $q->where('operation_key', $this->functionFilter);
        }

        return (object) $q->selectRaw(
            'COUNT(*) as total_operations,
             SUM(CASE WHEN status = ? THEN operation_count ELSE 0 END) as blocked',
            [AiUsageEvent::STATUS_BLOCKED]
        )->first()?->toArray() ?? (object) ['total_operations' => 0, 'blocked' => 0];
    }

    private function aggregateTokenEvents(Carbon $from, Carbon $to): object
    {
        $q = AiTokenEvent::query()
            ->whereBetween('created_at', [$from, $to]);

        if ($this->selectedCustomerId !== '') {
            $q->where('customer_id', (int) $this->selectedCustomerId);
        }
        if ($this->functionFilter !== '') {
            $q->where('operation_key', $this->functionFilter);
        }

        $row = $q->selectRaw('SUM(total_tokens) as total_tokens')->first();

        return (object) ['total_tokens' => (int) ($row?->total_tokens ?? 0)];
    }

    private function countActivatedCases(Carbon $from, Carbon $to): int
    {
        $row = $this->activatedCaseUsageQuery($from, $to)
            ->selectRaw('COUNT(DISTINCT saved_notice_id) as activated_cases')
            ->first();

        return (int) ($row?->activated_cases ?? 0);
    }

    private function totalCapacity(): int
    {
        $q = Customer::query()->where('is_active', true);

        if ($this->selectedCustomerId !== '') {
            $q->where('id', (int) $this->selectedCustomerId);
        }

        $service = app(BillingEntitlementService::class);

        return (int) $q->get()->sum(fn (Customer $c) => $service->includedAiCredits($c));
    }

    // -------------------------------------------------------------------------
    // Function rows
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFunctionRows(Carbon $from, Carbon $to, array $rateMap = []): array
    {
        $usageQ = AiUsageEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->select([
                'operation_key',
                DB::raw('COUNT(*) as event_count'),
                DB::raw("SUM(CASE WHEN status = '".AiUsageEvent::STATUS_BLOCKED."' THEN operation_count ELSE 0 END) as blocked_count"),
            ])
            ->groupBy('operation_key')
            ->orderByDesc('event_count');

        if ($this->selectedCustomerId !== '') {
            $usageQ->where('customer_id', (int) $this->selectedCustomerId);
        }
        if ($this->functionFilter !== '') {
            $usageQ->where('operation_key', $this->functionFilter);
        }

        $usageRows = $usageQ->get()->keyBy('operation_key');

        $tokenQ = AiTokenEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->select(['operation_key', DB::raw('SUM(total_tokens) as total_tokens')])
            ->groupBy('operation_key');

        if ($this->selectedCustomerId !== '') {
            $tokenQ->where('customer_id', (int) $this->selectedCustomerId);
        }
        if ($this->functionFilter !== '') {
            $tokenQ->where('operation_key', $this->functionFilter);
        }

        $tokenRows = $tokenQ->get()->keyBy('operation_key');

        $totalOps = $usageRows->sum('event_count') ?: 1;

        return $usageRows->map(function (object $row) use ($tokenRows, $totalOps, $rateMap): array {
            $tokenRow     = $tokenRows->get($row->operation_key);
            $hasTokenData = $tokenRow !== null;
            $inputTokens  = $hasTokenData ? (int) $tokenRow->input_tokens  : 0;
            $outputTokens = $hasTokenData ? (int) $tokenRow->output_tokens : 0;
            $tokens       = $hasTokenData ? (int) $tokenRow->total_tokens   : 0;

            $costData = $hasTokenData
                ? $this->computeRowCost($rateMap, $inputTokens, $outputTokens, $tokens)
                : ['status' => AiTokenCostEstimator::RESULT_NO_TOKENS, 'cost_usd' => null];

            return [
                'operation_key'  => $row->operation_key,
                'label'          => $this->operationLabel($row->operation_key),
                'operations'     => (int) $row->event_count,
                'blocked'        => (int) $row->blocked_count,
                'tokens'         => $tokens,
                'pct'            => (int) round($row->event_count / $totalOps * 100),
                'has_token_data' => $hasTokenData,
                'cost_usd'       => $costData['cost_usd'],
                'cost_status'    => $costData['status'],
            ];
        })->values()->all();
    }

    // -------------------------------------------------------------------------
    // Customer capacity rows
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomerCapacityRows(Carbon $from, Carbon $to): array
    {
        $customersQ = Customer::query()->where('is_active', true)->orderBy('name');

        if ($this->selectedCustomerId !== '') {
            $customersQ->where('id', (int) $this->selectedCustomerId);
        }

        $customers = $customersQ->get();

        $casesByCustomer = $this->activatedCaseUsageQuery($from, $to)
            ->select(['customer_id', DB::raw('COUNT(DISTINCT saved_notice_id) as case_count')])
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $service = app(BillingEntitlementService::class);

        return $customers->map(function (Customer $customer) use ($casesByCustomer, $service): array {
            $cases           = (int) ($casesByCustomer->get($customer->id)?->case_count ?? 0);
            $limit           = $service->includedAiCredits($customer);
            $limitDefined    = $limit > 0;
            $pct             = $limitDefined ? min(100, (int) round($cases / $limit * 100)) : null;
            $status          = $limitDefined
                ? ($pct >= 100 ? 'over' : ($pct >= 80 ? 'warning' : 'ok'))
                : 'undefined';

            return [
                'customer_id'    => $customer->id,
                'customer_name'  => $customer->name,
                'plan'           => $customer->planName(),
                'activated'      => $cases,
                'limit'          => $limit,
                'limit_defined'  => $limitDefined,
                'pct'            => $pct,
                'status'         => $status,
            ];
        })->values()->all();
    }

    // -------------------------------------------------------------------------
    // User rows
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildUserRows(Carbon $from, Carbon $to, array $rateMap = []): array
    {
        $usageQ = AiUsageEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->select([
                'customer_id',
                'user_id',
                DB::raw('COUNT(*) as operations'),
                DB::raw("SUM(CASE WHEN status = '".AiUsageEvent::STATUS_BLOCKED."' THEN operation_count ELSE 0 END) as blocked"),
            ])
            ->groupBy('customer_id', 'user_id')
            ->orderByDesc('operations')
            ->limit(50);

        if ($this->selectedCustomerId !== '') {
            $usageQ->where('customer_id', (int) $this->selectedCustomerId);
        }
        if ($this->functionFilter !== '') {
            $usageQ->where('operation_key', $this->functionFilter);
        }

        $usageRows = $usageQ->get();

        $userIds     = $usageRows->pluck('user_id')->filter()->unique()->all();
        $customerIds = $usageRows->pluck('customer_id')->unique()->all();

        $users     = DB::table('users')->whereIn('id', $userIds)->select(['id', 'name', 'bid_role'])->get()->keyBy('id');
        $customers = DB::table('customers')->whereIn('id', $customerIds)->select(['id', 'name'])->get()->keyBy('id');

        $tokenRows = AiTokenEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->select(['user_id',
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens'),
                DB::raw('SUM(total_tokens) as total_tokens'),
            ])
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->when($this->functionFilter !== '', fn ($q) => $q->where('operation_key', $this->functionFilter))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return $usageRows->map(function (object $row) use ($users, $customers, $tokenRows, $rateMap): array {
            $tokenRow      = $tokenRows->get($row->user_id);
            $hasTokenData  = $tokenRow !== null;
            $inputTokens   = $hasTokenData ? (int) $tokenRow->input_tokens  : 0;
            $outputTokens  = $hasTokenData ? (int) $tokenRow->output_tokens : 0;
            $tokens        = $hasTokenData ? (int) $tokenRow->total_tokens   : 0;
            $ops           = (int) $row->operations;
            $avgTok        = ($hasTokenData && $ops > 0) ? (int) round($tokens / $ops) : 0;
            $user          = $users->get($row->user_id);
            $customer      = $customers->get($row->customer_id);

            $costData = $hasTokenData
                ? $this->computeRowCost($rateMap, $inputTokens, $outputTokens, $tokens)
                : ['status' => AiTokenCostEstimator::RESULT_NO_TOKENS, 'cost_usd' => null];

            return [
                'user_name'      => $user?->name ?? '(ukjent)',
                'customer_name'  => $customer?->name ?? '(ukjent)',
                'role'           => $user?->bid_role ?? '—',
                'operations'     => $ops,
                'tokens'         => $tokens,
                'avg_tokens'     => $avgTok,
                'blocked'        => (int) $row->blocked,
                'has_token_data' => $hasTokenData,
                'cost_usd'       => $costData['cost_usd'],
                'cost_status'    => $costData['status'],
            ];
        })->values()->all();
    }

    // -------------------------------------------------------------------------
    // Trend rows
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTrendRows(Carbon $from, Carbon $to, array $rateMap = []): array
    {
        $groupFormat = match ($this->trendGrouping) {
            'week'  => "TO_CHAR(created_at, 'IYYY-IW')",
            'month' => "TO_CHAR(created_at, 'YYYY-MM')",
            default => "TO_CHAR(created_at, 'YYYY-MM-DD')",
        };

        $usageQ = AiUsageEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                "$groupFormat as period_key,
                 COUNT(*) as operations,
                 SUM(CASE WHEN status = '".AiUsageEvent::STATUS_BLOCKED."' THEN operation_count ELSE 0 END) as blocked"
            )
            ->groupByRaw($groupFormat)
            ->orderByRaw($groupFormat);

        if ($this->selectedCustomerId !== '') {
            $usageQ->where('customer_id', (int) $this->selectedCustomerId);
        }
        if ($this->functionFilter !== '') {
            $usageQ->where('operation_key', $this->functionFilter);
        }

        $tokenQ = AiTokenEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                "$groupFormat as period_key,
                 SUM(input_tokens) as input_tokens,
                 SUM(output_tokens) as output_tokens,
                 SUM(total_tokens) as total_tokens"
            )
            ->groupByRaw($groupFormat)
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->when($this->functionFilter !== '', fn ($q) => $q->where('operation_key', $this->functionFilter));

        $usageRows = $usageQ->get()->keyBy('period_key');
        $tokenRows = $tokenQ->get()->keyBy('period_key');

        $allKeys = $this->buildPeriodBuckets($from, $to);

        return $allKeys->map(function (string $key) use ($usageRows, $tokenRows, $rateMap): array {
            $usage        = $usageRows->get($key);
            $tRow         = $tokenRows->get($key);
            $ops          = (int) ($usage?->operations ?? 0);
            $inputTokens  = (int) ($tRow?->input_tokens  ?? 0);
            $outputTokens = (int) ($tRow?->output_tokens ?? 0);
            $tokens       = (int) ($tRow?->total_tokens  ?? 0);
            $avgTok       = $ops > 0 ? (int) round($tokens / $ops) : 0;

            $costData = $tRow !== null
                ? $this->computeRowCost($rateMap, $inputTokens, $outputTokens, $tokens)
                : ['status' => AiTokenCostEstimator::RESULT_NO_TOKENS, 'cost_usd' => null];

            return [
                'period'      => $key,
                'operations'  => $ops,
                'tokens'      => $tokens,
                'avg_tokens'  => $avgTok,
                'blocked'     => (int) ($usage?->blocked ?? 0),
                'cost_usd'    => $costData['cost_usd'],
                'cost_status' => $costData['status'],
            ];
        })->values()->all();
    }

    /**
     * Generate a complete ordered list of period bucket keys from $from to $to,
     * using the same key format as the SQL group-by expressions in buildTrendRows.
     * Ensures the chart x-axis covers the full selected period even when data is sparse.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function buildPeriodBuckets(Carbon $from, Carbon $to): Collection
    {
        $buckets = collect();

        if ($this->trendGrouping === 'week') {
            $cursor = $from->copy()->startOfWeek(Carbon::MONDAY);
            while ($cursor->lte($to)) {
                $buckets->push($cursor->format('o-W'));
                $cursor->addWeek();
            }
        } elseif ($this->trendGrouping === 'month') {
            $cursor = $from->copy()->startOfMonth();
            while ($cursor->lte($to)) {
                $buckets->push($cursor->format('Y-m'));
                $cursor->addMonth();
            }
        } else {
            $cursor = $from->copy()->startOfDay();
            while ($cursor->lte($to)) {
                $buckets->push($cursor->format('Y-m-d'));
                $cursor->addDay();
            }
        }

        return $buckets;
    }

    /**
     * Purpose: Build the token usage summary per customer for the selected period.
     * Inputs: Period boundaries.
     * Returns: Aggregated token data grouped by customer.
     * Side effects: Runs one grouped token-event query and one customer lookup query.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomerTokenRows(Carbon $from, Carbon $to): array
    {
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
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('customer_id')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->get();

        return $customerAgg->map(function (object $row) use ($customerNames): array {
            return [
                'customer_id'         => (int) $row->customer_id,
                'customer_name'       => $customerNames[$row->customer_id] ?? '(ukjent kunde)',
                'event_count'         => (int) $row->event_count,
                'total_input_tokens'  => (int) $row->total_input_tokens,
                'total_output_tokens' => (int) $row->total_output_tokens,
                'total_tokens_sum'    => (int) $row->total_tokens_sum,
            ];
        })->values()->all();
    }

    /**
     * Purpose: Build the token usage summary per model for the selected period.
     * Inputs: Period boundaries.
     * Returns: Aggregated token data grouped by model.
     * Side effects: Runs one grouped token-event query.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildModelTokenRows(Carbon $from, Carbon $to): array
    {
        $modelAgg = AiTokenEvent::query()
            ->select([
                'model',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('SUM(input_tokens) as total_input_tokens'),
                DB::raw('SUM(output_tokens) as total_output_tokens'),
                DB::raw('SUM(total_tokens) as total_tokens_sum'),
            ])
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('model')
            ->orderByDesc(DB::raw('SUM(total_tokens)'))
            ->get();

        return $modelAgg->map(static function (object $row): array {
            return [
                'model'               => (string) $row->model,
                'event_count'         => (int) $row->event_count,
                'total_input_tokens'  => (int) $row->total_input_tokens,
                'total_output_tokens' => (int) $row->total_output_tokens,
                'total_tokens_sum'    => (int) $row->total_tokens_sum,
            ];
        })->values()->all();
    }

    /**
     * Purpose: Build the most recent token events table for the selected period.
     * Inputs: Period boundaries.
     * Returns: A list of the latest token events with customer and user names attached.
     * Side effects: Runs two small lookup queries and one limited token-event query.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentEvents(Carbon $from, Carbon $to): array
    {
        $customerNames = Customer::query()
            ->select(['id', 'name'])
            ->pluck('name', 'id')
            ->all();

        $userNames = DB::table('users')
            ->select(['id', 'name'])
            ->pluck('name', 'id')
            ->all();

        $recentEvents = AiTokenEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->when($this->functionFilter !== '', fn ($q) => $q->where('operation_key', $this->functionFilter))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return $recentEvents->map(function (AiTokenEvent $event) use ($customerNames, $userNames): array {
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

    // -------------------------------------------------------------------------
    // Alerts
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildAlerts(Carbon $from, Carbon $to): array
    {
        $alerts = [];

        foreach ($this->customerCapacityRows as $row) {
            if ($row['pct'] >= 100) {
                $alerts[] = [
                    'type'    => 'red',
                    'title'   => $row['customer_name'].' — over kapasitetsgrense',
                    'message' => "{$row['activated']} AI-aktiverte anbud av {$row['limit']} inkludert ({$row['pct']} %).",
                ];
            } elseif ($row['pct'] >= 80) {
                $alerts[] = [
                    'type'    => 'amber',
                    'title'   => $row['customer_name'].' — nærmer seg grense',
                    'message' => "{$row['activated']} AI-aktiverte anbud av {$row['limit']} inkludert ({$row['pct']} %).",
                ];
            }
        }

        $blockedCustomers = AiUsageEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', AiUsageEvent::STATUS_BLOCKED)
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->select(['customer_id', DB::raw('SUM(operation_count) as blocked_count')])
            ->groupBy('customer_id')
            ->having(DB::raw('SUM(operation_count)'), '>', 5)
            ->orderByDesc('blocked_count')
            ->limit(5)
            ->get();

        $customerNames = DB::table('customers')->pluck('name', 'id');

        foreach ($blockedCustomers as $row) {
            $name = $customerNames->get($row->customer_id, '(ukjent)');
            $alerts[] = [
                'type'    => 'amber',
                'title'   => "$name — blokkerte AI-forsøk",
                'message' => (int) $row->blocked_count." blokkerte forsøk i valgt periode.",
            ];
        }

        $heavyUsers = AiTokenEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->select(['customer_id', 'operation_key',
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('COUNT(*) as event_count'),
            ])
            ->groupBy('customer_id', 'operation_key')
            ->having(DB::raw('SUM(total_tokens)'), '>', 500000)
            ->orderByDesc('total_tokens')
            ->limit(3)
            ->get();

        foreach ($heavyUsers as $row) {
            $name  = $customerNames->get($row->customer_id, '(ukjent)');
            $label = $this->operationLabel($row->operation_key);
            $tokens = number_format((int) $row->total_tokens, 0, ',', ' ');
            $alerts[] = [
                'type'    => 'blue',
                'title'   => "$name · $label — høyt tokenforbruk",
                'message' => "$tokens tokens totalt i valgt periode.",
            ];
        }

        return $alerts;
    }

    /**
     * Purpose: Build the AI case usage ledger query for the selected customer and dashboard period.
     * Inputs: The current dashboard period boundaries.
     * Returns: A query limited to customer_ai_case_usages rows within the selected period.
     * Side effects: None.
     */
    private function activatedCaseUsageQuery(Carbon $from, Carbon $to)
    {
        $q = CustomerAiCaseUsage::query()
            ->whereBetween('activated_at', [$from, $to]);

        if ($this->selectedCustomerId !== '') {
            $q->where('customer_id', (int) $this->selectedCustomerId);
        }

        return $q;
    }

    // -------------------------------------------------------------------------
    // Charts (inline SVG polyline points)
    // -------------------------------------------------------------------------

    private function buildCharts(Carbon $from, Carbon $to): void
    {
        $operationsValues = array_column($this->trendRows, 'operations');
        $blockedValues    = array_column($this->trendRows, 'blocked');
        $tokensValues     = array_column($this->trendRows, 'tokens');
        $labels           = array_column($this->trendRows, 'period');

        $maxOps = max(array_merge([1], $operationsValues, $blockedValues));
        $this->operationsChartMax    = $maxOps;

        $this->operationsChartPoints = $this->svgPoints($operationsValues, $maxOps);
        $this->blockedChartPoints    = $this->svgPoints($blockedValues, $maxOps);
        $hasRealTokenData            = array_sum($tokensValues) > 0;
        $maxTokens                   = max(array_merge([1], $tokensValues));
        $this->tokensChartMax        = $hasRealTokenData ? $maxTokens : 0;
        $this->tokensChartPoints     = $hasRealTokenData
            ? $this->svgPoints($tokensValues, $maxTokens)
            : '';
        $this->operationsChartLabels = $this->svgLabels($labels);
    }

    /**
     * @param array<int, int> $values
     */
    private function svgPoints(array $values, int $max, int $w = 560, int $h = 140, int $padT = 10, int $padB = 30): string
    {
        if (count($values) === 0) {
            return '';
        }

        if (count($values) === 1) {
            $values = [$values[0], $values[0]];
        }

        $max     = max($max, 1);
        $plotH   = $h - $padT - $padB;
        $stepX   = $w / (count($values) - 1);
        $points  = [];

        foreach (array_values($values) as $i => $v) {
            $x = round($i * $stepX, 1);
            $y = round($padT + $plotH - ($v / $max * $plotH), 1);
            $points[] = "$x,$y";
        }

        return implode(' ', $points);
    }

    /**
     * @param array<int, string> $labels
     * @return array<int, string>
     */
    private function svgLabels(array $labels, int $maxCount = 6): array
    {
        $count = count($labels);

        if ($count === 0) {
            return [];
        }

        if ($count <= $maxCount) {
            return array_map(fn (string $l): string => $this->shortenChartLabel($l), $labels);
        }

        $step   = (int) ceil($count / $maxCount);
        $result = [];

        foreach ($labels as $i => $label) {
            if ($i % $step === 0 || $i === $count - 1) {
                $result[] = $this->shortenChartLabel($label);
            } else {
                $result[] = '';
            }
        }

        return $result;
    }

    private function shortenChartLabel(string $label): string
    {
        if ($this->trendGrouping === 'day'
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $label, $m)) {
            return $m[3].'.'.$m[2];
        }

        if ($this->trendGrouping === 'week'
            && preg_match('/^(\d{4})-(\d{1,2})$/', $label, $m)) {
            return 'U'.(int) $m[2];
        }

        if ($this->trendGrouping === 'month'
            && preg_match('/^(\d{4})-(\d{2})$/', $label, $m)) {
            $months = [
                '01' => 'jan', '02' => 'feb', '03' => 'mar', '04' => 'apr',
                '05' => 'mai', '06' => 'jun', '07' => 'jul', '08' => 'aug',
                '09' => 'sep', '10' => 'okt', '11' => 'nov', '12' => 'des',
            ];

            return ($months[$m[2]] ?? $m[2])." '".substr($m[1], 2);
        }

        return $label;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function applyPresetDates(): void
    {
        $today = now();

        match ($this->periodPreset) {
            'month'   => [$this->dateFrom, $this->dateTo] = [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'quarter' => [$this->dateFrom, $this->dateTo] = [$today->copy()->firstOfQuarter()->toDateString(), $today->toDateString()],
            'year'    => [$this->dateFrom, $this->dateTo] = [$today->copy()->startOfYear()->toDateString(), $today->toDateString()],
            'custom'  => null,
            default   => [$this->dateFrom, $this->dateTo] = [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
        };
    }

    private function trendPct(int $current, int $previous): int
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }

    // -------------------------------------------------------------------------
    // Cost helpers
    // -------------------------------------------------------------------------

    /**
     * Purpose: Compute total estimated cost using PostgreSQL LATERAL join for temporal price accuracy.
     * Inputs: Filtered period boundaries.
     * Returns: None — sets $totalCostUsd and $totalCostStatus.
     * Side effects: Runs one aggregate SQL query.
     */
    private function buildTotalCost(Carbon $from, Carbon $to): void
    {
        $customerClause  = $this->selectedCustomerId !== ''
            ? 'AND e.customer_id = ' . (int) $this->selectedCustomerId
            : '';
        $functionClause  = $this->functionFilter !== ''
            ? "AND e.operation_key = '" . addslashes($this->functionFilter) . "'"
            : '';

        $result = DB::selectOne("
            SELECT
                SUM(
                    CASE
                        WHEN p.id IS NOT NULL AND (UPPER(p.currency) = 'NOK' OR fx.id IS NOT NULL) THEN
                            (e.input_tokens::float  / 1000000.0 * p.input_price_per_1m_tokens::float +
                             e.output_tokens::float / 1000000.0 * p.output_price_per_1m_tokens::float)
                            * CASE WHEN UPPER(p.currency) = 'NOK' THEN 1.0 ELSE fx.rate::float END
                        ELSE NULL
                    END
                ) AS total_cost_nok,
                COUNT(CASE WHEN p.id IS NULL AND e.total_tokens > 0 THEN 1 END) AS unpriced_count,
                COUNT(CASE WHEN p.id IS NOT NULL AND UPPER(p.currency) != 'NOK' AND fx.id IS NULL AND e.total_tokens > 0 THEN 1 END) AS no_rate_count,
                COUNT(CASE WHEN e.total_tokens > 0 THEN 1 END) AS has_tokens_count
            FROM ai_token_events e
            LEFT JOIN LATERAL (
                SELECT id, input_price_per_1m_tokens, output_price_per_1m_tokens, currency
                FROM ai_model_prices
                WHERE provider = e.provider
                  AND model = e.model
                  AND (deployment_name IS NOT DISTINCT FROM e.deployment_name)
                  AND (provider_region  IS NOT DISTINCT FROM e.provider_region)
                  AND valid_from <= e.created_at::date
                  AND (valid_to IS NULL OR valid_to >= e.created_at::date)
                ORDER BY valid_from DESC
                LIMIT 1
            ) p ON TRUE
            LEFT JOIN LATERAL (
                SELECT id, rate
                FROM exchange_rates
                WHERE UPPER(base_currency)  = UPPER(p.currency)
                  AND UPPER(quote_currency) = 'NOK'
                  AND rate_date <= e.created_at::date
                ORDER BY rate_date DESC
                LIMIT 1
            ) fx ON p.id IS NOT NULL AND UPPER(p.currency) != 'NOK'
            WHERE e.created_at BETWEEN ? AND ?
            {$customerClause}
            {$functionClause}
        ", [$from, $to]);

        $hasTokensCount = (int) ($result->has_tokens_count ?? 0);
        $unpricedCount  = (int) ($result->unpriced_count  ?? 0);
        $noRateCount    = (int) ($result->no_rate_count   ?? 0);

        if ($hasTokensCount === 0) {
            $this->totalCostNok    = null;
            $this->totalCostStatus = AiTokenCostEstimator::RESULT_NO_TOKENS;
            return;
        }

        if ($unpricedCount === $hasTokensCount) {
            $this->totalCostNok    = null;
            $this->totalCostStatus = AiTokenCostEstimator::RESULT_MISSING;
            return;
        }

        if ($noRateCount === $hasTokensCount) {
            $this->totalCostNok    = null;
            $this->totalCostStatus = AiTokenCostEstimator::RESULT_NO_RATE;
            return;
        }

        $this->totalCostNok    = $result->total_cost_nok !== null ? round((float) $result->total_cost_nok, 2) : null;
        $this->totalCostStatus = ($unpricedCount > 0 || $noRateCount > 0)
            ? 'partial'
            : AiTokenCostEstimator::RESULT_OK;
    }

    /**
     * Purpose: Build a (provider|model|deployment|region) → AiModelPrice map for the period.
     * Inputs: Period boundaries.
     * Returns: Array keyed by combo string, value is AiModelPrice or null.
     * Side effects: Runs one DB query per unique model combination.
     *
     * @return array<string, AiModelPrice|null>
     */
    private function buildPriceRateMap(Carbon $from, Carbon $to): array
    {
        $combos = AiTokenEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('provider')
            ->select(['provider', 'model', 'deployment_name', 'provider_region'])
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->distinct()
            ->get();

        $map       = [];
        $periodEnd = $to->toDateTime();

        foreach ($combos as $combo) {
            $key       = $this->comboKey($combo->provider, $combo->model, $combo->deployment_name, $combo->provider_region);
            $map[$key] = AiModelPrice::findForEvent(
                $combo->provider,
                $combo->model,
                $combo->deployment_name,
                $combo->provider_region,
                $periodEnd,
            );
        }

        return $map;
    }

    /**
     * Purpose: Compute estimated cost for one aggregated row using the pre-built price rate map.
     * Inputs: Rate map, input/output/total token counts.
     * Returns: Array with 'status' (ok|price_missing|no_tokens) and 'cost_usd' (float|null).
     *
     * @param array<string, AiModelPrice|null> $rateMap
     * @return array{status: string, cost_usd: float|null}
     */
    private function computeRowCost(array $rateMap, int $inputTokens, int $outputTokens, int $totalTokens): array
    {
        if ($totalTokens <= 0) {
            return ['status' => AiTokenCostEstimator::RESULT_NO_TOKENS, 'cost_usd' => null];
        }

        if ($rateMap === []) {
            return ['status' => AiTokenCostEstimator::RESULT_MISSING, 'cost_usd' => null];
        }

        $totalCost    = 0.0;
        $priceFound   = false;

        foreach ($rateMap as $price) {
            if ($price === null) {
                continue;
            }
            $priceFound = true;
        }

        if (! $priceFound) {
            return ['status' => AiTokenCostEstimator::RESULT_MISSING, 'cost_usd' => null];
        }

        // Use the first available price (accurate when there is one model/provider per period).
        $firstPrice = array_values(array_filter(array_values($rateMap)))[0] ?? null;

        if ($firstPrice === null) {
            return ['status' => AiTokenCostEstimator::RESULT_MISSING, 'cost_usd' => null];
        }

        $nativeCost = $inputTokens  / 1_000_000 * (float) $firstPrice->input_price_per_1m_tokens
                    + $outputTokens / 1_000_000 * (float) $firstPrice->output_price_per_1m_tokens;

        // Convert to NOK if the price is in a different currency.
        $priceCurrency = strtoupper((string) $firstPrice->currency);

        if ($priceCurrency === 'NOK') {
            return ['status' => AiTokenCostEstimator::RESULT_OK, 'cost_usd' => round($nativeCost, 2)];
        }

        $fxRate = \App\Models\ExchangeRate::findForDate($priceCurrency, 'NOK', now());

        if ($fxRate === null) {
            return ['status' => AiTokenCostEstimator::RESULT_NO_RATE, 'cost_usd' => null];
        }

        return ['status' => AiTokenCostEstimator::RESULT_OK, 'cost_usd' => round($nativeCost * (float) $fxRate->rate, 2)];
    }

    private function comboKey(?string $provider, ?string $model, ?string $deploymentName, ?string $providerRegion): string
    {
        return implode('|', [
            $provider       ?? '',
            $model          ?? '',
            $deploymentName ?? '',
            $providerRegion ?? '',
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildCustomerList(): array
    {
        return Customer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Customer $c): array => ['id' => (string) $c->id, 'name' => $c->name])
            ->values()
            ->all();
    }

    public function operationLabel(string $key): string
    {
        return match ($key) {
            'saved_notice_requirement_answer_draft'  => 'Svarutkast',
            'saved_notice_documents_upload'           => 'Krav-ekstraksjon',
            'saved_notice_evidence_refresh'           => 'Bevisgrunnlag',
            'saved_notice_assessment_refresh'         => 'Vurdering',
            'saved_notice_individual_prompt'          => 'Individuell prompt',
            'individual_prompt'                       => 'Individuell prompt',
            'answer_regeneration', 'requirement_regeneration' => 'Regenerering',
            'document_summary'                        => 'Dokumentsammendrag',
            'knowledge_document_upload'               => 'Kunnskapsbase-opplasting',
            'knowledge_chunk_metadata_update'         => 'Chunk-metadata',
            'knowledge_vocabulary_analysis_batch'     => 'Standardvokabular',
            default                                   => ucwords(str_replace(['saved_notice_', 'knowledge_', '_'], ['', '', ' '], $key)),
        };
    }

    public function trendClass(int $pct, bool $inverseGood = false): string
    {
        if ($pct === 0) {
            return 'text-gray-500 bg-gray-100';
        }

        $positive = $pct > 0;
        $good     = $inverseGood ? ! $positive : $positive;

        return $good
            ? 'text-emerald-700 bg-emerald-50'
            : 'text-red-700 bg-red-50';
    }

    /**
     * Purpose: Return a text-only colour class for the KPI footer line (no background pill).
     */
    public function trendTextClass(int $pct, bool $inverseGood = false): string
    {
        if ($pct === 0) {
            return 'text-gray-400';
        }

        $positive = $pct > 0;
        $good     = $inverseGood ? ! $positive : $positive;

        return $good ? 'text-emerald-600' : 'text-red-500';
    }

    public function capacityStatusClass(string $status): string
    {
        return match ($status) {
            'over'      => 'text-red-700 bg-red-50 border-red-200',
            'warning'   => 'text-amber-700 bg-amber-50 border-amber-200',
            'undefined' => 'text-gray-500 bg-gray-50 border-gray-200',
            default     => 'text-emerald-700 bg-emerald-50 border-emerald-200',
        };
    }

    public function capacityStatusLabel(string $status): string
    {
        return match ($status) {
            'over'      => 'Over grense',
            'warning'   => 'Nær grense',
            'undefined' => 'Ikke definert',
            default     => 'Normal',
        };
    }
}
