<?php

namespace App\Services\Ai\Experience;

use App\Data\Ai\CustomerAiCapacity;
use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Billing\BillingPeriod;
use App\Models\AiCustomerExperiencePeriod;
use App\Models\AiCustomerExperiencePeriodRevision;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Services\Ai\Commercial\AiBaseCapacityCalculator;
use App\Services\Ai\Commercial\AiCapacityCalibrationService;
use App\Services\Ai\Commercial\AiCapacityTierCatalog;
use App\Services\Ai\Commercial\AiUnitConverter;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Ai\Usage\AiUsageLedger;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\CustomerBillingPeriodResolver;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes the AI experience snapshots: one row per customer and billing period.
 *
 * Usage figures are recomputed from the trusted ledger on every refresh (AiUsageLedger, the
 * calibration service's verdict counts), so the snapshot can always be rebuilt. The customer's shape
 * in the period — active users, packages, tier, capacity — cannot be rebuilt later, so it is
 * captured while the period runs and never re-read once the period has ended: an old period is not
 * analysed through today's subscription.
 *
 *  - open:   the period runs, or is inside the settle grace; refreshed freely.
 *  - final:  `finalize_after_days` after the end. Skipped by a normal refresh; recomputed only on
 *            request (or when the snapshot format changes), and then every change to its usage is
 *            written as a revision — a closed period is correctable, never silently changed.
 *
 * Only trusted ledger data is used. Periods start at the trusted-ledger boundary at the earliest;
 * a period that began before it, or holds legacy rows, is marked `partial` and kept out of the
 * default analysis.
 *
 * Read-only toward the commercial model: nothing here changes a weight, tier, override or price.
 */
class AiExperienceSnapshotService
{
    private ?CarbonImmutable $boundary = null;

    private bool $boundaryResolved = false;

    public function __construct(
        private readonly AiUsageLedger $ledger,
        private readonly AiCapacityCalibrationService $calibration,
        private readonly CustomerBillingPeriodResolver $billingPeriods,
        private readonly CustomerAiCapacityService $capacity,
        private readonly AiBaseCapacityCalculator $base,
        private readonly AiCapacityTierCatalog $tiers,
        private readonly AiUnitConverter $units,
        private readonly ModuleEntitlementService $modules,
        private readonly BillingEntitlementService $billing,
    ) {}

    /**
     * Every customer that is active or has trusted usage: each of its billing periods from the
     * trusted boundary until now.
     *
     * @return array{created: int, updated: int, finalized: int, revised: int, skipped: int}
     */
    public function refreshAll(bool $recheckFinal = false): array
    {
        $stats = $this->emptyStats();

        if ($this->trustedBoundary() === null) {
            return $stats;
        }

        $withUsage = AiUsageAttempt::query()->trusted()->whereNotNull('customer_id')->distinct()->select('customer_id');

        Customer::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $withUsage))
            ->orderBy('id')
            ->each(function (Customer $customer) use (&$stats, $recheckFinal): void {
                $stats = $this->merge($stats, $this->refreshCustomer($customer, $recheckFinal));
            });

        return $stats;
    }

    /** @return array{created: int, updated: int, finalized: int, revised: int, skipped: int} */
    public function refreshCustomer(Customer $customer, bool $recheckFinal = false): array
    {
        $stats = $this->emptyStats();
        $boundary = $this->trustedBoundary();

        if ($boundary === null) {
            return $stats;
        }

        $now = CarbonImmutable::now('UTC');
        $created = $customer->created_at === null ? $boundary : CarbonImmutable::instance($customer->created_at)->utc();
        $at = $created->gt($boundary) ? $created : $boundary;

        // Bounded by the number of billing periods since the boundary.
        while ($at->lte($now)) {
            $period = $this->billingPeriods->at($customer, $at);
            $stats[$this->snapshot($customer, $period, $recheckFinal)]++;
            $at = $period->end;
        }

        return $stats;
    }

    /**
     * The one period containing $at.
     *
     * @return 'created'|'updated'|'finalized'|'revised'|'skipped'
     */
    public function refreshPeriod(Customer $customer, DateTimeInterface $at, bool $recheckFinal = true): string
    {
        if ($this->trustedBoundary() === null) {
            return 'skipped';
        }

        return $this->snapshot($customer, $this->billingPeriods->at($customer, $at), $recheckFinal);
    }

    /** The first trusted ledger row: before it, no AI usage may be used as an economic basis. */
    public function trustedBoundary(): ?CarbonImmutable
    {
        if (! $this->boundaryResolved) {
            $first = AiUsageAttempt::query()
                ->where('ledger_version', '>=', AiUsageAttempt::TRUSTED_SINCE_LEDGER_VERSION)
                ->min('started_at');
            $this->boundary = $first === null ? null : CarbonImmutable::parse($first, 'UTC');
            $this->boundaryResolved = true;
        }

        return $this->boundary;
    }

    /** @return 'created'|'updated'|'finalized'|'revised'|'skipped' */
    private function snapshot(Customer $customer, BillingPeriod $period, bool $recheckFinal): string
    {
        $now = CarbonImmutable::now('UTC');
        $existing = AiCustomerExperiencePeriod::query()
            ->where('customer_id', $customer->id)
            ->where('period_start', $period->start)
            ->first();

        $outdated = $existing !== null && $existing->snapshot_version < AiCustomerExperiencePeriod::SNAPSHOT_VERSION;

        if ($existing?->isFinal() && ! $recheckFinal && ! $outdated) {
            return 'skipped';
        }

        // The context is captured while the period runs, and never re-read after it has ended.
        $context = $existing !== null && ($existing->isFinal() || $now->gte($period->end))
            ? $this->storedContext($existing)
            : $this->context($customer, $period, $now);

        ['usage' => $usage, 'features' => $features] = $this->usage($customer, $period, $context['included_units']);
        $final = $now->gte($period->end->addDays(max(0, (int) config('ai_experience.finalize_after_days', 3))));

        $attributes = [
            'period_end' => $period->end,
            'period_source' => $period->source,
            'billing_interval' => $this->interval($period),
            'period_months' => $this->months($period),
            'coverage' => $this->coverage($period, $usage['legacy_calls']),
            ...$context,
            ...$usage,
            'nok_per_unit' => (float) config('ai_customer_capacity.nok_per_unit', 0.10),
            'snapshot_version' => AiCustomerExperiencePeriod::SNAPSHOT_VERSION,
            'ledger_version' => AiUsageAttempt::TRUSTED_SINCE_LEDGER_VERSION,
            'generated_at' => $now,
        ];

        return DB::transaction(function () use ($customer, $period, $existing, $attributes, $features, $final, $now, $outdated): string {
            if ($existing === null) {
                $row = AiCustomerExperiencePeriod::query()->create([
                    'customer_id' => $customer->id,
                    'period_start' => $period->start,
                    'status' => $final ? AiCustomerExperiencePeriod::STATUS_FINAL : AiCustomerExperiencePeriod::STATUS_OPEN,
                    'finalized_at' => $final ? $now : null,
                    ...$attributes,
                ]);
                $this->writeFeatures($row, $features);

                return 'created';
            }

            if ($existing->isFinal()) {
                $changes = $this->changes($existing, $attributes, $features);

                if ($changes === []) {
                    return 'skipped';
                }

                $revision = $existing->revision + 1;
                AiCustomerExperiencePeriodRevision::query()->create([
                    'ai_customer_experience_period_id' => $existing->id,
                    'revision' => $revision,
                    'changes' => $changes,
                    'reason' => $outdated ? 'snapshot_version' : 'recheck',
                    'created_at' => $now,
                ]);
                $existing->forceFill([...$attributes, 'revision' => $revision])->save();
                $this->writeFeatures($existing, $features);

                return 'revised';
            }

            $existing->forceFill([
                ...$attributes,
                'status' => $final ? AiCustomerExperiencePeriod::STATUS_FINAL : AiCustomerExperiencePeriod::STATUS_OPEN,
                'finalized_at' => $final ? $now : null,
            ])->save();
            $this->writeFeatures($existing, $features);

            return $final ? 'finalized' : 'updated';
        });
    }

    /**
     * The customer's shape right now, sized for $period: what the capacity gate uses.
     *
     * @return array<string, mixed>
     */
    private function context(Customer $customer, BillingPeriod $period, CarbonImmutable $now): array
    {
        $packages = $this->modules->activePackageKeys($customer);
        $basePerMonth = $this->base->unitsPerMonth($customer);
        $tier = $this->tiers->effective($customer->ai_capacity_tier);
        $months = $this->months($period);
        ['units' => $included, 'source' => $source] = $this->capacity->resolveIncluded($customer, $period);

        return [
            'context_captured_at' => $now,
            'active_users_count' => $this->billing->currentBillableUsers($customer),
            'active_packages' => $packages,
            'module_mix' => self::moduleMix($packages),
            'tier_key' => $tier['key'] ?? null,
            'tier_multiplier' => $tier['multiplier'] ?? null,
            'base_units_per_month' => $basePerMonth,
            'calculated_base_capacity' => $basePerMonth * $months,
            'calculated_total_capacity' => $tier === null ? null : (int) round($basePerMonth * $tier['multiplier'], 0, PHP_ROUND_HALF_UP) * $months,
            'override_units' => $source === CustomerAiCapacity::SOURCE_OVERRIDE ? $included : null,
            'included_units' => $included,
            'capacity_source' => $source,
        ];
    }

    /** @return array<string, mixed> */
    private function storedContext(AiCustomerExperiencePeriod $row): array
    {
        return [
            'context_captured_at' => $row->context_captured_at,
            'active_users_count' => $row->active_users_count,
            'active_packages' => $row->active_packages,
            'module_mix' => $row->module_mix,
            'tier_key' => $row->tier_key,
            'tier_multiplier' => $row->tier_multiplier,
            'base_units_per_month' => $row->base_units_per_month,
            'calculated_base_capacity' => $row->calculated_base_capacity,
            'calculated_total_capacity' => $row->calculated_total_capacity,
            'override_units' => $row->override_units,
            'included_units' => $row->included_units,
            'capacity_source' => $row->capacity_source,
        ];
    }

    /**
     * Trusted usage in the period: four aggregate queries, whatever the volume.
     *
     * @return array{usage: array<string, mixed>, features: array<string, array{calls: int, settled_cost_nok: float, settled_units: float, reserved_units: float}>}
     */
    private function usage(Customer $customer, BillingPeriod $period, ?int $included): array
    {
        $filter = new AiUsageFilter($period->toUsagePeriod(), customerId: (int) $customer->id);
        $totals = $this->ledger->totals($filter);
        $verdicts = $this->calibration->verdicts($filter);
        $settledUnits = round($this->units->unitsForCost((float) $totals['settled_cost_nok']), 2);
        $reservedUnits = round($this->units->unitsForCost((float) $totals['pending_reserved_cost_nok'] + (float) $totals['unresolved_reserved_cost_nok']), 2);

        $features = [];

        foreach ($this->ledger->breakdown($filter, ['feature', 'resource_type']) as $row) {
            $key = AiExperienceAttribution::key($row['feature'], $row['resource_type']);
            $features[$key] ??= ['calls' => 0, 'settled_cost_nok' => 0.0, 'reserved_cost_nok' => 0.0];
            $features[$key]['calls'] += $row['calls'];
            $features[$key]['settled_cost_nok'] += $row['settled_cost_nok'];
            $features[$key]['reserved_cost_nok'] += $row['pending_reserved_cost_nok'] + $row['unresolved_reserved_cost_nok'];
        }

        ksort($features);
        $features = array_map(fn (array $feature): array => [
            'calls' => $feature['calls'],
            'settled_cost_nok' => round($feature['settled_cost_nok'], 4),
            'settled_units' => round($this->units->unitsForCost($feature['settled_cost_nok']), 2),
            'reserved_units' => round($this->units->unitsForCost($feature['reserved_cost_nok']), 2),
        ], $features);

        return [
            'usage' => [
                'calls' => $totals['calls'],
                'successful_calls' => $totals['successful_calls'],
                'failed_calls' => $totals['failed_calls'],
                'settled_calls' => $totals['settled_calls'],
                'pending_calls' => $totals['pending_calls'],
                'unresolved_calls' => $totals['unresolved_calls'],
                'legacy_calls' => $this->ledger->legacyCalls($filter),
                'total_tokens' => $totals['total_tokens'],
                'settled_cost_nok' => $totals['settled_cost_nok'],
                'pending_reserved_cost_nok' => $totals['pending_reserved_cost_nok'],
                'unresolved_reserved_cost_nok' => $totals['unresolved_reserved_cost_nok'],
                'settled_units' => $settledUnits,
                'reserved_units' => $reservedUnits,
                'used_percent' => $included === null || $included <= 0 ? null : round($settledUnits / $included * 100, 2),
                'committed_percent' => $included === null || $included <= 0 ? null : round(($settledUnits + $reservedUnits) / $included * 100, 2),
                'verdict_allow' => $verdicts[CustomerAiCapacityService::VERDICT_ALLOW],
                'verdict_warn' => $verdicts[CustomerAiCapacityService::VERDICT_WARN],
                'verdict_exhausted' => $verdicts[CustomerAiCapacityService::VERDICT_EXHAUSTED],
                'verdict_insufficient' => $verdicts[CustomerAiCapacityService::VERDICT_INSUFFICIENT],
                'verdict_unmetered' => $verdicts[CustomerAiCapacityService::VERDICT_UNMETERED],
                'would_have_blocked' => $verdicts['would_have_blocked'],
            ],
            'features' => $features,
        ];
    }

    /** @param array<string, array<string, int|float>> $features */
    private function writeFeatures(AiCustomerExperiencePeriod $row, array $features): void
    {
        $row->features()->delete();
        $now = CarbonImmutable::now('UTC');

        $row->features()->insert(array_map(fn (string $key, array $feature): array => [
            'ai_customer_experience_period_id' => $row->id,
            'feature_key' => $key,
            ...$feature,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys($features), $features));
    }

    /**
     * Every usage figure of a final period that a recompute would change.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, int|float>>  $features
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(AiCustomerExperiencePeriod $row, array $attributes, array $features): array
    {
        $changes = [];

        foreach (AiCustomerExperiencePeriod::USAGE_FIELDS as $field) {
            $from = $row->getAttribute($field);
            $to = $attributes[$field] ?? null;
            $same = is_numeric($from) && is_numeric($to) ? abs((float) $from - (float) $to) < 0.00005 : $from === $to;

            if (! $same) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        $stored = $row->features()->orderBy('feature_key')->get()
            ->mapWithKeys(fn ($feature): array => [$feature->feature_key => round((float) $feature->settled_cost_nok, 4)])
            ->all();
        $recomputed = array_map(fn (array $feature): float => round((float) $feature['settled_cost_nok'], 4), $features);

        if ($stored != $recomputed) {
            $changes['features'] = ['from' => $stored, 'to' => $recomputed];
        }

        return $changes;
    }

    private function coverage(BillingPeriod $period, int $legacyCalls): string
    {
        $boundary = $this->trustedBoundary();

        return $boundary !== null && $period->start->gte($boundary) && $legacyCalls === 0
            ? AiCustomerExperiencePeriod::COVERAGE_FULL
            : AiCustomerExperiencePeriod::COVERAGE_PARTIAL;
    }

    private function interval(BillingPeriod $period): string
    {
        return in_array($period->interval, [BillingPeriod::INTERVAL_YEAR, Customer::BILLING_YEARLY], true)
            ? BillingPeriod::INTERVAL_YEAR
            : BillingPeriod::INTERVAL_MONTH;
    }

    private function months(BillingPeriod $period): int
    {
        return $this->interval($period) === BillingPeriod::INTERVAL_YEAR ? 12 : 1;
    }

    /** The packages as one stable key, in catalog order: «basis+risk+tender». */
    public static function moduleMix(array $packages): string
    {
        return $packages === [] ? 'none' : implode('+', $packages);
    }

    /** @return array{created: int, updated: int, finalized: int, revised: int, skipped: int} */
    private function emptyStats(): array
    {
        return ['created' => 0, 'updated' => 0, 'finalized' => 0, 'revised' => 0, 'skipped' => 0];
    }

    private function merge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] += $value;
        }

        return $left;
    }
}
