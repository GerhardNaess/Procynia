<?php

namespace App\Services\Objectives;

use App\Models\Objective;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nytt mål and Rediger — the register work on an objective, for every entry point.
 *
 * Ordinary registration, not a human decision: objective.edit is not human-only, and an integration
 * holding it may do this (phase 0 step 15). What the service holds, whoever calls it:
 *
 *  - objective.edit in the area the objective is in, and in the area it is created in or moved to
 *    (ObjectiveAccessService: the key, the actor's own customer, the module);
 *  - an owner who is an active person of the customer able to read objectives in that area — and on
 *    a move, every KPI owner too, or the move is refused rather than leaving them without access;
 *  - a closed objective is reopened before it is changed.
 *
 * An update is judged on the objective as it stands under the row lock. Status is never written
 * here; it changes only through ObjectiveLifecycleService. The controller validates the form's shape
 * and checks the same first, so its answers are unchanged.
 *
 * @phpstan-type Attributes array{title: string, description: ?string, business_area_id: int, owner_user_id: int, target_date: ?string}
 */
class ObjectiveRegistration
{
    public function __construct(
        private readonly ObjectiveAccessService $access,
    ) {}

    /** @param Attributes $attributes */
    public function create(User $actor, array $attributes): Objective
    {
        $areaId = (int) $attributes['business_area_id'];

        // The area is the scope the new objective will live in, so the actor must be allowed to
        // edit in *that* area — not merely hold objective.edit somewhere.
        $this->authorizeArea($actor, $areaId);
        $this->guardOwner($actor, (int) $attributes['owner_user_id'], $areaId);

        return Objective::query()->create($this->fields($attributes) + [
            'customer_id' => (int) $actor->customer_id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param Attributes $attributes */
    public function update(User $actor, Objective $objective, array $attributes): Objective
    {
        return DB::transaction(function () use ($actor, $objective, $attributes): Objective {
            $locked = Objective::query()->whereKey($objective->id)->lockForUpdate()->firstOrFail();

            // Edit authority in the area the objective is in now.
            $this->access->authorize($actor, CustomerPermissionCatalog::OBJECTIVE_EDIT, $locked);

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'status' => __('procynia.objectives.validation.reopen_before_edit'),
                ]);
            }

            $targetAreaId = (int) $attributes['business_area_id'];
            $moved = $targetAreaId !== (int) $locked->business_area_id;

            // Moving an objective changes who can see it, so it takes edit authority on both sides:
            // the old area above, the new one here.
            if ($moved) {
                $this->authorizeArea($actor, $targetAreaId);
            }

            // Against the area the objective will be in, so a move cannot keep an owner who would no
            // longer be able to see it.
            $this->guardOwner($actor, (int) $attributes['owner_user_id'], $targetAreaId);

            if ($moved) {
                $this->guardKpiOwners($locked, $targetAreaId);
            }

            $locked->fill($this->fields($attributes) + ['updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    /**
     * A 422 rather than a 403 or 404: the area id came from a form, and whether it names an area of
     * another tenant, an area outside the actor's scope or nothing at all, the answer is the same.
     */
    private function authorizeArea(User $actor, int $areaId): void
    {
        if (! $this->access->canInArea($actor, CustomerPermissionCatalog::OBJECTIVE_EDIT, (int) $actor->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'business_area_id' => __('procynia.objectives.validation.area_not_allowed'),
            ]);
        }
    }

    /**
     * The owner must be an active person in the same customer who can read objectives in the
     * given area. An owner who cannot open the objective they own is not an owner.
     */
    private function guardOwner(User $actor, int $ownerId, int $areaId): void
    {
        $owner = User::query()
            ->where('customer_id', (int) $actor->customer_id)
            ->where('is_active', true)
            ->find($ownerId);

        if ($owner === null
            || ! $this->access->canInArea($owner, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $actor->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.objectives.validation.owner_not_allowed'),
            ]);
        }
    }

    /**
     * A KPI takes its fagområde from its objective, so moving the objective moves its KPIs. Their
     * own owners must be able to read objectives in the new area too, or the move is refused —
     * the same rule as for the objective's owner, never a silent loss of access.
     */
    private function guardKpiOwners(Objective $objective, int $areaId): void
    {
        $owners = User::query()
            ->whereIn('id', $objective->kpis()->whereNotNull('owner_user_id')->select('owner_user_id'))
            ->get();

        foreach ($owners as $owner) {
            if (! $this->access->canInArea($owner, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $objective->customer_id, $areaId)) {
                throw ValidationException::withMessages([
                    'business_area_id' => __('procynia.objectives.validation.kpi_owner_not_allowed'),
                ]);
            }
        }
    }

    /**
     * @param  Attributes  $attributes
     * @return array<string, mixed>
     */
    private function fields(array $attributes): array
    {
        $description = trim((string) ($attributes['description'] ?? ''));

        return [
            'business_area_id' => (int) $attributes['business_area_id'],
            'title' => trim($attributes['title']),
            'description' => $description !== '' ? $description : null,
            'owner_user_id' => (int) $attributes['owner_user_id'],
            'target_date' => $attributes['target_date'] ?? null,
        ];
    }
}
