<?php

namespace App\Services\Objectives;

use App\Models\BusinessArea;
use App\Models\Kpi;
use App\Models\Objective;
use App\Models\User;
use App\Services\Permissions\BusinessAreaGrants;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
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
 * Every read of objectives on a user's behalf starts from visibleObjectives(). An objective outside
 * the user's areas is absent — not listed, not counted, not searchable and 404 by URL.
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
     * Every objective the user may read, and nothing else. The only correct starting point for any
     * list, search, count or lookup of objectives on a user's behalf.
     *
     * @return Builder<Objective>
     */
    public function visibleObjectives(User $user): Builder
    {
        $areaIds = $this->canOpenModule($user)
            ? $this->areaIdsFor($user, CustomerPermissionCatalog::OBJECTIVE_VIEW)
            : [];

        return Objective::query()
            ->where('objectives.customer_id', (int) $user->customer_id)
            // An empty scope must match nothing; whereIn([]) would be a footgun to rely on.
            ->whereIn('objectives.business_area_id', $areaIds === [] ? [0] : $areaIds);
    }

    public function findVisible(User $user, int $objectiveId): ?Objective
    {
        return $this->visibleObjectives($user)->whereKey($objectiveId)->first();
    }

    /**
     * Every KPI the user may read: those whose objective is visible, and nothing else. A KPI has no
     * fagområde of its own, so there is no second rule here — hide the objective and its KPIs go
     * with it.
     *
     * @return Builder<Kpi>
     */
    public function visibleKpis(User $user): Builder
    {
        return Kpi::query()
            ->where('kpis.customer_id', (int) $user->customer_id)
            ->whereIn('kpis.objective_id', $this->visibleObjectives($user)->select('objectives.id'));
    }

    /**
     * A KPI by its objective and its own id, when the user may read the objective. A KPI asked for
     * under another objective is absent, like one that does not exist.
     */
    public function findVisibleKpi(User $user, int $objectiveId, int $kpiId): ?Kpi
    {
        return $this->visibleKpis($user)->where('kpis.objective_id', $objectiveId)->whereKey($kpiId)->first();
    }

    /**
     * Whether the user may do the given thing to this objective, in the area it is in now. The
     * tenant check is part of the answer, not left to the caller.
     */
    public function can(User $user, string $permissionKey, Objective $objective): bool
    {
        return $this->canInArea($user, $permissionKey, (int) $objective->customer_id, (int) $objective->business_area_id);
    }

    public function canView(User $user, Objective $objective): bool
    {
        return $this->can($user, CustomerPermissionCatalog::OBJECTIVE_VIEW, $objective);
    }

    /** Editing covers creating, changing, closing and reopening. Never deleting. */
    public function canEdit(User $user, Objective $objective): bool
    {
        return $this->can($user, CustomerPermissionCatalog::OBJECTIVE_EDIT, $objective);
    }

    /**
     * Registering and withdrawing measurements. Its own permission: objective.edit does not imply
     * it, and it does not imply objective.edit.
     */
    public function canMeasure(User $user, Objective $objective): bool
    {
        return $this->can($user, CustomerPermissionCatalog::OBJECTIVE_MEASURE, $objective);
    }

    public function canDelete(User $user, Objective $objective): bool
    {
        return $this->can($user, CustomerPermissionCatalog::OBJECTIVE_DELETE, $objective);
    }

    /**
     * The areas the user may create objectives in, or move an objective into: objective.edit.
     *
     * @return Collection<int, BusinessArea>
     */
    public function editableAreas(User $user): Collection
    {
        return $this->areasFor($user, CustomerPermissionCatalog::OBJECTIVE_EDIT);
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
