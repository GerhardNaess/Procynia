<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\AiQuotaPolicy;
use App\Data\Ai\AiQuotaStatus;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiQuotaPeriod;
use App\Models\CustomerAiUsageReservation;
use Carbon\CarbonImmutable;

/**
 * The single place the commercial AI quota is turned into something a customer can read.
 *
 * It counts the same thing the hard stop counts — distinct AI-activated SavedNotices in the
 * calendar period, against included plus administratively granted credits — so the number a
 * customer sees can never disagree with the number that blocks them. Provider-call volume
 * (`ai_usage_events`, `ai_usage_attempts`) is operational telemetry and is deliberately not part
 * of this calculation.
 */
class AiQuotaStatusService
{
    public function __construct(private readonly AiQuotaPolicyResolver $quotaPolicies) {}

    public function forCustomer(Customer $customer, ?CarbonImmutable $at = null): AiQuotaStatus
    {
        $at ??= CarbonImmutable::now(config('app.timezone') ?: 'UTC');
        $periodStart = $at->startOfMonth()->toDateString();
        $periodEnd = $at->endOfMonth()->toDateString();

        $policy = $this->quotaPolicies->resolve($customer);
        $extra = (int) (CustomerAiQuotaPeriod::query()
            ->where(['customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd])
            ->value('extra_credits') ?? 0);

        $usedNoticeIds = CustomerAiCaseUsage::query()
            ->where(['customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd])
            ->pluck('saved_notice_id')->map(fn ($id): int => (int) $id)->all();
        $used = count(array_unique($usedNoticeIds));

        // A case whose first provider call is in flight already owns its credit. Leaving it out
        // would show "1 left" to a customer whose last credit a running job has already taken.
        $reservedNoticeIds = CustomerAiUsageReservation::query()
            ->where(['customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd])
            ->whereIn('status', [CustomerAiUsageReservation::STATUS_RESERVED, CustomerAiUsageReservation::STATUS_UNCERTAIN])
            ->pluck('saved_notice_id')->map(fn ($id): int => (int) $id)->all();
        $reserved = count(array_diff(array_unique($reservedNoticeIds), $usedNoticeIds));

        $isSuspended = ($customer->ai_access_status ?? Customer::AI_ACCESS_ENABLED) === Customer::AI_ACCESS_SUSPENDED;

        $status = match ($policy->type) {
            AiQuotaPolicy::UNLIMITED => new AiQuotaStatus(
                customerId: (int) $customer->id,
                quotaType: AiQuotaPolicy::UNLIMITED,
                included: 0, extra: $extra, used: $used, reserved: $reserved, remaining: null,
                periodStart: $periodStart, periodEnd: $periodEnd, percentageUsed: null,
                status: AiQuotaStatus::STATUS_NORMAL, isUnlimited: true, isSuspended: false,
            ),
            AiQuotaPolicy::NONE => new AiQuotaStatus(
                customerId: (int) $customer->id,
                quotaType: AiQuotaPolicy::NONE,
                included: 0, extra: 0, used: $used, reserved: $reserved, remaining: 0,
                periodStart: $periodStart, periodEnd: $periodEnd, percentageUsed: null,
                status: AiQuotaStatus::STATUS_NORMAL, isUnlimited: false, isSuspended: false,
            ),
            default => $this->finiteStatus($customer, $policy, $extra, $used, $reserved, $periodStart, $periodEnd),
        };

        return $isSuspended ? AiQuotaStatus::suspendedFor($customer, $status) : $status;
    }

    private function finiteStatus(
        Customer $customer,
        AiQuotaPolicy $policy,
        int $extra,
        int $used,
        int $reserved,
        string $periodStart,
        string $periodEnd,
    ): AiQuotaStatus {
        $allowance = max(0, $policy->includedCredits + $extra);

        // A downgrade can leave used above allowance. The customer is told the truth about both
        // numbers, but never shown a negative remainder.
        $remaining = max(0, $allowance - $used - $reserved);
        $percentage = $allowance > 0 ? (int) min(100, round((($used + $reserved) / $allowance) * 100)) : 100;

        return new AiQuotaStatus(
            customerId: (int) $customer->id,
            quotaType: AiQuotaPolicy::FINITE,
            included: $policy->includedCredits,
            extra: $extra,
            used: $used,
            reserved: $reserved,
            remaining: $remaining,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            percentageUsed: $percentage,
            status: $this->statusForPercentage($percentage, $remaining),
            isUnlimited: false,
            isSuspended: false,
        );
    }

    private function statusForPercentage(int $percentage, int $remaining): string
    {
        if ($remaining <= 0 || $percentage >= 100) {
            return AiQuotaStatus::STATUS_EXHAUSTED;
        }

        if ($percentage >= $this->criticalPercent()) {
            return AiQuotaStatus::STATUS_CRITICAL;
        }

        return $percentage >= $this->warningPercent()
            ? AiQuotaStatus::STATUS_WARNING
            : AiQuotaStatus::STATUS_NORMAL;
    }

    public function warningPercent(): int
    {
        return max(1, min(99, (int) config('procynia.ai.quota.warning_percent', 80)));
    }

    public function criticalPercent(): int
    {
        return max($this->warningPercent(), min(99, (int) config('procynia.ai.quota.critical_percent', 90)));
    }
}
