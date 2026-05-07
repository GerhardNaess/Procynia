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
        if ($customer->included_ai_credits !== null) {
            return (int) $customer->included_ai_credits;
        }

        return (int) ($customer->planConfig()['included_ai_credits'] ?? 0);
    }

    public function currentBillableUsers(Customer $customer): int
    {
        return $customer->users()
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->count();
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
        return $this->includedAiCredits($customer) > 0 || $this->customerHasFeature($customer, 'ai_offer');
    }
}
