<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\ComplianceRequirement;
use App\Models\Supplier;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kontrollkrav — the catalogue, and requirements for one supplier (docs/supplier-assurance-v2-plan.md
 * §5.1, §6.6, §13.2). The only writer of SupplierControlRequirement.
 *
 * WHO. supplier.assure, and only that (§13.2): the catalogue is what Leverandørkontroll checks
 * against. supplier.edit, supplier.assess and supplier.delete grant nothing here.
 *
 * Mutable current state with no history of its own: an edit changes the requirement in place, and
 * the overrides and controls keep their own snapshot of title and level. Sett som utgått
 * retires it — it then applies to no one, is never controlled again, and its overrides and
 * controls stay — and Ta i bruk igjen reverses
 * that. Slett is only for a requirement nothing refers to.
 *
 * A requirement for one supplier (supplier_id set) has no rule and always applies to that supplier.
 * It is written only while that supplier is not ended, and reaches the supplier through
 * SupplierAccessService.
 *
 * THE ANCHOR IN ETTERLEVELSE OG REVISJON is optional and read only through ComplianceAccessService:
 * without the module or compliance.view it is neither offered nor changed — an anchor the person
 * cannot see is kept as it is, never cleared by a form that did not show it. Only an active
 * requirement can be chosen as a new anchor. Nothing of the compliance requirement is copied;
 * basis_text is the supplier side's own text.
 */
class SupplierControlRequirementService
{
    public function __construct(
        private readonly SupplierAccessService $access,
        private readonly ComplianceAccessService $compliance,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(bool $forSupplier = false): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'guidance' => ['nullable', 'string', 'max:5000'],
            'theme' => ['required', 'string', Rule::in(SupplierControlRequirement::THEMES)],
            'level' => ['required', 'string', Rule::in(SupplierControlRequirement::LEVELS)],
            'control_point' => ['required', 'string', Rule::in(SupplierControlRequirement::CONTROL_POINTS)],
            'control_interval_months' => ['nullable', 'integer', Rule::in(SupplierControlRequirement::CONTROL_INTERVALS)],
            'accepted_document_types' => ['nullable', 'array'],
            'accepted_document_types.*' => ['string', Rule::in(SupplierDocument::TYPES)],
            'basis_text' => ['nullable', 'string', 'max:5000'],
            'compliance_requirement_id' => ['nullable', 'integer'],
        ];

        if (! $forSupplier) {
            $rules += [
                'rule_mode' => ['required', 'string', Rule::in([SupplierRequirementRule::MODE_ALL, SupplierRequirementRule::MODE_CONDITIONS])],
                'conditions' => ['nullable', 'array', 'max:'.SupplierRequirementRule::MAX_GROUPS],
                'conditions.*' => ['string', Rule::in(SupplierRequirementRule::conditions())],
                'criticality_scope' => ['nullable', 'string', Rule::in(['', SupplierRequirementRule::SCOPE_IMPORTANT, SupplierRequirementRule::SCOPE_CRITICAL])],
            ];
        }

        return $rules;
    }

    /** @return array<string, string|array<string, string>> */
    public static function messages(): array
    {
        return SupplierValidationMessages::messages() + [
            'conditions.max' => __('procynia.supplier_management.validation.conditions_max', ['max' => SupplierRequirementRule::MAX_GROUPS]),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $labels = (array) __('procynia.supplier_management.control.fields');

        return SupplierValidationMessages::attributes() + $labels + [
            'accepted_document_types.*' => $labels['accepted_document_types'] ?? 'accepted_document_types',
            'conditions.*' => $labels['conditions'] ?? 'conditions',
        ];
    }

    /**
     * A new requirement: in the catalogue, or — with $supplier — for that one supplier.
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(User $actor, array $validated, ?Supplier $supplier = null): SupplierControlRequirement
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $validated, $supplier): SupplierControlRequirement {
            $locked = $supplier !== null ? $this->lockOpenSupplier($actor, (int) $supplier->id) : null;

            $requirement = new SupplierControlRequirement([
                'customer_id' => (int) $actor->customer_id,
                'supplier_id' => $locked?->id,
                'status' => SupplierControlRequirement::STATUS_ACTIVE,
                'created_by' => (int) $actor->id,
            ]);
            $this->fill($requirement, $actor, $validated);
            $requirement->save();

            return $requirement;
        });
    }

    /** @param  array<string, mixed>  $validated */
    public function update(User $actor, SupplierControlRequirement $requirement, array $validated): SupplierControlRequirement
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $requirement, $validated): SupplierControlRequirement {
            $locked = $this->lock($actor, $requirement);
            $this->fill($locked, $actor, $validated);
            $locked->save();

            return $locked;
        });
    }

    /** Sett som utgått: it applies to no one from now on; its overrides stay. */
    public function retire(User $actor, SupplierControlRequirement $requirement): void
    {
        $this->changeStatus($actor, $requirement, SupplierControlRequirement::STATUS_RETIRED);
    }

    /** Ta i bruk igjen. */
    public function reactivate(User $actor, SupplierControlRequirement $requirement): void
    {
        $this->changeStatus($actor, $requirement, SupplierControlRequirement::STATUS_ACTIVE);
    }

    /** Slett: only a requirement nothing refers to; one that has been used is retired instead. */
    public function delete(User $actor, SupplierControlRequirement $requirement): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $requirement): void {
            $locked = $this->lock($actor, $requirement);

            if (! $locked->isDeletable()) {
                throw ValidationException::withMessages(['requirement' => __('procynia.supplier_management.validation.control_requirement_in_use')]);
            }

            $locked->delete();
        });
    }

    /**
     * A requirement the person may manage: of their own customer, and — for one supplier's — on a
     * supplier they can see. Anything else is null, the same as an id that does not exist.
     */
    public function findManageable(User $actor, int $requirementId): ?SupplierControlRequirement
    {
        $requirement = SupplierControlRequirement::query()
            ->where('customer_id', (int) $actor->customer_id)
            ->whereKey($requirementId)
            ->first();

        if ($requirement?->supplier_id !== null && $this->access->findVisibleSupplier($actor, (int) $requirement->supplier_id) === null) {
            return null;
        }

        return $requirement;
    }

    private function changeStatus(User $actor, SupplierControlRequirement $requirement, string $status): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $requirement, $status): void {
            $locked = $this->lock($actor, $requirement);

            if ($locked->status === $status) {
                throw ValidationException::withMessages(['requirement' => __('procynia.supplier_management.validation.control_requirement_status_unchanged')]);
            }

            $locked->forceFill(['status' => $status, 'updated_by' => (int) $actor->id])->save();
        });
    }

    /** @param  array<string, mixed>  $validated */
    private function fill(SupplierControlRequirement $requirement, User $actor, array $validated): void
    {
        $text = function (?string $value): ?string {
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        };

        $rule = $requirement->supplier_id !== null ? [] : SupplierRequirementRule::fromForm(
            (string) ($validated['rule_mode'] ?? SupplierRequirementRule::MODE_ALL),
            (array) ($validated['conditions'] ?? []),
            $validated['criticality_scope'] ?? null,
        );

        if (($validated['rule_mode'] ?? null) === SupplierRequirementRule::MODE_CONDITIONS && $rule === []) {
            throw ValidationException::withMessages(['conditions' => __('procynia.supplier_management.validation.conditions_required')]);
        }

        if (! SupplierRequirementRule::isValid($rule)) {
            throw ValidationException::withMessages(['conditions' => __('procynia.supplier_management.validation.rules.choose', ['attribute' => __('procynia.supplier_management.control.fields.conditions')])]);
        }

        $requirement->forceFill([
            'title' => trim((string) $validated['title']),
            'description' => $text($validated['description'] ?? null),
            'guidance' => $text($validated['guidance'] ?? null),
            'theme' => $validated['theme'],
            'level' => $validated['level'],
            'control_point' => $validated['control_point'],
            'control_interval_months' => isset($validated['control_interval_months']) ? (int) $validated['control_interval_months'] : null,
            'applies_when' => $rule,
            'accepted_document_types' => array_values(array_intersect(SupplierDocument::TYPES, (array) ($validated['accepted_document_types'] ?? []))),
            'basis_text' => $text($validated['basis_text'] ?? null),
            'compliance_requirement_id' => $this->anchor($actor, $requirement, $validated['compliance_requirement_id'] ?? null),
            'updated_by' => (int) $actor->id,
        ]);
    }

    /**
     * The anchor to store. Unchanged unless the person can see both what is there now and what they
     * chose; a new anchor must be an active requirement they can read.
     */
    private function anchor(User $actor, SupplierControlRequirement $requirement, mixed $chosen): ?int
    {
        $current = $requirement->compliance_requirement_id !== null ? (int) $requirement->compliance_requirement_id : null;
        $chosen = $chosen !== null && $chosen !== '' ? (int) $chosen : null;

        if (! $this->compliance->canReadFromAnotherModule($actor)) {
            return $current;
        }

        if ($current !== null && $this->compliance->findVisibleRequirement($actor, $current) === null) {
            return $current;
        }

        if ($chosen === null || $chosen === $current) {
            return $chosen;
        }

        $anchor = $this->compliance->findVisibleRequirement($actor, $chosen);

        if ($anchor === null || $anchor->status !== ComplianceRequirement::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['compliance_requirement_id' => __('procynia.supplier_management.validation.requirement_not_available')]);
        }

        return (int) $anchor->id;
    }

    private function authorize(User $actor): void
    {
        if (! $this->access->canAssure($actor)) {
            throw new AuthorizationException;
        }
    }

    /** The requirement row, locked, checked again inside the lock; for one supplier, that supplier open. */
    private function lock(User $actor, SupplierControlRequirement $requirement): SupplierControlRequirement
    {
        if ($requirement->supplier_id !== null) {
            $this->lockOpenSupplier($actor, (int) $requirement->supplier_id);
        }

        return SupplierControlRequirement::query()
            ->where('customer_id', (int) $actor->customer_id)
            ->whereKey($requirement->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockOpenSupplier(User $actor, int $supplierId): Supplier
    {
        $supplier = $this->access->visibleSuppliers($actor)->whereKey($supplierId)->lockForUpdate()->firstOrFail();

        if ($supplier->isEnded()) {
            throw ValidationException::withMessages(['requirement' => __('procynia.supplier_management.validation.reopen_before_edit')]);
        }

        return $supplier;
    }
}
