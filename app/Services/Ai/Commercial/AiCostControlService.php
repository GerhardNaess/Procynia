<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\AiCostControlDecision;
use App\Data\Ai\AiQuotaPolicy;
use App\Data\Ai\Operational\AiBudgetReservation;
use App\Data\Ai\Operational\AiCostState;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\AiOperationalBudgetPeriod;
use App\Models\AiUsageAttempt;
use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiQuotaPeriod;
use App\Models\CustomerAiUsageReservation;
use App\Services\Ai\Operational\AiOperationalAlertService;
use App\Services\Ai\Operational\AiOperationalBudgetService;
use App\Services\Ai\Operational\AiOperationalPricingService;
use App\Services\Ai\Operational\AiPaymentPolicyService;
use App\Support\Ai\AiCallContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Canonical server-side AI guard: entitlement, runtime stops and finite-case reservations. */
class AiCostControlService
{
    public function __construct(
        private readonly AiQuotaPolicyResolver $quotaPolicies,
        private readonly AiRuntimeControlService $runtimeControls,
        private readonly AiQuotaNotificationService $quotaNotifications,
        private readonly AiOperationalPricingService $pricing,
        private readonly AiOperationalBudgetService $budgets,
        private readonly AiPaymentPolicyService $paymentPolicy,
        private readonly AiOperationalAlertService $operationalAlerts,
    ) {}

    /**
     * Decide whether one imminent provider call may proceed, in strict precedence order.
     *
     * Platform safety first, then the customer's own situation, then money. The order matters: a
     * platform-wide stop must not be reachable by any customer-level argument, and an operational
     * NOK ceiling must be able to stop a customer whose commercial plan says unlimited.
     */
    public function authorize(AiCallContext $context): AiCostControlDecision
    {
        if ($this->runtimeControls->globalStopEnabled()) {
            throw new AiCostControlException(AiCostControlException::GLOBAL_STOP);
        }

        // Pricing is a platform concern: a model Procynia cannot cost would spend money invisibly,
        // so it is refused before any customer-level consideration.
        $this->assertModelIsPriceable($context);

        $customer = ($context->customerId ?? 0) > 0
            ? Customer::query()->find($context->customerId)
            : null;

        $budgetReservation = $this->reserveOperationalBudget($context, $customer);

        if (! $customer instanceof Customer) {
            Log::notice('[AI_COST_CONTROL] Provider call has no customer context; treating it as explicit system work.', [
                'operation' => $context->operation, 'feature' => $context->feature,
            ]);

            return (new AiCostControlDecision($context, AiQuotaPolicy::UNLIMITED, null, 0, null, null, null, null, 'normal'))
                ->withBudgetReservation($budgetReservation);
        }

        try {
            return $this->authorizeCustomer($context, $customer)->withBudgetReservation($budgetReservation);
        } catch (Throwable $exception) {
            // A refusal after the NOK hold was taken must give the money back, or a blocked
            // customer would slowly consume the platform budget by being blocked.
            $this->budgets->release($budgetReservation);

            throw $exception;
        }
    }

    private function authorizeCustomer(AiCallContext $context, Customer $customer): AiCostControlDecision
    {
        $this->assertPaymentStateAllows($context, $customer);

        return DB::transaction(function () use ($context): AiCostControlDecision {
            $customer = Customer::query()->lockForUpdate()->findOrFail($context->customerId);
            if (($customer->ai_access_status ?? Customer::AI_ACCESS_ENABLED) === Customer::AI_ACCESS_SUSPENDED) {
                $this->assertOverrideMayBypass($context, AiCostControlException::CUSTOMER_SUSPENDED, $customer);
            }

            $policy = $this->quotaPolicies->resolve($customer);
            if ($policy->type === AiQuotaPolicy::NONE) {
                $this->assertOverrideMayBypass($context, AiCostControlException::NOT_INCLUDED, $customer);

                // Nothing commercial left to meter once entitlement itself was overridden.
                return new AiCostControlDecision($context, AiQuotaPolicy::NONE, null, 0, 0, 0, null, null, 'exhausted');
            }

            if (! $context->commercialCredit || ($context->savedNoticeId ?? 0) <= 0) {
                return new AiCostControlDecision($context, $policy->type, null, 0, $policy->type === AiQuotaPolicy::FINITE ? $policy->includedCredits : null, null, null, null, 'normal');
            }

            $now = CarbonImmutable::now(config('app.timezone') ?: 'UTC');
            $periodStart = $now->startOfMonth()->toDateString();
            $periodEnd = $now->endOfMonth()->toDateString();
            DB::table('customer_ai_quota_periods')->insertOrIgnore([
                'customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'extra_credits' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $period = CustomerAiQuotaPeriod::query()->where([
                'customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd,
            ])->lockForUpdate()->firstOrFail();

            // A credit is a SavedNotice, not a provider call. Occupancy is therefore counted as
            // distinct cases: one case that fans out into several parallel provider calls holds
            // exactly one slot, and must never exhaust the plan against itself.
            $committedNoticeIds = CustomerAiCaseUsage::query()
                ->where(['customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd])
                ->pluck('saved_notice_id')->map(fn ($id): int => (int) $id)->all();
            $used = in_array((int) $context->savedNoticeId, $committedNoticeIds, true);
            $committed = count($committedNoticeIds);
            // An administrative withdrawal can push the net below the plan; the allowance floors at
            // zero so the comparison below stays meaningful.
            $included = $policy->type === AiQuotaPolicy::FINITE
                ? max(0, $policy->includedCredits + (int) $period->extra_credits)
                : null;
            $remaining = $included !== null ? max(0, $included - $committed) : null;

            if ($used) {
                return new AiCostControlDecision($context, $policy->type, null, $committed, $included, $remaining, $periodStart, $periodEnd, $this->status($committed, $included));
            }

            if ($policy->type === AiQuotaPolicy::UNLIMITED) {
                return new AiCostControlDecision($context, $policy->type, null, $committed, null, null, $periodStart, $periodEnd, 'normal');
            }

            $reservedNoticeIds = CustomerAiUsageReservation::query()->where([
                'customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd,
            ])->whereIn('status', [CustomerAiUsageReservation::STATUS_RESERVED, CustomerAiUsageReservation::STATUS_UNCERTAIN])
                ->where('saved_notice_id', '!=', $context->savedNoticeId)
                ->pluck('saved_notice_id')->map(fn ($id): int => (int) $id)->all();
            $occupied = count(array_unique(array_merge($committedNoticeIds, $reservedNoticeIds)));

            if ($occupied + 1 > $included) {
                // An override still takes a reservation and still commits the credit: the ledger
                // must record that the work happened, even when the allowance was exceeded.
                $this->assertOverrideMayBypass($context, AiCostControlException::QUOTA_EXHAUSTED, $customer);
            }

            $reservation = CustomerAiUsageReservation::query()->create([
                'customer_id' => $customer->id, 'saved_notice_id' => $context->savedNoticeId,
                'period_start' => $periodStart, 'period_end' => $periodEnd,
                'operation' => Str::limit($context->operation ?: 'saved_notice.ai', 100, ''),
                'correlation_key' => Str::limit($context->requestCorrelationId ?: (string) Str::uuid(), 128, ''),
                'status' => CustomerAiUsageReservation::STATUS_RESERVED, 'reserved_at' => now(),
            ]);

            return new AiCostControlDecision($context, $policy->type, $reservation->id, $committed, $included, max(0, $included - $occupied - 1), $periodStart, $periodEnd, $this->status($occupied + 1, $included));
        });
    }

    /**
     * Refuse a call whose model has no usable price.
     *
     * Letting it through would spend real money that Procynia could only record as zero, which is
     * exactly the blind spot every other guard here exists to prevent. An operator can override it
     * for a recovery run — the spend is then recorded as unknown, not as free.
     */
    private function assertModelIsPriceable(AiCallContext $context): void
    {
        $model = trim((string) ($context->model ?? ''));

        if ($model === '') {
            return;
        }

        if (! $this->pricing->catalogueIsConfigured()) {
            $this->operationalAlerts->reportPriceCatalogueEmpty();

            return;
        }

        $provider = (string) ($context->provider ?? config('services.openai.provider_key', 'openai'));
        $priceState = $this->pricing->priceState(
            $provider,
            $model,
            config('services.openai.deployment_name'),
            config('services.openai.provider_region'),
        );

        if (! $priceState->isMissing()) {
            $this->operationalAlerts->reportPriceState($provider, $model, $priceState);

            return;
        }

        $this->operationalAlerts->reportMissingModelPrice($provider, $model, $context);

        if ($this->overrideIsComplete($context)) {
            $this->recordOverride($context, AiCostControlException::MODEL_PRICE_UNKNOWN, $context->customerId);

            return;
        }

        throw new AiCostControlException(AiCostControlException::MODEL_PRICE_UNKNOWN);
    }

    /**
     * Hold a conservative NOK estimate against every safety budget this call touches.
     *
     * Platform budgets are never overridable: they are the ceiling that protects Procynia from its
     * own automation, and an operator flag that could lift them would defeat the purpose. Customer
     * budgets are equally non-overridable in v1 — raising the limit in admin is the deliberate act.
     */
    private function reserveOperationalBudget(AiCallContext $context, ?Customer $customer): AiBudgetReservation
    {
        $model = trim((string) ($context->model ?? ''));

        if ($model === '') {
            return AiBudgetReservation::none();
        }

        $estimate = $this->pricing->estimateMaxCostNok(
            (string) ($context->provider ?? config('services.openai.provider_key', 'openai')),
            $model,
            config('services.openai.deployment_name'),
            config('services.openai.provider_region'),
            $context->operation,
        );

        if ($estimate === null) {
            // Only reachable when an operator overrode the unknown-price stop above. The call is
            // allowed but cannot be reserved against a budget it cannot be priced for.
            return AiBudgetReservation::none();
        }

        try {
            return $this->budgets->reserve($customer, $estimate);
        } catch (AiCostControlException $exception) {
            $this->budgets->logBlocked($exception->reason, $customer?->id, $context->operation);
            $this->operationalAlerts->reportBudgetBlocked($exception->reason, $customer);

            throw $exception;
        }
    }

    /**
     * Payment state is read now, not when the work was queued: a job dispatched before a card
     * failed must not still be able to spend money after it.
     */
    private function assertPaymentStateAllows(AiCallContext $context, Customer $customer): void
    {
        $evaluation = $this->paymentPolicy->evaluate($customer);

        if ($evaluation['decision'] !== AiPaymentPolicyService::BLOCK) {
            if ($evaluation['decision'] === AiPaymentPolicyService::GRACE) {
                $this->operationalAlerts->reportPaymentGrace($customer, $evaluation);
            }

            return;
        }

        $this->operationalAlerts->reportPaymentBlocked($customer, $evaluation);

        // A recovery run may need to finish work for a customer who has stopped paying; a normal
        // customer request may not.
        $this->assertOverrideMayBypass($context, (string) $evaluation['reason'], $customer);
    }

    private function overrideIsComplete(AiCallContext $context): bool
    {
        return $context->operatorOverride
            && ($context->operatorActorUserId ?? 0) > 0
            && trim((string) $context->operatorOverrideReason) !== '';
    }

    /**
     * A commercial guard just refused this call. Either an authorised operator override is present
     * — in which case the bypass is audited and the call proceeds — or the block stands.
     *
     * The global emergency stop never reaches this method: it is evaluated before any customer
     * lookup and has no override path at all, which is the whole point of an emergency stop.
     */
    private function assertOverrideMayBypass(AiCallContext $context, string $reason, Customer $customer): void
    {
        // Belt and braces: a platform safety stop has no override path, wherever it is raised from.
        if (in_array($reason, AiCostControlException::PLATFORM_REASONS, true)) {
            throw new AiCostControlException($reason);
        }

        if (! $this->overrideIsComplete($context)) {
            throw new AiCostControlException($reason);
        }

        $this->recordOverride($context, $reason, $customer);
    }

    private function recordOverride(AiCallContext $context, string $bypassedReason, Customer|int|null $customer): void
    {
        $customerId = $customer instanceof Customer ? (int) $customer->id : $customer;

        Log::warning('[AI_COST_CONTROL] Commercial guard bypassed by an operator override.', [
            'customer_id' => $customerId,
            'bypassed' => $bypassedReason,
            'operation' => $context->operation,
            'actor_user_id' => $context->operatorActorUserId,
        ]);

        // Written inside the authorising transaction on purpose: a bypass that is not recorded
        // did not happen as far as the audit trail is concerned.
        rescue(fn () => BillingEvent::query()->create([
            'customer_id' => $customerId,
            'user_id' => $context->operatorActorUserId,
            'event_type' => 'ai_operator_override_used',
            'source' => 'ai_cost_control',
            'description' => $context->operatorOverrideReason,
            'before' => ['blocked_by' => $bypassedReason],
            'after' => [
                'operation' => $context->operation,
                'feature' => $context->feature,
                'resource_type' => $context->resourceType,
                'resource_id' => $context->resourceId,
            ],
        ]), null, false);
    }

    /**
     * The call succeeded. Settle both holds: the commercial credit and the NOK reservation.
     *
     * The NOK figure is settled from the attempt's own cost snapshot when the meter managed to
     * write one; otherwise the conservative reservation stands, because a call whose cost cannot
     * be established still spent money.
     */
    public function finalize(AiCostControlDecision $decision): void
    {
        $this->settleBudget($decision);

        if ($decision->reservationId === null) {
            return;
        }

        $committedCustomerId = DB::transaction(function () use ($decision): ?int {
            $reservation = CustomerAiUsageReservation::query()->lockForUpdate()->find($decision->reservationId);
            if (! $reservation || $reservation->status !== CustomerAiUsageReservation::STATUS_RESERVED) {
                return null;
            }

            CustomerAiCaseUsage::query()->firstOrCreate([
                'customer_id' => $reservation->customer_id, 'saved_notice_id' => $reservation->saved_notice_id,
                'period_start' => $reservation->period_start, 'period_end' => $reservation->period_end,
            ], [
                'activated_at' => now(), 'activated_by_user_id' => $decision->context->userId,
                'source_operation_key' => $reservation->operation,
            ]);
            $reservation->forceFill(['status' => CustomerAiUsageReservation::STATUS_COMMITTED, 'finalized_at' => now()])->save();

            return (int) $reservation->customer_id;
        });

        // Committing a credit is the one true commercial state transition, so threshold evaluation
        // belongs here — after the transaction, so it reads committed state and holds no lock.
        if ($committedCustomerId !== null) {
            $customer = Customer::query()->find($committedCustomerId);

            if ($customer instanceof Customer) {
                $this->quotaNotifications->evaluate($customer);
            }
        }
    }

    public function fail(AiCostControlDecision $decision, Throwable $exception): void
    {
        $uncertain = $this->isUncertain($exception);

        $this->closeBudget($decision, $uncertain);
        $this->closeFailure($decision, $uncertain ? CustomerAiUsageReservation::STATUS_UNCERTAIN : CustomerAiUsageReservation::STATUS_RELEASED, $exception::class);
    }

    public function failHttp(AiCostControlDecision $decision, int $status): void
    {
        $uncertain = $status === 408 || $status >= 500;

        $this->closeBudget($decision, $uncertain);
        $this->closeFailure($decision, $uncertain ? CustomerAiUsageReservation::STATUS_UNCERTAIN : CustomerAiUsageReservation::STATUS_RELEASED, 'http_'.$status);
    }

    /**
     * Turn the estimated hold into settled spend, using the real cost when the attempt ledger
     * recorded one. Also evaluates the budget thresholds, once, after the money has moved.
     */
    private function settleBudget(AiCostControlDecision $decision): void
    {
        $reservation = $decision->budgetReservation;

        if ($reservation === null || $reservation->isEmpty()) {
            return;
        }

        $attempt = $this->latestAttemptFor($decision);
        $costStatus = $attempt?->cost_status;
        $actualNok = $attempt?->cost_nok === null ? null : (float) $attempt->cost_nok;

        $this->budgets->commit(
            $reservation,
            $actualNok,
            costUnknown: $costStatus === AiCostState::UNKNOWN || $actualNok === null,
        );

        $this->evaluateBudgetThresholds($decision);
    }

    /**
     * A failure that certainly did no work gives the money back; a timeout or a 5xx does not.
     * Releasing an uncertain call would let a provider that did charge us look free.
     */
    private function closeBudget(AiCostControlDecision $decision, bool $uncertain): void
    {
        $reservation = $decision->budgetReservation;

        if ($reservation === null || $reservation->isEmpty()) {
            return;
        }

        if ($uncertain) {
            $this->budgets->markUncertain($reservation);
            $this->evaluateBudgetThresholds($decision);

            return;
        }

        $this->budgets->release($reservation);
    }

    private function latestAttemptFor(AiCostControlDecision $decision): ?AiUsageAttempt
    {
        $attemptId = app(AiCallContextScope::class)->latestAttemptId();

        return $attemptId === null ? null : AiUsageAttempt::query()->find($attemptId);
    }

    /** Internal-only warnings; the customer never sees a NOK safety budget in this phase. */
    private function evaluateBudgetThresholds(AiCostControlDecision $decision): void
    {
        rescue(function () use ($decision): void {
            $customer = ($decision->context->customerId ?? 0) > 0
                ? Customer::query()->find($decision->context->customerId)
                : null;

            foreach ([
                [AiOperationalBudgetPeriod::SCOPE_GLOBAL, null],
                [AiOperationalBudgetPeriod::SCOPE_CUSTOMER, $customer?->id],
            ] as [$scope, $customerId]) {
                if ($scope === AiOperationalBudgetPeriod::SCOPE_CUSTOMER && $customerId === null) {
                    continue;
                }

                foreach ([AiOperationalBudgetPeriod::WINDOW_DAILY, AiOperationalBudgetPeriod::WINDOW_MONTHLY] as $window) {
                    $status = $this->budgets->status($scope, $customerId, $window);
                    $percentage = $status['percentage'];

                    if ($status['limit'] === null || $percentage === null || $percentage < $this->budgets->warningPercent()) {
                        continue;
                    }

                    $this->operationalAlerts->reportBudgetThreshold(
                        $scope,
                        $scope === AiOperationalBudgetPeriod::SCOPE_CUSTOMER ? $customer : null,
                        $window,
                        $percentage,
                        (float) $status['limit'],
                    );
                }
            }
        }, null, false);
    }

    private function closeFailure(AiCostControlDecision $decision, string $status, string $reason): void
    {
        if ($decision->reservationId === null) {
            return;
        }
        CustomerAiUsageReservation::query()->whereKey($decision->reservationId)->where('status', CustomerAiUsageReservation::STATUS_RESERVED)->update([
            'status' => $status, 'failure_reason' => Str::limit($reason, 100, ''),
            $status === CustomerAiUsageReservation::STATUS_UNCERTAIN ? 'finalized_at' : 'released_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isUncertain(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException || str_contains(mb_strtolower($exception->getMessage(), 'UTF-8'), 'timed out');
    }

    private function status(int $used, ?int $included): string
    {
        if ($included === null || $included <= 0) {
            return 'normal';
        }
        $ratio = $used / $included;

        return $ratio >= 1 ? 'exhausted' : ($ratio >= .9 ? 'critical' : ($ratio >= .8 ? 'warning' : 'normal'));
    }
}
