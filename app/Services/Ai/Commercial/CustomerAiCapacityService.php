<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\CustomerAiCapacity;
use App\Data\Ai\Usage\AiUsageFilter;
use App\Data\Billing\BillingPeriod;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\Customer;
use App\Services\Ai\Usage\AiUsageLedger;
use App\Services\Billing\CustomerBillingPeriodResolver;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The shared AI capacity: one pool per customer and billing period, used by every module.
 *
 * It holds no state of its own. Everything is read from the usage ledger and converted to units:
 *
 *   ai_usage_attempts (internal NOK) → AiUnitConverter → AI units
 *
 *  - used:      settled cost (`cost_nok` of settled attempts)
 *  - reserved:  what open attempts hold (`reserved_cost_nok` of pending and unresolved attempts —
 *               including a call in flight, which the meter opens as pending with its estimate)
 *  - available: included − used − reserved
 *
 * The reservation therefore is the cost-control estimate already written on the attempt; there is
 * no second reservation table that could drift from the ledger. When an attempt settles, its
 * reservation turns into usage at the actual cost by itself.
 *
 * Included capacity (resolveIncluded()): customers.included_ai_units (an explicit override, per
 * billing period) wins; otherwise the customer's AI capacity tier (AiCapacityTierCatalog, per month
 * × the period length); otherwise unconfigured (unmetered). AI capacity is a commercial dimension
 * of its own: Basis, the options and the old Pro/Max/Ultra plans never add capacity — one
 * customer, one pool, however many modules it holds.
 */
class CustomerAiCapacityService
{
    public const REFUSE_EXHAUSTED = AiCostControlException::CAPACITY_EXHAUSTED;

    public const REFUSE_INSUFFICIENT = AiCostControlException::CAPACITY_INSUFFICIENT;

    public const VERDICT_ALLOW = 'allow';

    public const VERDICT_WARN = 'warn';

    public const VERDICT_EXHAUSTED = 'exhausted';

    public const VERDICT_INSUFFICIENT = 'insufficient';

    public const VERDICT_UNMETERED = 'unmetered';

    public function __construct(
        private readonly AiUsageLedger $ledger,
        private readonly CustomerBillingPeriodResolver $billingPeriods,
        private readonly AiUnitConverter $units,
        private readonly AiCapacityTierCatalog $tiers,
    ) {}

    public function forCustomer(Customer|int $customer, ?DateTimeInterface $at = null): CustomerAiCapacity
    {
        $customer = $customer instanceof Customer ? $customer : Customer::query()->findOrFail($customer);
        $period = $this->billingPeriods->at($customer, $at ?? CarbonImmutable::now('UTC'));
        ['units' => $included, 'source' => $source] = $this->resolveIncluded($customer, $period);
        $tier = $this->tiers->find($customer->ai_capacity_tier);

        $totals = $this->ledger->totals(new AiUsageFilter($period->toUsagePeriod(), customerId: (int) $customer->id));
        $usedExact = $this->units->unitsForCost((float) $totals['settled_cost_nok']);
        $reservedExact = $this->units->unitsForCost((float) $totals['pending_reserved_cost_nok'] + (float) $totals['unresolved_reserved_cost_nok']);

        $status = $this->status($included, $usedExact);
        $reservedUnits = $this->units->displayUnits($reservedExact);

        return new CustomerAiCapacity(
            customerId: (int) $customer->id,
            period: $period,
            includedUnits: $included,
            includedSource: $source,
            tierKey: $tier['key'] ?? null,
            tierName: $tier === null ? null : $this->tierName($tier),
            isProvisional: $source === CustomerAiCapacity::SOURCE_TIER && (bool) config('ai_customer_capacity.tiers_provisional', true),
            usedUnitsExact: $usedExact,
            reservedUnitsExact: $reservedExact,
            usedUnits: $this->units->displayUnits($usedExact),
            reservedUnits: $reservedUnits,
            status: $status,
            showsReservation: $this->showsReservation($included, $usedExact, $reservedExact, $reservedUnits, $status),
        );
    }

    /**
     * What the gate says about starting an operation estimated at $estimatedCostNok right now:
     *  - VERDICT_ALLOW:        it fits
     *  - VERDICT_WARN:         it fits, but used + reserved + this estimate reaches the warning share
     *  - VERDICT_EXHAUSTED:    settled usage alone has reached the included capacity
     *  - VERDICT_INSUFFICIENT: what is left after reservations does not cover this estimate — not
     *                          "everything is used", so it is never worded that way
     *  - VERDICT_UNMETERED:    no capacity is defined for the customer
     *
     * The first two admit, the next two refuse (in enforce mode). Recorded on the attempt either
     * way, so observe mode shows how often the current capacity would have stopped someone.
     */
    public function evaluate(Customer|int $customer, ?float $estimatedCostNok, ?DateTimeInterface $at = null): string
    {
        $capacity = $this->forCustomer($customer, $at);

        if (! $capacity->isConfigured()) {
            return self::VERDICT_UNMETERED;
        }

        $included = (float) $capacity->includedUnits;

        if ($capacity->usedUnitsExact >= $included) {
            return self::VERDICT_EXHAUSTED;
        }

        $available = (float) $capacity->availableUnitsExact();
        $needed = $this->units->unitsForCost($estimatedCostNok);

        if ($available <= 0 || $available < $needed) {
            return self::VERDICT_INSUFFICIENT;
        }

        $projected = ($capacity->usedUnitsExact + $capacity->reservedUnitsExact + $needed) / $included * 100;

        return $projected >= (float) config('ai_customer_capacity.thresholds.warning_percent', 80)
            ? self::VERDICT_WARN
            : self::VERDICT_ALLOW;
    }

    /** The refusal reason for a verdict, or null when the verdict admits the call. */
    public static function refusalReason(string $verdict): ?string
    {
        return match ($verdict) {
            self::VERDICT_EXHAUSTED => AiCostControlException::CAPACITY_EXHAUSTED,
            self::VERDICT_INSUFFICIENT => AiCostControlException::CAPACITY_INSUFFICIENT,
            default => null,
        };
    }

    /** Null when an operation estimated at $estimatedCostNok may start, else the refusal reason. */
    public function refusalFor(Customer|int $customer, ?float $estimatedCostNok, ?DateTimeInterface $at = null): ?string
    {
        return self::refusalReason($this->evaluate($customer, $estimatedCostNok, $at));
    }

    /** Units included in this billing period, or null when nothing defines any. */
    public function includedUnits(Customer $customer, BillingPeriod $period): ?int
    {
        return $this->resolveIncluded($customer, $period)['units'];
    }

    /**
     * The included capacity and where it came from.
     *
     * @return array{units: ?int, source: string}
     */
    public function resolveIncluded(Customer $customer, BillingPeriod $period): array
    {
        if ($customer->included_ai_units !== null) {
            return ['units' => max(0, (int) $customer->included_ai_units), 'source' => CustomerAiCapacity::SOURCE_OVERRIDE];
        }

        $tier = $this->tiers->find($customer->ai_capacity_tier);

        if ($tier === null) {
            return ['units' => null, 'source' => CustomerAiCapacity::SOURCE_UNCONFIGURED];
        }

        // Procynia bills monthly or yearly; a tier is sized per month.
        $months = in_array($period->interval, [BillingPeriod::INTERVAL_YEAR, Customer::BILLING_YEARLY], true) ? 12 : 1;

        return ['units' => $tier['included_units_per_month'] * $months, 'source' => CustomerAiCapacity::SOURCE_TIER];
    }

    /** The customer-facing tier name, falling back to the catalog's internal name. */
    private function tierName(array $tier): string
    {
        $key = 'procynia.billing.ai_capacity.tier_names.'.$tier['key'];
        $translated = __($key);

        return $translated === $key ? $tier['name'] : (string) $translated;
    }

    private function status(?int $included, float $usedExact): string
    {
        if ($included === null) {
            return CustomerAiCapacity::STATUS_NOT_CONFIGURED;
        }

        if ($included <= 0) {
            return CustomerAiCapacity::STATUS_EXHAUSTED;
        }

        $percent = $usedExact / $included * 100;

        return match (true) {
            $percent >= (float) config('ai_customer_capacity.thresholds.exhausted_percent', 100) => CustomerAiCapacity::STATUS_EXHAUSTED,
            $percent >= (float) config('ai_customer_capacity.thresholds.warning_percent', 80) => CustomerAiCapacity::STATUS_WARNING,
            default => CustomerAiCapacity::STATUS_NORMAL,
        };
    }

    /**
     * Reserved capacity is mentioned only when it changes the picture: it holds a noticeable share
     * of the capacity, or it is what leaves nothing available. A few calls in flight are not news.
     */
    private function showsReservation(?int $included, float $usedExact, float $reservedExact, int $reservedUnits, string $status): bool
    {
        if ($included === null || $included <= 0 || $reservedUnits <= 0 || $status === CustomerAiCapacity::STATUS_EXHAUSTED) {
            return false;
        }

        return $reservedExact / $included * 100 >= (float) config('ai_customer_capacity.reservation_notice_percent', 5)
            || $included - $usedExact - $reservedExact < 1.0;
    }
}
