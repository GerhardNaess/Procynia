<?php

namespace App\Services\Suppliers;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tidligere koblinger til etterlevelseskrav: the links between a supplier and requirements in
 * Etterlevelse og revisjon made under Leverandøroppfølging v1 (docs/supplier-management-v1-plan.md
 * §7.3), read-only.
 *
 * RETIRED IN THE USER FLOW on 2026-10-13. A link had no status, no control and no follow-up, and
 * overlapped with a control requirement's anchor in Etterlevelse og revisjon («Forankret i»), which
 * is how a supplier requirement is tied to a compliance requirement now. Nothing writes a link any
 * more; the rows made before are kept — never converted, never deleted here — and listed under
 * Historikk. A requirement deleted in Etterlevelse og revisjon still takes its rows along (the
 * foreign key's CASCADE), and a supplier deleted under the usual rules takes its links along
 * (SupplierLifecycleService::delete()) — they never block deleting it on their own.
 *
 * NO SUPPLIER STATUS FROM THE REQUIREMENT. A link never said the supplier meets the requirement.
 * Nothing here reads assessments or ComplianceStatusResolver; the requirement's own lifecycle is
 * shown — a retired requirement is marked as retired.
 *
 * SEEING. compliance.view in a customer holding Etterlevelse og revisjon (canReadFromAnotherModule()),
 * and only the requirements visibleRequirements() returns — one the person cannot read is not
 * listed, not counted, not hinted at.
 */
class SupplierComplianceRequirementService
{
    public function __construct(
        private readonly ComplianceAccessService $compliance,
    ) {}

    /**
     * Whether the supplier page may say anything about Etterlevelse og revisjon to this person: the
     * customer holds the module and the person has compliance.view.
     */
    public function canReadRequirements(User $user): bool
    {
        return $this->compliance->canReadFromAnotherModule($user);
    }

    /**
     * Tidligere koblinger til etterlevelseskrav: the linked requirements the person can read, in register
     * order, with reference, title and kravkilde read from Etterlevelse og revisjon now, and whether
     * the requirement has been retired there. Never its compliance status. Null when the person
     * cannot read Etterlevelse og revisjon at all, so the page says nothing about it.
     *
     * @return list<array{id: int, reference: string|null, title: string, source_label: string|null, retired: bool, url: string}>|null
     */
    public function requirementsFor(User $user, Supplier $supplier): ?array
    {
        if (! $this->canReadRequirements($user)) {
            return null;
        }

        $linked = $supplier->requirementLinks()->pluck('compliance_requirement_id')->all();

        if ($linked === []) {
            return [];
        }

        return $this->inRegisterOrder($this->compliance->visibleRequirements($user)->whereIn('compliance_requirements.id', $linked))
            ->get()
            ->map(fn (ComplianceRequirement $requirement): array => [
                'id' => (int) $requirement->id,
                'reference' => $requirement->reference,
                'title' => $requirement->title,
                'source_label' => $requirement->source ? $this->sourceLabel($requirement->source) : null,
                'retired' => ! $requirement->isActive(),
                'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * The order Etterlevelse og revisjon lists its register in: active first, then by kravkilde,
     * reference and title.
     *
     * @param  Builder<ComplianceRequirement>  $query
     * @return Builder<ComplianceRequirement>
     */
    private function inRegisterOrder(Builder $query): Builder
    {
        return $query
            ->with('source:id,name,version')
            ->orderByRaw('CASE compliance_requirements.status WHEN ? THEN 0 ELSE 1 END', [ComplianceRequirement::STATUS_ACTIVE])
            ->orderBy(ComplianceSource::query()->select('name')->whereColumn('compliance_sources.id', 'compliance_requirements.source_id'))
            ->orderBy('compliance_requirements.source_id')
            ->orderByRaw('lower(compliance_requirements.reference) ASC NULLS LAST')
            ->orderBy('compliance_requirements.title')
            ->orderBy('compliance_requirements.id');
    }

    /** The kravkilde as Etterlevelse og revisjon names it: «ISO 27001 (2022)», or the name alone. */
    private function sourceLabel(ComplianceSource $source): string
    {
        return $source->version !== null && $source->version !== '' ? "{$source->name} ({$source->version})" : $source->name;
    }
}
