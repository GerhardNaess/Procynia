<?php

namespace App\Support\Ai;

use App\Exceptions\Ai\AiCostControlException;
use App\Models\Customer;
use App\Services\Ai\Commercial\AiQuotaStatusService;

/**
 * Turns a hard-stop reason code into something a bid manager can act on.
 *
 * Each reason gets its own message, because they call for different actions: an entitlement gap is
 * a subscription decision, an exhausted quota resolves itself next period, a suspension needs
 * Procynia, and a global stop is nobody at the customer's problem. Collapsing them into one
 * "AI is unavailable" would leave the customer unable to tell which.
 */
class AiCostControlPresenter
{
    public function __construct(private readonly AiQuotaStatusService $quotaStatus) {}

    public function message(AiCostControlException $exception, ?Customer $customer = null): string
    {
        return match ($exception->reason) {
            AiCostControlException::QUOTA_EXHAUSTED => $this->quotaExhaustedMessage($customer),
            AiCostControlException::NOT_INCLUDED => __('procynia.ai_quota.hard_stop.not_included'),
            AiCostControlException::CUSTOMER_SUSPENDED => __('procynia.ai_quota.hard_stop.customer_suspended'),
            default => __('procynia.ai_quota.hard_stop.global_stop'),
        };
    }

    /**
     * The JSON shape for AI endpoints that answer with a payload rather than a redirect. The reason
     * code is safe to expose — it is a stable contract, not an internal exception message.
     *
     * @return array<string, mixed>
     */
    public function payload(AiCostControlException $exception, ?Customer $customer = null): array
    {
        $payload = [
            'reason' => $exception->reason,
            'error' => $this->message($exception, $customer),
        ];

        if ($exception->reason === AiCostControlException::QUOTA_EXHAUSTED && $customer instanceof Customer) {
            $payload['ai_quota'] = $this->quotaStatus->forCustomer($customer)->toArray();
        }

        return $payload;
    }

    private function quotaExhaustedMessage(?Customer $customer): string
    {
        if (! $customer instanceof Customer) {
            return __('procynia.ai_quota.hard_stop.quota_exhausted_generic');
        }

        $status = $this->quotaStatus->forCustomer($customer);

        // Phase 2's policy is that an already-activated case keeps working; only a new AI case is
        // refused. The wording has to say exactly that, or a customer mid-tender will believe
        // their in-progress work has just been switched off.
        return __('procynia.ai_quota.hard_stop.quota_exhausted', [
            'used' => $status->used + $status->reserved,
            'allowance' => $status->allowance(),
            'period_end' => $status->periodEnd,
        ]);
    }
}
