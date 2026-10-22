<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use Illuminate\Support\Str;

/**
 * A person allowed to write a customer's Kvalitet, for tests that only need a flow or a control to
 * exist as setup.
 *
 * The Kvalitet services check their actor themselves since phase 0 step 12: the key in the
 * customer, with the Kvalitet module. The customer's System Owner holds every Kvalitet key without
 * a role, so that is who writes the fixture — created when the customer has none. A customer
 * without a package carrying Kvalitet is given Basis, which does.
 */
trait AuthorsQualityFlows
{
    protected function qualitySystemOwner(int $customerId): User
    {
        $entitlements = app(ModuleEntitlementService::class);
        $customer = Customer::query()->findOrFail($customerId);

        if (! $entitlements->hasModule($customer, 'quality')) {
            $entitlements->activatePackage($customer, 'basis');
        }

        return User::query()
            ->where('customer_id', $customerId)
            ->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)
            ->where('is_active', true)
            ->orderBy('id')
            ->first()
            ?? User::query()->create([
                'name' => 'Kvalitetseier '.Str::upper(Str::random(4)),
                'email' => 'kvalitet-'.Str::lower(Str::random(10)).'@procynia.local',
                'password' => bcrypt('secret-password'),
                'role' => User::ROLE_CUSTOMER_ADMIN,
                'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
                'customer_id' => $customerId,
                'is_active' => true,
            ]);
    }
}
