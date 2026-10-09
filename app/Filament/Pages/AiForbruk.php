<?php

namespace App\Filament\Pages;

use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Ai\Usage\AiUsagePeriod;
use App\Filament\Concerns\HasAdminPageHelp;
use App\Models\AdminPageHelp;
use App\Models\AiUsageAttempt;
use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Services\Ai\Usage\AiUsageLedger;
use App\Services\Billing\BillingEntitlementService;
use App\Support\CustomerContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Purpose: Comprehensive internal AI usage dashboard for Procynia Super Admin.
 * Inputs: Livewire public properties (customer, period, function, trend grouping).
 * Returns: Calls, tokens and cost from trusted ai_usage_attempts rows (AiUsageLedger); guard blocks
 *          from ai_usage_events; Anbud AI-case capacity from customer_ai_case_usages.
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

    public string $totalCostStatus = self::COST_NO_CALLS;

    /** Calls in the period whose cost is not final (pending + unresolved) — never summed as zero. */
    public int $unpricedCalls = 0;

    /** Settlement split of the trusted calls in the period; never added into one figure. */
    public int $pendingCalls = 0;

    public float $pendingReservedNok = 0.0;

    public int $unresolvedCalls = 0;

    public float $unresolvedReservedNok = 0.0;

    /** Pre-boundary attempts in the period: shown as a count only, their cost is never used. */
    public int $legacyCalls = 0;

    public const COST_OK = 'ok';

    public const COST_PARTIAL = 'partial';

    public const COST_MISSING = 'price_missing';

    public const COST_NO_CALLS = 'no_tokens';

    /** Features the filter offers, from the operation registry. */
    public const FEATURE_LABELS = [
        'tender' => 'Anbud',
        'wiki' => 'Wiki',
        'quality' => 'Kvalitet',
        'supplier' => 'Leverandører',
        'compliance' => 'Etterlevelse',
        'risk' => 'Risiko',
        'improvements' => 'Avvik og forbedringer',
        'objectives' => 'Mål og KPI',
        'document_analysis' => 'Dokumentanalyse',
        'system' => 'System',
    ];

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
        $this->dateTo = now()->toDateString();
        $this->customerList = $this->buildCustomerList();
        $this->loadData();
    }

    public function updatedSelectedCustomerId(): void
    {
        $this->loadData();
    }

    public function updatedPeriodPreset(): void
    {
        $this->applyPresetDates();
        $this->loadData();
    }

    public function updatedFunctionFilter(): void
    {
        $this->loadData();
    }

    public function updatedTrendGrouping(): void
    {
        $this->loadData();
    }

    public function applyFilters(): void
    {
        $this->loadData();
    }

    public function resetFilters(): void
    {
        $this->selectedCustomerId = '';
        $this->periodPreset = 'last30';
        $this->functionFilter = '';
        $this->trendGrouping = 'day';
        $this->applyPresetDates();
        $this->loadData();
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    private function loadData(): void
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        $this->periodLabel = $from->format('d.m.Y').' – '.$to->format('d.m.Y');

        $selectedCustomer = $this->selectedCustomerId !== ''
            ? Customer::query()->find((int) $this->selectedCustomerId)
            : null;

        $this->pageContextTitle = $selectedCustomer?->name ?? 'Alle kunder samlet';

        $periodDays = (int) $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($periodDays);
        $prevTo = $from->copy()->subDay();

        $this->kpi = $this->buildKpi($from, $to, $prevFrom, $prevTo);
        $this->functionRows = $this->buildFunctionRows($from, $to);
        $this->customerCapacityRows = $this->buildCustomerCapacityRows($from, $to);
        $this->userRows = $this->buildUserRows($from, $to);
        $this->trendRows = $this->buildTrendRows($from, $to);
        $this->customerTokenRows = $this->buildCustomerTokenRows($from, $to);
        $this->modelTokenRows = $this->buildModelTokenRows($from, $to);
        $this->recentEvents = $this->buildRecentEvents($from, $to);
        $this->alerts = $this->buildAlerts($from, $to);
        $this->buildTotalCost($from, $to);
        $this->buildCharts($from, $to);
    }

    /** Trusted attempt rows for the selected customer, feature and period. */
    private function usageFilter(Carbon $from, Carbon $to): AiUsageFilter
    {
        return new AiUsageFilter(
            period: AiUsagePeriod::days($from, $to),
            customerId: $this->selectedCustomerId !== '' ? (int) $this->selectedCustomerId : null,
            feature: $this->functionFilter !== '' ? $this->functionFilter : null,
        );
    }

    private function ledger(): AiUsageLedger
    {
        return app(AiUsageLedger::class);
    }

    // -------------------------------------------------------------------------
    // KPI
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildKpi(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $cur = $this->ledger()->totals($this->usageFilter($from, $to));
        $prev = $this->ledger()->totals($this->usageFilter($prevFrom, $prevTo));

        $curOps = $cur['calls'];
        $prevOps = $prev['calls'];

        $curBlocked = $this->countBlocked($from, $to);
        $prevBlocked = $this->countBlocked($prevFrom, $prevTo);

        $curTok = $cur['total_tokens'];
        $prevTok = $prev['total_tokens'];

        $curAvg = $curOps > 0 ? (int) round($curTok / $curOps) : 0;
        $prevAvg = $prevOps > 0 ? (int) round($prevTok / $prevOps) : 0;

        $activatedCases = $this->countActivatedCases($from, $to);
        $totalCapacity = $this->totalCapacity();

        return [
            'operations' => $curOps,
            'blocked' => $curBlocked,
            'tokens' => $curTok,
            'avg_tokens' => $curAvg,
            'activated_cases' => $activatedCases,
            'capacity' => $totalCapacity,
            'capacity_pct' => $totalCapacity > 0 ? min(100, (int) round($activatedCases / $totalCapacity * 100)) : 0,
            'trend_operations' => $this->trendPct($curOps, $prevOps),
            'trend_blocked' => $this->trendPct($curBlocked, $prevBlocked),
            'trend_tokens' => $this->trendPct($curTok, $prevTok),
            'trend_avg' => $this->trendPct($curAvg, $prevAvg),
        ];
    }

    /**
     * Calls the Anbud usage guard refused before they reached the provider. They never become
     * attempts, so they are read from the guard's own log; the guard only covers Anbud.
     */
    private function blockedEventsQuery(Carbon $from, Carbon $to)
    {
        return AiUsageEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', AiUsageEvent::STATUS_BLOCKED)
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->when(! in_array($this->functionFilter, ['', 'tender'], true), fn ($q) => $q->whereRaw('1 = 0'));
    }

    private function countBlocked(Carbon $from, Carbon $to): int
    {
        return (int) $this->blockedEventsQuery($from, $to)->sum('operation_count');
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
     * One row per feature and operation, with calls, tokens and actual cost.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFunctionRows(Carbon $from, Carbon $to): array
    {
        $rows = $this->ledger()->breakdown($this->usageFilter($from, $to), ['feature', 'operation_key']);
        $totalOps = array_sum(array_column($rows, 'calls')) ?: 1;

        return array_map(fn (array $row): array => [
            'feature' => $row['feature'],
            'operation_key' => $row['operation_key'],
            'label' => $this->operationLabel((string) $row['operation_key']),
            'operations' => $row['calls'],
            'blocked' => 0,
            'tokens' => $row['total_tokens'],
            'pct' => (int) round($row['calls'] / $totalOps * 100),
            'has_token_data' => $row['total_tokens'] > 0,
            'cost_nok' => $row['cost_nok'],
            'cost_status' => $this->costStatus($row),
        ], $rows);
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
            $cases = (int) ($casesByCustomer->get($customer->id)?->case_count ?? 0);
            $limit = $service->includedAiCredits($customer);
            $limitDefined = $limit > 0;
            $pct = $limitDefined ? min(100, (int) round($cases / $limit * 100)) : null;
            $status = $limitDefined
                ? ($pct >= 100 ? 'over' : ($pct >= 80 ? 'warning' : 'ok'))
                : 'undefined';

            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'plan' => $customer->planName(),
                'activated' => $cases,
                'limit' => $limit,
                'limit_defined' => $limitDefined,
                'pct' => $pct,
                'status' => $status,
            ];
        })->values()->all();
    }

    // -------------------------------------------------------------------------
    // User rows
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildUserRows(Carbon $from, Carbon $to): array
    {
        $rows = $this->ledger()->breakdown($this->usageFilter($from, $to), ['customer_id', 'user_id'], 50);

        $users = DB::table('users')->whereIn('id', array_filter(array_column($rows, 'user_id')))->select(['id', 'name', 'bid_role'])->get()->keyBy('id');
        $customers = DB::table('customers')->whereIn('id', array_filter(array_column($rows, 'customer_id')))->select(['id', 'name'])->get()->keyBy('id');
        $blocked = $this->blockedEventsQuery($from, $to)
            ->select(['customer_id', 'user_id', DB::raw('SUM(operation_count) as blocked')])
            ->groupBy('customer_id', 'user_id')
            ->get()
            ->keyBy(fn (object $row): string => $row->customer_id.'|'.$row->user_id);

        return array_map(function (array $row) use ($users, $customers, $blocked): array {
            $user = $users->get($row['user_id']);
            $customer = $customers->get($row['customer_id']);

            return [
                'user_name' => $user?->name ?? ($row['user_id'] === null ? '(ingen bruker — jobb)' : '(ukjent)'),
                'customer_name' => $customer?->name ?? ($row['customer_id'] === null ? 'System' : '(ukjent)'),
                'role' => $user?->bid_role ?? '—',
                'operations' => $row['calls'],
                'tokens' => $row['total_tokens'],
                'avg_tokens' => $row['calls'] > 0 ? (int) round($row['total_tokens'] / $row['calls']) : 0,
                'blocked' => (int) ($blocked->get($row['customer_id'].'|'.$row['user_id'])?->blocked ?? 0),
                'has_token_data' => $row['total_tokens'] > 0,
                'cost_nok' => $row['cost_nok'],
                'cost_status' => $this->costStatus($row),
            ];
        }, $rows);
    }

    // -------------------------------------------------------------------------
    // Trend rows
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTrendRows(Carbon $from, Carbon $to): array
    {
        $groupFormat = match ($this->trendGrouping) {
            'week' => "TO_CHAR(created_at, 'IYYY-IW')",
            'month' => "TO_CHAR(created_at, 'YYYY-MM')",
            default => "TO_CHAR(created_at, 'YYYY-MM-DD')",
        };

        $usage = $this->ledger()->trend($this->usageFilter($from, $to), in_array($this->trendGrouping, ['week', 'month'], true) ? $this->trendGrouping : 'day');
        $blocked = $this->blockedEventsQuery($from, $to)
            ->selectRaw("$groupFormat as period_key, SUM(operation_count) as blocked")
            ->groupByRaw($groupFormat)
            ->get()
            ->keyBy('period_key');

        return $this->buildPeriodBuckets($from, $to)->map(function (string $key) use ($usage, $blocked): array {
            $row = $usage[$key] ?? null;
            $ops = (int) ($row['calls'] ?? 0);
            $tokens = (int) ($row['total_tokens'] ?? 0);

            return [
                'period' => $key,
                'operations' => $ops,
                'tokens' => $tokens,
                'avg_tokens' => $ops > 0 ? (int) round($tokens / $ops) : 0,
                'blocked' => (int) ($blocked->get($key)?->blocked ?? 0),
                'cost_nok' => $row['cost_nok'] ?? null,
                'cost_status' => $row === null ? self::COST_NO_CALLS : $this->costStatus($row),
            ];
        })->values()->all();
    }

    /**
     * Generate a complete ordered list of period bucket keys from $from to $to,
     * using the same key format as the SQL group-by expressions in buildTrendRows.
     * Ensures the chart x-axis covers the full selected period even when data is sparse.
     *
     * @return Collection<int, string>
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
     * Calls, tokens and cost per customer for the selected period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomerTokenRows(Carbon $from, Carbon $to): array
    {
        $customerNames = Customer::query()->pluck('name', 'id')->all();

        return array_map(fn (array $row): array => [
            'customer_id' => $row['customer_id'],
            'customer_name' => $row['customer_id'] === null ? 'System' : ($customerNames[$row['customer_id']] ?? '(ukjent kunde)'),
            'event_count' => $row['calls'],
            'total_input_tokens' => $row['input_tokens'],
            'total_output_tokens' => $row['output_tokens'],
            'total_tokens_sum' => $row['total_tokens'],
            'cost_nok' => $row['cost_nok'],
            'cost_status' => $this->costStatus($row),
        ], $this->ledger()->breakdown($this->usageFilter($from, $to), ['customer_id']));
    }

    /**
     * Calls, tokens and cost per model for the selected period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildModelTokenRows(Carbon $from, Carbon $to): array
    {
        return array_map(fn (array $row): array => [
            'model' => (string) $row['model'],
            'event_count' => $row['calls'],
            'total_input_tokens' => $row['input_tokens'],
            'total_output_tokens' => $row['output_tokens'],
            'total_tokens_sum' => $row['total_tokens'],
            'cost_nok' => $row['cost_nok'],
            'cost_status' => $this->costStatus($row),
        ], $this->ledger()->breakdown($this->usageFilter($from, $to), ['model']));
    }

    /**
     * The latest provider attempts in the period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentEvents(Carbon $from, Carbon $to): array
    {
        $attempts = $this->ledger()->recent($this->usageFilter($from, $to), 30);
        $customerNames = Customer::query()->whereIn('id', $attempts->pluck('customer_id')->filter())->pluck('name', 'id')->all();
        $userNames = DB::table('users')->whereIn('id', $attempts->pluck('user_id')->filter())->pluck('name', 'id')->all();

        return $attempts->map(fn (AiUsageAttempt $attempt): array => [
            'id' => $attempt->id,
            'created_at' => $attempt->started_at?->format('d.m.Y H:i'),
            'customer_name' => $attempt->customer_id === null ? 'System' : ($customerNames[$attempt->customer_id] ?? '(ukjent)'),
            'user_name' => $attempt->user_id ? ($userNames[$attempt->user_id] ?? '(ukjent)') : '—',
            'feature' => $attempt->feature,
            'operation_key' => $attempt->operation_key,
            'resource_type' => $attempt->resource_type,
            'status' => $attempt->status,
            'model' => $attempt->model,
            'input_tokens' => $attempt->input_tokens,
            'output_tokens' => $attempt->output_tokens,
            'total_tokens' => $attempt->total_tokens,
            'cost_nok' => $attempt->settlement_status === AiUsageAttempt::SETTLEMENT_SETTLED ? (float) $attempt->cost_nok : null,
        ])->values()->all();
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
                    'type' => 'red',
                    'title' => $row['customer_name'].' — over kapasitetsgrense',
                    'message' => "{$row['activated']} AI-aktiverte anbud av {$row['limit']} inkludert ({$row['pct']} %).",
                ];
            } elseif ($row['pct'] >= 80) {
                $alerts[] = [
                    'type' => 'amber',
                    'title' => $row['customer_name'].' — nærmer seg grense',
                    'message' => "{$row['activated']} AI-aktiverte anbud av {$row['limit']} inkludert ({$row['pct']} %).",
                ];
            }
        }

        $customerNames = DB::table('customers')->pluck('name', 'id');

        $blockedCustomers = $this->blockedEventsQuery($from, $to)
            ->select(['customer_id', DB::raw('SUM(operation_count) as blocked_count')])
            ->groupBy('customer_id')
            ->having(DB::raw('SUM(operation_count)'), '>', 5)
            ->orderByDesc('blocked_count')
            ->limit(5)
            ->get();

        foreach ($blockedCustomers as $row) {
            $name = $customerNames->get($row->customer_id, '(ukjent)');
            $alerts[] = [
                'type' => 'amber',
                'title' => "$name — blokkerte AI-forsøk",
                'message' => (int) $row->blocked_count.' blokkerte forsøk i valgt periode.',
            ];
        }

        $heavyUsers = array_filter(
            $this->ledger()->breakdown($this->usageFilter($from, $to), ['customer_id', 'operation_key'], 20),
            fn (array $row): bool => $row['total_tokens'] > 500000,
        );

        foreach (array_slice($heavyUsers, 0, 3) as $row) {
            $name = $customerNames->get($row['customer_id'], 'System');
            $label = $this->operationLabel((string) $row['operation_key']);
            $tokens = number_format($row['total_tokens'], 0, ',', ' ');
            $alerts[] = [
                'type' => 'blue',
                'title' => "$name · $label — høyt tokenforbruk",
                'message' => "$tokens tokens totalt i valgt periode.",
            ];
        }

        // Calls that reached the provider with no owner are excluded from every figure above, so
        // they have to be visible here or they would simply vanish.
        $unattributed = $this->ledger()->unattributed(CarbonImmutable::instance($from));

        if ($unattributed['count'] > 0) {
            $alerts[] = [
                'type' => 'red',
                'title' => 'AI-kall uten kunde',
                'message' => sprintf('%d kall i perioden nådde leverandøren uten kunde (sist %s). De er ikke med i tallene. Kjør ai:usage-integrity.', $unattributed['count'], $unattributed['last_at']),
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
        $blockedValues = array_column($this->trendRows, 'blocked');
        $tokensValues = array_column($this->trendRows, 'tokens');
        $labels = array_column($this->trendRows, 'period');

        $maxOps = max(array_merge([1], $operationsValues, $blockedValues));
        $this->operationsChartMax = $maxOps;

        $this->operationsChartPoints = $this->svgPoints($operationsValues, $maxOps);
        $this->blockedChartPoints = $this->svgPoints($blockedValues, $maxOps);
        $hasRealTokenData = array_sum($tokensValues) > 0;
        $maxTokens = max(array_merge([1], $tokensValues));
        $this->tokensChartMax = $hasRealTokenData ? $maxTokens : 0;
        $this->tokensChartPoints = $hasRealTokenData
            ? $this->svgPoints($tokensValues, $maxTokens)
            : '';
        $this->operationsChartLabels = $this->svgLabels($labels);
    }

    /**
     * @param  array<int, int>  $values
     */
    private function svgPoints(array $values, int $max, int $w = 560, int $h = 140, int $padT = 10, int $padB = 30): string
    {
        if (count($values) === 0) {
            return '';
        }

        if (count($values) === 1) {
            $values = [$values[0], $values[0]];
        }

        $max = max($max, 1);
        $plotH = $h - $padT - $padB;
        $stepX = $w / (count($values) - 1);
        $points = [];

        foreach (array_values($values) as $i => $v) {
            $x = round($i * $stepX, 1);
            $y = round($padT + $plotH - ($v / $max * $plotH), 1);
            $points[] = "$x,$y";
        }

        return implode(' ', $points);
    }

    /**
     * @param  array<int, string>  $labels
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

        $step = (int) ceil($count / $maxCount);
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
            'month' => [$this->dateFrom, $this->dateTo] = [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'quarter' => [$this->dateFrom, $this->dateTo] = [$today->copy()->firstOfQuarter()->toDateString(), $today->toDateString()],
            'year' => [$this->dateFrom, $this->dateTo] = [$today->copy()->startOfYear()->toDateString(), $today->toDateString()],
            'custom' => null,
            default => [$this->dateFrom, $this->dateTo] = [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
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
     * Settled cost in NOK of the trusted calls in the period, from the attempt snapshots, with the
     * open settlements (pending, unresolved) and legacy calls beside it — never inside it.
     */
    private function buildTotalCost(Carbon $from, Carbon $to): void
    {
        $filter = $this->usageFilter($from, $to);
        $totals = $this->ledger()->totals($filter);

        $this->unpricedCalls = $totals['pending_calls'] + $totals['unresolved_calls'];
        $this->pendingCalls = $totals['pending_calls'];
        $this->pendingReservedNok = round($totals['pending_reserved_cost_nok'], 2);
        $this->unresolvedCalls = $totals['unresolved_calls'];
        $this->unresolvedReservedNok = round($totals['unresolved_reserved_cost_nok'], 2);
        $this->legacyCalls = $this->ledger()->legacyCalls($filter);
        $this->totalCostStatus = $this->costStatus($totals);
        $this->totalCostNok = in_array($this->totalCostStatus, [self::COST_OK, self::COST_PARTIAL], true)
            ? round($totals['cost_nok'], 2)
            : null;
    }

    /**
     * ok: no call has an open settlement. partial: some calls are pending or unresolved, the sum
     * covers the settled rest. price_missing: none is settled. no_tokens: no calls.
     *
     * @param  array<string, int|float>  $row
     */
    private function costStatus(array $row): string
    {
        $calls = (int) ($row['calls'] ?? 0);
        $unpriced = (int) ($row['pending_calls'] ?? 0) + (int) ($row['unresolved_calls'] ?? 0);

        return match (true) {
            $calls === 0 => self::COST_NO_CALLS,
            $unpriced === 0 => self::COST_OK,
            $unpriced < $calls => self::COST_PARTIAL,
            default => self::COST_MISSING,
        };
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

    /** A readable label for an operation key: its feature, then the operation itself. */
    public function operationLabel(string $key): string
    {
        $feature = strstr($key, '.', true) ?: $key;
        $operation = $feature === $key ? '' : substr($key, strlen($feature) + 1);

        return trim((self::FEATURE_LABELS[$feature] ?? $feature).' · '.str_replace(['_', '.'], [' ', ' · '], $operation), ' ·');
    }

    public function trendClass(int $pct, bool $inverseGood = false): string
    {
        if ($pct === 0) {
            return 'text-gray-500 bg-gray-100';
        }

        $positive = $pct > 0;
        $good = $inverseGood ? ! $positive : $positive;

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
        $good = $inverseGood ? ! $positive : $positive;

        return $good ? 'text-emerald-600' : 'text-red-500';
    }

    public function capacityStatusClass(string $status): string
    {
        return match ($status) {
            'over' => 'text-red-700 bg-red-50 border-red-200',
            'warning' => 'text-amber-700 bg-amber-50 border-amber-200',
            'undefined' => 'text-gray-500 bg-gray-50 border-gray-200',
            default => 'text-emerald-700 bg-emerald-50 border-emerald-200',
        };
    }

    public function capacityStatusLabel(string $status): string
    {
        return match ($status) {
            'over' => 'Over grense',
            'warning' => 'Nær grense',
            'undefined' => 'Ikke definert',
            default => 'Normal',
        };
    }
}
