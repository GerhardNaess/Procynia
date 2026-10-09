<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Ai\Usage\AiUsagePeriod;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Services\Ai\Experience\AiExperienceAttribution;
use App\Services\Ai\Usage\AiUsageLedger;
use App\Support\Statistics\Distribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Internal measurements for deciding the commercial AI capacity later — never a customer view, and
 * never an automatic adjustment: nothing here changes nok_per_unit, a capacity tier, an override or
 * an operation estimate. It reads the ledger (trusted rows) and reports.
 *
 * Answers, per customer and window: what AI cost and how many units, which features, operations
 * and models drive it, how often the current capacity would have stopped the customer
 * (capacity_verdict), the peak number of simultaneous calls, and how good the preflight estimates
 * in config/ai_operations.php are against actual settled cost.
 */
class AiCapacityCalibrationService
{
    /** An estimate below the p95 actual cost lets real calls exceed what was reserved. */
    public const ASSESSMENT_TOO_LOW = 'too_low';

    /** An estimate this many times the average actual cost holds far more capacity than used. */
    public const TOO_HIGH_RATIO = 3.0;

    public const ASSESSMENT_TOO_HIGH = 'too_high';

    public const ASSESSMENT_OK = 'ok';

    public function __construct(
        private readonly AiUsageLedger $ledger,
        private readonly CustomerAiCapacityService $capacity,
        private readonly AiUnitConverter $units,
    ) {}

    /**
     * One customer over a window.
     *
     * @return array<string, mixed>
     */
    public function customerReport(Customer $customer, AiUsagePeriod $period, ?string $operation = null): array
    {
        $filter = new AiUsageFilter($period, customerId: (int) $customer->id, operation: $operation);
        $totals = $this->ledger->totals($filter);
        $reservedNok = $totals['pending_reserved_cost_nok'] + $totals['unresolved_reserved_cost_nok'];
        // The capacity of the billing period the window starts in: what the gate compared against.
        $capacity = $this->capacity->forCustomer($customer, $period->start);

        return [
            'customer_id' => (int) $customer->id,
            'customer' => (string) $customer->name,
            'period_start' => $period->start->toIso8601String(),
            'period_end' => $period->end->toIso8601String(),
            // override | tier | unconfigured — never Basis or an option.
            'included_units' => $capacity->includedUnits,
            'included_source' => $capacity->includedSource,
            // The selected tier, reported even when an override is what sizes the capacity.
            'tier_key' => $capacity->tierKey,
            'tier_name' => $capacity->tierName,
            'calls' => $totals['calls'],
            'settled_cost_nok' => $totals['settled_cost_nok'],
            'used_units' => round($this->units->unitsForCost($totals['settled_cost_nok']), 2),
            'reserved_units' => round($this->units->unitsForCost($reservedNok), 2),
            'verdicts' => $this->verdicts($filter),
            'peak_concurrency' => $this->peakConcurrency($filter),
            'breakdown' => array_map(fn (array $row): array => [
                'feature' => $row['feature'],
                'operation_key' => $row['operation_key'],
                'model' => $row['model'],
                'calls' => $row['calls'],
                'settled_cost_nok' => $row['settled_cost_nok'],
                'used_units' => round($this->units->unitsForCost($row['settled_cost_nok']), 2),
            ], $this->ledger->breakdown($filter, ['feature', 'operation_key', 'model'])),
        ];
    }

    /**
     * Every customer with trusted usage in the window, heaviest first, plus how the cost spreads
     * across customers (median and heaviest) — "what does a typical and a heavy customer cost".
     *
     * @return array{customers: list<array<string, mixed>>, median_cost_nok: float, max_cost_nok: float}
     */
    public function overview(AiUsagePeriod $period, ?string $operation = null): array
    {
        $customerIds = $this->scope(new AiUsageFilter($period, operation: $operation))
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id');

        $rows = Customer::query()->whereIn('id', $customerIds)->get()
            ->map(function (Customer $customer) use ($period, $operation): array {
                $report = $this->customerReport($customer, $period, $operation);
                unset($report['breakdown']);

                return $report;
            })
            ->sortByDesc('settled_cost_nok')
            ->values()
            ->all();

        $costs = Distribution::describe(array_map(fn (array $row): float => (float) $row['settled_cost_nok'], $rows));

        return [
            'customers' => $rows,
            'median_cost_nok' => $costs['median'] ?? 0.0,
            'max_cost_nok' => $costs['max'] ?? 0.0,
        ];
    }

    /**
     * Preflight estimate against actual settled cost, per operation: calls that settled with both an
     * estimate (reserved_cost_nok) and an actual cost.
     *
     * The estimate is meant as a ceiling, so a ratio above 1 is expected. too_low: the p95 actual
     * cost exceeds the average estimate. too_high: the average estimate is more than TOO_HIGH_RATIO
     * times the average actual cost. Reported only; estimates are never changed automatically.
     *
     * A projection of operationStatistics(), so the CLI and the Admin read the same figures.
     *
     * @return list<array<string, mixed>>
     */
    public function estimateAccuracy(AiUsageFilter $filter): array
    {
        return array_values(array_map(fn (array $row): array => [
            'operation_key' => $row['operation_key'],
            'calls' => $row['estimated_calls'],
            'avg_estimate_nok' => $row['mean_estimate_nok'],
            'avg_actual_nok' => $row['estimated_mean_actual_nok'],
            'median_actual_nok' => $row['estimated_median_actual_nok'],
            'p95_actual_nok' => $row['estimated_p95_actual_nok'],
            'estimate_actual_ratio' => $row['estimate_actual_ratio'],
            'assessment' => $row['assessment'],
        ], array_filter($this->operationStatistics($filter), fn (array $row): bool => $row['estimated_calls'] > 0)));
    }

    /**
     * Per operation, in one aggregate query: how often it runs, how often it fails or stays open,
     * the distribution of its actual settled cost (mean, median, p75, p95) and how its preflight
     * estimate compares with actual cost. Pending and unresolved calls count toward the open rate,
     * never toward actual cost.
     *
     * $attributionKey narrows to one AiExperienceAttribution key (e.g. wiki.supplier).
     *
     * @return list<array<string, mixed>>
     */
    public function operationStatistics(AiUsageFilter $filter, ?string $attributionKey = null): array
    {
        $settled = "settlement_status = '".AiUsageAttempt::SETTLEMENT_SETTLED."' AND cost_nok IS NOT NULL";
        $paired = "{$settled} AND reserved_cost_nok IS NOT NULL";
        $open = "settlement_status IN ('".implode("', '", AiUsageAttempt::OPEN_SETTLEMENTS)."')";

        return $this->scope($filter)
            ->when($attributionKey !== null, fn (Builder $query) => AiExperienceAttribution::constrain($query, $attributionKey))
            ->select('operation_key')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw("COUNT(*) FILTER (WHERE status <> '".AiUsageAttempt::STATUS_SUCCESS."') as failed_calls")
            ->selectRaw("COUNT(*) FILTER (WHERE {$open}) as open_calls")
            ->selectRaw("COUNT(*) FILTER (WHERE {$settled}) as settled_calls")
            ->selectRaw("COALESCE(SUM(cost_nok) FILTER (WHERE {$settled}), 0) as settled_cost_nok")
            ->selectRaw("AVG(cost_nok) FILTER (WHERE {$settled}) as mean_actual_nok")
            ->selectRaw("PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cost_nok) FILTER (WHERE {$settled}) as median_actual_nok")
            ->selectRaw("PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY cost_nok) FILTER (WHERE {$settled}) as p75_actual_nok")
            ->selectRaw("PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY cost_nok) FILTER (WHERE {$settled}) as p95_actual_nok")
            ->selectRaw("COUNT(*) FILTER (WHERE {$paired}) as estimated_calls")
            ->selectRaw("AVG(reserved_cost_nok) FILTER (WHERE {$paired}) as mean_estimate_nok")
            ->selectRaw("AVG(cost_nok) FILTER (WHERE {$paired}) as estimated_mean_actual_nok")
            ->selectRaw("PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cost_nok) FILTER (WHERE {$paired}) as estimated_median_actual_nok")
            ->selectRaw("PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY cost_nok) FILTER (WHERE {$paired}) as estimated_p95_actual_nok")
            ->groupBy('operation_key')
            ->orderBy('operation_key')
            ->toBase()
            ->get()
            ->map(function (object $row): array {
                $calls = (int) $row->calls;
                $estimate = $row->mean_estimate_nok === null ? null : (float) $row->mean_estimate_nok;
                $pairedActual = $row->estimated_mean_actual_nok === null ? null : (float) $row->estimated_mean_actual_nok;
                $pairedP95 = $row->estimated_p95_actual_nok === null ? null : (float) $row->estimated_p95_actual_nok;
                $ratio = $estimate !== null && $pairedActual !== null && $pairedActual > 0 ? $estimate / $pairedActual : null;
                $round = fn (mixed $value): ?float => $value === null ? null : round((float) $value, 4);

                return [
                    'operation_key' => (string) $row->operation_key,
                    'calls' => $calls,
                    'failed_calls' => (int) $row->failed_calls,
                    'open_calls' => (int) $row->open_calls,
                    'settled_calls' => (int) $row->settled_calls,
                    'failure_rate' => $calls > 0 ? round((int) $row->failed_calls / $calls, 4) : 0.0,
                    'open_rate' => $calls > 0 ? round((int) $row->open_calls / $calls, 4) : 0.0,
                    'settled_cost_nok' => round((float) $row->settled_cost_nok, 4),
                    'mean_actual_nok' => $round($row->mean_actual_nok),
                    'median_actual_nok' => $round($row->median_actual_nok),
                    'p75_actual_nok' => $round($row->p75_actual_nok),
                    'p95_actual_nok' => $round($row->p95_actual_nok),
                    'estimated_calls' => (int) $row->estimated_calls,
                    'mean_estimate_nok' => $round($estimate),
                    'estimated_mean_actual_nok' => $round($pairedActual),
                    'estimated_median_actual_nok' => $round($row->estimated_median_actual_nok),
                    'estimated_p95_actual_nok' => $round($pairedP95),
                    'estimate_actual_ratio' => $ratio === null ? null : round($ratio, 2),
                    'assessment' => $estimate === null ? null : self::assess($estimate, $pairedP95 ?? 0.0, $ratio),
                ];
            })
            ->all();
    }

    /** ok | too_low | too_high for an average estimate against the actual cost it stood in for. */
    public static function assess(float $meanEstimate, float $p95Actual, ?float $ratio): string
    {
        return match (true) {
            $p95Actual > $meanEstimate => self::ASSESSMENT_TOO_LOW,
            $ratio !== null && $ratio > self::TOO_HIGH_RATIO => self::ASSESSMENT_TOO_HIGH,
            default => self::ASSESSMENT_OK,
        };
    }

    /**
     * How often the gate admitted, warned and would have refused in the window.
     *
     * @return array{allow: int, warn: int, exhausted: int, insufficient: int, unmetered: int, would_have_blocked: int}
     */
    public function verdicts(AiUsageFilter $filter): array
    {
        $counts = $this->scope($filter)
            ->whereNotNull('capacity_verdict')
            ->select('capacity_verdict')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('capacity_verdict')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->capacity_verdict => (int) $row->count]);

        $verdicts = [];

        foreach ([
            CustomerAiCapacityService::VERDICT_ALLOW,
            CustomerAiCapacityService::VERDICT_WARN,
            CustomerAiCapacityService::VERDICT_EXHAUSTED,
            CustomerAiCapacityService::VERDICT_INSUFFICIENT,
            CustomerAiCapacityService::VERDICT_UNMETERED,
        ] as $verdict) {
            $verdicts[$verdict] = (int) ($counts[$verdict] ?? 0);
        }

        $verdicts['would_have_blocked'] = $verdicts[CustomerAiCapacityService::VERDICT_EXHAUSTED] + $verdicts[CustomerAiCapacityService::VERDICT_INSUFFICIENT];

        return $verdicts;
    }

    /**
     * The most provider calls for the customer that were in flight at the same moment in the window,
     * from started_at/finished_at. A call still unfinished counts as in flight.
     */
    public function peakConcurrency(AiUsageFilter $filter): int
    {
        if ($filter->customerId === null) {
            return 0;
        }

        $ids = $this->scope($filter)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $peak = DB::table('ai_usage_attempts as a')
            ->join('ai_usage_attempts as b', function ($join): void {
                $join->on('b.customer_id', '=', 'a.customer_id')
                    ->whereColumn('b.started_at', '<=', 'a.started_at')
                    ->where(fn ($inner) => $inner->whereColumn('b.id', 'a.id')
                        ->orWhereNull('b.finished_at')
                        ->orWhereColumn('b.finished_at', '>', 'a.started_at'));
            })
            ->whereIn('a.id', $ids)
            ->whereIn('b.id', $ids)
            ->groupBy('a.id')
            ->selectRaw('COUNT(b.id) as overlapping')
            ->get()
            ->max('overlapping');

        return (int) ($peak ?? 0);
    }

    /** Trusted attempts in the filter's window, customer and operation. */
    private function scope(AiUsageFilter $filter): Builder
    {
        return AiUsageAttempt::query()
            ->trusted()
            ->where('started_at', '>=', $filter->period->start)
            ->where('started_at', '<', $filter->period->end)
            ->when($filter->customerId !== null, fn (Builder $query) => $query->where('customer_id', $filter->customerId))
            ->when($filter->feature !== null, fn (Builder $query) => $query->where('feature', $filter->feature))
            ->when($filter->operation !== null, fn (Builder $query) => $query->where('operation_key', $filter->operation));
    }
}
