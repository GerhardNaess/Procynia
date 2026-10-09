<?php

namespace App\Services\Ai\Usage;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Operational\AiCostState;
use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Billing\BillingPeriod;
use App\Models\AiUsageAttempt;
use App\Services\Billing\CustomerBillingPeriodResolver;
use App\Support\Ai\AiOperationCatalog;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one reader of AI usage and cost: `ai_usage_attempts`, trusted rows by default.
 *
 * Every aggregate the admin report, a future capacity engine, the subscription page or internal
 * finance needs comes from here, so "how much AI did customer X use in period P" has exactly one
 * answer. It reads; it never decides whether a call may run (that is AiCostControlService).
 *
 * Cost is read by settlement, and the three are never added into one figure:
 *  - settled_cost_nok:            actual cost of settled calls (known, or estimated from a stale
 *                                 price/rate). `cost_nok` is the same figure, kept for readers.
 *  - pending_reserved_cost_nok:   what pending calls (timeout, 5xx, in flight) still hold.
 *  - unresolved_reserved_cost_nok: what unresolved calls (work done, cost not establishable) hold.
 * A call with an open settlement is never summed as zero and never charged as settled; whatever
 * commercial rule a capacity engine applies to it, it can apply from these figures.
 *
 * Billing periods come from CustomerBillingPeriodResolver: `forBillingPeriod()` answers usage for
 * a customer's actual subscription period.
 */
class AiUsageLedger
{
    /** Columns a breakdown may group by. */
    public const DIMENSIONS = ['customer_id', 'user_id', 'feature', 'operation_key', 'model', 'provider', 'resource_type', 'status', 'attribution', 'settlement_status'];

    public function __construct(
        private readonly CustomerBillingPeriodResolver $billingPeriods,
    ) {}

    /**
     * A customer's trusted usage in the billing period that contains $at (default: now), totals
     * and per feature/operation — the shape a capacity engine reads.
     *
     * @return array{period: BillingPeriod, totals: array<string, int|float>, operations: list<array<string, mixed>>}
     */
    public function forBillingPeriod(int $customerId, ?DateTimeInterface $at = null): array
    {
        $period = $this->billingPeriods->at($customerId, $at ?? CarbonImmutable::now('UTC'));
        $filter = new AiUsageFilter($period->toUsagePeriod(), customerId: $customerId);

        return [
            'period' => $period,
            'totals' => $this->totals($filter),
            'operations' => $this->breakdown($filter, ['feature', 'operation_key']),
        ];
    }

    /** @return array<string, int|float> */
    public function totals(AiUsageFilter $filter): array
    {
        $row = $this->query($filter)->selectRaw($this->aggregates())->toBase()->first();

        return $this->normalise((array) $row);
    }

    /**
     * Aggregates grouped by one or more dimensions, largest cost first.
     *
     * @param  list<string>  $dimensions
     * @return list<array<string, mixed>>
     */
    public function breakdown(AiUsageFilter $filter, array $dimensions, ?int $limit = null): array
    {
        foreach ($dimensions as $dimension) {
            if (! in_array($dimension, self::DIMENSIONS, true)) {
                throw new InvalidArgumentException("Unknown AI usage dimension [{$dimension}].");
            }
        }

        $query = $this->query($filter)
            ->select($dimensions)
            ->selectRaw($this->aggregates())
            ->groupBy($dimensions)
            ->orderByDesc(DB::raw("COALESCE(SUM(CASE WHEN settlement_status = '".AiUsageAttempt::SETTLEMENT_SETTLED."' THEN cost_nok END), 0)"))
            ->orderByDesc(DB::raw('COUNT(*)'));

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->toBase()->get()->map(function (object $row) use ($dimensions): array {
            $values = (array) $row;
            $keys = array_intersect_key($values, array_flip($dimensions));

            return $keys + $this->normalise($values);
        })->values()->all();
    }

    /**
     * Aggregates per day, ISO week or month of started_at, keyed `YYYY-MM-DD`, `IYYY-IW` or `YYYY-MM`.
     *
     * @return array<string, array<string, int|float>>
     */
    public function trend(AiUsageFilter $filter, string $grouping = 'day'): array
    {
        $bucket = match ($grouping) {
            'day' => "TO_CHAR(started_at, 'YYYY-MM-DD')",
            'week' => "TO_CHAR(started_at, 'IYYY-IW')",
            'month' => "TO_CHAR(started_at, 'YYYY-MM')",
            default => throw new InvalidArgumentException("Unknown AI usage grouping [{$grouping}]."),
        };

        return $this->query($filter)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw($this->aggregates())
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->bucket => $this->normalise((array) $row)])
            ->all();
    }

    /** @return Collection<int, AiUsageAttempt> */
    public function recent(AiUsageFilter $filter, int $limit = 30)
    {
        return $this->query($filter)->orderByDesc('started_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Answers "do we have unattributed AI calls?": post-boundary attempts that reached the
     * provider with neither a customer nor an explicit system marking. Should always be zero.
     *
     * @return array{count: int, last_at: ?string, operations: list<array{feature: string, operation_key: string, count: int, last_at: string}>}
     */
    public function unattributed(?CarbonImmutable $since = null): array
    {
        $query = fn (): Builder => AiUsageAttempt::query()->unattributed()
            ->when($since !== null, fn (Builder $inner) => $inner->where('started_at', '>=', $since));

        $operations = $query()
            ->select(['feature', 'operation_key'])
            ->selectRaw('COUNT(*) as count, MAX(started_at) as last_at')
            ->groupBy(['feature', 'operation_key'])
            ->orderByDesc(DB::raw('MAX(started_at)'))
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'feature' => (string) $row->feature,
                'operation_key' => (string) $row->operation_key,
                'count' => (int) $row->count,
                'last_at' => (string) $row->last_at,
            ])->all();

        $lastAt = $query()->max('started_at');

        return [
            'count' => array_sum(array_column($operations, 'count')),
            'last_at' => $lastAt === null ? null : (string) $lastAt,
            'operations' => $operations,
        ];
    }

    /**
     * Post-boundary attempts whose cost is still open (pending or unresolved), for operations:
     * how many, what they hold, and since when. `$olderThan` narrows it to the ones that have
     * outlived normal retry and are an operational deviation.
     *
     * @return array{count: int, reserved_cost_nok: float, oldest_started_at: ?string, operations: list<array{settlement_status: string, feature: string, operation_key: string, count: int, reserved_cost_nok: float, oldest_started_at: string}>}
     */
    public function openSettlements(?CarbonImmutable $olderThan = null): array
    {
        $operations = AiUsageAttempt::query()
            ->where('ledger_version', '>=', AiUsageAttempt::TRUSTED_SINCE_LEDGER_VERSION)
            ->openSettlement()
            ->when($olderThan !== null, fn (Builder $query) => $query->where('started_at', '<', $olderThan))
            ->select(['settlement_status', 'feature', 'operation_key'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(reserved_cost_nok), 0) as reserved_cost_nok, MIN(started_at) as oldest_started_at')
            ->groupBy(['settlement_status', 'feature', 'operation_key'])
            ->orderBy(DB::raw('MIN(started_at)'))
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'settlement_status' => (string) $row->settlement_status,
                'feature' => (string) $row->feature,
                'operation_key' => (string) $row->operation_key,
                'count' => (int) $row->count,
                'reserved_cost_nok' => round((float) $row->reserved_cost_nok, 4),
                'oldest_started_at' => (string) $row->oldest_started_at,
            ])->all();

        $oldest = array_column($operations, 'oldest_started_at');
        sort($oldest);

        return [
            'count' => array_sum(array_column($operations, 'count')),
            'reserved_cost_nok' => round(array_sum(array_column($operations, 'reserved_cost_nok')), 4),
            'oldest_started_at' => $oldest[0] ?? null,
            'operations' => $operations,
        ];
    }

    /**
     * Legacy (pre-boundary) attempts matching the filter's period and scope. A count for display
     * only — their cost is never part of any economic figure.
     */
    public function legacyCalls(AiUsageFilter $filter): int
    {
        return AiUsageAttempt::query()
            ->legacy()
            ->where('started_at', '>=', $filter->period->start)
            ->where('started_at', '<', $filter->period->end)
            ->when($filter->customerId !== null, fn (Builder $query) => $query->where('customer_id', $filter->customerId))
            ->when($filter->feature !== null, fn (Builder $query) => $query->where('feature', $filter->feature))
            ->when($filter->operation !== null, fn (Builder $query) => $query->where('operation_key', $filter->operation))
            ->count();
    }

    /**
     * Everything the strict gate (`ai:usage-integrity`) asks of post-boundary attempts in the
     * window: ownership, classification and settlement, plus the traffic evidence it requires.
     *
     * @return array{unattributed: array{count: int, last_at: ?string, operations: list<array<string, mixed>>}, customer_without_customer_id: int, missing_feature: int, unregistered_operations: list<array{operation_key: string, count: int}>, system_not_classified: int, missing_settlement: int, first_attempt_at: ?string, tender_extraction_calls: int, wiki_calls: int}
     */
    public function attributionIntegrity(?CarbonImmutable $since = null): array
    {
        $query = fn (): Builder => AiUsageAttempt::query()
            ->where('ledger_version', '>=', AiUsageAttempt::TRUSTED_SINCE_LEDGER_VERSION)
            ->when($since !== null, fn (Builder $inner) => $inner->where('started_at', '>=', $since));

        $unregistered = $query()
            ->whereNotIn('operation_key', AiOperationCatalog::registeredOperations())
            ->select('operation_key')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('operation_key')
            ->orderBy('operation_key')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['operation_key' => (string) $row->operation_key, 'count' => (int) $row->count])
            ->all();

        $first = AiUsageAttempt::query()
            ->where('ledger_version', '>=', AiUsageAttempt::TRUSTED_SINCE_LEDGER_VERSION)
            ->min('started_at');

        return [
            'unattributed' => $this->unattributed($since),
            'customer_without_customer_id' => $query()->where('attribution', AiCallContext::ATTRIBUTION_CUSTOMER)->whereNull('customer_id')->count(),
            'missing_feature' => $query()->where(fn (Builder $inner) => $inner->whereNull('feature')->orWhereIn('feature', ['', 'unclassified']))->count(),
            'unregistered_operations' => $unregistered,
            'system_not_classified' => $query()->where('attribution', AiCallContext::ATTRIBUTION_SYSTEM)->where('operation_key', 'not like', 'system.%')->count(),
            'missing_settlement' => $query()->whereNull('settlement_status')->count(),
            'first_attempt_at' => $first === null ? null : (string) $first,
            'tender_extraction_calls' => $query()->where('operation_key', 'like', 'tender.requirement_extraction%')->count(),
            'wiki_calls' => $query()->where('feature', 'wiki')->count(),
        ];
    }

    /** @return array{trusted: int, unattributed: int, legacy: int} */
    public function integrity(): array
    {
        return [
            'trusted' => AiUsageAttempt::query()->trusted()->count(),
            'unattributed' => AiUsageAttempt::query()->unattributed()->count(),
            'legacy' => AiUsageAttempt::query()->legacy()->count(),
        ];
    }

    private function query(AiUsageFilter $filter): Builder
    {
        return AiUsageAttempt::query()
            ->when($filter->trustedOnly, fn (Builder $query) => $query->trusted())
            ->where('started_at', '>=', $filter->period->start)
            ->where('started_at', '<', $filter->period->end)
            ->when($filter->customerId !== null, fn (Builder $query) => $query->where('customer_id', $filter->customerId))
            ->when($filter->feature !== null, fn (Builder $query) => $query->where('feature', $filter->feature))
            ->when($filter->operation !== null, fn (Builder $query) => $query->where('operation_key', $filter->operation));
    }

    private function aggregates(): string
    {
        $settled = "settlement_status = '".AiUsageAttempt::SETTLEMENT_SETTLED."'";
        $pending = "settlement_status = '".AiUsageAttempt::SETTLEMENT_PENDING."'";
        $unresolved = "settlement_status = '".AiUsageAttempt::SETTLEMENT_UNRESOLVED."'";

        return implode(', ', [
            'COUNT(*) as calls',
            "SUM(CASE WHEN status = '".AiUsageAttempt::STATUS_SUCCESS."' THEN 1 ELSE 0 END) as successful_calls",
            "SUM(CASE WHEN status <> '".AiUsageAttempt::STATUS_SUCCESS."' THEN 1 ELSE 0 END) as failed_calls",
            'COALESCE(SUM(input_tokens), 0) as input_tokens',
            'COALESCE(SUM(cached_input_tokens), 0) as cached_input_tokens',
            'COALESCE(SUM(output_tokens), 0) as output_tokens',
            'COALESCE(SUM(reasoning_tokens), 0) as reasoning_tokens',
            'COALESCE(SUM(total_tokens), 0) as total_tokens',
            "COALESCE(SUM(CASE WHEN {$settled} THEN cost_nok END), 0) as cost_nok",
            "SUM(CASE WHEN {$settled} THEN 1 ELSE 0 END) as settled_calls",
            "SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END) as pending_calls",
            "COALESCE(SUM(CASE WHEN {$pending} THEN reserved_cost_nok END), 0) as pending_reserved_cost_nok",
            "SUM(CASE WHEN {$unresolved} THEN 1 ELSE 0 END) as unresolved_calls",
            "COALESCE(SUM(CASE WHEN {$unresolved} THEN reserved_cost_nok END), 0) as unresolved_reserved_cost_nok",
            "SUM(CASE WHEN settlement_status = '".AiUsageAttempt::SETTLEMENT_RELEASED."' THEN 1 ELSE 0 END) as released_calls",
            "SUM(CASE WHEN cost_status = '".AiCostState::ESTIMATED."' THEN 1 ELSE 0 END) as estimated_cost_calls",
            "SUM(CASE WHEN cost_status = '".AiCostState::UNKNOWN."' OR cost_status IS NULL THEN 1 ELSE 0 END) as unknown_cost_calls",
            "SUM(CASE WHEN cost_status = '".AiCostState::UNCERTAIN."' THEN 1 ELSE 0 END) as uncertain_cost_calls",
            'COALESCE(SUM(reserved_cost_nok), 0) as reserved_nok',
            "SUM(CASE WHEN attribution = '".AiCallContext::ATTRIBUTION_SYSTEM."' THEN 1 ELSE 0 END) as system_calls",
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int|float>
     */
    private function normalise(array $row): array
    {
        $out = [];

        foreach (['calls', 'successful_calls', 'failed_calls', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens', 'estimated_cost_calls', 'unknown_cost_calls', 'uncertain_cost_calls', 'system_calls', 'settled_calls', 'pending_calls', 'unresolved_calls', 'released_calls'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        $out['cost_nok'] = round((float) ($row['cost_nok'] ?? 0), 4);
        $out['settled_cost_nok'] = $out['cost_nok'];
        $out['pending_reserved_cost_nok'] = round((float) ($row['pending_reserved_cost_nok'] ?? 0), 4);
        $out['unresolved_reserved_cost_nok'] = round((float) ($row['unresolved_reserved_cost_nok'] ?? 0), 4);
        $out['reserved_nok'] = round((float) ($row['reserved_nok'] ?? 0), 4);

        return $out;
    }
}
