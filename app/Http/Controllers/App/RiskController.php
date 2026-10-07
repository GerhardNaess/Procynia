<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\BusinessArea;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskAttentionService;
use App\Services\Risk\RiskControlService;
use App\Services\Risk\RiskCreator;
use App\Services\Risk\RiskQualityContextService;
use App\Services\Risk\RiskReviewSchedule;
use App\Services\Risk\RiskScoringPolicy;
use App\Services\Risk\RiskTreatmentService;
use App\Services\Risk\RiskWikiKnowledgeService;
use App\Services\Suppliers\SupplierRiskService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use App\Support\Risk\RiskValidationMessages;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Risiko — the risk register.
 *
 * Every read starts from RiskAccessService::visibleRisks(), so a risk outside the user's
 * fagområder never enters a list, a search, a count or a lookup. Asking for one by URL is
 * a 404, the same answer as for an id that does not exist: the register does not confirm what it
 * will not show.
 *
 * A user without risk.view at all gets a 403 on everything, before any id is looked at — that
 * response says nothing about which risks exist either.
 */
class RiskController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly RiskAccessService $access,
        private readonly RiskScoringPolicy $scoring,
        private readonly RiskControlService $controls,
        private readonly RiskQualityContextService $context,
        private readonly RiskTreatmentService $treatments,
        private readonly RiskAcceptanceService $acceptances,
        private readonly RiskReviewSchedule $reviewSchedule,
        private readonly RiskAttentionService $attention,
        private readonly RiskWikiKnowledgeService $wikiKnowledge,
        private readonly RiskCreator $creator,
        private readonly SupplierRiskService $supplierRisks,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), Risk::STATUSES, true) ? (string) $request->query('status') : '';

        $query = $this->access->visibleRisks($user)->with(['businessArea:id,name', 'owner:id,name']);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->whereRaw('lower(risks.title) like ?', [$needle]);

                foreach (['cause', 'event', 'consequence', 'description'] as $column) {
                    $inner->orWhereRaw("lower(coalesce(risks.{$column}, '')) like ?", [$needle]);
                }
            });
        }

        if ($status !== '') {
            $query->where('risks.status', $status);
        }

        $creatableAreas = $this->access->areasFor($user, CustomerPermissionCatalog::RISK_CREATE);
        $hasAreas = $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW) !== [];

        $risks = $query->orderBy('risks.title')->get();
        // Restrisiko per row, from each risk's latest assessment — one query, and only for the ids
        // the scoped query above already returned.
        $latest = RiskAssessment::latestForRisks(
            (int) $user->customer_id,
            $risks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        return Inertia::render('App/Risk/Index', [
            'risks' => $risks
                ->map(fn (Risk $risk): array => $this->riskRow($risk) + [
                    'residual_level' => $this->residualLevel($latest->get((int) $risk->id)),
                ])
                ->all(),
            // Only what the user can see. A total over hidden risks would reveal that they exist.
            'visible_count' => $this->access->visibleRisks($user)->count(),
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => Risk::STATUSES,
            'has_areas' => $hasAreas,
            // Trenger oppmerksomhet, over the same visible set as the register — never the whole
            // customer. Only a role with «Alle» may have it described as the whole picture.
            'attention' => $hasAreas ? $this->attention->overview($user) + [
                'scope' => $this->access->reachesAllAreas($user, CustomerPermissionCatalog::RISK_VIEW) ? 'all' : 'areas',
            ] : null,
            'access_setup' => $hasAreas ? null : $this->accessSetup($user),
            'permissions' => ['can_create' => $creatableAreas->isNotEmpty()],
            'area_options' => $this->areaOptions($creatableAreas),
            'owner_options' => $this->ownerOptions($user, $creatableAreas),
        ]);
    }

    /**
     * Where System Owner goes from an empty register. They administer areas and roles, so they
     * are pointed at Kundemiljø → Tilganger rather than told to ask themselves. Whether any area
     * exists is no secret to them — Tilganger lists every area — and it still grants no risk data:
     * the register stays empty until one of their roles reaches an area.
     *
     * @return array{customer_has_areas: bool, manage_url: string}|null
     */
    private function accessSetup(User $user): ?array
    {
        if (! $user->isSystemOwner()) {
            return null;
        }

        return [
            'customer_has_areas' => BusinessArea::query()->forCustomer((int) $user->customer_id)->exists(),
            'manage_url' => route('app.customer-environment.index', ['tab' => 'permissions']).'#business-areas',
        ];
    }

    public function show(int $riskId): Response
    {
        $user = $this->authorizedUser();
        $risk = $this->visibleRiskOrFail($user, $riskId);
        $risk->loadMissing(['businessArea:id,name', 'owner:id,name']);

        $canEdit = $this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk);
        // Control information is Kvalitet's, so it is shown only to someone who can read it there.
        // Without that, the page says nothing about controls — not even how many are linked.
        $canReadControls = $this->controls->canReadQuality($user);
        $editableAreas = $canEdit
            ? $this->access->areasFor($user, CustomerPermissionCatalog::RISK_EDIT)
            : new EloquentCollection;

        return Inertia::render('App/Risk/Show', [
            'risk' => $this->riskRow($risk),
            'statuses' => Risk::STATUSES,
            'review_intervals' => Risk::REVIEW_INTERVALS,
            'treatment_strategies' => Risk::TREATMENT_STRATEGIES,
            // Periodisk vurdering, derived from the latest assessment and the interval. Whoever may
            // see the risk may see when it is due; changing the interval takes risk.edit.
            'review_schedule' => $this->reviewSchedule->scheduleFor($risk),
            // The risk was reached through visibleRisks(), and its assessments carry no access of
            // their own — whoever may see the risk sees its whole history.
            'assessments' => $risk->assessments()->with('assessor:id,name')->get()
                ->map(fn (RiskAssessment $assessment): array => $this->assessmentRow($assessment, $risk))
                ->all(),
            // The scale and bands for the form, so the page can show the level before saving
            // without keeping its own copy of the rules.
            'risk_criteria' => $this->scoring->criteria(),
            'controls' => $canReadControls ? $this->controls->linkedControls($risk) : null,
            'control_options' => $canReadControls && $canEdit ? $this->controls->controlOptions($risk) : [],
            // Where the risk belongs in Kvalitet, under the same rule: null, not empty, without read access.
            'quality_context' => $canReadControls ? $this->context->linkedContext($risk) : null,
            'quality_context_options' => $canReadControls && $canEdit ? $this->context->contextOptions($risk) : [],
            // Tiltak carry no access of their own: whoever may see the risk sees them; risk.edit changes them.
            'treatment_actions' => $this->treatments->actionsFor($risk),
            'treatment_owner_options' => $canEdit ? $this->treatments->ownerOptions($risk) : [],
            // The residual-risk decision and its history: whoever may see the risk may read them.
            'risk_acceptance' => $this->acceptances->decisionFor($risk),
            // Wiki knowledge handed over from this risk, read live from the Wiki. Links only for
            // someone who may read the Wiki; nothing of the risk was copied into it.
            'wiki_knowledge' => $this->wikiKnowledge->describeForRisk($risk, $this->wikiKnowledge->canReadWiki($user)),
            // «Gjelder leverandør»: null unless the risk concerns a supplier, the customer holds
            // Leverandøroppfølging *and* the person can read that supplier there. Nothing about the
            // supplier otherwise.
            'supplier_origin' => $this->supplierRisks->provenanceFor($user, $risk),
            'permissions' => [
                'can_edit' => $canEdit,
                'can_link_controls' => $canReadControls && $canEdit,
                'can_link_context' => $canReadControls && $canEdit,
                'can_manage_actions' => $canEdit,
                'can_assess' => $this->access->can($user, CustomerPermissionCatalog::RISK_ASSESS, $risk),
                'can_accept' => $this->access->can($user, CustomerPermissionCatalog::RISK_ACCEPT, $risk),
                'can_delete' => $this->access->can($user, CustomerPermissionCatalog::RISK_DELETE, $risk),
                'can_create_wiki_knowledge' => $this->wikiKnowledge->canHandOff($user, $risk),
            ],
            'area_options' => $this->areaOptions($editableAreas),
            'owner_options' => $canEdit ? $this->ownerOptions($user, $editableAreas) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        $risk = $this->creator->create($user, $this->validated($request));

        return redirect()
            ->route('app.risk.show', ['riskId' => $risk->id])
            ->with('success', __('procynia.risk.flash.created'));
    }

    public function update(Request $request, int $riskId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $risk = $this->visibleRiskOrFail($user, $riskId);

        abort_unless($this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk), 403);

        $validated = $this->validated($request);
        $targetAreaId = (int) $validated['business_area_id'];

        // Moving a risk changes who can see it, so it takes edit authority on both sides.
        if ($targetAreaId !== (int) $risk->business_area_id) {
            $this->creator->assertAreaAllows($user, CustomerPermissionCatalog::RISK_EDIT, $targetAreaId);
        }

        $this->creator->assertValidOwner($user, $validated);

        $risk->fill($this->creator->fields($validated) + ['updated_by' => $user->id]);

        // Only when sent, so a client that does not know the field never clears the cycle.
        if (array_key_exists('review_interval_months', $validated)) {
            $risk->review_interval_months = $validated['review_interval_months'];
        }

        // Same rule. Only the direction is stored: «accept» here is not an acceptance — that takes
        // risk.accept and RiskAcceptanceService — and «reduce» creates no tiltak.
        if (array_key_exists('treatment_strategy', $validated)) {
            $risk->treatment_strategy = $validated['treatment_strategy'];
        }

        $risk->save();

        // After a move the user may no longer see the risk; the register is the only safe place
        // to land in that case.
        if ($this->access->findVisible($user, (int) $risk->id) === null) {
            return redirect()->route('app.risk.index')->with('success', __('procynia.risk.flash.updated'));
        }

        return back()->with('success', __('procynia.risk.flash.updated'));
    }

    public function destroy(int $riskId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $risk = $this->visibleRiskOrFail($user, $riskId);

        abort_unless($this->access->can($user, CustomerPermissionCatalog::RISK_DELETE, $risk), 403);

        $risk->delete();

        return redirect()->route('app.risk.index')->with('success', __('procynia.risk.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleRiskOrFail(User $user, int $riskId): Risk
    {
        return $this->access->findVisible($user, $riskId) ?? abort(404);
    }

    /**
     * The fields of a risk and their rules are RiskCreator's, for registering and editing alike.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate(RiskCreator::rules(), RiskValidationMessages::messages(), RiskValidationMessages::attributes());
    }

    /** @return array<string, mixed> */
    private function riskRow(Risk $risk): array
    {
        return [
            'id' => (int) $risk->id,
            'title' => $risk->title,
            'cause' => $risk->cause,
            'event' => $risk->event,
            'consequence' => $risk->consequence,
            'has_structured_description' => $risk->hasStructuredDescription(),
            // «Utfyllende informasjon» — supplementary, never the risk description itself.
            'description' => $risk->description,
            'status' => $risk->status,
            'review_interval_months' => $risk->review_interval_months,
            // Behandlingsvalg; null is «Ikke besluttet ennå».
            'treatment_strategy' => $risk->treatment_strategy,
            'business_area_id' => (int) $risk->business_area_id,
            'area_name' => $risk->businessArea?->name,
            'owner_user_id' => $risk->owner_user_id !== null ? (int) $risk->owner_user_id : null,
            'owner_name' => $risk->owner?->name,
            'updated_at' => $risk->updated_at?->toIso8601String(),
            'url' => route('app.risk.show', ['riskId' => $risk->id]),
        ];
    }

    /** @return array<string, mixed> */
    private function assessmentRow(RiskAssessment $assessment, Risk $risk): array
    {
        return [
            'id' => (int) $assessment->id,
            'assessed_at' => $assessment->assessed_at?->toIso8601String(),
            'assessed_by_name' => $assessment->assessor?->name,
            'rationale' => $assessment->rationale,
            'risk_description' => $this->assessedDescription($assessment, $risk),
            'inherent' => $this->scoring->evaluate(
                $assessment->inherent_likelihood,
                $assessment->inherent_consequence,
                $assessment->criteria_key,
            ),
            'residual' => $assessment->hasResidual()
                ? $this->scoring->evaluate(
                    $assessment->residual_likelihood,
                    $assessment->residual_consequence,
                    $assessment->criteria_key,
                )
                : null,
        ];
    }

    /**
     * The register's Restrisiko: the residual level of the latest assessment, the same reading as
     * Trenger oppmerksomhet. Null when the risk is not assessed or its latest assessment has no
     * residual — an earlier residual is not carried forward past a newer assessment.
     */
    private function residualLevel(?RiskAssessment $latest): ?string
    {
        if ($latest === null || ! $latest->hasResidual()) {
            return null;
        }

        return $this->scoring->evaluate(
            $latest->residual_likelihood,
            $latest->residual_consequence,
            $latest->criteria_key,
        )['level'];
    }

    /**
     * The risk description an assessment was made against, from its own snapshot — never from the
     * risk as it reads today. Null when the assessment has no snapshot: it predates the snapshot or
     * the risk had no structured description then.
     *
     * @return array{cause: string, event: string, consequence: string, changed_since: bool}|null
     */
    private function assessedDescription(RiskAssessment $assessment, Risk $risk): ?array
    {
        if (blank($assessment->risk_cause) || blank($assessment->risk_event) || blank($assessment->risk_consequence)) {
            return null;
        }

        return [
            'cause' => $assessment->risk_cause,
            'event' => $assessment->risk_event,
            'consequence' => $assessment->risk_consequence,
            'changed_since' => $assessment->risk_cause !== $risk->cause
                || $assessment->risk_event !== $risk->event
                || $assessment->risk_consequence !== $risk->consequence,
        ];
    }

    /**
     * @param  Collection<int, BusinessArea>  $areas
     * @return list<array{id: int, name: string}>
     */
    private function areaOptions(Collection $areas): array
    {
        return $areas
            ->map(fn (BusinessArea $area): array => ['id' => (int) $area->id, 'name' => $area->name])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, BusinessArea>  $offeredAreas
     * @return list<array{id: int, name: string, area_ids: list<int>}>
     */
    private function ownerOptions(User $user, Collection $offeredAreas): array
    {
        return $this->creator->ownerOptions($user, $offeredAreas->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
