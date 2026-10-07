<?php

namespace App\Services\Suppliers;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\Supplier;
use App\Models\SupplierComplianceRequirement;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Krav som gjelder leverandøren: «Legg til krav» and «Fjern krav» on a supplier
 * (docs/supplier-management-v1-plan.md §7.3).
 *
 * Leverandøroppfølging never grows a requirement register of its own. Requirements are registered,
 * assessed, retired and audited in Etterlevelse og revisjon; the supplier keeps a row saying a
 * requirement applies to it (SupplierComplianceRequirement) and nothing else. Saying so is a claim
 * about the supplier, not a change to the requirement.
 *
 * NO SUPPLIER STATUS FROM THE REQUIREMENT. A requirement assessed as Oppfylt in Etterlevelse og
 * revisjon means the organisation meets it — not that a given supplier does. Nothing here reads
 * assessments or ComplianceStatusResolver, and nothing about compliance status leaves this class.
 * Whether the supplier follows the requirements is judged in the supplier assessment (§4.3). The
 * requirement's own lifecycle is shown: a retired requirement is still listed, marked as retired.
 *
 * WHO. supplier.edit on a supplier that is not ended (plan §9.2) and compliance.view in a customer
 * holding Etterlevelse og revisjon (canReadFromAnotherModule()), for adding and removing alike. Only
 * active requirements can be added; one retired afterwards stays listed until removed.
 *
 * SEEING. The supplier page lists only the linked requirements visibleRequirements() returns — one
 * the person cannot read is not listed, not counted, not hinted at. Etterlevelse og revisjon does
 * not show the suppliers back in v1 (§7.3 decides no reverse view).
 */
class SupplierComplianceRequirementService
{
    /** How many requirements «Legg til krav» offers, in register order. */
    private const LINK_OPTIONS_LIMIT = 500;

    public function __construct(
        private readonly SupplierAccessService $suppliers,
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

    /** Legg til krav: an active requirement the person can read, not already listed here. */
    public function link(User $user, Supplier $supplier, int $requirementId): void
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        $requirement = $this->canReadRequirements($user) ? $this->compliance->findVisibleRequirement($user, $requirementId) : null;

        // A 422 rather than a 404: the id came from a form, and whether it names a requirement the
        // person cannot see, a retired one or nothing at all, the answer is the same.
        if ($requirement === null || ! $requirement->isActive()) {
            throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.requirement_not_available')]);
        }

        DB::transaction(function () use ($user, $supplier, $requirement): void {
            $locked = $this->lockOpen($supplier);

            if ($locked->requirementLinks()->where('compliance_requirement_id', $requirement->id)->exists()) {
                throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.requirement_already_linked')]);
            }

            SupplierComplianceRequirement::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'compliance_requirement_id' => (int) $requirement->id,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Fjern krav: the row goes, the requirement stays in Etterlevelse og revisjon, untouched — also
     * when it has been retired since. A link to a requirement the person cannot see is a 404.
     */
    public function unlink(User $user, Supplier $supplier, int $linkId): void
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        $link = $supplier->requirementLinks()->whereKey($linkId)->first();
        $visible = $link !== null
            && $this->canReadRequirements($user)
            && $this->compliance->findVisibleRequirement($user, (int) $link->compliance_requirement_id) !== null;

        abort_unless($visible, 404);

        DB::transaction(function () use ($supplier, $link): void {
            $this->lockOpen($supplier);
            $link->delete();
        });
    }

    /**
     * «Krav som gjelder leverandøren»: the linked requirements the person can read, in register
     * order, with reference, title and kravkilde read from Etterlevelse og revisjon now, and whether
     * the requirement has been retired there. Never its compliance status. Null when the person
     * cannot read Etterlevelse og revisjon at all, so the page says nothing about it.
     *
     * @return list<array{link_id: int, id: int, reference: string|null, title: string, source_label: string|null, retired: bool, url: string}>|null
     */
    public function requirementsFor(User $user, Supplier $supplier): ?array
    {
        if (! $this->canReadRequirements($user)) {
            return null;
        }

        $links = $supplier->requirementLinks()->get()->keyBy('compliance_requirement_id');

        if ($links->isEmpty()) {
            return [];
        }

        return $this->inRegisterOrder($this->compliance->visibleRequirements($user)->whereIn('compliance_requirements.id', $links->keys()->all()))
            ->get()
            ->map(fn (ComplianceRequirement $requirement): array => [
                'link_id' => (int) $links[(int) $requirement->id]->id,
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
     * The requirements «Legg til krav» offers: active ones the person can read, not yet listed on the
     * supplier, in register order.
     *
     * @return list<array{id: int, reference: string|null, title: string, source_label: string|null}>
     */
    public function linkOptions(User $user, Supplier $supplier): array
    {
        if (! $this->canReadRequirements($user)) {
            return [];
        }

        $query = $this->compliance->visibleRequirements($user)
            ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
            ->whereNotIn('compliance_requirements.id', $supplier->requirementLinks()->select('compliance_requirement_id'));

        return $this->inRegisterOrder($query)
            ->limit(self::LINK_OPTIONS_LIMIT)
            ->get()
            ->map(fn (ComplianceRequirement $requirement): array => [
                'id' => (int) $requirement->id,
                'reference' => $requirement->reference,
                'title' => $requirement->title,
                'source_label' => $requirement->source ? $this->sourceLabel($requirement->source) : null,
            ])
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

    /** The supplier row, locked; refused for an ended supplier, which is read-only. */
    private function lockOpen(Supplier $supplier): Supplier
    {
        $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

        if ($locked->isEnded()) {
            throw ValidationException::withMessages(['requirement_id' => __('procynia.supplier_management.validation.reopen_before_edit')]);
        }

        return $locked;
    }
}
