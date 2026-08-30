<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\AiCostControlDecision;
use App\Data\Ai\AiQuotaPolicy;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiQuotaPeriod;
use App\Models\CustomerAiUsageReservation;
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
    ) {}

    public function authorize(AiCallContext $context): AiCostControlDecision
    {
        if ($this->runtimeControls->globalStopEnabled()) {
            throw new AiCostControlException(AiCostControlException::GLOBAL_STOP);
        }

        if (($context->customerId ?? 0) <= 0) {
            Log::notice('[AI_COST_CONTROL] Provider call has no customer context; treating it as explicit system work.', [
                'operation' => $context->operation, 'feature' => $context->feature,
            ]);

            return new AiCostControlDecision($context, AiQuotaPolicy::UNLIMITED, null, 0, null, null, null, null, 'normal');
        }

        return DB::transaction(function () use ($context): AiCostControlDecision {
            $customer = Customer::query()->lockForUpdate()->findOrFail($context->customerId);
            if (($customer->ai_access_status ?? Customer::AI_ACCESS_ENABLED) === Customer::AI_ACCESS_SUSPENDED) {
                throw new AiCostControlException(AiCostControlException::CUSTOMER_SUSPENDED);
            }

            $policy = $this->quotaPolicies->resolve($customer);
            if ($policy->type === AiQuotaPolicy::NONE) {
                throw new AiCostControlException(AiCostControlException::NOT_INCLUDED);
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
            $included = $policy->type === AiQuotaPolicy::FINITE ? $policy->includedCredits + (int) $period->extra_credits : null;
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
                throw new AiCostControlException(AiCostControlException::QUOTA_EXHAUSTED);
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

    public function finalize(AiCostControlDecision $decision): void
    {
        if ($decision->reservationId === null) {
            return;
        }

        DB::transaction(function () use ($decision): void {
            $reservation = CustomerAiUsageReservation::query()->lockForUpdate()->find($decision->reservationId);
            if (! $reservation || $reservation->status !== CustomerAiUsageReservation::STATUS_RESERVED) {
                return;
            }

            CustomerAiCaseUsage::query()->firstOrCreate([
                'customer_id' => $reservation->customer_id, 'saved_notice_id' => $reservation->saved_notice_id,
                'period_start' => $reservation->period_start, 'period_end' => $reservation->period_end,
            ], [
                'activated_at' => now(), 'activated_by_user_id' => $decision->context->userId,
                'source_operation_key' => $reservation->operation,
            ]);
            $reservation->forceFill(['status' => CustomerAiUsageReservation::STATUS_COMMITTED, 'finalized_at' => now()])->save();
        });
    }

    public function fail(AiCostControlDecision $decision, Throwable $exception): void
    {
        $this->closeFailure($decision, $this->isUncertain($exception) ? CustomerAiUsageReservation::STATUS_UNCERTAIN : CustomerAiUsageReservation::STATUS_RELEASED, $exception::class);
    }

    public function failHttp(AiCostControlDecision $decision, int $status): void
    {
        $uncertain = $status === 408 || $status >= 500;
        $this->closeFailure($decision, $uncertain ? CustomerAiUsageReservation::STATUS_UNCERTAIN : CustomerAiUsageReservation::STATUS_RELEASED, 'http_'.$status);
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
