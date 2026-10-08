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
 * Included capacity: customers.included_ai_units (per billing period) wins; otherwise the plan's
 * monthly `included_ai_units` × the period length (12 for a yearly period). Neither set → not
 * configured, and nothing is metered against it.
 */
class CustomerAiCapacityService
{
    public const REFUSE_EXHAUSTED = AiCostControlException::CAPACITY_EXHAUSTED;

    public const REFUSE_INSUFFICIENT = AiCostControlException::CAPACITY_INSUFFICIENT;

    public function __construct(
        private readonly AiUsageLedger $ledger,
        private readonly CustomerBillingPeriodResolver $billingPeriods,
        private readonly AiUnitConverter $units,
    ) {}

    public function forCustomer(Customer|int $customer, ?DateTimeInterface $at = null): CustomerAiCapacity
    {
        $customer = $customer instanceof Customer ? $customer : Customer::query()->findOrFail($customer);
        $period = $this->billingPeriods->at($customer, $at ?? CarbonImmutable::now('UTC'));
        $included = $this->includedUnits($customer, $period);

        $totals = $this->ledger->totals(new AiUsageFilter($period->toUsagePeriod(), customerId: (int) $customer->id));
        $usedExact = $this->units->unitsForCost((float) $totals['settled_cost_nok']);
        $reservedExact = $this->units->unitsForCost((float) $totals['pending_reserved_cost_nok'] + (float) $totals['unresolved_reserved_cost_nok']);

        $status = $this->status($included, $usedExact);
        $reservedUnits = $this->units->displayUnits($reservedExact);

        return new CustomerAiCapacity(
            customerId: (int) $customer->id,
            period: $period,
            includedUnits: $included,
            usedUnitsExact: $usedExact,
            reservedUnitsExact: $reservedExact,
            usedUnits: $this->units->displayUnits($usedExact),
            reservedUnits: $reservedUnits,
            status: $status,
            showsReservation: $this->showsReservation($included, $usedExact, $reservedExact, $reservedUnits, $status),
        );
    }

    /**
     * Can this customer start an AI operation estimated at $estimatedCostNok right now?
     *
     * Null when it fits (or when no capacity is configured); otherwise the refusal reason:
     *  - REFUSE_EXHAUSTED:    settled usage alone has reached the included capacity
     *  - REFUSE_INSUFFICIENT: what is left after reservations does not cover this operation's
     *                         estimate — not "everything is used", so it is never worded that way
     */
    public function refusalFor(Customer|int $customer, ?float $estimatedCostNok, ?DateTimeInterface $at = null): ?string
    {
        $capacity = $this->forCustomer($customer, $at);

        if (! $capacity->isConfigured()) {
            return null;
        }

        if ($capacity->usedUnitsExact >= (float) $capacity->includedUnits) {
            return self::REFUSE_EXHAUSTED;
        }

        $available = (float) $capacity->availableUnitsExact();
        $needed = $this->units->unitsForCost($estimatedCostNok);

        return $available > 0 && $available >= $needed ? null : self::REFUSE_INSUFFICIENT;
    }

    /** Units included in this billing period, or null when neither customer nor plan defines any. */
    public function includedUnits(Customer $customer, BillingPeriod $period): ?int
    {
        if ($customer->included_ai_units !== null) {
            return max(0, (int) $customer->included_ai_units);
        }

        $monthly = $customer->planConfig()['included_ai_units'] ?? null;

        if ($monthly === null) {
            return null;
        }

        // Procynia bills monthly or yearly; the plan figure is per month.
        $months = in_array($period->interval, [BillingPeriod::INTERVAL_YEAR, Customer::BILLING_YEARLY], true) ? 12 : 1;

        return max(0, (int) $monthly) * $months;
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
