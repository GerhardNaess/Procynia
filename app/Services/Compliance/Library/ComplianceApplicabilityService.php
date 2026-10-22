<?php

namespace App\Services\Compliance\Library;

use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Support\CustomerPermissionCatalog;
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
 * Only library requirements, and only active ones. Who decided and when is recorded on the row.
 * compliance.edit, in the requirement's own customer with the module, is checked here (phase 0
 * step 13).
 */
class ComplianceApplicabilityService
{
    public function __construct(
        private readonly ComplianceAccessService $access,
    ) {}

    public function set(ComplianceRequirement $requirement, User $actor, string $applicability, ?string $rationale): ComplianceRequirement
    {
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
