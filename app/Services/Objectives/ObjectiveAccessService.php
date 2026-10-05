<?php

namespace App\Services\Objectives;

use App\Models\BusinessArea;
use App\Models\User;
use App\Services\Permissions\BusinessAreaGrants;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one place that answers "may this user do this, to objectives and KPIs in which areas?".
 *
 * The same model as Risiko (RiskAccessService), over the objective.* keys:
 *
 *  - WHAT: objective.view, objective.edit, objective.measure, objective.delete on a customer role.
 *  - WHERE: the fagområder of that same role — explicit, or «Alle». An objective carries the
 *    fagområde; a KPI is reached through its objective and has none of its own.
 *
 * A user may do P in area A when one of their active roles grants P *and* reaches A
 * (BusinessAreaGrants). Never a permission from one role and an area from another.
 *
 * SYSTEM OWNER, FAIL-CLOSED.
 *
 * System Owner holds every permission key (CustomerPermissionService), so the module opens and the
 * roles and fagområder stay theirs to administer. But that grant carries no fagområde, and System
 * Owner is never given «Alle» implicitly: objective and KPI data is reached through a role only,
 * exactly as for risks.
 *
 * Every read of objectives or KPIs on a user's behalf must start from the areas this service
 * returns. An objective outside them is absent — not listed, not counted, 404 by URL.
 */
class ObjectiveAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly BusinessAreaGrants $grants,
    ) {}

    /**
     * Whether the user may open Mål og KPI at all. Says nothing about which objectives they will
     * find there.
     */
    public function canOpenModule(?User $user): bool
    {
        return $user instanceof User
            && $user->customer_id !== null
            && $this->permissions->has($user, CustomerPermissionCatalog::OBJECTIVE_VIEW);
    }

    /**
     * The fagområder in which the user may do the given thing, through their own active roles.
     *
     * @return list<int>
     */
    public function areaIdsFor(User $user, string $permissionKey): array
    {
        if ($user->customer_id === null || ! $this->isObjectivePermission($permissionKey)) {
            return [];
        }

        return $this->grants->areaIdsFor((int) $user->customer_id, (int) $user->id, $permissionKey);
    }

    /**
     * Whether one of the user's own active roles grants the permission with «Alle». Only then may a
     * page speak of the whole virksomhet's objectives. Never true for System Owner by virtue of the
     * administrator role.
     */
    public function reachesAllAreas(User $user, string $permissionKey): bool
    {
        if (! $this->canOpenModule($user) || ! $this->isObjectivePermission($permissionKey)) {
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
     * Whether the user may do the given thing in this area of this customer. The tenant check is
     * part of the answer, not left to the caller.
     */
    public function canInArea(User $user, string $permissionKey, int $customerId, int $areaId): bool
    {
        return $this->canOpenModule($user)
            && (int) $user->customer_id === $customerId
            && in_array($areaId, $this->areaIdsFor($user, $permissionKey), true);
    }

    /**
     * Per user of the customer, the areas they may read objectives in. For offering only owners who
     * would actually be able to see what they own.
     *
     * @return array<int, list<int>>
     */
    public function viewerAreaIdsByUser(int $customerId): array
    {
        return $this->grants->areaIdsByUser($customerId, CustomerPermissionCatalog::OBJECTIVE_VIEW);
    }

    private function isObjectivePermission(string $permissionKey): bool
    {
        return in_array(
            $permissionKey,
            CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_OBJECTIVE],
            true,
        );
    }
}
