<?php

namespace App\Services\Compliance\Library;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementApplicabilityChange;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Relevans — the customer's judgement of whether a library requirement applies: Gjelder, Ikke
 * relevant or Ikke vurdert. The only writer of compliance_requirements.applicability.
 *
 * Kept apart from the requirement's normative character: only a requirement the standard lets an
 * organisation exclude (applicability_rule conditional) can be Ikke relevant, and never without a
 * begrunnelse. A mandatory requirement applies within the scope, whatever anyone chooses here.
 * An Annex A control (applicability_rule control) is Kontrollvalg: Inkludert (applies) or Utelatt
 * (not_applicable), and both need a begrunnelse — together they are the basis for the Statement of
 * Applicability.
 *
 * Kept apart from etterlevelse too: relevance says whether the requirement is part of the work at
 * all; assessments say whether it is met. Only a requirement that applies is assessed.
 *
 * Only library requirements, and only active ones. The choice in force — who and when — is on the
 * row; every choice that changes something is kept as an immutable
 * ComplianceRequirementApplicabilityChange in the same transaction (A35).
 *
 * A person's decision (HumanDecision::COMPLIANCE_APPLICABILITY, phase 0 step 13): the guard, then
 * compliance.edit in the requirement's own customer with the module. The key stays the one the
 * register work uses, so who may decide is unchanged; only a person may.
 */
class ComplianceApplicabilityService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly ComplianceAccessService $access,
    ) {}

    public function set(ComplianceRequirement $requirement, User $actor, string $applicability, ?string $rationale): ComplianceRequirement
    {
        $actor = $this->guard->assert($actor, HumanDecision::COMPLIANCE_APPLICABILITY);
        $this->access->authorize($actor, CustomerPermissionCatalog::COMPLIANCE_EDIT, (int) $requirement->customer_id);

        $rationale = trim((string) $rationale);

        if (! in_array($applicability, ComplianceRequirement::APPLICABILITIES, true)) {
            throw ValidationException::withMessages(['applicability' => __('procynia.compliance.library.validation.applicability_unknown')]);
        }

        return DB::transaction(function () use ($requirement, $actor, $applicability, $rationale): ComplianceRequirement {
            $locked = ComplianceRequirement::query()->with('frameworkNode')->whereKey($requirement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isFromLibrary() || $locked->frameworkNode === null) {
                throw ValidationException::withMessages(['applicability' => __('procynia.compliance.library.validation.applicability_library_only')]);
            }

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['applicability' => __('procynia.compliance.library.validation.applicability_retired')]);
            }

            if ($applicability === ComplianceRequirement::APPLICABILITY_NOT_APPLICABLE && ! $locked->frameworkNode->mayBeNotApplicable()) {
                throw ValidationException::withMessages(['applicability' => __('procynia.compliance.library.validation.applicability_mandatory')]);
            }

            if ($rationale === '' && $locked->frameworkNode->requiresApplicabilityRationale($applicability)) {
                throw ValidationException::withMessages(['rationale' => __($locked->frameworkNode->isControl()
                    ? 'procynia.compliance.library.validation.control_rationale_required'
                    : 'procynia.compliance.library.validation.rationale_required')]);
            }

            $stored = $rationale !== '' ? $rationale : null;

            if ($locked->applicability !== $applicability || $locked->applicability_rationale !== $stored) {
                ComplianceRequirementApplicabilityChange::query()->create([
                    'customer_id' => (int) $locked->customer_id,
                    'requirement_id' => (int) $locked->id,
                    'from_applicability' => $locked->applicability,
                    'to_applicability' => $applicability,
                    'rationale' => $stored,
                    'changed_by_user_id' => (int) $actor->id,
                    'changed_at' => now(),
                ]);
            }

            $locked->forceFill([
                'applicability' => $applicability,
                'applicability_rationale' => $rationale !== '' ? $rationale : null,
                'applicability_set_by' => (int) $actor->id,
                'applicability_set_at' => now(),
                'updated_by' => (int) $actor->id,
            ])->save();

            return $locked;
        });
    }
}
