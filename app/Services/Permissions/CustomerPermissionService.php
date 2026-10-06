<?php

namespace App\Services\Permissions;

use App\Models\CustomerRole;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Collection;

/**
 * The one place that answers "may this user do this?" for customer-defined roles.
 *
 * Effective permissions are the union of every active role the user holds inside their own
 * customer. Union, not intersection and not precedence: roles describe jobs a person does, and a
 * person who does two jobs does both. There is no deny — a role grants, nothing takes away — so
 * adding a role can never reduce what someone can do, which is the property that makes the gallery
 * in Tilganger safe to experiment with.
 *
 * System Owner holds the whole catalogue unconditionally, mirroring Customer::roleHasPermission().
 * That is what makes the administration surface impossible to lock yourself out of: the person who
 * edits roles does not depend on a role to keep editing them.
 *
 * The one exception is the explicit-grant domains (CustomerPermissionCatalog::explicitGrantDomains()):
 * their keys reach System Owner only through a role, exactly as for anyone else. Nothing about the
 * administration surface depends on them, so the lock-out argument above does not apply.
 */
class CustomerPermissionService
{
    /**
     * The roles the user actually holds: active, and belonging to the user's own customer.
     *
     * The customer check is not redundant with the assignment's own customer_id. It is the tenant
     * guard — it reads the role's owner, so an assignment row that somehow points at another
     * tenant's role grants nothing.
     *
     * @return Collection<int, CustomerRole>
     */
    public function rolesFor(User $user): Collection
    {
        if ($user->customer_id === null) {
            return collect();
        }

        return $user->customerRoles()
            ->where('customer_roles.customer_id', $user->customer_id)
            ->where('customer_roles.is_active', true)
            ->with('permissions')
            ->get();
    }

    /**
     * The union of the permission keys granted by the user's active roles. System Owner's implicit
     * full grant is deliberately *not* included here — this answers "what did the customer's own
     * roles give this person", which is what an administration surface needs to show.
     *
     * @return list<string>
     */
    public function permissionsFromRoles(User $user): array
    {
        return CustomerPermissionCatalog::filterKnown(
            $this->rolesFor($user)
                ->flatMap(fn (CustomerRole $role): array => $role->permissionKeys())
                ->all()
        );
    }

    /**
     * What the user may actually do, including System Owner's unconditional grant.
     *
     * @return list<string>
     */
    public function effectivePermissions(User $user): array
    {
        if (! $user->isSystemOwner()) {
            return $this->permissionsFromRoles($user);
        }

        $fromRoles = $this->permissionsFromRoles($user);

        // In catalogue order: the implicit grant minus the explicit-grant domains, plus whatever of
        // those the System Owner's own roles give them.
        return array_values(array_filter(
            CustomerPermissionCatalog::all(),
            fn (string $key): bool => ! CustomerPermissionCatalog::requiresExplicitGrant($key) || in_array($key, $fromRoles, true),
        ));
    }

    public function has(User $user, string $permissionKey): bool
    {
        if (! CustomerPermissionCatalog::exists($permissionKey)) {
            return false;
        }

        if ($user->isSystemOwner() && ! CustomerPermissionCatalog::requiresExplicitGrant($permissionKey)) {
            return true;
        }

        return in_array($permissionKey, $this->permissionsFromRoles($user), true);
    }

    /**
     * Whether the user holds every one of the given keys.
     *
     * @param  list<string>  $permissionKeys
     */
    public function hasAll(User $user, array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if (! $this->has($user, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the user holds at least one of the given keys.
     *
     * @param  list<string>  $permissionKeys
     */
    public function hasAny(User $user, array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if ($this->has($user, $key)) {
                return true;
            }
        }

        return false;
    }
}
