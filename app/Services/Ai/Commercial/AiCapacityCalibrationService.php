<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Ai\Usage\AiUsagePeriod;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Services\Ai\Usage\AiUsageLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Internal measurements for deciding the commercial AI capacity later — never a customer view, and
 * never an automatic adjustment: nothing here changes nok_per_unit, the Basis capacity or an
 * operation estimate. It reads the ledger (trusted rows) and reports.
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
            'included_units' => $capacity->includedUnits,
            'included_source' => $capacity->includedSource,
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

        $costs = array_map(fn (array $row): float => (float) $row['settled_cost_nok'], $rows);
        sort($costs);

        return [
            'customers' => $rows,
            'median_cost_nok' => $costs === [] ? 0.0 : round($this->percentile($costs, 0.5), 4),
            'max_cost_nok' => $costs === [] ? 0.0 : round((float) end($costs), 4),
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
     * @return list<array<string, mixed>>
     */
    public function estimateAccuracy(AiUsageFilter $filter): array
    {
        return $this->scope($filter)
            ->where('settlement_status', AiUsageAttempt::SETTLEMENT_SETTLED)
            ->whereNotNull('cost_nok')
            ->whereNotNull('reserved_cost_nok')
            ->select('operation_key')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('AVG(reserved_cost_nok) as avg_estimate_nok')
            ->selectRaw('AVG(cost_nok) as avg_actual_nok')
            ->selectRaw('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cost_nok) as median_actual_nok')
            ->selectRaw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY cost_nok) as p95_actual_nok')
            ->groupBy('operation_key')
            ->orderBy('operation_key')
            ->toBase()
            ->get()
            ->map(function (object $row): array {
                $estimate = (float) $row->avg_estimate_nok;
                $actual = (float) $row->avg_actual_nok;
                $p95 = (float) $row->p95_actual_nok;
                $ratio = $actual > 0 ? $estimate / $actual : null;

                return [
                    'operation_key' => (string) $row->operation_key,
                    'calls' => (int) $row->calls,
                    'avg_estimate_nok' => round($estimate, 4),
                    'avg_actual_nok' => round($actual, 4),
                    'median_actual_nok' => round((float) $row->median_actual_nok, 4),
                    'p95_actual_nok' => round($p95, 4),
                    'estimate_actual_ratio' => $ratio === null ? null : round($ratio, 2),
                    'assessment' => match (true) {
                        $p95 > $estimate => self::ASSESSMENT_TOO_LOW,
                        $ratio !== null && $ratio > self::TOO_HIGH_RATIO => self::ASSESSMENT_TOO_HIGH,
                        default => self::ASSESSMENT_OK,
                    },
                ];
            })
            ->all();
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
            ->when($filter->operation !== null, fn (Builder $query) => $query->where('operation_key', $filter->operation));
    }

    /** @param list<float> $sorted */
    private function percentile(array $sorted, float $fraction): float
    {
        $index = ($fraction * (count($sorted) - 1));
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower);
    }
}
