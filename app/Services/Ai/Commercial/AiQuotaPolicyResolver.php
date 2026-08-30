<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\AiQuotaPolicy;
use App\Models\Customer;
use App\Services\Billing\BillingEntitlementService;

class AiQuotaPolicyResolver
{
    public function __construct(private readonly BillingEntitlementService $entitlements) {}

    public function resolve(Customer $customer): AiQuotaPolicy
    {
        if (! $this->entitlements->canUseAiOffer($customer)) {
            return new AiQuotaPolicy(AiQuotaPolicy::NONE);
        }

        // A plan config with null credits explicitly declares unlimited access. A feature-only
        // entitlement is likewise unlimited until a priced finite allowance is configured.
        if (($customer->planConfig()['included_ai_credits'] ?? null) === null
            || ($this->entitlements->includedAiCredits($customer) <= 0 && $this->entitlements->canUseFeature($customer, 'ai_offer'))) {
            return new AiQuotaPolicy(AiQuotaPolicy::UNLIMITED);
        }

        return new AiQuotaPolicy(AiQuotaPolicy::FINITE, max(0, $this->entitlements->includedAiCredits($customer)));
    }
}
