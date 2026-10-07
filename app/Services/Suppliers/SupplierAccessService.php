<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that answers "may this user do this?" in Leverandøroppfølging.
 *
 * Customer-wide in v1: a role that grants supplier.view will read every supplier of the user's own
 * customer; there is no fagområde. supplier.edit registers and changes suppliers and moves them
 * through their lifecycle; supplier.assess registers supplier assessments; supplier.delete removes
 * a supplier registered by mistake. Neither edit nor assess implies the other.
 *
 * SYSTEM OWNER, EXPLICIT GRANT.
 *
 * supplier is an explicit-grant domain (CustomerPermissionCatalog::explicitGrantDomains()), so
 * CustomerPermissionService gives System Owner none of these keys by virtue of the administrator
 * role, and nothing here makes an exception: System Owner reaches Leverandøroppfølging only through
 * a role of their own, like anyone else.
 *
 * Whether the customer holds the module at all is the route guard's (EnsureModuleIsEnabled, via
 * `app.supplier-management.` in config/procynia_modules.php).
 *
 * Every read on a user's behalf starts from visibleSuppliers(), which narrows the data set *before*
 * any lookup. A supplier of another customer, or any supplier for a user without supplier.view, is
 * absent — not listed, not counted, not searchable and 404 by URL, the same answer as an id that
 * does not exist.
 */
class SupplierAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /** Whether the user may open Leverandøroppfølging at all. */
    public function canOpenModule(?User $user): bool
    {
        return $user instanceof User
            && $user->customer_id !== null
            && $this->permissions->has($user, CustomerPermissionCatalog::SUPPLIER_VIEW);
    }

    /**
     * Whether a page in another module may show Leverandøroppfølging data — «Gjelder leverandør» on a
     * case: the customer holds the `supplier` module *and* the user has supplier.view. Such a page is
     * reached without this module's route guard, so the entitlement is checked here.
     */
    public function canReadFromAnotherModule(?User $user): bool
    {
        $customer = $user?->customer;

        return $customer !== null
            && $this->canOpenModule($user)
            && $this->entitlements->hasModule($customer, 'supplier');
    }

    /** Registering and changing suppliers, their criticality and documentation, and their lifecycle. */
    public function canEdit(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::SUPPLIER_EDIT);
    }

    /**
     * Registering a supplier assessment. supplier.edit alone is not enough: keeping the register and
     * judging how a supplier performs are separate responsibilities, as with risk.assess.
     */
    public function canAssess(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::SUPPLIER_ASSESS);
    }

    /** Deleting a supplier registered by mistake. */
    public function canDelete(User $user): bool
    {
        return $this->canOpenModule($user) && $this->permissions->has($user, CustomerPermissionCatalog::SUPPLIER_DELETE);
    }

    /**
     * Every supplier the user may read, and nothing else. The only correct starting point for any
     * list, search, count or lookup of suppliers on a user's behalf.
     *
     * @return Builder<Supplier>
     */
    public function visibleSuppliers(User $user): Builder
    {
        $query = Supplier::query();

        // An empty scope must match nothing; it is expressed as a contradiction rather than left to
        // the caller.
        if (! $this->canOpenModule($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('suppliers.customer_id', (int) $user->customer_id);
    }

    public function findVisibleSupplier(User $user, int $supplierId): ?Supplier
    {
        return $this->visibleSuppliers($user)->whereKey($supplierId)->first();
    }

    /**
     * Whether this person may be intern ansvarlig for a supplier of the customer: active, of the same
     * customer, and able to read suppliers. A responsible person who cannot open the supplier is
     * none. Being responsible grants nothing — it is not a permission.
     */
    public function isValidOwner(?User $owner, int $customerId): bool
    {
        return $owner instanceof User
            && (bool) $owner->is_active
            && (int) $owner->customer_id === $customerId
            && $this->canOpenModule($owner);
    }

    /**
     * The people who could be intern ansvarlig: active users of the user's customer who hold
     * supplier.view through an active role of that customer. Through a role only — which is also
     * exactly how System Owner holds it, so the list and isValidOwner() agree.
     *
     * @return list<array{id: int, name: string}>
     */
    public function ownerCandidates(User $user): array
    {
        $customerId = (int) $user->customer_id;

        return User::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereHas('customerRoles', fn (Builder $roles) => $roles
                ->where('customer_roles.customer_id', $customerId)
                ->where('customer_roles.is_active', true)
                ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('permission_key', CustomerPermissionCatalog::SUPPLIER_VIEW)))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $candidate): array => ['id' => (int) $candidate->id, 'name' => $candidate->name])
            ->all();
    }
}
