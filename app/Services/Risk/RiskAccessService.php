<?php

namespace App\Services\Risk;

use App\Models\BusinessArea;
use App\Models\Risk;
use App\Models\User;
use App\Services\Permissions\BusinessAreaGrants;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one place that answers "may this user do this, to risks in which areas?".
 *
 * Two questions, answered by two things the customer already administers in Tilganger:
 *
 *  - WHAT: a permission key (risk.view, risk.create, risk.edit, risk.delete) on a customer role.
 *  - WHERE: the fagområder of that same role — explicit areas, or «Alle», which reaches every
 *    area the customer has now or creates later (CustomerRole::$all_business_areas, never
 *    expanded into link rows).
 *
 * A user may do P to a risk in area A when at least one of their active roles grants P *and*
 * reaches A. It is the same union-of-roles model as CustomerPermissionService, taken over
 * (permission, area) pairs rather than over permissions and areas separately. The difference
 * matters: a «Leser Økonomi» role and an «Redaktør HR» role must never add up to editing Økonomi.
 *
 * SYSTEM OWNER.
 *
 * System Owner holds every permission key unconditionally (CustomerPermissionService), and keeps
 * that here: Risiko is reachable, and the fagområder are System Owner's to administer. But
 * that implicit grant carries no fagområde — and System Owner is never given «Alle» implicitly. Risk content is reached through roles only, System Owner
 * included — a System Owner who needs to work in Beredskap gives themselves a role that holds the
 * risk permissions and reaches Beredskap (or «Alle»), which Rediger bruker allows on one's own account. Lock-out is therefore impossible
 * while sensitive risks are never readable merely by holding the administrator role.
 *
 * Everything that reads risks for a user goes through visibleRisks(). A risk outside the user's
 * areas is not forbidden, it is absent: not listed, not counted, not searchable and 404 by URL.
 *
 * The (permission, area) query itself is BusinessAreaGrants, shared with Mål og KPI. What stays here
 * is what is Risiko's own: which keys count, when the module opens, and what a risk is.
 */
class RiskAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly BusinessAreaGrants $grants,
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
     * The fagområder in which the user may do the given thing, through their own active roles.
     *
     * @return list<int>
     */
    public function areaIdsFor(User $user, string $permissionKey): array
    {
        if ($user->customer_id === null || ! $this->isRiskPermission($permissionKey)) {
            return [];
        }

        return $this->grants->areaIdsFor((int) $user->customer_id, (int) $user->id, $permissionKey);
    }

    /**
     * Whether one of the user's own active roles grants the permission with «Alle». Only then may
     * a page speak of the customer's whole risk picture; anyone else sees their fagområder only.
     * Never true for System Owner by virtue of the administrator role.
     */
    public function reachesAllAreas(User $user, string $permissionKey): bool
    {
        if (! $this->canOpenModule($user) || ! $this->isRiskPermission($permissionKey)) {
            return false;
        }

        return $this->grants->reachesAllAreas((int) $user->customer_id, (int) $user->id, $permissionKey);
    }

    /**
     * The areas themselves, for forms that let the user choose one.
     *
     * @return Collection<int, BusinessArea>
     */
    public function areasFor(User $user, string $permissionKey): Collection
    {
        $ids = $this->areaIdsFor($user, $permissionKey);

        return BusinessArea::query()
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
            ->whereIn('risks.business_area_id', $areaIds === [] ? [0] : $areaIds);
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
        return $this->canInArea($user, $permissionKey, (int) $risk->customer_id, (int) $risk->business_area_id);
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
        return $this->grants->areaIdsByUser($customerId, CustomerPermissionCatalog::RISK_VIEW);
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
