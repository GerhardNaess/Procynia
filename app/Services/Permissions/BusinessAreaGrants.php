<?php

namespace App\Services\Permissions;

use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which fagområder a user's own roles reach with a given permission. Shared by every module that
 * scopes its content by fagområde (Risiko, Mål og KPI), so the rule exists exactly once.
 *
 * THE RULE.
 *
 * A user may do P in area A when one and the same active role of their customer grants P *and*
 * reaches A — through an explicit link, or through «Alle» (CustomerRole::$all_business_areas),
 * which is resolved here at read time against the customer's areas as they are now and is never
 * expanded into link rows. A permission from one role and an area from another never meet: a
 * «Leser Økonomi» role and a «Redaktør HR» role must never add up to editing Økonomi.
 *
 * WHAT THIS DELIBERATELY DOES NOT KNOW.
 *
 *  - System Owner. Only the customer's own roles are read, so the administrator role carries no
 *    fagområde — fail-closed by construction. Each module's access service decides what System
 *    Owner may open; none of them may grant content through this class without a role.
 *  - Modules and entitlements. Whether the user may open the module at all is the caller's
 *    question. This answers only "where", and only for keys of an area-scoped domain; any other
 *    key reaches nothing.
 *
 * Every join re-checks customer_id against the role's, the user's and the area's own tenant, so a
 * stray cross-tenant pivot row grants nothing.
 */
final class BusinessAreaGrants
{
    /**
     * The ids of the customer's areas in which the user may do the given thing, ascending.
     *
     * @return list<int>
     */
    public function areaIdsFor(int $customerId, int $userId, string $permissionKey): array
    {
        if (! CustomerPermissionCatalog::isAreaScoped($permissionKey)) {
            return [];
        }

        return $this->grantQuery($customerId, $permissionKey, $userId)
            ->distinct()
            ->orderBy('grants.business_area_id')
            ->pluck('grants.business_area_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Whether one of the user's own active roles grants the permission with «Alle».
     */
    public function reachesAllAreas(int $customerId, int $userId, string $permissionKey): bool
    {
        if (! CustomerPermissionCatalog::isAreaScoped($permissionKey)) {
            return false;
        }

        return $this->rolesGranting($customerId, $permissionKey, $userId)
            ->where('cr.all_business_areas', true)
            ->exists();
    }

    /**
     * Per user of the customer, the areas their roles reach with the given permission.
     *
     * @return array<int, list<int>>
     */
    public function areaIdsByUser(int $customerId, string $permissionKey): array
    {
        if (! CustomerPermissionCatalog::isAreaScoped($permissionKey)) {
            return [];
        }

        $byUser = [];

        foreach ($this->grantQuery($customerId, $permissionKey)
            ->distinct()
            ->get(['grants.user_id', 'grants.business_area_id']) as $row) {
            $byUser[(int) $row->user_id][] = (int) $row->business_area_id;
        }

        return $byUser;
    }

    /**
     * Rows of (user_id, business_area_id) where one and the same active role of the customer grants
     * the permission *and* reaches the area — explicitly, or through «Alle». Both branches start
     * from that one role, so a permission from one role and an area from another never meet.
     */
    private function grantQuery(int $customerId, string $permissionKey, ?int $userId = null): Builder
    {
        $explicit = $this->rolesGranting($customerId, $permissionKey, $userId)
            ->join('customer_role_business_areas as crba', 'crba.customer_role_id', '=', 'cr.id')
            ->join('business_areas as area', 'area.id', '=', 'crba.business_area_id')
            ->where('cr.all_business_areas', false)
            ->where('area.customer_id', $customerId)
            ->select(['cur.user_id', 'area.id as business_area_id']);

        $wildcard = $this->rolesGranting($customerId, $permissionKey, $userId)
            ->join('business_areas as area', 'area.customer_id', '=', 'cr.customer_id')
            ->where('cr.all_business_areas', true)
            ->select(['cur.user_id', 'area.id as business_area_id']);

        return DB::query()->fromSub($explicit->unionAll($wildcard), 'grants');
    }

    /** (role, user) pairs where an active role of the customer grants the permission. */
    private function rolesGranting(int $customerId, string $permissionKey, ?int $userId): Builder
    {
        return DB::table('customer_roles as cr')
            ->join('customer_user_roles as cur', 'cur.customer_role_id', '=', 'cr.id')
            ->join('customer_role_permissions as crp', 'crp.customer_role_id', '=', 'cr.id')
            ->join('users as u', 'u.id', '=', 'cur.user_id')
            ->where('cr.customer_id', $customerId)
            ->where('cr.is_active', true)
            ->where('u.customer_id', $customerId)
            ->where('crp.permission_key', $permissionKey)
            ->when($userId !== null, fn ($query) => $query->where('cur.user_id', $userId));
    }
}
