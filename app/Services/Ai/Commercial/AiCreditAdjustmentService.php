<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\AiQuotaStatus;
use App\Models\BillingEvent;
use App\Models\Customer;
use App\Models\CustomerAiCreditAdjustment;
use App\Models\CustomerAiQuotaPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way AI capacity is granted or corrected administratively.
 *
 * Every change is a new row, never an edit of an old one, so the reason a customer had 15 credits
 * in August stays answerable in December. `customer_ai_quota_periods.extra_credits` is rewritten
 * from the ledger sum in the same locked transaction, which keeps the authorisation hot path
 * unchanged while leaving exactly one source of truth.
 */
class AiCreditAdjustmentService
{
    public function __construct(
        private readonly AiQuotaStatusService $quotaStatus,
        private readonly AiQuotaNotificationService $quotaNotifications,
    ) {}

    public function adjust(Customer $customer, int $amount, string $reason, User $actor, ?CarbonImmutable $at = null): AiQuotaStatus
    {
        $reason = trim($reason);

        if ($amount === 0) {
            throw new InvalidArgumentException('A credit adjustment must change the capacity.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A credit adjustment requires a reason.');
        }

        $at ??= CarbonImmutable::now(config('app.timezone') ?: 'UTC');
        $periodStart = $at->startOfMonth()->toDateString();
        $periodEnd = $at->endOfMonth()->toDateString();

        $before = $this->quotaStatus->forCustomer($customer, $at);

        DB::transaction(function () use ($customer, $amount, $reason, $actor, $periodStart, $periodEnd, $before): void {
            DB::table('customer_ai_quota_periods')->insertOrIgnore([
                'customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'extra_credits' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // Same lock the reservation path takes, so an adjustment can never interleave with a
            // concurrent authorisation and leave the projection disagreeing with the ledger.
            $period = CustomerAiQuotaPeriod::query()
                ->where(['customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd])
                ->lockForUpdate()
                ->firstOrFail();

            CustomerAiCreditAdjustment::query()->create([
                'customer_id' => $customer->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount' => $amount,
                'reason' => mb_substr($reason, 0, 500),
                'actor_user_id' => $actor->id,
            ]);

            // Stored signed. A withdrawal has to be able to reduce the plan's own allowance, not
            // just an "extra" bucket — otherwise taking back capacity that was never granted as
            // extra would silently do nothing. The allowance itself is what gets clamped at zero.
            $total = (int) CustomerAiCreditAdjustment::query()
                ->where(['customer_id' => $customer->id, 'period_start' => $periodStart, 'period_end' => $periodEnd])
                ->sum('amount');

            $period->forceFill(['extra_credits' => $total])->save();

            BillingEvent::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'event_type' => 'ai_credits_adjusted',
                'source' => 'ai_cost_control',
                'description' => $reason,
                'before' => [
                    'extra_credits' => $before->extra,
                    'allowance' => $before->allowance(),
                    'remaining' => $before->remaining,
                ],
                'after' => [
                    'extra_credits' => $total,
                    'amount' => $amount,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ],
            ]);
        });

        $after = $this->quotaStatus->forCustomer($customer->fresh(), $at);

        $this->quotaNotifications->notifyCreditsAdjusted($customer->fresh(), $amount, $after);

        return $after;
    }

    /** @return Collection<int, CustomerAiCreditAdjustment> */
    public function historyFor(Customer $customer, int $limit = 20): Collection
    {
        return CustomerAiCreditAdjustment::query()
            ->where('customer_id', $customer->id)
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
