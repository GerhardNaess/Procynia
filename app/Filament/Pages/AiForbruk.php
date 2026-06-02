<?php

namespace App\Filament\Pages;

use App\Models\AiTokenEvent;
use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\RequirementExtractionRun;
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
 * Returns: Aggregated data from ai_usage_events, ai_token_events and customers.
 * Side effects: Runs DB aggregate queries on page load and whenever any filter changes.
 * Access: Internal Procynia Super Admin only (customer_id = null, role = super_admin).
 */
class AiForbruk extends Page
{
    protected string $view = 'filament.pages.ai-forbruk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'AI-forbruk';

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 4;

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
    public array $alerts = [];

    // --- Chart SVG point strings ---
    public string $operationsChartPoints = '';
    public string $blockedChartPoints = '';
    public string $tokensChartPoints = '';

    // --- Chart axis helpers ---
    public int $operationsChartMax = 0;

    /** @var array<int, string> */
    public array $operationsChartLabels = [];

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
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
        return 'Intern Procynia-analyse · Kun Super Admin';
    }

    public function mount(): void
    {
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
        $this->functionRows = $this->buildFunctionRows($from, $to);
        $this->customerCapacityRows = $this->buildCustomerCapacityRows($from, $to);
        $this->userRows = $this->buildUserRows($from, $to);
        $this->trendRows = $this->buildTrendRows($from, $to);
        $this->alerts = $this->buildAlerts($from, $to);
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
        $q = AiTokenEvent::query()
            ->whereNotNull('saved_notice_id')
            ->whereBetween('created_at', [$from, $to]);

        if ($this->selectedCustomerId !== '') {
            $q->where('customer_id', (int) $this->selectedCustomerId);
        }

        return (int) $q->distinct('saved_notice_id')->count('saved_notice_id');
    }

    private function totalCapacity(): int
    {
        $q = Customer::query()->where('is_active', true);

        if ($this->selectedCustomerId !== '') {
            $q->where('id', (int) $this->selectedCustomerId);
        }

        return (int) $q->sum('included_ai_credits');
    }

    // -------------------------------------------------------------------------
    // Function rows
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFunctionRows(Carbon $from, Carbon $to): array
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

        return $usageRows->map(function (object $row) use ($tokenRows, $totalOps): array {
            $tokenRow     = $tokenRows->get($row->operation_key);
            $hasTokenData = $tokenRow !== null;
            $tokens       = $hasTokenData ? (int) $tokenRow->total_tokens : 0;

            return [
                'operation_key'  => $row->operation_key,
                'label'          => $this->operationLabel($row->operation_key),
                'operations'     => (int) $row->event_count,
                'blocked'        => (int) $row->blocked_count,
                'tokens'         => $tokens,
                'pct'            => (int) round($row->event_count / $totalOps * 100),
                'has_token_data' => $hasTokenData,
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

        $casesByCustomer = AiTokenEvent::query()
            ->whereNotNull('saved_notice_id')
            ->whereBetween('created_at', [$from, $to])
            ->select(['customer_id', DB::raw('COUNT(DISTINCT saved_notice_id) as case_count')])
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        return $customers->map(function (Customer $customer) use ($casesByCustomer): array {
            $cases           = (int) ($casesByCustomer->get($customer->id)?->case_count ?? 0);
            $limit           = (int) $customer->included_ai_credits;
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
    private function buildUserRows(Carbon $from, Carbon $to): array
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
            ->select(['user_id', DB::raw('SUM(total_tokens) as total_tokens')])
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->when($this->functionFilter !== '', fn ($q) => $q->where('operation_key', $this->functionFilter))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return $usageRows->map(function (object $row) use ($users, $customers, $tokenRows): array {
            $tokenRow      = $tokenRows->get($row->user_id);
            $hasTokenData  = $tokenRow !== null;
            $tokens        = $hasTokenData ? (int) $tokenRow->total_tokens : 0;
            $ops           = (int) $row->operations;
            $avgTok        = ($hasTokenData && $ops > 0) ? (int) round($tokens / $ops) : 0;
            $user          = $users->get($row->user_id);
            $customer      = $customers->get($row->customer_id);

            return [
                'user_name'      => $user?->name ?? '(ukjent)',
                'customer_name'  => $customer?->name ?? '(ukjent)',
                'role'           => $user?->bid_role ?? '—',
                'operations'     => $ops,
                'tokens'         => $tokens,
                'avg_tokens'     => $avgTok,
                'blocked'        => (int) $row->blocked,
                'has_token_data' => $hasTokenData,
            ];
        })->values()->all();
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
                 SUM(total_tokens) as total_tokens"
            )
            ->groupByRaw($groupFormat)
            ->when($this->selectedCustomerId !== '', fn ($q) => $q->where('customer_id', (int) $this->selectedCustomerId))
            ->when($this->functionFilter !== '', fn ($q) => $q->where('operation_key', $this->functionFilter));

        $usageRows = $usageQ->get()->keyBy('period_key');
        $tokenRows = $tokenQ->get()->keyBy('period_key');

        $allKeys = $usageRows->keys()->merge($tokenRows->keys())->unique()->sort()->values();

        return $allKeys->map(function (string $key) use ($usageRows, $tokenRows): array {
            $usage   = $usageRows->get($key);
            $tRow    = $tokenRows->get($key);
            $ops     = (int) ($usage?->operations ?? 0);
            $tokens  = (int) ($tRow?->total_tokens ?? 0);
            $avgTok  = $ops > 0 ? (int) round($tokens / $ops) : 0;

            return [
                'period'     => $key,
                'operations' => $ops,
                'tokens'     => $tokens,
                'avg_tokens' => $avgTok,
                'blocked'    => (int) ($usage?->blocked ?? 0),
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
        $this->tokensChartPoints     = $hasRealTokenData
            ? $this->svgPoints($tokensValues, max(array_merge([1], $tokensValues)))
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
            return $labels;
        }

        $step   = (int) ceil($count / $maxCount);
        $result = [];

        foreach ($labels as $i => $label) {
            if ($i % $step === 0 || $i === $count - 1) {
                $result[] = strlen($label) > 10 ? substr($label, 5) : $label;
            } else {
                $result[] = '';
            }
        }

        return $result;
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
