<?php

namespace App\Services\Ai\Usage;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\Operational\AiCostState;
use App\Data\Ai\Usage\AiUsageFilter;
use App\Models\AiUsageAttempt;
use Carbon\CarbonImmutable;
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
 * Cost is the actual cost snapshotted from provider usage. `cost_nok` sums known and estimated
 * (stale price or rate, padded) costs; calls whose cost is unknown or uncertain are counted
 * separately and never summed as zero. `reserved_nok` is what those calls reserved before they
 * ran — shown next to actual cost, never instead of it.
 */
class AiUsageLedger
{
    /** Columns a breakdown may group by. */
    public const DIMENSIONS = ['customer_id', 'user_id', 'feature', 'operation_key', 'model', 'provider', 'resource_type', 'status', 'attribution'];

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
            ->orderByDesc(DB::raw('COALESCE(SUM(CASE WHEN cost_status IN (\''.AiCostState::KNOWN.'\', \''.AiCostState::ESTIMATED.'\') THEN cost_nok END), 0)'))
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
        $priced = "cost_status IN ('".AiCostState::KNOWN."', '".AiCostState::ESTIMATED."')";

        return implode(', ', [
            'COUNT(*) as calls',
            "SUM(CASE WHEN status = '".AiUsageAttempt::STATUS_SUCCESS."' THEN 1 ELSE 0 END) as successful_calls",
            "SUM(CASE WHEN status <> '".AiUsageAttempt::STATUS_SUCCESS."' THEN 1 ELSE 0 END) as failed_calls",
            'COALESCE(SUM(input_tokens), 0) as input_tokens',
            'COALESCE(SUM(cached_input_tokens), 0) as cached_input_tokens',
            'COALESCE(SUM(output_tokens), 0) as output_tokens',
            'COALESCE(SUM(reasoning_tokens), 0) as reasoning_tokens',
            'COALESCE(SUM(total_tokens), 0) as total_tokens',
            "COALESCE(SUM(CASE WHEN {$priced} THEN cost_nok END), 0) as cost_nok",
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

        foreach (['calls', 'successful_calls', 'failed_calls', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens', 'estimated_cost_calls', 'unknown_cost_calls', 'uncertain_cost_calls', 'system_calls'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        $out['cost_nok'] = round((float) ($row['cost_nok'] ?? 0), 4);
        $out['reserved_nok'] = round((float) ($row['reserved_nok'] ?? 0), 4);

        return $out;
    }
}
