<?php

namespace App\Services\Ai\Experience;

use App\Data\Ai\Experience\AiExperienceFilter;
use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Ai\Usage\AiUsagePeriod;
use App\Models\AiCustomerExperiencePeriod;
use App\Models\AiCustomerExperiencePeriodFeature;
use App\Models\AiUsageAttempt;
use App\Services\Ai\Commercial\AiCapacityCalibrationService;
use App\Services\Ai\Commercial\AiUnitConverter;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\Statistics\Distribution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one analysis of the AI experience snapshots, shared by Admin → AI-erfaring and
 * `ai:capacity-analysis --experience`: how actual AI usage varies with users, modules and capacity
 * levels, set beside the current capacity formula.
 *
 * DESCRIPTIVE ONLY. It reports distributions (mean, median, p75, p95) and neutral signals («For lite
 * datagrunnlag», «Stor variasjon», «ligger ofte over/under»). It never proposes a new value, and
 * nothing it returns is read by anything that sizes a capacity, changes a weight or sets a price.
 * Differences between customers with and without a module are associations in this dataset, never
 * presented as caused by the module.
 *
 * Query strategy: the filtered snapshots in one query (joined with the customer name), their feature
 * rows in one more; every statistic is computed in memory over that bounded set (customers ×
 * periods). The trend is one grouped ledger query; the operation table is the calibration service's
 * single aggregate query. No per-customer or per-period queries.
 *
 * Figures per period are also normalised per month (a yearly period counts as twelve), so monthly
 * and yearly customers can be compared. Utilisation is a share and needs no normalising.
 */
class AiExperienceAnalysisService
{
    public const SORTS = ['cost', 'utilization', 'calls'];

    public const SIGNAL_INSUFFICIENT = 'insufficient_data';

    public const SIGNAL_HIGH_VARIATION = 'high_variation';

    public const SIGNAL_OFTEN_ABOVE = 'often_above';

    public const SIGNAL_MOSTLY_BELOW = 'mostly_below';

    public const SIGNAL_OFTEN_TIGHT = 'often_tight';

    public const SIGNAL_MOSTLY_UNUSED = 'mostly_unused';

    public const SIGNAL_ESTIMATE_OFTEN_BELOW = 'estimate_often_below';

    public const SIGNAL_ESTIMATE_OFTEN_ABOVE = 'estimate_often_above';

    /** At or above this share of periods over a value: «ligger ofte over». */
    private const OFTEN_SHARE = 0.5;

    /** At or below this share of periods over a value: «ligger som regel under». */
    private const RARELY_SHARE = 0.1;

    public function __construct(
        private readonly AiCapacityCalibrationService $calibration,
        private readonly AiUnitConverter $units,
        private readonly ModuleEntitlementService $modules,
    ) {}

    /**
     * Everything the overview needs, from one load of the filtered snapshots.
     *
     * @return array<string, mixed>
     */
    public function report(AiExperienceFilter $filter, string $sort = 'cost'): array
    {
        $rows = $this->rows($filter);

        return [
            'basis' => $this->basis($rows),
            'excluded_incomplete' => $filter->includeIncomplete ? 0 : $this->incompleteCount($filter),
            'overview' => $this->overview($rows),
            'rows' => $this->sorted($rows, $sort)->values()->all(),
            'per_user' => $this->perUser($rows),
            'features' => $this->features($rows),
            'modules' => $this->moduleAnalysis($rows),
            'module_mixes' => $this->moduleMixes($rows),
            'tiers' => $this->tiers($rows),
            'formula' => $this->formula($rows),
        ];
    }

    /**
     * The filtered experience periods as plain rows, two queries.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(AiExperienceFilter $filter): Collection
    {
        $snapshots = $this->query($filter)
            ->join('customers', 'customers.id', '=', 'ai_customer_experience_periods.customer_id')
            ->select('ai_customer_experience_periods.*', 'customers.name as customer_name')
            ->orderBy('ai_customer_experience_periods.period_start')
            ->orderBy('ai_customer_experience_periods.customer_id')
            ->get();

        $features = AiCustomerExperiencePeriodFeature::query()
            ->whereIn('ai_customer_experience_period_id', $snapshots->pluck('id'))
            ->get()
            ->groupBy('ai_customer_experience_period_id');

        return $snapshots
            ->map(fn (AiCustomerExperiencePeriod $snapshot): array => $this->row($snapshot, $features->get($snapshot->id, collect())))
            ->when($filter->feature !== null, fn (Collection $rows) => $rows
                ->filter(fn (array $row): bool => ($row['features'][$filter->feature]['calls'] ?? 0) > 0)
                ->map(fn (array $row): array => $this->narrowToFeature($row, $filter->feature)))
            ->values();
    }

    /**
     * One customer and period: the snapshot, its feature split, its revisions and per-operation
     * statistics from the ledger for exactly that period.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $snapshotId, ?string $operation = null): ?array
    {
        $snapshot = AiCustomerExperiencePeriod::query()
            ->join('customers', 'customers.id', '=', 'ai_customer_experience_periods.customer_id')
            ->select('ai_customer_experience_periods.*', 'customers.name as customer_name')
            ->where('ai_customer_experience_periods.id', $snapshotId)
            ->first();

        if (! $snapshot instanceof AiCustomerExperiencePeriod) {
            return null;
        }

        $row = $this->row($snapshot, $snapshot->features()->get());
        $totalCost = max(0.0, $row['settled_cost_nok']);
        $features = collect($row['features'])
            ->map(fn (array $feature, string $key): array => ['key' => $key, ...$feature, 'share' => $totalCost > 0 ? round($feature['settled_cost_nok'] / $totalCost * 100, 1) : 0.0])
            ->sortByDesc('settled_cost_nok')
            ->values()
            ->all();

        $usagePeriod = new AiUsagePeriod($snapshot->period_start, $snapshot->period_end);

        return [
            ...$row,
            'features' => $features,
            'operations' => $this->operationRows(new AiUsageFilter($usagePeriod, customerId: (int) $snapshot->customer_id, operation: $operation)),
            'revisions' => $snapshot->revisions()->orderByDesc('revision')->get(['revision', 'reason', 'changes', 'created_at'])->toArray(),
        ];
    }

    /**
     * Per operation over the filter's window (all customers unless one is chosen): the calibration
     * service's shared statistics.
     *
     * @return list<array<string, mixed>>
     */
    public function operations(AiExperienceFilter $filter): array
    {
        return $this->operationRows(new AiUsageFilter($this->window($filter), customerId: $filter->customerId, operation: $filter->operation), $filter->feature);
    }

    /**
     * A calendar-month trend across customers — explicitly a trend, not a billing analysis (billing
     * periods differ per customer). One grouped ledger query.
     *
     * @return list<array{month: string, cost_nok: float, units: float, calls: int, customers: int, median_cost_nok: ?float, p95_cost_nok: ?float}>
     */
    public function trend(AiExperienceFilter $filter): array
    {
        $window = $this->window($filter);
        $bucket = "TO_CHAR(started_at, 'YYYY-MM')";
        $settled = "settlement_status = '".AiUsageAttempt::SETTLEMENT_SETTLED."'";

        $perCustomer = AiUsageAttempt::query()
            ->trusted()
            ->whereNotNull('customer_id')
            ->where('started_at', '>=', $window->start)
            ->where('started_at', '<', $window->end)
            ->when($filter->customerId !== null, fn (Builder $query) => $query->where('customer_id', $filter->customerId))
            ->when($filter->feature !== null, fn (Builder $query) => AiExperienceAttribution::constrain($query, $filter->feature))
            ->when($filter->operation !== null, fn (Builder $query) => $query->where('operation_key', $filter->operation))
            ->selectRaw("{$bucket} as month, customer_id, COUNT(*) as calls")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$settled} THEN cost_nok END), 0) as cost_nok")
            ->groupByRaw("{$bucket}, customer_id")
            ->toBase()
            ->get()
            ->groupBy('month');

        return $perCustomer
            ->sortKeys()
            ->map(function (Collection $customers, string $month): array {
                $costs = $customers->map(fn (object $row): float => (float) $row->cost_nok);
                $stats = Distribution::describe($costs);
                $total = round($costs->sum(), 4);

                return [
                    'month' => $month,
                    'cost_nok' => $total,
                    'units' => round($this->units->unitsForCost($total), 2),
                    'calls' => (int) $customers->sum('calls'),
                    'customers' => $customers->count(),
                    'median_cost_nok' => $stats['median'],
                    'p95_cost_nok' => $stats['p95'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The current capacity formula's values, read from config (never written).
     *
     * @return array{basis: int, per_user: int, options: array<string, int>, tiers: array<string, float>, nok_per_unit: float}
     */
    public function currentModel(): array
    {
        $base = (array) config('ai_customer_capacity.base', []);

        return [
            'basis' => (int) ($base['basis'] ?? 0),
            'per_user' => (int) ($base['per_user'] ?? 0),
            'options' => array_map('intval', (array) ($base['options'] ?? [])),
            'tiers' => array_map(fn (array $tier): float => (float) ($tier['multiplier'] ?? 0), (array) config('ai_customer_capacity.tiers', [])),
            'nok_per_unit' => (float) config('ai_customer_capacity.nok_per_unit', 0.10),
        ];
    }

    /** @return list<string> the attribution keys present in the snapshots, for the filter */
    public function featureKeys(): array
    {
        return AiCustomerExperiencePeriodFeature::query()->distinct()->orderBy('feature_key')->pluck('feature_key')->all();
    }

    // -------------------------------------------------------------------------

    private function query(AiExperienceFilter $filter): Builder
    {
        $table = 'ai_customer_experience_periods';

        return AiCustomerExperiencePeriod::query()
            ->when(! $filter->includeIncomplete, fn (Builder $query) => $query
                ->where("{$table}.status", AiCustomerExperiencePeriod::STATUS_FINAL)
                ->where("{$table}.coverage", AiCustomerExperiencePeriod::COVERAGE_FULL))
            ->when($filter->from !== null, fn (Builder $query) => $query->where("{$table}.period_start", '>=', $filter->from))
            ->when($filter->to !== null, fn (Builder $query) => $query->where("{$table}.period_start", '<', $filter->to->addDay()->startOfDay()))
            ->when($filter->customerId !== null, fn (Builder $query) => $query->where("{$table}.customer_id", $filter->customerId))
            ->when($filter->tier !== null, fn (Builder $query) => $query->where("{$table}.tier_key", $filter->tier))
            ->when($filter->package !== null, fn (Builder $query) => $query->whereJsonContains("{$table}.active_packages", $filter->package))
            ->when($filter->usersMin !== null, fn (Builder $query) => $query->where("{$table}.active_users_count", '>=', $filter->usersMin))
            ->when($filter->usersMax !== null, fn (Builder $query) => $query->where("{$table}.active_users_count", '<=', $filter->usersMax))
            ->when($filter->interval !== null, fn (Builder $query) => $query->where("{$table}.billing_interval", $filter->interval));
    }

    private function incompleteCount(AiExperienceFilter $filter): int
    {
        $all = new AiExperienceFilter(
            from: $filter->from, to: $filter->to, customerId: $filter->customerId, tier: $filter->tier,
            package: $filter->package, usersMin: $filter->usersMin, usersMax: $filter->usersMax,
            interval: $filter->interval, includeIncomplete: true,
        );

        return $this->query($all)->count() - $this->query($filter)->count();
    }

    /** @param Collection<int, AiCustomerExperiencePeriodFeature> $features */
    private function row(AiCustomerExperiencePeriod $snapshot, Collection $features): array
    {
        $months = max(1, $snapshot->period_months);
        $users = $snapshot->active_users_count;

        return [
            'id' => $snapshot->id,
            'customer_id' => $snapshot->customer_id,
            'customer' => (string) $snapshot->getAttribute('customer_name'),
            'period_start' => $snapshot->period_start,
            'period_end' => $snapshot->period_end,
            'status' => $snapshot->status,
            'coverage' => $snapshot->coverage,
            'revision' => $snapshot->revision,
            'interval' => $snapshot->billing_interval,
            'months' => $months,
            'context_captured_at' => $snapshot->context_captured_at,
            'context_after_end' => $snapshot->context_captured_at->gte($snapshot->period_end),
            'users' => $users,
            'packages' => $snapshot->active_packages ?? [],
            'module_mix' => $snapshot->module_mix,
            'tier_key' => $snapshot->tier_key,
            'tier_multiplier' => $snapshot->tier_multiplier,
            'base_units_per_month' => $snapshot->base_units_per_month,
            'calculated_base_capacity' => $snapshot->calculated_base_capacity,
            'calculated_total_capacity' => $snapshot->calculated_total_capacity,
            'override_units' => $snapshot->override_units,
            'included_units' => $snapshot->included_units,
            'capacity_source' => $snapshot->capacity_source,
            'calls' => $snapshot->calls,
            'successful_calls' => $snapshot->successful_calls,
            'failed_calls' => $snapshot->failed_calls,
            'pending_calls' => $snapshot->pending_calls,
            'unresolved_calls' => $snapshot->unresolved_calls,
            'legacy_calls' => $snapshot->legacy_calls,
            'total_tokens' => $snapshot->total_tokens,
            'settled_cost_nok' => $snapshot->settled_cost_nok,
            'pending_reserved_cost_nok' => $snapshot->pending_reserved_cost_nok,
            'unresolved_reserved_cost_nok' => $snapshot->unresolved_reserved_cost_nok,
            'settled_units' => $snapshot->settled_units,
            'reserved_units' => $snapshot->reserved_units,
            'used_percent' => $snapshot->used_percent,
            'committed_percent' => $snapshot->committed_percent,
            'verdict_warn' => $snapshot->verdict_warn,
            'verdict_exhausted' => $snapshot->verdict_exhausted,
            'verdict_insufficient' => $snapshot->verdict_insufficient,
            'would_have_blocked' => $snapshot->would_have_blocked,
            'monthly_cost_nok' => round($snapshot->settled_cost_nok / $months, 4),
            'monthly_units' => round($snapshot->settled_units / $months, 2),
            'cost_per_user_nok' => $users > 0 ? round($snapshot->settled_cost_nok / $months / $users, 4) : null,
            'units_per_user' => $users > 0 ? round($snapshot->settled_units / $months / $users, 2) : null,
            'features' => $features->mapWithKeys(fn (AiCustomerExperiencePeriodFeature $feature): array => [$feature->feature_key => [
                'calls' => $feature->calls,
                'settled_cost_nok' => $feature->settled_cost_nok,
                'settled_units' => $feature->settled_units,
                'reserved_units' => $feature->reserved_units,
            ]])->all(),
        ];
    }

    /** Usage figures become the one attribution key's; capacity and utilisation stay the pool's. */
    private function narrowToFeature(array $row, string $key): array
    {
        $feature = $row['features'][$key];
        $months = $row['months'];

        return [
            ...$row,
            'calls' => $feature['calls'],
            'settled_cost_nok' => $feature['settled_cost_nok'],
            'settled_units' => $feature['settled_units'],
            'reserved_units' => $feature['reserved_units'],
            'monthly_cost_nok' => round($feature['settled_cost_nok'] / $months, 4),
            'monthly_units' => round($feature['settled_units'] / $months, 2),
            'cost_per_user_nok' => $row['users'] > 0 ? round($feature['settled_cost_nok'] / $months / $row['users'], 4) : null,
            'units_per_user' => $row['users'] > 0 ? round($feature['settled_units'] / $months / $row['users'], 2) : null,
        ];
    }

    /** @return array{customers: int, periods: int, calls: int, sufficient: bool} */
    private function basis(Collection $rows): array
    {
        $customers = $rows->pluck('customer_id')->unique()->count();
        $periods = $rows->count();
        $calls = (int) $rows->sum('calls');
        $minimum = (array) config('ai_experience.minimum_sample', []);

        return [
            'customers' => $customers,
            'periods' => $periods,
            'calls' => $calls,
            'sufficient' => $customers >= (int) ($minimum['customers'] ?? 5)
                && $periods >= (int) ($minimum['periods'] ?? 10)
                && $calls >= (int) ($minimum['calls'] ?? 200),
        ];
    }

    private function overview(Collection $rows): array
    {
        return [
            'settled_cost_nok' => round((float) $rows->sum('settled_cost_nok'), 4),
            'pending_reserved_cost_nok' => round((float) $rows->sum('pending_reserved_cost_nok'), 4),
            'unresolved_reserved_cost_nok' => round((float) $rows->sum('unresolved_reserved_cost_nok'), 4),
            'open_calls' => (int) $rows->sum('pending_calls') + (int) $rows->sum('unresolved_calls'),
            'cost' => Distribution::describe($rows->pluck('monthly_cost_nok')),
            'units' => Distribution::describe($rows->pluck('monthly_units'), 2),
            'utilization' => Distribution::describe($rows->pluck('used_percent')->filter(fn ($value): bool => $value !== null), 2),
        ];
    }

    private function sorted(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'utilization' => $rows->sortByDesc(fn (array $row): float => (float) ($row['used_percent'] ?? -1)),
            'calls' => $rows->sortByDesc('calls'),
            default => $rows->sortByDesc('settled_cost_nok'),
        };
    }

    private function perUser(Collection $rows): array
    {
        $withUsers = $rows->filter(fn (array $row): bool => $row['users'] > 0);

        return [
            'basis' => $this->basis($withUsers),
            'cost' => Distribution::describe($withUsers->pluck('cost_per_user_nok')),
            'units' => Distribution::describe($withUsers->pluck('units_per_user'), 2),
            'users' => Distribution::describe($withUsers->pluck('users'), 1),
        ];
    }

    /** Per attribution key: who used it, and what it cost per period and month. */
    private function features(Collection $rows): array
    {
        $total = max(0.0, (float) $rows->sum(fn (array $row): float => array_sum(array_column($row['features'], 'settled_cost_nok'))));
        $keys = $rows->flatMap(fn (array $row): array => array_keys($row['features']))->unique()->sort()->values();

        return $keys->map(function (string $key) use ($rows, $total): array {
            $using = $rows->filter(fn (array $row): bool => ($row['features'][$key]['calls'] ?? 0) > 0);
            $cost = (float) $using->sum(fn (array $row): float => $row['features'][$key]['settled_cost_nok']);

            return [
                'key' => $key,
                'customers' => $using->pluck('customer_id')->unique()->count(),
                'periods' => $using->count(),
                'calls' => (int) $using->sum(fn (array $row): int => $row['features'][$key]['calls']),
                'settled_cost_nok' => round($cost, 4),
                'reserved_units' => round((float) $using->sum(fn (array $row): float => $row['features'][$key]['reserved_units']), 2),
                'share' => $total > 0 ? round($cost / $total * 100, 1) : 0.0,
                'monthly_cost' => Distribution::describe($using->map(fn (array $row): float => $row['features'][$key]['settled_cost_nok'] / $row['months'])),
                'monthly_units' => Distribution::describe($using->map(fn (array $row): float => $row['features'][$key]['settled_units'] / $row['months']), 2),
            ];
        })->sortByDesc('settled_cost_nok')->values()->all();
    }

    /**
     * Per package: usage of customers with it against customers without it (an association in this
     * dataset), and the usage directly attributed to the package's own features.
     */
    private function moduleAnalysis(Collection $rows): array
    {
        $analysis = [];

        foreach (array_keys($this->modules->packages()) as $package) {
            [$with, $without] = $rows->partition(fn (array $row): bool => in_array($package, $row['packages'], true));
            $withUnits = Distribution::describe($with->pluck('monthly_units'), 2);
            $withoutUnits = Distribution::describe($without->pluck('monthly_units'), 2);

            $analysis[] = [
                'package' => $package,
                'customers_with' => $with->pluck('customer_id')->unique()->count(),
                'customers_without' => $without->pluck('customer_id')->unique()->count(),
                'basis' => $this->basis($with),
                'with_units' => $withUnits,
                'without_units' => $withoutUnits,
                'with_cost' => Distribution::describe($with->pluck('monthly_cost_nok')),
                'without_cost' => Distribution::describe($without->pluck('monthly_cost_nok')),
                'median_difference_units' => $withUnits['median'] === null || $withoutUnits['median'] === null
                    ? null
                    : round($withUnits['median'] - $withoutUnits['median'], 2),
                'direct_units' => Distribution::describe($with->map(fn (array $row): float => $this->directUnits($row, $package) / $row['months']), 2),
            ];
        }

        return $analysis;
    }

    private function moduleMixes(Collection $rows): array
    {
        return $rows->groupBy('module_mix')
            ->map(fn (Collection $group, string $mix): array => [
                'module_mix' => $mix,
                'packages' => $group->first()['packages'],
                'basis' => $this->basis($group),
                'users' => Distribution::describe($group->pluck('users'), 1),
                'units' => Distribution::describe($group->pluck('monthly_units'), 2),
                'cost' => Distribution::describe($group->pluck('monthly_cost_nok')),
            ])
            ->sortByDesc(fn (array $mix): int => $mix['basis']['periods'])
            ->values()
            ->all();
    }

    private function tiers(Collection $rows): array
    {
        $warning = (float) config('ai_customer_capacity.thresholds.warning_percent', 80);

        return $rows->groupBy(fn (array $row): string => $row['tier_key'] ?? 'none')
            ->map(function (Collection $group, string $tier) use ($warning): array {
                $utilization = $group->pluck('used_percent')->filter(fn ($value): bool => $value !== null);
                $tight = $group->filter(fn (array $row): bool => ($row['used_percent'] ?? 0) >= $warning
                    || $row['verdict_warn'] > 0 || $row['would_have_blocked'] > 0);

                return [
                    'tier_key' => $tier,
                    'basis' => $this->basis($group),
                    'units' => Distribution::describe($group->pluck('monthly_units'), 2),
                    'utilization' => Distribution::describe($utilization, 2),
                    'would_have_blocked' => (int) $group->sum('would_have_blocked'),
                    'periods_blocked' => $group->filter(fn (array $row): bool => $row['would_have_blocked'] > 0)->count(),
                    'periods_tight' => $tight->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * The current formula beside what the data shows, factor by factor, with neutral signals only.
     * No factor ever carries a proposed value.
     */
    private function formula(Collection $rows): array
    {
        $model = $this->currentModel();
        $factors = [];

        // Base capacity (before the tier) against actual monthly usage, per period.
        $factors[] = $this->factor('base_capacity', null, $rows, $rows->pluck('monthly_units'),
            $rows->map(fn (array $row): bool => $row['monthly_units'] > $row['base_units_per_month']));

        $basisOnly = $rows->filter(fn (array $row): bool => $row['packages'] === ['basis']);
        $factors[] = $this->factor('basis', $model['basis'], $basisOnly, $basisOnly->map(fn (array $row): float => $this->directUnits($row, 'basis') / $row['months']),
            $basisOnly->map(fn (array $row): bool => $this->directUnits($row, 'basis') / $row['months'] > $model['basis']));

        $withUsers = $rows->filter(fn (array $row): bool => $row['users'] > 0);
        $factors[] = $this->factor('per_user', $model['per_user'], $withUsers, $withUsers->pluck('units_per_user'),
            $withUsers->map(fn (array $row): bool => $row['units_per_user'] > $model['per_user']));

        foreach ($model['options'] as $package => $weight) {
            $with = $rows->filter(fn (array $row): bool => in_array($package, $row['packages'], true));
            $direct = $with->map(fn (array $row): float => $this->directUnits($row, $package) / $row['months']);
            $factors[] = $this->factor('option', $weight, $with, $direct, $direct->map(fn (float $units): bool => $units > $weight), $package);
        }

        $warning = (float) config('ai_customer_capacity.thresholds.warning_percent', 80);

        foreach ($model['tiers'] as $tier => $multiplier) {
            $group = $rows->filter(fn (array $row): bool => $row['tier_key'] === $tier);
            $utilization = $group->pluck('used_percent')->filter(fn ($value): bool => $value !== null);
            $stats = Distribution::describe($utilization, 2);
            $signals = $this->sampleSignals($group, $stats);
            $tightShare = $group->isEmpty() ? 0.0 : $group->filter(fn (array $row): bool => ($row['used_percent'] ?? 0) >= $warning || $row['would_have_blocked'] > 0)->count() / $group->count();

            if ($group->isNotEmpty() && $tightShare >= 0.25) {
                $signals[] = self::SIGNAL_OFTEN_TIGHT;
            }

            if ($stats['p95'] !== null && $stats['p95'] < 50.0) {
                $signals[] = self::SIGNAL_MOSTLY_UNUSED;
            }

            $factors[] = [
                'factor' => 'tier',
                'key' => $tier,
                'current' => $multiplier,
                'observed' => $stats,
                'observed_measure' => 'utilization',
                'basis' => $this->basis($group),
                'share_above' => round($tightShare, 2),
                'signals' => array_values(array_unique($signals)),
            ];
        }

        return [
            'model' => $model,
            'factors' => $factors,
            'estimate_signals' => $this->estimateSignals(),
        ];
    }

    /**
     * @param  Collection<int, float|int|null>  $observed
     * @param  Collection<int, bool>  $above
     */
    private function factor(string $factor, ?int $current, Collection $rows, Collection $observed, Collection $above, ?string $key = null): array
    {
        $stats = Distribution::describe($observed->filter(fn ($value): bool => $value !== null), 2);
        $signals = $this->sampleSignals($rows, $stats);
        $share = $above->isEmpty() ? null : $above->filter()->count() / $above->count();

        if ($share !== null && $rows->isNotEmpty()) {
            if ($share >= self::OFTEN_SHARE) {
                $signals[] = self::SIGNAL_OFTEN_ABOVE;
            } elseif ($share <= self::RARELY_SHARE) {
                $signals[] = self::SIGNAL_MOSTLY_BELOW;
            }
        }

        return [
            'factor' => $factor,
            'key' => $key ?? $factor,
            'current' => $current,
            'observed' => $stats,
            'observed_measure' => 'units',
            'basis' => $this->basis($rows),
            'share_above' => $share === null ? null : round($share, 2),
            'signals' => array_values(array_unique($signals)),
        ];
    }

    /** @return list<string> */
    private function sampleSignals(Collection $rows, array $stats): array
    {
        $signals = [];

        if (! $this->basis($rows)['sufficient']) {
            $signals[] = self::SIGNAL_INSUFFICIENT;
        }

        if ($stats['median'] !== null && $stats['median'] > 0 && (float) config('ai_experience.large_variation_ratio', 3.0) <= $stats['p95'] / $stats['median']) {
            $signals[] = self::SIGNAL_HIGH_VARIATION;
        }

        return $signals;
    }

    /**
     * How the preflight estimates compare with actual cost across all trusted settled calls in the
     * last 90 days: the counts of operations whose estimate is often below or far above.
     *
     * @return array{too_low: list<string>, too_high: list<string>}
     */
    private function estimateSignals(): array
    {
        $now = CarbonImmutable::now('UTC');
        $rows = $this->calibration->estimateAccuracy(new AiUsageFilter(new AiUsagePeriod($now->subDays(90), $now)));

        return [
            'too_low' => array_values(array_column(array_filter($rows, fn (array $row): bool => $row['assessment'] === AiCapacityCalibrationService::ASSESSMENT_TOO_LOW), 'operation_key')),
            'too_high' => array_values(array_column(array_filter($rows, fn (array $row): bool => $row['assessment'] === AiCapacityCalibrationService::ASSESSMENT_TOO_HIGH), 'operation_key')),
        ];
    }

    private function directUnits(array $row, string $package): float
    {
        $units = 0.0;

        foreach (AiExperienceAttribution::PACKAGE_KEYS[$package] ?? [] as $key) {
            $units += (float) ($row['features'][$key]['settled_units'] ?? 0);
        }

        return $units;
    }

    /** @return list<array<string, mixed>> */
    private function operationRows(AiUsageFilter $filter, ?string $attributionKey = null): array
    {
        $rows = $this->calibration->operationStatistics($filter, $attributionKey);
        $total = array_sum(array_column($rows, 'settled_cost_nok'));

        usort($rows, fn (array $a, array $b): int => $b['settled_cost_nok'] <=> $a['settled_cost_nok']);

        return array_map(fn (array $row): array => [
            ...$row,
            'share' => $total > 0 ? round($row['settled_cost_nok'] / $total * 100, 1) : 0.0,
        ], $rows);
    }

    /** The filter's dates as a ledger window; the last twelve months when none is given. */
    private function window(AiExperienceFilter $filter): AiUsagePeriod
    {
        $now = CarbonImmutable::now('UTC');
        $start = $filter->from?->startOfDay() ?? $now->subMonthsNoOverflow(12)->startOfMonth();
        $end = $filter->to?->addDay()->startOfDay() ?? $now->addSecond();

        return new AiUsagePeriod($start, $end);
    }
}
