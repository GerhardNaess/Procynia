<?php

namespace App\Services\Suppliers;

use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;

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
 * `app.supplier-management.` in config/procynia_modules.php). There are no supplier records yet,
 * so there is nothing to scope; the record-level reads (visibleSuppliers() and friends) arrive
 * with the register and start from canOpenModule().
 */
class SupplierAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
    ) {}

    /** Whether the user may open Leverandøroppfølging at all. */
    public function canOpenModule(?User $user): bool
    {
        return $user instanceof User
            && $user->customer_id !== null
            && $this->permissions->has($user, CustomerPermissionCatalog::SUPPLIER_VIEW);
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
}
