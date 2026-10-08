<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierProfile;
use App\Models\SupplierRequirementOverride;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Legg til krav · Gjelder ikke denne leverandøren · Tilbake til automatisk vurdering — the only way a
 * person changes whether a control requirement applies to a supplier
 * (docs/supplier-assurance-v2-plan.md §5.5). Each is one new, immutable
 * SupplierRequirementOverride with a begrunnelse; nothing is ever updated or deleted, and clear is
 * a row too.
 *
 * Inside one transaction, with the supplier row locked:
 *  - the supplier is looked up again through SupplierAccessService::visibleSuppliers() (another
 *    customer's is a 404) and the actor must hold supplier.assure — never edit or assess;
 *  - an ended supplier is refused;
 *  - the requirement must be an active catalogue requirement of the same customer — not one for a
 *    single supplier, which is retired instead;
 *  - include only when it does not apply automatically and is not already included; exclude only
 *    when it applies automatically, is not mandatory and is not already excluded; clear only while
 *    an include or exclude is in force. Each is checked against the applicability computed inside
 *    the lock, so a double submit writes one row and the second is told why.
 */
class SupplierRequirementOverrideService
{
    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly SupplierRequirementApplicability $applicability,
    ) {}

    public function record(User $actor, Supplier $supplier, int $requirementId, string $action, ?string $reason): SupplierRequirementOverride
    {
        $reason = trim((string) $reason);

        return DB::transaction(function () use ($actor, $supplier, $requirementId, $action, $reason): SupplierRequirementOverride {
            $locked = $this->access->visibleSuppliers($actor)->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if (! $this->access->canAssure($actor)) {
                throw new AuthorizationException;
            }

            if ($locked->isEnded()) {
                throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.reopen_before_edit')]);
            }

            if (! in_array($action, SupplierRequirementOverride::ACTIONS, true)) {
                throw ValidationException::withMessages(['action' => __('procynia.supplier_management.validation.override_not_allowed')]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => __('procynia.supplier_management.validation.reason_required')]);
            }

            $requirement = SupplierControlRequirement::query()
                ->where('customer_id', (int) $locked->customer_id)
                ->whereNull('supplier_id')
                ->where('status', SupplierControlRequirement::STATUS_ACTIVE)
                ->whereKey($requirementId)
                ->first();

            // A 422 rather than a 404: the id came from a form, and whether it names another
            // customer's requirement, a retired one or nothing at all, the answer is the same.
            if ($requirement === null) {
                throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.control_requirement_not_available')]);
            }

            $answers = SupplierProfile::query()->whereKey($locked->id)->first()?->answers();
            $latest = $this->applicability->latestOverrides([(int) $locked->id])[$locked->id.':'.$requirement->id] ?? null;
            $decision = SupplierRequirementApplicability::decide(
                $requirement,
                $locked,
                SupplierProfilePredicates::evaluate($locked, $answers),
                SupplierProfilePredicates::uncertain($locked, $answers),
                $latest,
            );

            $inForce = $decision['override']?->action;
            $allowed = match ($action) {
                SupplierRequirementOverride::ACTION_INCLUDE => ! $decision['automatic'] && $inForce !== SupplierRequirementOverride::ACTION_INCLUDE,
                SupplierRequirementOverride::ACTION_EXCLUDE => $decision['automatic'] && ! $requirement->isMandatory() && $inForce !== SupplierRequirementOverride::ACTION_EXCLUDE,
                SupplierRequirementOverride::ACTION_CLEAR => $inForce !== null,
            };

            if (! $allowed) {
                $message = $action === SupplierRequirementOverride::ACTION_EXCLUDE && $requirement->isMandatory()
                    ? 'mandatory_not_excludable'
                    : 'override_not_allowed';

                throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.'.$message)]);
            }

            return SupplierRequirementOverride::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'requirement_id' => (int) $requirement->id,
                'action' => $action,
                'reason' => $reason,
                'requirement_title' => $requirement->title,
                'requirement_level' => $requirement->level,
                'created_by_user_id' => (int) $actor->id,
                'created_at' => now(),
            ]);
        });
    }
}
