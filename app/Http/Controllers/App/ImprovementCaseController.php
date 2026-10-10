<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\BusinessArea;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Models\User;
use App\Services\Compliance\ComplianceAuditFindingHandoffService;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeHandoffService;
use App\Services\Improvements\ImprovementActionVerificationResolver;
use App\Services\Improvements\ImprovementAttentionService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseCreator;
use App\Services\Improvements\ImprovementCaseLifecycleService;
use App\Services\Improvements\ImprovementCaseQualityContextService;
use App\Services\ManagementReview\ManagementReviewDecisionService;
use App\Services\Suppliers\SupplierImprovementHandoffService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use App\Support\Improvements\ImprovementValidationMessages;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avvik og forbedringer — the register, one case, and its lifecycle.
 *
 * Every read starts from ImprovementCaseAccessService::visibleCases(), so a case outside the user's
 * fagområder never enters a list, a search, a count or a lookup. Asking for one by URL is a 404, the
 * same answer as for an id that does not exist. A user without improvement.view at all gets a 403
 * on everything, before any id is looked at.
 *
 * improvement.edit registers, changes and starts handling; improvement.close closes, cancels and
 * reopens; improvement.delete deletes a case nobody has started on. Status changes only through the
 * lifecycle actions, never through store() or update().
 */
class ImprovementCaseController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ImprovementCaseAccessService $access,
        private readonly ImprovementCaseLifecycleService $lifecycle,
        private readonly ImprovementCaseQualityContextService $qualityContext,
        private readonly ImprovementActionVerificationResolver $verifications,
        private readonly ImprovementAttentionService $attention,
        private readonly ImprovementCaseCreator $creator,
        private readonly ComplianceAuditFindingHandoffService $auditFindings,
        private readonly SupplierImprovementHandoffService $supplierHandoff,
        private readonly WikiKnowledgeHandoffService $knowledgeHandoff,
        private readonly ManagementReviewDecisionService $managementReviewDecisions,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $viewAreas = $this->access->visibleAreas($user);
        $viewAreaIds = $viewAreas->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $search = trim((string) $request->query('search', ''));
        $type = in_array($request->query('type'), ImprovementCase::TYPES, true) ? (string) $request->query('type') : '';
        $status = in_array($request->query('status'), ImprovementCase::STATUSES, true) ? (string) $request->query('status') : '';
        // Only an area the user can read is a filter; anything else is ignored rather than answered.
        $areaId = (int) $request->query('area', 0);
        $areaId = in_array($areaId, $viewAreaIds, true) ? $areaId : 0;

        $query = $this->access->visibleCases($user)
            ->with(['businessArea:id,name', 'owner:id,name'])
            // The register's light tiltak indicator, counted in the same query.
            ->withCount([
                'actions as action_count',
                'actions as open_action_count' => fn ($actions) => $actions->whereIn('status', ImprovementAction::ACTIVE_STATUSES),
            ]);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->whereRaw('lower(improvement_cases.title) like ?', [$needle])
                    ->orWhereRaw('lower(improvement_cases.description) like ?', [$needle]);
            });
        }

        if ($type !== '') {
            $query->where('improvement_cases.type', $type);
        }

        if ($status !== '') {
            $query->where('improvement_cases.status', $status);
        }

        if ($areaId !== 0) {
            $query->where('improvement_cases.business_area_id', $areaId);
        }

        $cases = $query
            // Åpen first, then Under arbeid, then the ended ones. Among active cases the nearest
            // frist comes first and cases without one after; otherwise the newest first.
            ->orderByRaw('CASE improvement_cases.status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [ImprovementCase::STATUS_OPEN, ImprovementCase::STATUS_IN_PROGRESS])
            ->orderByRaw('CASE WHEN improvement_cases.status IN (?, ?) THEN improvement_cases.due_date END ASC NULLS LAST', ImprovementCase::ACTIVE_STATUSES)
            ->orderByDesc('improvement_cases.created_at')
            ->orderByDesc('improvement_cases.id')
            ->get();

        $editableAreas = $this->access->editableAreas($user);

        return Inertia::render('App/Improvements/Index', [
            'cases' => $cases
                ->map(fn (ImprovementCase $case): array => $this->caseRow($case) + [
                    // «3 tiltak · 1 åpent». Open is planned or under arbeid; a completed tiltak awaiting
                    // its effektverifisering is the attention panel's business, not this count's.
                    'action_summary' => (int) $case->action_count > 0
                        ? ['total' => (int) $case->action_count, 'open' => (int) $case->open_action_count]
                        : null,
                ])
                ->all(),
            // Only what the user can see. A total over hidden cases would reveal that they exist.
            'visible_count' => $this->access->visibleCases($user)->count(),
            'filters' => [
                'search' => $search,
                'type' => $type,
                'status' => $status,
                'area' => $areaId !== 0 ? $areaId : null,
            ],
            'types' => ImprovementCase::TYPES,
            'statuses' => ImprovementCase::STATUSES,
            'filter_area_options' => $this->areaOptions($viewAreas),
            'has_areas' => $viewAreaIds !== [],
            // Trenger oppmerksomhet, over the same visible set as the list — never the whole customer,
            // and unaffected by the search and filters. Only a role with «Alle» may have it described
            // as the whole picture.
            'attention' => $viewAreaIds !== [] ? $this->attention->overview($user) + [
                'scope' => $this->access->reachesAllAreas($user, CustomerPermissionCatalog::IMPROVEMENT_VIEW) ? 'all' : 'areas',
            ] : null,
            'access_setup' => $viewAreaIds !== [] ? null : $this->accessSetup($user),
            'permissions' => ['can_create' => $editableAreas->isNotEmpty()],
            'area_options' => $this->areaOptions($editableAreas),
            'owner_options' => $this->access->ownerCandidates($user, $this->areaIds($editableAreas)),
            'today' => now()->toDateString(),
        ]);
    }

    public function show(int $caseId): Response
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);
        $case->loadMissing([
            'businessArea:id,name', 'owner:id,name', 'reportedBy:id,name', 'closedBy:id,name',
            'actions.owner:id,name', 'actions.completedBy:id,name', 'actions.statusChanges.changedBy:id,name',
            'actions.verifications.verifiedBy:id,name',
        ]);

        $active = $case->isActive();
        $canEdit = $this->access->canEdit($user, $case);
        $canClose = $this->access->canClose($user, $case);
        // An ended case is reopened before it is changed, so its fields are not offered.
        $editableAreas = $canEdit && $active ? $this->access->editableAreas($user) : collect();
        $canReadQuality = $this->qualityContext->canReadQuality($user);
        $canLinkContext = $canEdit && $active && $canReadQuality;
        // Tiltak are worked while the case is; an ended case is reopened first.
        $canManageActions = $canEdit && $active;
        // Verifiser effekt is a decision about the outcome: improvement.close, while the case is active.
        $canVerify = $canClose && $active;
        $currentVerifications = $this->verifications->forActions($case->actions);
        $today = now()->startOfDay();

        return Inertia::render('App/Improvements/Show', [
            // «Lag kunnskapsartikkel»: the shared Wiki handoff (WikiKnowledgeHandoffService).
            'knowledge_handoff' => $this->knowledgeHandoff->panel($user, 'improvement_case', $case),
            'case' => $this->caseRow($case) + [
                'description' => $case->description,
                'cause_analysis' => $case->cause_analysis,
                'occurred_at' => $case->occurred_at?->format('Y-m-d'),
                'reported_by_name' => $case->reportedBy?->name,
                'created_at' => $case->created_at?->toIso8601String(),
                // The current ending, from the case itself — not inferred from the history.
                'closed_at' => $case->closed_at?->toIso8601String(),
                'closed_by_name' => $case->closedBy?->name,
                'closing_note' => $case->closing_note,
            ],
            // The case was reached through visibleCases(); its history carries no access of its own.
            'status_history' => $case->statusChanges()->with('changedBy:id,name')->get()
                ->map(fn (ImprovementCaseStatusChange $change): array => [
                    'id' => (int) $change->id,
                    'from_status' => $change->from_status,
                    'to_status' => $change->to_status,
                    'note' => $change->note,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                    'changed_by_name' => $change->changedBy?->name,
                ])
                ->all(),
            'permissions' => [
                'can_edit' => $canEdit && $active,
                'can_start' => $canEdit && $case->status === ImprovementCase::STATUS_OPEN,
                'can_close' => $canClose && $active,
                'can_cancel' => $canClose && $active,
                'can_reopen' => $canClose && ! $active,
                'can_delete' => $this->access->canDelete($user, $case) && $case->isDeletable(),
                'can_link_context' => $canLinkContext,
                'can_edit_cause' => $canEdit && $active,
                'can_manage_actions' => $canManageActions,
            ],
            // A short note when the case or its tiltak need attention; the panel lives on the register.
            // Null when there is nothing to say, and always for an ended case.
            'attention' => $this->attention->forCase($case),
            // The case was reached through visibleCases(); its tiltak carry no access of their own.
            'actions' => $case->actions
                ->map(fn (ImprovementAction $action): array => $this->actionRow($action, $case, $today, $canManageActions, $canVerify, $currentVerifications[(int) $action->id] ?? null))
                ->all(),
            // Who can be responsible for a tiltak: people who can read cases in the case's area.
            'action_owner_options' => $canManageActions
                ? $this->access->ownerCandidates($user, [(int) $case->business_area_id])
                : [],
            'types' => ImprovementCase::TYPES,
            'area_options' => $this->areaOptions($editableAreas),
            'owner_options' => $this->access->ownerCandidates($user, $this->areaIds($editableAreas)),
            // null, not empty: the person cannot read Kvalitet, so nothing is said about context.
            'quality_context' => $canReadQuality ? $this->qualityContext->linkedContext($case) : null,
            'quality_context_options' => $canLinkContext ? $this->qualityContext->contextOptions($case) : [],
            // «Fra revisjonsfunn i …»: null unless the case came from a finding, the customer holds
            // Etterlevelse og revisjon *and* the person can read that audit there. Nothing about the
            // audit otherwise.
            'audit_origin' => $this->auditFindings->provenanceFor($user, $case),
            // «Gjelder leverandør»: null unless the case concerns a supplier, the customer holds
            // Leverandøroppfølging *and* the person can read that supplier there. Nothing about the
            // supplier otherwise.
            'supplier_origin' => $this->supplierHandoff->provenanceFor($user, $case),
            // «Fra Ledelsens gjennomgåelse»: null unless a tiltak from a review is followed up in this
            // case and the person can read that review.
            'review_origin' => $this->managementReviewDecisions->provenanceFor($user, $case),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * Årsak og bakgrunn. improvement.edit in the case's area, while the case is open or under
     * arbeid; it may be emptied again. Nothing else on the case changes.
     */
    public function updateCause(Request $request, int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);

        abort_unless($this->access->canEdit($user, $case), 403);

        if (! $case->isActive()) {
            return back()->with('error', __('procynia.improvements.validation.reopen_before_edit'));
        }

        $validated = $request->validate([
            'cause_analysis' => ['nullable', 'string', 'max:10000'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $text = trim((string) ($validated['cause_analysis'] ?? ''));

        $case->forceFill([
            'cause_analysis' => $text !== '' ? $text : null,
            'updated_by' => $user->id,
        ])->save();

        return back()->with('success', __('procynia.improvements.cause.flash.updated'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();

        // The area is the scope the new case will live in, so the creator checks edit authority in
        // *that* area — not merely improvement.edit somewhere — and the owner against it.
        $case = $this->creator->create($user, $this->validated($request));

        return redirect()
            ->route('app.improvements.show', ['caseId' => $case->id])
            ->with('success', __('procynia.improvements.flash.created'));
    }

    public function update(Request $request, int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);

        // Edit authority in the area the case is in now.
        abort_unless($this->access->canEdit($user, $case), 403);

        if (! $case->isActive()) {
            return back()->with('error', __('procynia.improvements.validation.reopen_before_edit'));
        }

        $validated = $this->validated($request);
        $targetAreaId = (int) $validated['business_area_id'];

        // Moving a case changes who can see it, so it takes edit authority on both sides: the old
        // area above, the new one here.
        if ($targetAreaId !== (int) $case->business_area_id) {
            $this->creator->assertCanEditIn($user, $targetAreaId);
        }

        // Against the area the case will be in, so a move cannot keep an owner who would no longer
        // be able to see it.
        $this->creator->assertValidOwner($user, (int) $validated['owner_user_id'], $targetAreaId);

        $case->fill($this->creator->fields($validated) + [
            'business_area_id' => $targetAreaId,
            'updated_by' => $user->id,
        ])->save();

        // After a move the user may no longer see the case; the register is the only safe place to
        // land in that case.
        if ($this->access->findVisible($user, (int) $case->id) === null) {
            return redirect()->route('app.improvements.index')->with('success', __('procynia.improvements.flash.updated'));
        }

        return back()->with('success', __('procynia.improvements.flash.updated'));
    }

    /** Start behandling: open → in_progress. improvement.edit in the case's area; no note. */
    public function start(int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);

        abort_unless($this->access->canEdit($user, $case), 403);

        $this->lifecycle->start($case, $user);

        return back()->with('success', __('procynia.improvements.flash.started'));
    }

    /** Lukk: the resultat / avsluttende kommentar is the point of closing, so it is required. */
    public function close(Request $request, int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);

        abort_unless($this->access->canClose($user, $case), 403);

        $validated = $request->validate([
            'closing_note' => ['required', 'string', 'max:5000'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $this->lifecycle->close($case, $user, $validated['closing_note']);

        return back()->with('success', __('procynia.improvements.flash.closed'));
    }

    /** Avbryt: the case will not be handled further. Always says why. */
    public function cancel(Request $request, int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);

        abort_unless($this->access->canClose($user, $case), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $this->lifecycle->cancel($case, $user, $validated['reason']);

        return back()->with('success', __('procynia.improvements.flash.cancelled'));
    }

    /** Gjenåpne: undoes an ending, so it always says why. The ending stays in the history. */
    public function reopen(Request $request, int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);

        abort_unless($this->access->canClose($user, $case), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $this->lifecycle->reopen($case, $user, $validated['reason']);

        return back()->with('success', __('procynia.improvements.flash.reopened'));
    }

    public function destroy(int $caseId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $case = $this->visibleCaseOrFail($user, $caseId);

        abort_unless($this->access->canDelete($user, $case), 403);

        if (! $case->isDeletable()) {
            return back()->with('error', __('procynia.improvements.validation.not_deletable'));
        }

        $case->delete();

        return redirect()->route('app.improvements.index')->with('success', __('procynia.improvements.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleCaseOrFail(User $user, int $caseId): ImprovementCase
    {
        return $this->access->findVisible($user, $caseId) ?? abort(404);
    }

    /**
     * The same fields for registering and editing (ImprovementCaseCreator::rules()). Status is not
     * among them: a case is created open and moves only through the lifecycle actions.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate(ImprovementCaseCreator::rules(), ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());
    }

    /**
     * Where System Owner goes from an empty register, as in Risiko and Mål og KPI: pointed at
     * Kundemiljø → Tilganger. It grants no case data.
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

    /** @return array<string, mixed> */
    private function caseRow(ImprovementCase $case): array
    {
        return [
            'id' => (int) $case->id,
            'type' => $case->type,
            'title' => $case->title,
            'status' => $case->status,
            'due_date' => $case->due_date?->format('Y-m-d'),
            'is_overdue' => $case->isActive() && $case->due_date !== null && $case->due_date->lt(now()->startOfDay()),
            'business_area_id' => (int) $case->business_area_id,
            'area_name' => $case->businessArea?->name,
            'owner_user_id' => $case->owner_user_id !== null ? (int) $case->owner_user_id : null,
            'owner_name' => $case->owner?->name,
            'url' => route('app.improvements.show', ['caseId' => $case->id]),
        ];
    }

    /**
     * One tiltak as the case page shows it, with its history newest first. Frist passert is
     * computed here, never stored, and never while the case is ended — an ended case is no longer
     * being worked, whatever its tiltak say. «After the case's frist» is only information.
     *
     * Effektverifisering: for a completed tiltak, the current verification of its current completion
     * (or none — «Venter på effektverifisering»), and below it every other verification the tiltak
     * has had, each marked when it judged an earlier completion.
     *
     * @param  array{completion: ImprovementActionStatusChange, verification: ?ImprovementActionVerification}|null  $current
     * @return array<string, mixed>
     */
    private function actionRow(ImprovementAction $action, ImprovementCase $case, CarbonInterface $today, bool $canManage, bool $canVerify, ?array $current): array
    {
        $active = $action->isActive();
        $completed = $action->status === ImprovementAction::STATUS_COMPLETED;
        $currentVerification = $current['verification'] ?? null;
        $completionId = isset($current['completion']) ? (int) $current['completion']->id : null;

        return [
            'id' => (int) $action->id,
            'title' => $action->title,
            'description' => $action->description,
            'status' => $action->status,
            'owner_user_id' => $action->owner_user_id !== null ? (int) $action->owner_user_id : null,
            'owner_name' => $action->owner?->name,
            'due_date' => $action->due_date?->format('Y-m-d'),
            'is_overdue' => $case->isActive() && $action->isOverdue($today),
            'is_after_case_due_date' => $active && $case->due_date !== null && $action->due_date !== null && $action->due_date->gt($case->due_date),
            'completion_note' => $action->completion_note,
            'completed_at' => $action->completed_at?->toIso8601String(),
            'completed_by_name' => $action->completedBy?->name,
            'history' => $action->statusChanges
                ->map(fn (ImprovementActionStatusChange $change): array => [
                    'id' => (int) $change->id,
                    'from_status' => $change->from_status,
                    'to_status' => $change->to_status,
                    'note' => $change->note,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                    'changed_by_name' => $change->changedBy?->name,
                ])
                ->values()
                ->all(),
            'verification' => [
                // Completed and its current completion not yet judged.
                'awaiting' => $completed && $currentVerification === null,
                'current' => $currentVerification !== null ? $this->verificationRow($currentVerification, $completionId) : null,
                'earlier' => $action->verifications
                    ->reject(fn (ImprovementActionVerification $verification): bool => $currentVerification !== null && (int) $verification->id === (int) $currentVerification->id)
                    ->map(fn (ImprovementActionVerification $verification): array => $this->verificationRow($verification, $completionId))
                    ->values()
                    ->all(),
            ],
            'permissions' => [
                'can_verify' => $canVerify && $completed,
                'can_edit' => $canManage && $active,
                'can_start' => $canManage && $action->status === ImprovementAction::STATUS_PLANNED,
                'can_complete' => $canManage && $active,
                'can_cancel' => $canManage && $active,
                'can_reopen' => $canManage && ! $active,
                'can_delete' => $canManage && $action->status === ImprovementAction::STATUS_PLANNED && $action->statusChanges->isEmpty(),
            ],
        ];
    }

    /** @return array{id: int, result: string, note: string, verified_at: ?string, verified_by_name: ?string, earlier_completion: bool} */
    private function verificationRow(ImprovementActionVerification $verification, ?int $currentCompletionId): array
    {
        return [
            'id' => (int) $verification->id,
            'result' => $verification->result,
            'note' => $verification->note,
            'verified_at' => $verification->verified_at?->toIso8601String(),
            'verified_by_name' => $verification->verifiedBy?->name,
            // It judged a completion that was later reopened: history, not the state of the tiltak.
            'earlier_completion' => (int) $verification->completion_status_change_id !== $currentCompletionId,
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
     * @param  Collection<int, BusinessArea>  $areas
     * @return list<int>
     */
    private function areaIds(Collection $areas): array
    {
        return $areas->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }
}
