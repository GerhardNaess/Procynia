<?php

namespace App\Services\Billing;

use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\User;

class BillingEntitlementService
{
    public function customerHasFeature(Customer $customer, string $featureKey): bool
    {
        if (in_array($featureKey, $customer->planConfig()['features'] ?? [], true)) {
            return true;
        }

        return $customer->billingLines()
            ->whereIn('status', ['active', 'pending_cancel'])
            ->with('billingPrice.product')
            ->get()
            ->contains(function ($line) use ($featureKey): bool {
                $metadataFeatures = data_get($line->billingPrice?->product?->metadata, 'features', []);

                return in_array($featureKey, $metadataFeatures, true);
            });
    }

    public function userHasServiceLevel(User $user, string $productKey, ?string $levelKey = null): bool
    {
        if (! $user->customer) {
            return false;
        }

        return $user->customer->userServiceLevels()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with('billingPrice.product')
            ->get()
            ->contains(function ($level) use ($productKey, $levelKey): bool {
                if (($level->billingPrice?->product?->key ?? null) !== $productKey) {
                    return false;
                }

                return $levelKey === null || $level->level_key === $levelKey;
            });
    }

    public function includedUsers(Customer $customer): int
    {
        if ($customer->included_users !== null) {
            return (int) $customer->included_users;
        }

        return (int) ($customer->planConfig()['included_users'] ?? 1);
    }

    public function includedAiCredits(Customer $customer): int
    {
        // Priority 1: active base-plan billing line with included_ai_offers configured (> 0).
        // A value of 0 is treated as "not configured" because the column defaults to 0 in the
        // schema — there is no way to distinguish a default 0 from an explicit 0 without a
        // nullable column (planned in a future migration).
        $line = $customer->billingLines()
            ->whereIn('status', ['active', 'pending_cancel'])
            ->whereHas('billingPrice.product', fn ($q) => $q->where('category', BillingProduct::CATEGORY_BASE_PLAN))
            ->with('billingPrice')
            ->first();

        if ($line !== null && ($line->billingPrice?->included_ai_offers ?? 0) > 0) {
            return (int) $line->billingPrice->included_ai_offers;
        }

        // Priority 2: customer snapshot written by BillingService::syncPlanMeta.
        if ($customer->included_ai_credits !== null) {
            return (int) $customer->included_ai_credits;
        }

        // Priority 3: plan config fallback for customers without a snapshot.
        return (int) ($customer->planConfig()['included_ai_credits'] ?? 0);
    }

    public function currentBillableUsers(Customer $customer): int
    {
        return $customer->users()
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->count();
    }

    public function canUseFeature(Customer $customer, string $featureKey): bool
    {
        return $this->customerHasFeature($customer, $featureKey);
    }

    public function canAddUser(Customer $customer): bool
    {
        $included = $this->includedUsers($customer);

        if ($included <= 0) {
            return true;
        }

        return $this->currentBillableUsers($customer) < $included;
    }

    public function canUseAiOffer(Customer $customer): bool
    {
        return $this->includedAiCredits($customer) > 0 || $this->canUseFeature($customer, 'ai_offer');
    }
}
