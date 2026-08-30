<?php

namespace App\Services\Ai\Operational;

use App\Data\Ai\Operational\AiBudgetReservation;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\AiOperationalBudgetPeriod;
use App\Models\AiRuntimeControl;
use App\Models\Customer;
use App\Models\CustomerAiOperationalLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Procynia's own economic safety net, in NOK.
 *
 * This is not the customer's commercial quota and must never be treated as one: it exists to stop
 * the platform spending money it did not plan to spend, and it can therefore stop a customer whose
 * plan says unlimited AI cases. The two limits answer different questions and are enforced
 * separately, in that order.
 *
 * Enforcement reserves a conservative estimate *before* the provider call and settles it after,
 * because a budget that only counts completed calls can be overrun by everything currently in
 * flight.
 */
class AiOperationalBudgetService
{
    public function __construct(private readonly AiOperationalPricingService $pricing) {}

    /**
     * Take a conservative hold on every budget window this call touches, or refuse it.
     *
     * All periods are locked in one transaction and in a stable order, so ten concurrent calls
     * against the last of a budget cannot all read the same balance and all decide to proceed.
     *
     * @throws AiCostControlException when a hard ceiling would be exceeded
     */
    public function reserve(?Customer $customer, float $estimatedNok, ?CarbonImmutable $at = null): AiBudgetReservation
    {
        $at ??= CarbonImmutable::now(config('app.timezone') ?: 'UTC');
        $scopes = $this->applicableScopes($customer, $at);

        if ($scopes === []) {
            return AiBudgetReservation::none();
        }

        return DB::transaction(function () use ($scopes, $estimatedNok, $at): AiBudgetReservation {
            $periodIds = [];

            foreach ($scopes as $scope) {
                $period = $this->lockPeriod($scope['scope'], $scope['customer_id'], $scope['window'], $at);

                if ($period->encumberedNok() + $estimatedNok > $scope['limit']) {
                    // Thrown inside the transaction so no partial hold survives the refusal.
                    throw new AiCostControlException($scope['reason']);
                }

                $period->forceFill(['reserved_nok' => (float) $period->reserved_nok + $estimatedNok])->save();
                $periodIds[] = (int) $period->id;
            }

            return new AiBudgetReservation($periodIds, $estimatedNok);
        });
    }

    /**
     * Replace the estimate with what the call actually cost.
     *
     * An unpriced call keeps its reservation as the committed figure rather than settling at zero:
     * a call Procynia cannot price still spent money, and the safety budget has to reflect that.
     */
    public function commit(AiBudgetReservation $reservation, ?float $actualNok, bool $costUnknown = false): void
    {
        if ($reservation->isEmpty()) {
            return;
        }

        $settled = $costUnknown || $actualNok === null
            ? $reservation->reservedNok
            : max(0.0, $actualNok);

        DB::transaction(function () use ($reservation, $settled, $costUnknown): void {
            foreach ($reservation->periodIds as $periodId) {
                $period = AiOperationalBudgetPeriod::query()->lockForUpdate()->find($periodId);

                if (! $period instanceof AiOperationalBudgetPeriod) {
                    continue;
                }

                $period->forceFill([
                    'reserved_nok' => max(0.0, (float) $period->reserved_nok - $reservation->reservedNok),
                    'committed_nok' => (float) $period->committed_nok + $settled,
                    'unknown_cost_count' => (int) $period->unknown_cost_count + ($costUnknown ? 1 : 0),
                ])->save();
            }
        });
    }

    /** Give the money back. Only for a failure that certainly did no provider work. */
    public function release(AiBudgetReservation $reservation): void
    {
        if ($reservation->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($reservation): void {
            foreach ($reservation->periodIds as $periodId) {
                $period = AiOperationalBudgetPeriod::query()->lockForUpdate()->find($periodId);

                if ($period instanceof AiOperationalBudgetPeriod) {
                    $period->forceFill([
                        'reserved_nok' => max(0.0, (float) $period->reserved_nok - $reservation->reservedNok),
                    ])->save();
                }
            }
        });
    }

    /**
     * A timeout or a 5xx may well have cost money. The hold stays, converted to committed spend,
     * so the budget never optimistically hands back money that was probably spent.
     */
    public function markUncertain(AiBudgetReservation $reservation): void
    {
        $this->commit($reservation, null, costUnknown: true);
    }

    /**
     * Current position for one scope and window, for admin display and threshold evaluation.
     *
     * @return array{limit: float|null, committed: float, reserved: float, encumbered: float, unknown_count: int, percentage: int|null, period_start: string, period_end: string}
     */
    public function status(string $scope, ?int $customerId, string $window, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now(config('app.timezone') ?: 'UTC');
        [$start, $end] = $this->windowBounds($window, $at);

        $period = AiOperationalBudgetPeriod::query()
            ->where(['scope' => $scope, 'customer_id' => $customerId, 'window' => $window, 'period_start' => $start])
            ->first();

        $limit = $scope === AiOperationalBudgetPeriod::SCOPE_GLOBAL
            ? $this->globalLimit($window)
            : $this->customerLimit($customerId, $window);

        $committed = (float) ($period->committed_nok ?? 0);
        $reserved = (float) ($period->reserved_nok ?? 0);
        $encumbered = $committed + $reserved;

        return [
            'limit' => $limit,
            'committed' => round($committed, 2),
            'reserved' => round($reserved, 2),
            'encumbered' => round($encumbered, 2),
            'unknown_count' => (int) ($period->unknown_cost_count ?? 0),
            'percentage' => $limit !== null && $limit > 0 ? (int) min(100, round($encumbered / $limit * 100)) : null,
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    /**
     * Which ceilings apply to this call, cheapest scope first.
     *
     * @return list<array{scope: string, customer_id: int|null, window: string, limit: float, reason: string}>
     */
    private function applicableScopes(?Customer $customer, CarbonImmutable $at): array
    {
        $scopes = [];

        foreach ([AiOperationalBudgetPeriod::WINDOW_DAILY, AiOperationalBudgetPeriod::WINDOW_MONTHLY] as $window) {
            $globalLimit = $this->globalLimit($window);

            if ($globalLimit !== null) {
                $scopes[] = [
                    'scope' => AiOperationalBudgetPeriod::SCOPE_GLOBAL,
                    'customer_id' => null,
                    'window' => $window,
                    'limit' => $globalLimit,
                    'reason' => $window === AiOperationalBudgetPeriod::WINDOW_DAILY
                        ? AiCostControlException::GLOBAL_DAILY_BUDGET_EXHAUSTED
                        : AiCostControlException::GLOBAL_MONTHLY_BUDGET_EXHAUSTED,
                ];
            }
        }

        if ($customer instanceof Customer) {
            foreach ([AiOperationalBudgetPeriod::WINDOW_DAILY, AiOperationalBudgetPeriod::WINDOW_MONTHLY] as $window) {
                $limit = $this->customerLimit($customer->id, $window);

                if ($limit !== null) {
                    $scopes[] = [
                        'scope' => AiOperationalBudgetPeriod::SCOPE_CUSTOMER,
                        'customer_id' => (int) $customer->id,
                        'window' => $window,
                        'limit' => $limit,
                        'reason' => $window === AiOperationalBudgetPeriod::WINDOW_DAILY
                            ? AiCostControlException::CUSTOMER_DAILY_BUDGET_EXHAUSTED
                            : AiCostControlException::CUSTOMER_MONTHLY_BUDGET_EXHAUSTED,
                    ];
                }
            }
        }

        return $scopes;
    }

    public function globalLimit(string $window): ?float
    {
        $control = AiRuntimeControl::query()->orderBy('id')->first();

        if (! $control instanceof AiRuntimeControl || ! $control->operational_budget_enabled) {
            return null;
        }

        $value = $window === AiOperationalBudgetPeriod::WINDOW_DAILY
            ? $control->global_daily_nok_limit
            : $control->global_monthly_nok_limit;

        return $value === null ? null : max(0.0, (float) $value);
    }

    public function customerLimit(?int $customerId, string $window): ?float
    {
        if ($customerId === null) {
            return null;
        }

        $limit = CustomerAiOperationalLimit::query()->where('customer_id', $customerId)->first();

        if (! $limit instanceof CustomerAiOperationalLimit || ! $limit->is_enabled) {
            return null;
        }

        $value = $window === AiOperationalBudgetPeriod::WINDOW_DAILY
            ? $limit->daily_nok_limit
            : $limit->monthly_nok_limit;

        return $value === null ? null : max(0.0, (float) $value);
    }

    private function lockPeriod(string $scope, ?int $customerId, string $window, CarbonImmutable $at): AiOperationalBudgetPeriod
    {
        [$start, $end] = $this->windowBounds($window, $at);

        DB::table('ai_operational_budget_periods')->insertOrIgnore([
            'scope' => $scope, 'customer_id' => $customerId, 'window' => $window,
            'period_start' => $start, 'period_end' => $end,
            'committed_nok' => 0, 'reserved_nok' => 0, 'unknown_cost_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return AiOperationalBudgetPeriod::query()
            ->where(['scope' => $scope, 'customer_id' => $customerId, 'window' => $window, 'period_start' => $start])
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array{0: string, 1: string} */
    private function windowBounds(string $window, CarbonImmutable $at): array
    {
        return $window === AiOperationalBudgetPeriod::WINDOW_DAILY
            ? [$at->toDateString(), $at->toDateString()]
            : [$at->startOfMonth()->toDateString(), $at->endOfMonth()->toDateString()];
    }

    public function warningPercent(): int
    {
        return max(1, min(99, (int) config('procynia.ai.operational_budget.warning_percent', 80)));
    }

    public function criticalPercent(): int
    {
        return max($this->warningPercent(), min(99, (int) config('procynia.ai.operational_budget.critical_percent', 90)));
    }

    public function logBlocked(string $reason, ?int $customerId, ?string $operation): void
    {
        Log::warning('[AI_OPERATIONAL_BUDGET] Provider call refused by an operational safety budget.', [
            'reason' => $reason,
            'customer_id' => $customerId,
            'operation' => $operation,
        ]);
    }
}
