<?php

namespace App\Services\Improvements;

use App\Models\BusinessArea;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Permissions\BusinessAreaGrants;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one place that answers "may this user do this, to avvik and forbedringer in which areas?".
 *
 * The same model as Risiko and Mål og KPI, over the improvement.* keys:
 *
 *  - WHAT: improvement.view, improvement.edit, improvement.close, improvement.delete on a role.
 *  - WHERE: the fagområder of that same role — explicit, or «Alle». A case carries its fagområde.
 *
 * A user may do P in area A when one of their active roles grants P *and* reaches A
 * (BusinessAreaGrants). Never a permission from one role and an area from another.
 *
 * SYSTEM OWNER, FAIL-CLOSED.
 *
 * System Owner holds every permission key, so the module opens and roles and fagområder stay theirs
 * to administer. That grant carries no fagområde, and System Owner is never given «Alle»
 * implicitly: cases are reached through a role only.
 *
 * Every read of cases on a user's behalf starts from visibleCases(). A case outside the user's
 * areas is absent — not listed, not counted, not searchable and 404 by URL.
 */
class ImprovementCaseAccessService
{
    public function __construct(
        private readonly CustomerPermissionService $permissions,
        private readonly BusinessAreaGrants $grants,
    ) {}

    /**
     * Whether the user may open Avvik og forbedringer at all. Says nothing about which cases they
     * will find there.
     */
    public function canOpenModule(?User $user): bool
    {
        return $user instanceof User
            && $user->customer_id !== null
            && $this->permissions->has($user, CustomerPermissionCatalog::IMPROVEMENT_VIEW);
    }

    /**
     * The fagområder in which the user may do the given thing, through their own active roles.
     *
     * @return list<int>
     */
    public function areaIdsFor(User $user, string $permissionKey): array
    {
        if ($user->customer_id === null || ! $this->isImprovementPermission($permissionKey)) {
            return [];
        }

        return $this->grants->areaIdsFor((int) $user->customer_id, (int) $user->id, $permissionKey);
    }

    /**
     * Whether one of the user's own active roles grants the permission with «Alle». Never true for
     * System Owner by virtue of the administrator role.
     */
    public function reachesAllAreas(User $user, string $permissionKey): bool
    {
        if (! $this->canOpenModule($user) || ! $this->isImprovementPermission($permissionKey)) {
            return false;
        }

        return $this->grants->reachesAllAreas((int) $user->customer_id, (int) $user->id, $permissionKey);
    }

    /**
     * The areas themselves, for forms and filters that let the user choose one.
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
     * The areas the user may read cases in — for the register's area filter.
     *
     * @return Collection<int, BusinessArea>
     */
    public function visibleAreas(User $user): Collection
    {
        return $this->canOpenModule($user)
            ? $this->areasFor($user, CustomerPermissionCatalog::IMPROVEMENT_VIEW)
            : new Collection;
    }

    /**
     * Every case the user may read, and nothing else. The only correct starting point for any list,
     * search, count or lookup of cases on a user's behalf.
     *
     * @return Builder<ImprovementCase>
     */
    public function visibleCases(User $user): Builder
    {
        $areaIds = $this->canOpenModule($user)
            ? $this->areaIdsFor($user, CustomerPermissionCatalog::IMPROVEMENT_VIEW)
            : [];

        return ImprovementCase::query()
            ->where('improvement_cases.customer_id', (int) $user->customer_id)
            // An empty scope must match nothing; whereIn([]) would be a footgun to rely on.
            ->whereIn('improvement_cases.business_area_id', $areaIds === [] ? [0] : $areaIds);
    }

    public function findVisible(User $user, int $caseId): ?ImprovementCase
    {
        return $this->visibleCases($user)->whereKey($caseId)->first();
    }

    /**
     * Whether the user may do the given thing to this case, in the area it is in now. The tenant
     * check is part of the answer, not left to the caller.
     */
    public function can(User $user, string $permissionKey, ImprovementCase $case): bool
    {
        return $this->canInArea($user, $permissionKey, (int) $case->customer_id, (int) $case->business_area_id);
    }

    public function canView(User $user, ImprovementCase $case): bool
    {
        return $this->can($user, CustomerPermissionCatalog::IMPROVEMENT_VIEW, $case);
    }

    /** Editing covers registering, changing and Start behandling. Never closing or deleting. */
    public function canEdit(User $user, ImprovementCase $case): bool
    {
        return $this->can($user, CustomerPermissionCatalog::IMPROVEMENT_EDIT, $case);
    }

    /** Lukk, Avbryt and Gjenåpne — the decisions that end a case or undo an ending. */
    public function canClose(User $user, ImprovementCase $case): bool
    {
        return $this->can($user, CustomerPermissionCatalog::IMPROVEMENT_CLOSE, $case);
    }

    public function canDelete(User $user, ImprovementCase $case): bool
    {
        return $this->can($user, CustomerPermissionCatalog::IMPROVEMENT_DELETE, $case);
    }

    /**
     * The areas the user may register cases in, or move a case into: improvement.edit.
     *
     * @return Collection<int, BusinessArea>
     */
    public function editableAreas(User $user): Collection
    {
        return $this->areasFor($user, CustomerPermissionCatalog::IMPROVEMENT_EDIT);
    }

    /**
     * Whether the user may do the given thing in this area of this customer.
     */
    public function canInArea(User $user, string $permissionKey, int $customerId, int $areaId): bool
    {
        return $this->canOpenModule($user)
            && (int) $user->customer_id === $customerId
            && in_array($areaId, $this->areaIdsFor($user, $permissionKey), true);
    }

    /**
     * Whether this person may be responsible for a case in the area: an active user of the same
     * customer who can read cases there. A responsible person who cannot open the case is none.
     */
    public function isValidOwner(?User $owner, int $customerId, int $areaId): bool
    {
        return $owner instanceof User
            && (bool) $owner->is_active
            && (int) $owner->customer_id === $customerId
            && $this->canInArea($owner, CustomerPermissionCatalog::IMPROVEMENT_VIEW, $customerId, $areaId);
    }

    /**
     * People who could be responsible, each with the areas — among those offered to the acting user
     * — in which they can read cases. Areas the acting user cannot reach are never named.
     *
     * @param  list<int>  $offeredAreaIds
     * @return list<array{id: int, name: string, area_ids: list<int>}>
     */
    public function ownerCandidates(User $user, array $offeredAreaIds): array
    {
        if ($offeredAreaIds === []) {
            return [];
        }

        $viewerAreas = $this->grants->areaIdsByUser((int) $user->customer_id, CustomerPermissionCatalog::IMPROVEMENT_VIEW);

        return User::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $candidate): array => [
                'id' => (int) $candidate->id,
                'name' => $candidate->name,
                'area_ids' => array_values(array_intersect($viewerAreas[(int) $candidate->id] ?? [], $offeredAreaIds)),
            ])
            ->filter(fn (array $option): bool => $option['area_ids'] !== [])
            ->values()
            ->all();
    }

    private function isImprovementPermission(string $permissionKey): bool
    {
        return in_array(
            $permissionKey,
            CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_IMPROVEMENT],
            true,
        );
    }
}
