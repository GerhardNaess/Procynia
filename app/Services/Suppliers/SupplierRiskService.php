<?php

namespace App\Services\Suppliers;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\Supplier;
use App\Models\SupplierRisk;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskCreator;
use App\Services\Risk\RiskScoringPolicy;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Risikoer som gjelder leverandøren: «Opprett risiko» and «Koble til eksisterende risiko» from a
 * supplier, and what each side may learn about the other afterwards
 * (docs/supplier-management-v1-plan.md §7.2).
 *
 * Leverandøroppfølging never grows a risk system of its own. A risk is registered through
 * RiskCreator, exactly like one registered in Risiko, and from then on it is Risiko's: assessment,
 * inherent and residual risk, treatment, tiltak, acceptance and review. The supplier keeps a row
 * saying the risk concerns it (SupplierRisk) and nothing else. Criticality never sets a risk level.
 *
 * WHO. supplier.edit on a supplier that is not ended (plan §9.2 — creating in another module), and
 * on the Risiko side: risk.create in the chosen fagområde with an owner who can read risks there
 * (checked by RiskCreator, as for any new risk); risk.edit on the risk to link or unlink it. The
 * supplier gets no fagområde, and nothing is guessed.
 *
 * ATOMIC. One transaction: the supplier row is locked against a simultaneous Avslutt, the risk is
 * created and the row written. A refusal anywhere leaves neither.
 *
 * SEEING. The supplier page lists only the linked risks RiskAccessService::visibleRisks() returns —
 * a risk the person cannot read is not listed, not counted, not hinted at. The risk page names the
 * supplier only to someone who can read it in Leverandøroppfølging (provenanceFor()).
 */
class SupplierRiskService
{
    /** How many existing risks «Koble til eksisterende risiko» offers, by title. */
    private const LINK_OPTIONS_LIMIT = 200;

    public function __construct(
        private readonly SupplierAccessService $suppliers,
        private readonly RiskAccessService $risks,
        private readonly RiskCreator $creator,
        private readonly RiskScoringPolicy $scoring,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /**
     * Whether the supplier page may say anything about Risiko to this person: the customer holds the
     * module and the person can open it.
     */
    public function canReadRisks(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null
            && $this->risks->canOpenModule($user)
            && $this->entitlements->hasModule($customer, 'risk');
    }

    /**
     * What «Opprett risiko» needs from Risiko: the areas the person may create risks in and who could
     * own a risk in each. Empty when they may create in none — no area is ever named that the person
     * cannot use.
     *
     * @return array{area_options: list<array{id: int, name: string}>, owner_options: list<array{id: int, name: string, area_ids: list<int>}>}
     */
    public function formOptions(User $user): array
    {
        $areas = $this->canReadRisks($user) ? $this->risks->areasFor($user, CustomerPermissionCatalog::RISK_CREATE) : collect();
        $areaIds = $areas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        return [
            'area_options' => $areas->map(fn ($area): array => ['id' => (int) $area->id, 'name' => $area->name])->values()->all(),
            'owner_options' => $this->creator->ownerOptions($user, $areaIds),
        ];
    }

    /**
     * Opprett risiko. $validated holds what RiskCreator::rules() asks for; the risk starts in Risiko's
     * first lifecycle step, as one registered there does.
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, Supplier $supplier, array $validated): Risk
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        if (! $this->canReadRisks($user)) {
            throw ValidationException::withMessages(['business_area_id' => __('procynia.risk.validation.area_not_allowed')]);
        }

        return DB::transaction(function () use ($user, $supplier, $validated): Risk {
            $locked = $this->lockOpen($supplier, 'title');

            $risk = $this->creator->create($user, $validated);

            SupplierRisk::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'risk_id' => (int) $risk->id,
                'origin' => SupplierRisk::ORIGIN_CREATED_FROM_SUPPLIER,
                'created_by' => $user->id,
            ]);

            return $risk;
        });
    }

    /** Koble til eksisterende risiko: one the person can edit in Risiko, not already listed here. */
    public function link(User $user, Supplier $supplier, int $riskId): void
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        $risk = $this->editableRisk($user, $riskId);

        // A 422 rather than a 404: the id came from a form, and whether it names a risk the person
        // cannot see, one they cannot edit or nothing at all, the answer is the same.
        if ($risk === null) {
            throw ValidationException::withMessages(['risk_id' => __('procynia.supplier_management.validation.risk_not_available')]);
        }

        DB::transaction(function () use ($user, $supplier, $risk): void {
            $locked = $this->lockOpen($supplier, 'risk_id');

            if ($locked->riskLinks()->where('risk_id', $risk->id)->exists()) {
                throw ValidationException::withMessages(['risk_id' => __('procynia.supplier_management.validation.risk_already_linked')]);
            }

            SupplierRisk::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'risk_id' => (int) $risk->id,
                'origin' => SupplierRisk::ORIGIN_LINKED,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Fjern koblingen (plan §7.2: supplier.edit + risk.edit on the risk). Whichever way the risk came
     * to concern the supplier; the risk itself stays in Risiko, untouched. A link to a risk the
     * person cannot see is a 404; one they can see but not edit, a 403.
     */
    public function unlink(User $user, Supplier $supplier, int $linkId): void
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        $link = $supplier->riskLinks()->whereKey($linkId)->first();
        $risk = $link !== null && $this->canReadRisks($user) ? $this->risks->findVisible($user, (int) $link->risk_id) : null;

        abort_if($risk === null, 404);
        abort_unless($this->risks->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk), 403);

        DB::transaction(function () use ($supplier, $link): void {
            $this->lockOpen($supplier, 'risk_id');
            $link->delete();
        });
    }

    /**
     * «Risikoer som gjelder leverandøren»: the linked risks the person can read, by title, with
     * fagområde, status and level read from Risiko now. Null when the person cannot read Risiko at
     * all, so the page says nothing about it.
     *
     * @return list<array<string, mixed>>|null
     */
    public function risksFor(User $user, Supplier $supplier): ?array
    {
        if (! $this->canReadRisks($user)) {
            return null;
        }

        $links = $supplier->riskLinks()->get()->keyBy('risk_id');

        if ($links->isEmpty()) {
            return [];
        }

        $risks = $this->risks->visibleRisks($user)
            ->whereIn('risks.id', $links->keys()->all())
            ->with('businessArea:id,name')
            ->orderBy('risks.title')
            ->orderBy('risks.id')
            ->get();
        $latest = RiskAssessment::latestForRisks((int) $user->customer_id, $risks->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $editableAreas = $this->risks->areaIdsFor($user, CustomerPermissionCatalog::RISK_EDIT);

        return $risks
            ->map(fn (Risk $risk): array => [
                'link_id' => (int) $links[(int) $risk->id]->id,
                'id' => (int) $risk->id,
                'title' => $risk->title,
                'area_name' => $risk->businessArea?->name,
                'status' => $risk->status,
                'level' => $this->level($latest->get((int) $risk->id)),
                'url' => route('app.risk.show', ['riskId' => $risk->id]),
                'origin' => $links[(int) $risk->id]->origin,
                'can_unlink' => in_array((int) $risk->business_area_id, $editableAreas, true),
            ])
            ->values()
            ->all();
    }

    /**
     * The risks «Koble til eksisterende risiko» offers: those the person may edit in Risiko, not yet
     * listed on the supplier, by title.
     *
     * @return list<array{id: int, title: string, area_name: string|null}>
     */
    public function linkOptions(User $user, Supplier $supplier): array
    {
        if (! $this->canReadRisks($user)) {
            return [];
        }

        $editableAreas = $this->risks->areaIdsFor($user, CustomerPermissionCatalog::RISK_EDIT);

        return $this->risks->visibleRisks($user)
            ->whereIn('risks.business_area_id', $editableAreas === [] ? [0] : $editableAreas)
            ->whereNotIn('risks.id', $supplier->riskLinks()->select('risk_id'))
            ->with('businessArea:id,name')
            ->orderBy('risks.title')
            ->orderBy('risks.id')
            ->limit(self::LINK_OPTIONS_LIMIT)
            ->get()
            ->map(fn (Risk $risk): array => [
                'id' => (int) $risk->id,
                'title' => $risk->title,
                'area_name' => $risk->businessArea?->name,
            ])
            ->all();
    }

    /**
     * «Gjelder leverandør» on the risk page — the suppliers the risk concerns that the person can read
     * in Leverandøroppfølging. Null for everyone else, and for a risk that concerns no supplier: no
     * name, no link, nothing about the supplier.
     *
     * @return list<array{name: string, url: string, from_supplier: bool}>|null
     */
    public function provenanceFor(User $user, Risk $risk): ?array
    {
        if (! $this->suppliers->canReadFromAnotherModule($user)) {
            return null;
        }

        $links = SupplierRisk::query()
            ->where('customer_id', $risk->customer_id)
            ->where('risk_id', $risk->id)
            ->orderBy('id')
            ->get();

        $suppliers = $links->isEmpty()
            ? collect()
            : $this->suppliers->visibleSuppliers($user)->whereIn('suppliers.id', $links->pluck('supplier_id'))->get()->keyBy('id');

        $origins = $links
            ->filter(fn (SupplierRisk $link): bool => $suppliers->has($link->supplier_id))
            ->map(fn (SupplierRisk $link): array => [
                'name' => $suppliers[$link->supplier_id]->name,
                'url' => route('app.supplier-management.show', ['supplierId' => $link->supplier_id]),
                'from_supplier' => $link->origin === SupplierRisk::ORIGIN_CREATED_FROM_SUPPLIER,
            ])
            ->values()
            ->all();

        return $origins === [] ? null : $origins;
    }

    /** A risk the person can both see and edit in Risiko, or null. */
    private function editableRisk(User $user, int $riskId): ?Risk
    {
        $risk = $this->canReadRisks($user) ? $this->risks->findVisible($user, $riskId) : null;

        return $risk !== null && $this->risks->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk) ? $risk : null;
    }

    /**
     * The level as the risk page shows it, from the latest assessment: residual when it was assessed,
     * otherwise inherent; null when the risk is not assessed.
     *
     * @return array{kind: string, level: string}|null
     */
    private function level(?RiskAssessment $latest): ?array
    {
        if ($latest === null) {
            return null;
        }

        if ($latest->hasResidual()) {
            return ['kind' => 'residual', 'level' => $this->scoring->evaluate($latest->residual_likelihood, $latest->residual_consequence, $latest->criteria_key)['level']];
        }

        return ['kind' => 'inherent', 'level' => $this->scoring->evaluate($latest->inherent_likelihood, $latest->inherent_consequence, $latest->criteria_key)['level']];
    }

    /** The supplier row, locked; refused for an ended supplier, which is read-only. */
    private function lockOpen(Supplier $supplier, string $errorField): Supplier
    {
        $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

        if ($locked->isEnded()) {
            throw ValidationException::withMessages([$errorField => __('procynia.supplier_management.validation.reopen_before_edit')]);
        }

        return $locked;
    }
}
