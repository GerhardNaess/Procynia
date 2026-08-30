<?php

namespace App\Services\Ai\Operational;

use App\Exceptions\Ai\AiCostControlException;
use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * What a customer's payment state means for their AI access.
 *
 * Read from Cashier's own subscription rows immediately before a provider call, not from a
 * snapshot taken when work was queued and not from the webhook alone: the webhook keeps the state
 * current, but enforcement has to consult the current state itself or a job dispatched before a
 * payment failed would still spend money afterwards.
 */
class AiPaymentPolicyService
{
    public const ALLOW = 'allow';
    public const GRACE = 'grace';
    public const BLOCK = 'block';

    /**
     * @return array{decision: string, state: string, reason: string|null, grace_ends_at: string|null}
     */
    public function evaluate(Customer $customer, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now(config('app.timezone') ?: 'UTC');
        $subscription = $this->currentSubscription($customer);

        // No Stripe link at all is the ordinary case for locally-provisioned customers, including
        // Enterprise contracts. Their access is decided by plan and entitlement, exactly as before.
        if ($subscription === null) {
            return $this->result(self::ALLOW, 'none', null, null);
        }

        $state = (string) ($subscription->stripe_status ?? '');

        return match ($state) {
            'active', 'trialing' => $this->result(self::ALLOW, $state, null, null),
            'past_due' => $this->evaluatePastDue($subscription, $state, $at),
            'unpaid' => $this->result(self::BLOCK, $state, AiCostControlException::PAYMENT_UNPAID, null),
            'incomplete', 'incomplete_expired' => $this->result(self::BLOCK, $state, AiCostControlException::PAYMENT_INCOMPLETE, null),

            // A cancelled subscription is not a payment failure: the customer simply falls back to
            // whatever their local plan entitles them to, which the entitlement guard already owns.
            'canceled', 'ended' => $this->result(self::ALLOW, $state, null, null),
            default => $this->result(self::ALLOW, $state === '' ? 'unknown' : $state, null, null),
        };
    }

    /**
     * A failed payment gets a grace window measured from when the subscription actually entered
     * past_due, not from "now minus seven days" — otherwise the clock would restart on every call
     * and the grace period would never end.
     */
    private function evaluatePastDue(object $subscription, string $state, CarbonImmutable $at): array
    {
        $graceDays = max(0, (int) config('procynia.ai.payment.past_due_grace_days', 7));
        $enteredAt = $subscription->updated_at ?? $subscription->created_at;

        if ($enteredAt === null) {
            // Fail-safe: without a timestamp we cannot prove the grace period has expired, and
            // cutting off a paying customer on a missing field is the worse error.
            return $this->result(self::GRACE, $state, null, null);
        }

        $graceEnds = CarbonImmutable::parse($enteredAt)->addDays($graceDays);

        return $graceEnds->greaterThan($at)
            ? $this->result(self::GRACE, $state, null, $graceEnds->toDateTimeString())
            : $this->result(self::BLOCK, $state, AiCostControlException::PAYMENT_UNPAID, $graceEnds->toDateTimeString());
    }

    private function currentSubscription(Customer $customer): ?object
    {
        return rescue(
            fn () => $customer->subscriptions()
                ->orderByDesc('id')
                ->first(),
            null,
            false,
        );
    }

    /** @return array{decision: string, state: string, reason: string|null, grace_ends_at: string|null} */
    private function result(string $decision, string $state, ?string $reason, ?string $graceEndsAt): array
    {
        return ['decision' => $decision, 'state' => $state, 'reason' => $reason, 'grace_ends_at' => $graceEndsAt];
    }
}
