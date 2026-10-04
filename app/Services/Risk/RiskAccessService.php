<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\RiskAccessArea;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "may this user do this, to risks in which areas?".
 *
 * Two questions, answered by two things the customer already administers in Tilganger:
 *
 *  - WHAT: a permission key (risk.view, risk.create, risk.edit, risk.delete) on a customer role.
 *  - WHICH: the tilgangsområder attached to that same role.
 *
 * A user may do P to a risk in area A when at least one of their active roles grants P *and*
 * reaches A. It is the same union-of-roles model as CustomerPermissionService, taken over
 * (permission, area) pairs rather than over permissions and areas separately. The difference
 * matters: a «Leser Økonomi» role and an «Redaktør HR» role must never add up to editing Økonomi.
 *
 * SYSTEM OWNER.
 *
 * System Owner holds every permission key unconditionally (CustomerPermissionService), and keeps
 * that here: Risiko is reachable, and its tilgangsområder are System Owner's to administer. But
 * that implicit grant carries no area. Risk content is reached through roles only, System Owner
 * included — a System Owner who needs to work in Beredskap gives themselves a role that reaches
 * Beredskap, which Rediger bruker allows on one's own account. Lock-out is therefore impossible
 * while sensitive risks are never readable merely by holding the administrator role.
 *
 * Everything that reads risks for a user goes through visibleRisks(). A risk outside the user's
 * areas is not forbidden, it is absent: not listed, not counted, not searchable and 404 by URL.
 */
class RiskAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
    ) {}

    /**
     * Whether the user may open Risiko at all. Says nothing about which risks they will find there.
     */
    public function canOpenModule(?User $user): bool
    {
        return $user instanceof User
            && $user->customer_id !== null
            && $this->permissions->has($user, CustomerPermissionCatalog::RISK_VIEW);
    }

    /**
     * The tilgangsområder in which the user may do the given thing, through their own active roles.
     *
     * @return list<int>
     */
    public function areaIdsFor(User $user, string $permissionKey): array
    {
        if ($user->customer_id === null || ! $this->isRiskPermission($permissionKey)) {
            return [];
        }

        return $this->grantQuery((int) $user->customer_id, $permissionKey)
            ->where('cur.user_id', $user->id)
            ->distinct()
            ->orderBy('crra.risk_access_area_id')
            ->pluck('crra.risk_access_area_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The areas themselves, for forms that let the user choose one.
     *
     * @return Collection<int, RiskAccessArea>
     */
    public function areasFor(User $user, string $permissionKey): Collection
    {
        $ids = $this->areaIdsFor($user, $permissionKey);

        return RiskAccessArea::query()
            ->forCustomer((int) $user->customer_id)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->orderBy('name')
            ->get();
    }

    /**
     * Every risk the user may read, and nothing else. The only correct starting point for any
     * list, search, count or lookup of risks on a user's behalf.
     *
     * @return Builder<Risk>
     */
    public function visibleRisks(User $user): Builder
    {
        $areaIds = $this->canOpenModule($user)
            ? $this->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW)
            : [];

        return Risk::query()
            ->where('risks.customer_id', (int) $user->customer_id)
            // An empty scope must match nothing; whereIn([]) would be a footgun to rely on.
            ->whereIn('risks.risk_access_area_id', $areaIds === [] ? [0] : $areaIds);
    }

    public function findVisible(User $user, int $riskId): ?Risk
    {
        return $this->visibleRisks($user)->whereKey($riskId)->first();
    }

    /**
     * Whether the user may do the given thing to this risk. The tenant check is part of the answer,
     * not left to the caller.
     */
    public function can(User $user, string $permissionKey, Risk $risk): bool
    {
        return $this->canInArea($user, $permissionKey, (int) $risk->customer_id, (int) $risk->risk_access_area_id);
    }

    public function canInArea(User $user, string $permissionKey, int $customerId, int $areaId): bool
    {
        return $this->canOpenModule($user)
            && (int) $user->customer_id === $customerId
            && in_array($areaId, $this->areaIdsFor($user, $permissionKey), true);
    }

    /**
     * Per user of the customer, the areas they may read risks in. Used to offer only owners who
     * would actually be able to see the risk they own.
     *
     * @return array<int, list<int>>
     */
    public function viewerAreaIdsByUser(int $customerId): array
    {
        $byUser = [];

        foreach ($this->grantQuery($customerId, CustomerPermissionCatalog::RISK_VIEW)
            ->distinct()
            ->get(['cur.user_id', 'crra.risk_access_area_id']) as $row) {
            $byUser[(int) $row->user_id][] = (int) $row->risk_access_area_id;
        }

        return $byUser;
    }

    /**
     * Rows of (user, area) where an active role of the customer grants the permission and reaches
     * the area. Every join re-checks customer_id against the role's and the area's own tenant, so a
     * stray cross-tenant pivot row grants nothing.
     */
    private function grantQuery(int $customerId, string $permissionKey): \Illuminate\Database\Query\Builder
    {
        return DB::table('customer_role_risk_access_areas as crra')
            ->join('customer_roles as cr', 'cr.id', '=', 'crra.customer_role_id')
            ->join('customer_user_roles as cur', 'cur.customer_role_id', '=', 'cr.id')
            ->join('customer_role_permissions as crp', 'crp.customer_role_id', '=', 'cr.id')
            ->join('risk_access_areas as area', 'area.id', '=', 'crra.risk_access_area_id')
            ->join('users as u', 'u.id', '=', 'cur.user_id')
            ->where('cr.customer_id', $customerId)
            ->where('cr.is_active', true)
            ->where('area.customer_id', $customerId)
            ->where('u.customer_id', $customerId)
            ->where('crp.permission_key', $permissionKey);
    }

    private function isRiskPermission(string $permissionKey): bool
    {
        return in_array(
            $permissionKey,
            CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_RISK],
            true,
        );
    }
}
