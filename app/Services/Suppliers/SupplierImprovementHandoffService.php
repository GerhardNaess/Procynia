<?php

namespace App\Services\Suppliers;

use App\Models\ImprovementCase;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierImprovementCase;
use App\Models\User;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\Modules\ModuleEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Følg opp i Avvik og forbedringer» from a supplier, and what each side may learn about the other
 * afterwards (docs/supplier-management-v1-plan.md §7.4).
 *
 * Leverandøroppfølging never grows a follow-up system of its own. A case is registered through
 * ImprovementCaseCreator, exactly like one registered in Avvik og forbedringer, and from then on it
 * is that module's: type, fagområde, ansvarlig, frist, status, tiltak, verification and closing.
 * The supplier keeps a row saying the case concerns it (SupplierImprovementCase) and nothing else.
 *
 * WHO. supplier.edit on a supplier that is not ended (plan §9.2 — creating in another module), and
 * improvement.edit in the chosen fagområde with an owner who can read cases there — both checked by
 * the creator as for any new case. The person chooses the type, the area, the owner and the frist;
 * nothing is guessed, and the supplier gets no fagområde.
 *
 * ATOMIC. One transaction: the supplier row is locked against a simultaneous Avslutt, the form's
 * one-time key is looked for (a double submit finds the first case and creates no second), the case
 * is created and the row written. A refusal anywhere leaves neither.
 *
 * SEEING. The supplier page lists only the linked cases ImprovementCaseAccessService::visibleCases()
 * returns — a case the person cannot read is not listed, not counted, not hinted at. The case page
 * names the supplier only to someone who can read it in Leverandøroppfølging (provenanceFor()).
 */
class SupplierImprovementHandoffService
{
    /** How many existing cases «Koble til eksisterende sak» offers, newest first. */
    private const LINK_OPTIONS_LIMIT = 200;

    public function __construct(
        private readonly SupplierAccessService $suppliers,
        private readonly ImprovementCaseAccessService $improvements,
        private readonly ImprovementCaseCreator $creator,
        private readonly ModuleEntitlementService $entitlements,
    ) {}

    /**
     * Whether the supplier page may say anything about Avvik og forbedringer to this person: the
     * customer holds the module and the person can open it.
     */
    public function canReadCases(User $user): bool
    {
        $customer = $user->customer;

        return $customer !== null
            && $this->improvements->canOpenModule($user)
            && $this->entitlements->hasModule($customer, 'improvements');
    }

    /**
     * What the hand-off form needs from Avvik og forbedringer: the areas the person may register
     * cases in and who could be responsible in each. Empty when they may register in none — no area
     * is ever named that the person cannot use.
     *
     * @return array{area_options: list<array{id: int, name: string}>, owner_options: list<array{id: int, name: string, area_ids: list<int>}>}
     */
    public function formOptions(User $user): array
    {
        $areas = $this->canReadCases($user) ? $this->improvements->editableAreas($user) : collect();
        $areaIds = $areas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        return [
            'area_options' => $areas->map(fn ($area): array => ['id' => (int) $area->id, 'name' => $area->name])->values()->all(),
            'owner_options' => $this->improvements->ownerCandidates($user, $areaIds),
        ];
    }

    /**
     * Hand off. $validated holds type, title, description, business_area_id, owner_user_id,
     * due_date, handoff_key and, from an assessment, supplier_assessment_id.
     *
     * @param  array<string, mixed>  $validated
     */
    public function handOff(User $user, Supplier $supplier, array $validated): ImprovementCase
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        return DB::transaction(function () use ($user, $supplier, $validated): ImprovementCase {
            $locked = $this->lockOpen($supplier, 'title');

            $earlier = SupplierImprovementCase::query()
                ->where('customer_id', $locked->customer_id)
                ->where('supplier_id', $locked->id)
                ->where('handoff_key', (string) $validated['handoff_key'])
                ->first();

            if ($earlier !== null) {
                // The same form sent twice: the first submit already created the case.
                return ImprovementCase::query()->findOrFail($earlier->improvement_case_id);
            }

            $assessmentId = $this->assessmentToFollowUp($locked, $validated['supplier_assessment_id'] ?? null);

            $case = $this->creator->create($user, [
                'type' => $validated['type'],
                'title' => $validated['title'],
                'description' => $validated['description'],
                'business_area_id' => $validated['business_area_id'],
                'owner_user_id' => $validated['owner_user_id'],
                'occurred_at' => null,
                'due_date' => $validated['due_date'] ?? null,
            ]);

            SupplierImprovementCase::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'improvement_case_id' => (int) $case->id,
                'supplier_assessment_id' => $assessmentId,
                'origin' => SupplierImprovementCase::ORIGIN_HANDOFF,
                'handoff_key' => (string) $validated['handoff_key'],
                'created_by' => $user->id,
            ]);

            return $case;
        });
    }

    /** Koble til eksisterende sak: one the person can read, not already listed on the supplier. */
    public function link(User $user, Supplier $supplier, int $caseId): void
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        // A 422 rather than a 404: the id came from a form, and whether it names a case the person
        // cannot read or nothing at all, the answer is the same.
        $case = $this->canReadCases($user) ? $this->improvements->findVisible($user, $caseId) : null;

        if ($case === null) {
            throw ValidationException::withMessages(['improvement_case_id' => __('procynia.supplier_management.validation.case_not_available')]);
        }

        DB::transaction(function () use ($user, $supplier, $case): void {
            $locked = $this->lockOpen($supplier, 'improvement_case_id');

            if ($locked->improvementCaseLinks()->where('improvement_case_id', $case->id)->exists()) {
                throw ValidationException::withMessages(['improvement_case_id' => __('procynia.supplier_management.validation.case_already_linked')]);
            }

            SupplierImprovementCase::query()->create([
                'customer_id' => (int) $locked->customer_id,
                'supplier_id' => (int) $locked->id,
                'improvement_case_id' => (int) $case->id,
                'origin' => SupplierImprovementCase::ORIGIN_LINKED,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Fjern koblingen: only for a case connected afterwards, and only one the person can read. A
     * case created from the supplier stays connected — that it came from here is history.
     */
    public function unlink(User $user, Supplier $supplier, int $linkId): void
    {
        abort_unless($this->suppliers->canEdit($user), 403);

        $link = $supplier->improvementCaseLinks()->whereKey($linkId)->first();

        abort_if($link === null || ! $this->canReadCases($user) || $this->improvements->findVisible($user, (int) $link->improvement_case_id) === null, 404);

        if ($link->origin !== SupplierImprovementCase::ORIGIN_LINKED) {
            throw ValidationException::withMessages(['improvement_case_id' => __('procynia.supplier_management.validation.handoff_not_unlinkable')]);
        }

        DB::transaction(function () use ($supplier, $link): void {
            $this->lockOpen($supplier, 'improvement_case_id');
            $link->delete();
        });
    }

    /**
     * «Avvik og forbedringer hos leverandøren»: the linked cases the person can read, newest first,
     * with type, title, status and frist read from Avvik og forbedringer now. Null when the person
     * cannot read that module at all, so the page says nothing about it.
     *
     * @return list<array<string, mixed>>|null
     */
    public function casesFor(User $user, Supplier $supplier): ?array
    {
        if (! $this->canReadCases($user)) {
            return null;
        }

        $links = $supplier->improvementCaseLinks()->with('assessment:id,assessed_on')->get()->keyBy('improvement_case_id');

        if ($links->isEmpty()) {
            return [];
        }

        return $this->improvements->visibleCases($user)
            ->whereIn('improvement_cases.id', $links->keys()->all())
            ->orderByDesc('improvement_cases.created_at')
            ->orderByDesc('improvement_cases.id')
            ->get()
            ->map(function (ImprovementCase $case) use ($links): array {
                $link = $links[(int) $case->id];

                return [
                    'link_id' => (int) $link->id,
                    'id' => (int) $case->id,
                    'type' => $case->type,
                    'title' => $case->title,
                    'status' => $case->status,
                    'due_date' => $case->due_date?->format('Y-m-d'),
                    'url' => route('app.improvements.show', ['caseId' => $case->id]),
                    'origin' => $link->origin,
                    'assessed_on' => $link->assessment?->assessed_on?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The cases «Koble til eksisterende sak» offers: the person's visible cases not yet listed on
     * the supplier, newest first.
     *
     * @return list<array{id: int, title: string, type: string, status: string}>
     */
    public function linkOptions(User $user, Supplier $supplier): array
    {
        if (! $this->canReadCases($user)) {
            return [];
        }

        return $this->improvements->visibleCases($user)
            ->whereNotIn('improvement_cases.id', $supplier->improvementCaseLinks()->select('improvement_case_id'))
            ->orderByDesc('improvement_cases.created_at')
            ->orderByDesc('improvement_cases.id')
            ->limit(self::LINK_OPTIONS_LIMIT)
            ->get(['improvement_cases.id', 'improvement_cases.title', 'improvement_cases.type', 'improvement_cases.status'])
            ->map(fn (ImprovementCase $case): array => [
                'id' => (int) $case->id,
                'title' => $case->title,
                'type' => $case->type,
                'status' => $case->status,
            ])
            ->all();
    }

    /**
     * «Gjelder leverandør» on the case page — the suppliers the case concerns that the person can
     * read in Leverandøroppfølging. Null for everyone else, and for a case that concerns no supplier:
     * no name, no link, nothing about the supplier.
     *
     * @return list<array{name: string, url: string, from_supplier: bool, assessed_on: string|null}>|null
     */
    public function provenanceFor(User $user, ImprovementCase $case): ?array
    {
        if (! $this->suppliers->canReadFromAnotherModule($user)) {
            return null;
        }

        $links = SupplierImprovementCase::query()
            ->where('customer_id', $case->customer_id)
            ->where('improvement_case_id', $case->id)
            ->with('assessment:id,assessed_on')
            ->orderBy('id')
            ->get();

        $suppliers = $links->isEmpty()
            ? collect()
            : $this->suppliers->visibleSuppliers($user)->whereIn('suppliers.id', $links->pluck('supplier_id'))->get()->keyBy('id');

        $origins = $links
            ->filter(fn (SupplierImprovementCase $link): bool => $suppliers->has($link->supplier_id))
            ->map(fn (SupplierImprovementCase $link): array => [
                'name' => $suppliers[$link->supplier_id]->name,
                'url' => route('app.supplier-management.show', ['supplierId' => $link->supplier_id]),
                'from_supplier' => $link->origin === SupplierImprovementCase::ORIGIN_HANDOFF,
                'assessed_on' => $link->assessment?->assessed_on?->toDateString(),
            ])
            ->values()
            ->all();

        return $origins === [] ? null : $origins;
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

    /**
     * From a leverandørvurdering: one of this supplier's, whose result calls for follow-up — Delvis
     * or Ikke tilfredsstillende (plan §4.3). Null when the hand-off is from the supplier itself.
     */
    private function assessmentToFollowUp(Supplier $supplier, mixed $assessmentId): ?int
    {
        if ($assessmentId === null || $assessmentId === '') {
            return null;
        }

        $assessment = SupplierAssessment::query()
            ->where('supplier_id', $supplier->id)
            ->whereKey((int) $assessmentId)
            ->first();

        if ($assessment === null || $assessment->overall_result === SupplierAssessment::RESULT_SATISFACTORY) {
            throw ValidationException::withMessages(['supplier_assessment_id' => __('procynia.supplier_management.validation.assessment_not_for_follow_up')]);
        }

        return (int) $assessment->id;
    }
}
