<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ImprovementCase;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierCriticalityChange;
use App\Models\SupplierDocument;
use App\Models\SupplierProfile;
use App\Models\SupplierProfileChange;
use App\Models\SupplierRequirementEvaluationDocument;
use App\Models\SupplierStatusChange;
use App\Models\User;
use App\Services\Suppliers\Assurance\SupplierRequirementPayload;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierAttentionService;
use App\Services\Suppliers\SupplierComplianceRequirementService;
use App\Services\Suppliers\SupplierCriticalityService;
use App\Services\Suppliers\SupplierImprovementHandoffService;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Services\Suppliers\SupplierReviewSchedule;
use App\Services\Suppliers\SupplierRiskService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leverandøroppfølging → Leverandører: the register, one supplier, and its lifecycle.
 *
 * The route guard has already refused a customer without the `supplier` module. Every read starts
 * from SupplierAccessService, which narrows to the user's own customer and to nothing at all
 * without supplier.view — before any id is looked at. A supplier of another customer is a 404 like
 * an id that does not exist; a user without supplier.view gets a 403 on everything. System Owner is
 * no exception — supplier is an explicit-grant domain.
 *
 * Leverandørvurderinger are shown here and registered by SupplierAssessmentController
 * (supplier.assess). The dokumentasjonsoversikt is shown here and written by
 * SupplierDocumentController (supplier.edit). Avvik og forbedringer hos leverandøren is read here
 * through SupplierImprovementHandoffService and written by SupplierImprovementController; Risikoer
 * som gjelder leverandøren likewise through SupplierRiskService and SupplierRiskController, and Krav
 * som gjelder leverandøren through SupplierComplianceRequirementService and
 * SupplierComplianceRequirementController — never with the requirement's compliance status.
 *
 * supplier.edit registers and changes suppliers, classifies their criticality (Vurder/Endre
 * kritikalitet, SupplierCriticalityService, the only writer of a criticality change) and moves them
 * through Ta i bruk, Avslutt and Gjenåpne (SupplierLifecycleService, the only writer of a status
 * change); supplier.delete deletes one registered by mistake and never used. supplier.assess — the
 * supplier assessment of how a supplier performs — is not used here: criticality is how important
 * the supplier is, a register decision (plan §9.2).
 *
 * The leverandørprofil (supplier-assurance-v2-plan §4) is shown here with its history and written by
 * SupplierProfileController (supplier.edit; supplier.assure grants nothing there). Krav og
 * kvalifikasjoner — which control requirements apply and why — is SupplierRequirementPayload's, and
 * is changed by SupplierRequirementOverrideController and SupplierControlRequirementController
 * (supplier.assure).
 *
 * «Trenger oppmerksomhet» is SupplierAttentionService's: a panel and a filter on the register, the
 * reasons inline on the supplier page — read from the supplier's own data only.
 *
 * An ended supplier is read-only until it is reopened.
 */
class SupplierManagementController extends Controller
{
    /** The register's status filter when none is chosen: everything not ended. */
    private const STATUS_FILTER_OPEN = '';

    private const STATUS_FILTER_ALL = 'all';

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierLifecycleService $lifecycle,
        private readonly SupplierCriticalityService $criticality,
        private readonly SupplierReviewSchedule $schedule,
        private readonly SupplierImprovementHandoffService $improvements,
        private readonly SupplierRiskService $risks,
        private readonly SupplierComplianceRequirementService $requirements,
        private readonly SupplierAttentionService $attention,
        private readonly SupplierRequirementPayload $controlRequirements,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', self::STATUS_FILTER_OPEN);
        $status = in_array($status, [self::STATUS_FILTER_ALL, ...Supplier::STATUSES], true) ? $status : self::STATUS_FILTER_OPEN;
        $category = in_array($request->query('category'), Supplier::CATEGORIES, true) ? (string) $request->query('category') : '';
        $criticality = in_array($request->query('criticality'), Supplier::CRITICALITIES, true) ? (string) $request->query('criticality') : '';
        $attentionOnly = $request->boolean('attention');

        $query = $this->access->visibleSuppliers($user);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->whereRaw('lower(suppliers.name) like ?', [$needle])
                    ->orWhereRaw("lower(coalesce(suppliers.organization_number, '')) like ?", [$needle]);
            });
        }

        match ($status) {
            self::STATUS_FILTER_OPEN => $query->where('suppliers.status', '!=', Supplier::STATUS_ENDED),
            self::STATUS_FILTER_ALL => null,
            default => $query->where('suppliers.status', $status),
        };

        if ($category !== '') {
            $query->where('suppliers.category', $category);
        }

        if ($criticality !== '') {
            $query->where('suppliers.criticality', $criticality);
        }

        $suppliers = $query
            ->select('suppliers.*')
            // The day of the current assessment, for Neste vurdering; never more than that.
            ->addSelect(['latest_assessed_on' => SupplierAssessment::query()
                ->selectRaw('max(assessed_on)')
                ->whereColumn('supplier_assessments.supplier_id', 'suppliers.id')
                ->whereColumn('supplier_assessments.customer_id', 'suppliers.customer_id')])
            ->with('owner:id,name')
            ->orderByRaw('lower(suppliers.name)')
            ->orderBy('suppliers.id')
            ->get();

        if ($attentionOnly) {
            $findings = $this->attention->findingsForSuppliers($suppliers);
            $suppliers = $suppliers->filter(fn (Supplier $supplier): bool => $findings[(int) $supplier->id] !== [])->values();
        }

        $canEdit = $this->access->canEdit($user);

        return Inertia::render('App/SupplierManagement/Index', [
            'suppliers' => $suppliers->map(fn (Supplier $supplier): array => $this->row($supplier))->all(),
            // Only what the user can see — which in v1 is the customer's whole register, or nothing.
            'visible_count' => $this->access->visibleSuppliers($user)->count(),
            // The worklist of the whole register the user can see, whatever the filters above.
            'attention' => $this->attention->overview($user),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'category' => $category,
                'criticality' => $criticality,
                'attention' => $attentionOnly,
            ],
            'statuses' => Supplier::STATUSES,
            'initial_statuses' => Supplier::INITIAL_STATUSES,
            'categories' => Supplier::CATEGORIES,
            'criticalities' => Supplier::CRITICALITIES,
            'review_intervals' => Supplier::REVIEW_INTERVALS,
            'permissions' => [
                'can_edit' => $canEdit,
            ],
            'owner_options' => $canEdit ? $this->access->ownerCandidates($user) : [],
        ]);
    }

    public function show(int $supplierId): Response
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        $supplier->loadMissing(['owner:id,name', 'createdBy:id,name']);

        $canEdit = $this->access->canEdit($user);
        $open = ! $supplier->isEnded();
        // Reached through visibleSuppliers(); the history carries no access of its own.
        $changes = $supplier->statusChanges()->with('changedBy:id,name')->get();
        $criticalityChanges = $supplier->criticalityChanges()->with('changedBy:id,name')->get();
        $assessments = $supplier->assessments()->with('assessedBy:id,name')->get();
        $documents = $supplier->documents()->with('updatedBy:id,name')->get();
        $undeletable = $this->documentsInControls($documents);
        $today = now();
        // Dokumentasjon: supplier.edit, or supplier.assure so the person who controls can register the
        // documentation they rely on (supplier-assurance-v2-plan §13.2).
        $canDocument = $canEdit || $this->access->canAssure($user);
        $canAssess = $this->access->canAssess($user);
        $canDelete = $this->access->canDelete($user);

        return Inertia::render('App/SupplierManagement/Show', [
            'supplier' => $this->row($supplier, $assessments->first()?->assessed_on?->toDateString()) + [
                'contact_name' => $supplier->contact_name,
                'contact_email' => $supplier->contact_email,
                'contact_phone' => $supplier->contact_phone,
                'note' => $supplier->note,
            ],
            'registered' => [
                'at' => $supplier->created_at?->toIso8601String(),
                'by_name' => $supplier->createdBy?->name,
                // The status it was registered with: where the oldest change started, or where it
                // still is.
                'status' => $changes->last()?->from_status ?? $supplier->status,
            ],
            'criticality' => $this->criticalityPayload($supplier, $criticalityChanges),
            'profile' => $this->profilePayload($supplier),
            // Krav og kvalifikasjoner (supplier-assurance-v2-plan §5.2): computed on read, never stored.
            // null for a customer that has no control requirements.
            'control_requirements' => $this->controlRequirements->forSupplier($user, $supplier),
            'attention' => $this->attention->findingsForSupplier($supplier),
            'assessments' => $assessments->map(fn (SupplierAssessment $assessment): array => [
                'id' => (int) $assessment->id,
                'assessed_on' => $assessment->assessed_on?->toDateString(),
                'assessed_by_name' => $assessment->assessedBy?->name,
                'ratings' => $assessment->only(SupplierAssessment::CRITERIA),
                'overall_result' => $assessment->overall_result,
                'rationale' => $assessment->rationale,
                'criticality' => $assessment->criticality,
                'review_interval_months' => $assessment->review_interval_months,
                'recorded_at' => $assessment->recorded_at?->toIso8601String(),
            ])->all(),
            'documents' => $documents->map(fn (SupplierDocument $document): array => [
                'id' => (int) $document->id,
                'document_type' => $document->document_type,
                'title' => $document->title,
                'standard' => $document->standard,
                'location' => $document->location,
                'valid_from' => $document->valid_from?->toDateString(),
                'valid_until' => $document->valid_until?->toDateString(),
                'comment' => $document->comment,
                'status' => $document->validityStatus($today),
                // Used as the basis of a control, or renewing such a row: kept (plan §10.4).
                'deletable' => ! isset($undeletable[(int) $document->id]),
                'updated_at' => $document->updated_at?->toIso8601String(),
                'updated_by_name' => $document->updatedBy?->name,
            ])->all(),
            // null, not empty: the person cannot read Avvik og forbedringer, so nothing is said about it.
            'improvement_cases' => $this->improvements->casesFor($user, $supplier),
            'improvement_handoff' => $canEdit && $open ? $this->improvements->formOptions($user) + [
                'link_options' => $this->improvements->linkOptions($user, $supplier),
                'types' => ImprovementCase::TYPES,
            ] : null,
            // null, not empty: the person cannot read Risiko, so nothing is said about it.
            'risks' => $this->risks->risksFor($user, $supplier),
            'risk_handoff' => $canEdit && $open && $this->risks->canReadRisks($user) ? $this->risks->formOptions($user) + [
                'link_options' => $this->risks->linkOptions($user, $supplier),
            ] : null,
            // null, not empty: the person cannot read Etterlevelse og revisjon. No compliance status.
            'requirements' => $this->requirements->requirementsFor($user, $supplier),
            'requirement_linking' => $canEdit && $open && $this->requirements->canReadRequirements($user) ? [
                'link_options' => $this->requirements->linkOptions($user, $supplier),
            ] : null,
            'status_history' => $changes->map(fn (SupplierStatusChange $change): array => [
                'id' => (int) $change->id,
                'from_status' => $change->from_status,
                'to_status' => $change->to_status,
                'reason' => $change->reason,
                'changed_at' => $change->changed_at?->toIso8601String(),
                'changed_by_name' => $change->changedBy?->name,
            ])->all(),
            'permissions' => [
                // An ended supplier is reopened before it is changed.
                'can_edit' => $canEdit && $open,
                'can_activate' => $canEdit && $supplier->status === Supplier::STATUS_ONBOARDING,
                'can_end' => $canEdit && $open,
                'can_reopen' => $canEdit && ! $open,
                'can_change_criticality' => $canEdit && $open,
                // The profile is supplier.edit only — never supplier.assure (supplier-assurance-v2-plan §13.2).
                'can_edit_profile' => $canEdit && $open,
                // Dokumentasjon: supplier.edit or supplier.assure, and only while the supplier is not ended.
                'can_manage_documents' => $canDocument && $open,
                // Says why the risk, case and requirement links are missing, for someone who could otherwise make them.
                'has_edit_right' => $canEdit,
                // Says why the documentation is read-only, for someone who could otherwise change it.
                'has_document_right' => $canDocument,
                // Only an active supplier is assessed (plan §4.3).
                'can_assess' => $canAssess && $supplier->status === Supplier::STATUS_ACTIVE,
                // Says why Vurder leverandør is missing, for someone who could otherwise assess.
                'has_assess_right' => $canAssess,
                'can_delete' => $canDelete && $supplier->isDeletable(),
                // Says why the delete button is missing, for someone who could otherwise delete.
                'has_delete_right' => $canDelete,
            ],
            'categories' => Supplier::CATEGORIES,
            'criticalities' => Supplier::CRITICALITIES,
            'review_intervals' => Supplier::REVIEW_INTERVALS,
            'ratings' => SupplierAssessment::RATINGS,
            'criteria' => SupplierAssessment::CRITERIA,
            'results' => SupplierAssessment::RESULTS,
            'document_types' => SupplierDocument::TYPES,
            'document_standards' => SupplierDocument::STANDARD_SUGGESTIONS,
            'today' => $today->toDateString(),
            'owner_options' => $canEdit && $open ? $this->access->ownerCandidates($user) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        abort_unless($this->access->canEdit($user), 403);

        // Registering asks, besides the master data, whether the supplier is in use and how important
        // it is — all checked at once, so every missing answer is shown together.
        [$fields, $validated] = $this->validatedFields($request, $user, null, [
            'initial_status' => ['required', 'string', Rule::in(Supplier::INITIAL_STATUSES)],
            ...SupplierCriticalityService::rules(),
        ]);
        $initialStatus = $validated['initial_status'];
        $classification = SupplierCriticalityService::classification($validated);

        $supplier = $this->guardOrganizationNumberRace(function () use ($fields, $user, $initialStatus, $classification): Supplier {
            $supplier = new Supplier($fields + [
                'customer_id' => (int) $user->customer_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            // The status and classification it is registered with are the supplier's own; neither is
            // a change.
            $supplier->status = $initialStatus;
            $supplier->forceFill($classification);
            $supplier->save();

            return $supplier;
        });

        return redirect()
            ->route('app.supplier-management.show', ['supplierId' => $supplier->id])
            ->with('success', __('procynia.supplier_management.flash.created'));
    }

    public function update(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        if ($supplier->isEnded()) {
            return back()->with('error', __('procynia.supplier_management.validation.reopen_before_edit'));
        }

        [$fields] = $this->validatedFields($request, $user, $supplier);

        $this->guardOrganizationNumberRace(fn () => $supplier->fill($fields + ['updated_by' => $user->id])->save());

        return back()->with('success', __('procynia.supplier_management.flash.updated'));
    }

    /**
     * Vurder kritikalitet / Endre kritikalitet: the level the user chose, the review interval, the
     * four answers it was decided on, and why. Written to the history; refused for an ended
     * supplier.
     */
    public function changeCriticality(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        if ($supplier->isEnded()) {
            return back()->with('error', __('procynia.supplier_management.validation.reopen_before_edit'));
        }

        $validated = $request->validate([
            ...SupplierCriticalityService::rules(),
            'reason' => ['required', 'string', 'max:5000'],
        ], SupplierCriticalityService::messages(), SupplierValidationMessages::attributes());

        $this->criticality->change($supplier, $user, SupplierCriticalityService::classification($validated), $validated['reason']);

        return back()->with('success', __('procynia.supplier_management.flash.criticality_changed'));
    }

    /** Ta i bruk: Under vurdering → Aktiv. */
    public function activate(int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->activate($supplier, $user);

        return back()->with('success', __('procynia.supplier_management.flash.activated'));
    }

    /** Avslutt leverandør: no longer in use, or not chosen. Always says why. */
    public function end(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->end($supplier, $user, $this->validatedReason($request));

        return back()->with('success', __('procynia.supplier_management.flash.ended'));
    }

    /** Gjenåpne leverandør: in use again. Always says why; the ending stays in the history. */
    public function reopen(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->reopen($supplier, $user, $this->validatedReason($request));

        return back()->with('success', __('procynia.supplier_management.flash.reopened'));
    }

    public function destroy(int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canDelete($user), 403);

        if (! $supplier->isDeletable()) {
            return back()->with('error', __('procynia.supplier_management.validation.not_deletable'));
        }

        $supplier->delete();

        return redirect()->route('app.supplier-management.index')->with('success', __('procynia.supplier_management.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleSupplierOrFail(User $user, int $supplierId): Supplier
    {
        return $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
    }

    /**
     * The same master data for registering and editing, checked against what the user can reach.
     * Status and criticality are not among them: both are chosen at registration (passed in as
     * $extraRules) and then move only through the lifecycle and Endre kritikalitet.
     *
     * @param  array<string, mixed>  $extraRules
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the master data, and everything validated
     */
    private function validatedFields(Request $request, User $user, ?Supplier $current, array $extraRules = []): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization_number' => ['nullable', 'string', 'max:50'],
            'category' => ['required', 'string', Rule::in(Supplier::CATEGORIES)],
            'deliverable_description' => ['required', 'string', 'max:5000'],
            'owner_user_id' => ['required', 'integer'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:10000'],
            ...$extraRules,
        ], SupplierCriticalityService::messages(), SupplierValidationMessages::attributes());

        // A 422 rather than a 404: the id came from a form, and whether it names another customer's
        // user or nobody at all, the answer is the same.
        $owner = User::query()->where('customer_id', (int) $user->customer_id)->find((int) $validated['owner_user_id']);

        if (! $this->access->isValidOwner($owner, (int) $user->customer_id)) {
            throw ValidationException::withMessages(['owner_user_id' => __('procynia.supplier_management.validation.owner_not_allowed')]);
        }

        // «987 654 321» and «987654321» are the same number.
        $organizationNumber = preg_replace('/\s+/u', '', (string) ($validated['organization_number'] ?? ''));
        $organizationNumber = $organizationNumber !== '' ? $organizationNumber : null;

        if ($organizationNumber !== null && $this->organizationNumberTaken((int) $user->customer_id, $organizationNumber, $current?->id)) {
            throw ValidationException::withMessages(['organization_number' => __('procynia.supplier_management.validation.organization_number_taken')]);
        }

        return [[
            'name' => trim($validated['name']),
            'organization_number' => $organizationNumber,
            'category' => $validated['category'],
            'deliverable_description' => trim($validated['deliverable_description']),
            'owner_user_id' => (int) $owner->id,
            'contact_name' => $this->optional($validated['contact_name'] ?? null),
            'contact_email' => $this->optional($validated['contact_email'] ?? null),
            'contact_phone' => $this->optional($validated['contact_phone'] ?? null),
            'note' => $this->optional($validated['note'] ?? null),
        ], $validated];
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** Within the customer — the same rule as the database's partial unique index. */
    private function organizationNumberTaken(int $customerId, string $organizationNumber, ?int $exceptId): bool
    {
        return Supplier::query()
            ->where('customer_id', $customerId)
            ->where('organization_number', $organizationNumber)
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Two saves of the same organisation number at once both pass the check above; the unique
     * index refuses the second, and it gets the same answer the check would have given.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guardOrganizationNumberRace(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['organization_number' => __('procynia.supplier_management.validation.organization_number_taken')]);
        }
    }

    private function validatedReason(Request $request): string
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes())['reason'];
    }

    /**
     * One supplier as the register and the page show it. Neste vurdering is the current
     * assessment's day plus the supplier's current interval, computed here and never stored.
     *
     * @return array<string, mixed>
     */
    private function row(Supplier $supplier, ?string $latestAssessedOn = null): array
    {
        $latestAssessedOn ??= $supplier->getAttribute('latest_assessed_on');
        $next = $this->schedule->nextReviewOn($supplier->review_interval_months, $latestAssessedOn !== null ? Carbon::parse($latestAssessedOn) : null);

        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->name,
            'organization_number' => $supplier->organization_number,
            'category' => $supplier->category,
            'deliverable_description' => $supplier->deliverable_description,
            'status' => $supplier->status,
            'criticality' => $supplier->criticality,
            'review_interval_months' => $supplier->review_interval_months,
            'last_assessed_on' => $latestAssessedOn !== null ? Carbon::parse($latestAssessedOn)->toDateString() : null,
            'next_review_on' => $next?->toDateString(),
            'owner_user_id' => $supplier->owner_user_id !== null ? (int) $supplier->owner_user_id : null,
            'owner_name' => $supplier->owner?->name,
            'url' => route('app.supplier-management.show', ['supplierId' => $supplier->id]),
        ];
    }

    /**
     * Hvor viktig er leverandøren for oss?: the current classification, when and by whom it was last
     * decided and why, and every change before it — newest first, each with the four answers before
     * and after. The classification the supplier was registered with closes the list; the oldest
     * change's from_* says what it was, or the supplier itself when nothing has changed since.
     *
     * @param  Collection<int, SupplierCriticalityChange>  $changes
     * @return array<string, mixed>
     */
    /**
     * The documentation rows that may not be deleted: those named in a control, and every row renewing
     * one of them (SupplierDocument::isDeletable(), for the whole list in one query).
     *
     * @param  Collection<int, SupplierDocument>  $documents
     * @return array<int, true>
     */
    private function documentsInControls(Collection $documents): array
    {
        $kept = SupplierRequirementEvaluationDocument::query()
            ->whereIn('supplier_document_id', $documents->pluck('id'))
            ->distinct()
            ->pluck('supplier_document_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
        $replacedBy = $documents->pluck('replaced_by_document_id', 'id');

        foreach (array_keys($kept) as $id) {
            $seen = [];

            while (($next = $replacedBy->get($id)) !== null && ! isset($seen[$id])) {
                $seen[$id] = true;
                $id = (int) $next;
                $kept[$id] = true;
            }
        }

        return $kept;
    }

    private function criticalityPayload(Supplier $supplier, $changes): array
    {
        $latest = $changes->first();
        $oldest = $changes->last();

        $registered = $oldest !== null
            ? ($oldest->from_criticality !== null ? $this->classificationFrom($oldest, 'from_') : null)
            : $supplier->classification();

        return [
            'current' => $supplier->classification(),
            'decided_at' => $latest !== null ? $latest->changed_at?->toIso8601String() : ($registered !== null ? $supplier->created_at?->toIso8601String() : null),
            'decided_by_name' => $latest !== null ? $latest->changedBy?->name : ($registered !== null ? $supplier->createdBy?->name : null),
            'reason' => $latest?->reason,
            'history' => $changes->map(fn (SupplierCriticalityChange $change): array => [
                'id' => (int) $change->id,
                'from' => $change->from_criticality !== null ? $this->classificationFrom($change, 'from_') : null,
                'to' => $this->classificationFrom($change, 'to_'),
                'reason' => $change->reason,
                'changed_at' => $change->changed_at?->toIso8601String(),
                'changed_by_name' => $change->changedBy?->name,
            ])->all(),
            'registered' => $registered !== null ? [
                'classification' => $registered,
                'at' => $supplier->created_at?->toIso8601String(),
                'by_name' => $supplier->createdBy?->name,
            ] : null,
        ];
    }

    /**
     * Leverandørprofil: the current answers (null before anyone has filled it in), which questions are
     * asked for this supplier, whether it is complete, the four criticality answers it is read with,
     * and every save newest first with the whole profile before and after. The answers are codes; the
     * page names them.
     *
     * @return array<string, mixed>
     */
    private function profilePayload(Supplier $supplier): array
    {
        $profile = $supplier->profile()->with('updatedBy:id,name')->first();
        $answers = $profile?->answers();
        $basis = $supplier->classification();

        return [
            'answers' => $answers,
            'visible' => SupplierProfile::visibleFields($supplier, $answers ?? SupplierProfile::emptyAnswers()),
            'complete' => $answers !== null && SupplierProfile::isComplete($supplier, $answers),
            'completed_at' => $profile?->completed_at?->toIso8601String(),
            'updated_at' => $profile?->updated_at?->toIso8601String(),
            'updated_by_name' => $profile?->updatedBy?->name,
            // Shown read-only on the profile: they change under Kritikalitet.
            'basis' => $basis !== null ? array_intersect_key($basis, array_flip(Supplier::CRITICALITY_QUESTIONS)) : null,
            'history' => $supplier->profileChanges()->with('changedBy:id,name')->get()
                ->map(fn (SupplierProfileChange $change): array => [
                    'id' => (int) $change->id,
                    'from' => $change->from_profile,
                    'to' => $change->to_profile,
                    'reason' => $change->reason,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                    'changed_by_name' => $change->changedBy?->name,
                ])->all(),
            'options' => [
                'groups' => SupplierProfile::GROUPS,
                'answers' => SupplierProfile::ANSWERS,
                'data_roles' => SupplierProfile::DATA_ROLES,
                'data_locations' => SupplierProfile::DATA_LOCATIONS,
                'sectors' => SupplierProfile::SECTORS,
                'high_risk_categories' => SupplierProfile::HIGH_RISK_CATEGORIES,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function classificationFrom(SupplierCriticalityChange $change, string $side): array
    {
        $classification = [
            'criticality' => $change->{$side.'criticality'},
            'review_interval_months' => $change->{$side.'review_interval_months'},
        ];

        foreach (Supplier::CRITICALITY_QUESTIONS as $question) {
            $classification[$question] = (bool) $change->{$side.$question};
        }

        return $classification;
    }
}
