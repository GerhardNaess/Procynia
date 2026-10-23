<?php

namespace App\Services\Objectives;

use App\Models\Kpi;
use App\Models\Objective;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ny KPI and Rediger — the register work on a KPI, for every entry point.
 *
 * Ordinary registration, not a human decision: objective.edit is not human-only, and an integration
 * holding it may do this (phase 0 step 15). What the service holds, whoever calls it, judged on the
 * objective and KPI as they stand under the locks:
 *
 *  - objective.edit in the objective's area (ObjectiveAccessService: the key, the actor's own
 *    customer, the module), with the objective active — and for a change, the KPI active too;
 *  - an owner, when one is given, who is an active person of the customer able to read objectives
 *    in that area;
 *  - once a measurement exists, what its numbers mean (unit, unit label, currency) stays as it is.
 *
 * Only the KPI's own fields are written (FIELDS): never its customer, its objective or its status,
 * whatever the caller passes. What the målverdi is and whether the numbers fit the unit is the
 * form's validation (KpiController) and the model's own invariants. The controller checks the same
 * first, so its answers are unchanged.
 */
class KpiRegistration
{
    /** The fields a registration writes. Status changes only through KpiLifecycleService. */
    public const FIELDS = [
        'title',
        'description',
        'owner_user_id',
        'unit',
        'unit_label',
        'currency_code',
        'target_min',
        'target_max',
        'tolerance',
        'baseline_value',
        'baseline_date',
        'frequency',
        'reporting_grace_days',
    ];

    public function __construct(
        private readonly ObjectiveAccessService $access,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Objective $objective, array $attributes): Kpi
    {
        return DB::transaction(function () use ($actor, $objective, $attributes): Kpi {
            $current = $this->lockEditableObjective($actor, (int) $objective->id);
            $this->guardOwner($attributes, $current);

            return Kpi::query()->create($this->fields($attributes) + [
                'customer_id' => (int) $current->customer_id,
                'objective_id' => (int) $current->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Kpi $kpi, array $attributes): Kpi
    {
        return DB::transaction(function () use ($actor, $kpi, $attributes): Kpi {
            $objective = $this->lockEditableObjective($actor, (int) $kpi->objective_id);
            $locked = Kpi::query()->whereKey($kpi->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'title' => __('procynia.objectives.kpi.validation.reopen_before_edit'),
                ]);
            }

            $fields = $this->fields($attributes);
            $this->guardOwner($fields, $objective);
            $this->guardUnitDefinition($locked, $fields);

            $locked->fill($fields + ['updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    /**
     * A KPI owner is an active person of the objective's customer who can read objectives in its
     * area. An owner who cannot open the KPI they own is not an owner.
     */
    public function ownerAllowed(int $ownerId, Objective $objective): bool
    {
        $owner = User::query()
            ->where('customer_id', (int) $objective->customer_id)
            ->where('is_active', true)
            ->find($ownerId);

        return $owner !== null
            && $this->access->canInArea($owner, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $objective->customer_id, (int) $objective->business_area_id);
    }

    /**
     * Once a measurement exists, what its numbers mean — unit, unit label, currency — is fixed, or
     * the history would change meaning. Prosentandel and prosentvis endring may still be swapped:
     * both are written in per cent. The target and tolerance stay editable: each measurement
     * keeps a snapshot of the ones it was registered against. The model refuses the same.
     *
     * @param  array<string, mixed>  $fields
     */
    public function guardUnitDefinition(Kpi $kpi, array $fields): void
    {
        $changed = array_filter(
            Kpi::UNIT_DEFINITION,
            fn (string $field): bool => array_key_exists($field, $fields) && $fields[$field] !== $kpi->getAttribute($field),
        );

        if ($changed !== [] && $kpi->unitDefinitionChanges($fields + $kpi->only(Kpi::UNIT_DEFINITION)) && $kpi->hasMeasurementHistory()) {
            throw ValidationException::withMessages(array_fill_keys(
                array_values($changed),
                __('procynia.objectives.kpi.validation.unit_locked'),
            ));
        }
    }

    /** The objective, share-locked, when the actor may edit in its area and it is active. */
    private function lockEditableObjective(User $actor, int $objectiveId): Objective
    {
        $objective = Objective::query()->whereKey($objectiveId)->sharedLock()->firstOrFail();

        $this->access->authorize($actor, CustomerPermissionCatalog::OBJECTIVE_EDIT, $objective);

        if (! $objective->isActive()) {
            throw ValidationException::withMessages([
                'title' => __('procynia.objectives.kpi.validation.objective_closed'),
            ]);
        }

        return $objective;
    }

    /** @param array<string, mixed> $attributes */
    private function guardOwner(array $attributes, Objective $objective): void
    {
        $ownerId = isset($attributes['owner_user_id']) ? (int) $attributes['owner_user_id'] : null;

        if ($ownerId !== null && ! $this->ownerAllowed($ownerId, $objective)) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.objectives.kpi.validation.owner_not_allowed'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fields(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::FIELDS));
    }
}
