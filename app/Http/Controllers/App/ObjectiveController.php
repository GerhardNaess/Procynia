<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\BusinessArea;
use App\Models\Kpi;
use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeHandoffService;
use App\Services\Objectives\KpiMeasurementResolver;
use App\Services\Objectives\KpiQualityContextService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Objectives\ObjectiveAttentionService;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use App\Support\Objectives\KpiPresenter;
use App\Support\Objectives\ObjectiveValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mål og KPI — the objectives.
 *
 * Every read starts from ObjectiveAccessService::visibleObjectives(), so an objective outside the
 * user's fagområder never enters a list, a search, a count or a lookup. Asking for one by URL is a
 * 404, the same answer as for an id that does not exist.
 *
 * A user without objective.view at all gets a 403 on everything, before any id is looked at.
 *
 * objective.edit creates, changes, closes and reopens; objective.delete deletes and is never
 * implied by edit. Status changes only through close() and reopen(), never through update().
 */
class ObjectiveController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ObjectiveAccessService $access,
        private readonly ObjectiveLifecycleService $lifecycle,
        private readonly KpiPresenter $kpiPresenter,
        private readonly KpiMeasurementResolver $measurementResolver,
        private readonly KpiQualityContextService $qualityContext,
        private readonly ObjectiveAttentionService $attention,
        private readonly WikiKnowledgeHandoffService $knowledgeHandoff,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $viewAreas = $this->access->areasFor($user, CustomerPermissionCatalog::OBJECTIVE_VIEW);
        $viewAreaIds = $viewAreas->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), Objective::STATUSES, true) ? (string) $request->query('status') : '';
        // Only an area the user can read is a filter; anything else is ignored rather than answered.
        $areaId = (int) $request->query('area', 0);
        $areaId = in_array($areaId, $viewAreaIds, true) ? $areaId : 0;

        $query = $this->access->visibleObjectives($user)->with(['businessArea:id,name', 'owner:id,name']);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->whereRaw('lower(objectives.title) like ?', [$needle])
                    ->orWhereRaw("lower(coalesce(objectives.description, '')) like ?", [$needle]);
            });
        }

        if ($status !== '') {
            $query->where('objectives.status', $status);
        }

        if ($areaId !== 0) {
            $query->where('objectives.business_area_id', $areaId);
        }

        $editableAreas = $this->access->editableAreas($user);

        $objectives = $query
            // Active first; within each group, by title.
            ->orderByRaw('CASE WHEN objectives.status = ? THEN 0 ELSE 1 END', [Objective::STATUS_ACTIVE])
            ->orderBy('objectives.title')
            ->orderBy('objectives.id')
            ->get();

        // «2 av 3 KPI-er på mål» per row: the KPIs of every listed objective in one query, their
        // latest measurements in one more. Never a query per objective.
        $kpisByObjective = Kpi::query()
            ->whereIn('objective_id', $objectives->modelKeys() ?: [0])
            ->get(['id', 'objective_id', 'status', 'target_min', 'target_max', 'tolerance'])
            ->groupBy('objective_id');
        $latest = $this->measurementResolver->latestForKpis($kpisByObjective->flatten());

        return Inertia::render('App/Objectives/Index', [
            'objectives' => $objectives
                ->map(fn (Objective $objective): array => $this->objectiveRow($objective) + [
                    'kpi_indicator' => $this->kpiPresenter->indicator($kpisByObjective->get($objective->id, []), $latest),
                ])
                ->all(),
            // Only what the user can see. A total over hidden objectives would reveal that they exist.
            'visible_count' => $this->access->visibleObjectives($user)->count(),
            'filters' => ['search' => $search, 'status' => $status, 'area' => $areaId !== 0 ? $areaId : null],
            'statuses' => Objective::STATUSES,
            'filter_area_options' => $this->areaOptions($viewAreas),
            'has_areas' => $viewAreaIds !== [],
            // Trenger oppmerksomhet, over the same visible set as the list — never the whole
            // customer, and unaffected by the search and filters. Only a role with «Alle» may have
            // it described as the whole picture.
            'attention' => $viewAreaIds !== [] ? $this->attention->overview($user) + [
                'scope' => $this->access->reachesAllAreas($user, CustomerPermissionCatalog::OBJECTIVE_VIEW) ? 'all' : 'areas',
            ] : null,
            'access_setup' => $viewAreaIds !== [] ? null : $this->accessSetup($user),
            'permissions' => ['can_create' => $editableAreas->isNotEmpty()],
            'area_options' => $this->areaOptions($editableAreas),
            'owner_options' => $this->ownerOptions($user, $editableAreas),
        ]);
    }

    public function show(int $objectiveId): Response
    {
        $user = $this->authorizedUser();
        $objective = $this->visibleObjectiveOrFail($user, $objectiveId);
        $objective->loadMissing(['businessArea:id,name', 'owner:id,name', 'closedBy:id,name']);

        $canEdit = $this->access->canEdit($user, $objective);
        $active = $objective->isActive();
        // A closed objective is reopened before it is changed, so its fields are not offered.
        $editableAreas = $canEdit && $active ? $this->access->editableAreas($user) : collect();

        $kpis = $objective->kpis()
            ->with('owner:id,name')
            // Active first; within each group, by title.
            ->orderByRaw('CASE WHEN kpis.status = ? THEN 0 ELSE 1 END', [Kpi::STATUS_ACTIVE])
            ->orderBy('kpis.title')
            ->orderBy('kpis.id')
            ->get()
            // The objective (and its owner, the fallback) is already at hand; no query per row.
            ->each(fn (Kpi $kpi) => $kpi->setRelation('objective', $objective));

        $latest = $this->measurementResolver->latestForKpis($kpis);

        return Inertia::render('App/Objectives/Show', [
            // «Lag kunnskapsartikkel»: the shared Wiki handoff (WikiKnowledgeHandoffService).
            'knowledge_handoff' => $this->knowledgeHandoff->panel($user, 'objective', $objective),
            // The KPIs come with the objective: whoever may read it reads them, nothing more.
            'kpis' => $kpis->map(fn (Kpi $kpi): array => $this->kpiPresenter->row($kpi, $latest[(int) $kpi->id] ?? null))->all(),
            'kpi_indicator' => $this->kpiPresenter->indicator($kpis, $latest),
            // A short note when the objective or its KPIs need attention; the panel lives on the
            // overview. Null when there is nothing to say, and always for a closed objective.
            'attention' => $this->attention->forObjective($objective),
            'kpi_form_options' => $canEdit && $active ? $this->kpiPresenter->formOptions($objective) : null,
            // Berørte prosesser, derived live from what the active KPIs measure. null, not empty,
            // without Kvalitet read: then nothing is said about processes at all.
            'affected_processes' => $this->qualityContext->canReadQuality($user)
                ? $this->qualityContext->processesForKpis((int) $objective->customer_id, $kpis->filter(fn (Kpi $kpi): bool => $kpi->isActive()))
                : null,
            'objective' => $this->objectiveRow($objective) + [
                // The current closing, from the objective itself — not inferred from the history.
                'closed_at' => $objective->closed_at?->toIso8601String(),
                'closed_by_name' => $objective->closedBy?->name,
                'closing_note' => $objective->closing_note,
            ],
            // The objective was reached through visibleObjectives(); its history carries no access
            // of its own — whoever may see the objective sees how its status got where it is.
            'status_history' => $objective->statusChanges()->with('changedBy:id,name')->get()
                ->map(fn (ObjectiveStatusChange $change): array => [
                    'id' => (int) $change->id,
                    'from_status' => $change->from_status,
                    'to_status' => $change->to_status,
                    'note' => $change->note,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                    'changed_by_name' => $change->changedBy?->name,
                ])
                ->all(),
            'closing_outcomes' => Objective::CLOSED_STATUSES,
            'permissions' => [
                'can_edit' => $canEdit && $active,
                'can_close' => $canEdit && $active,
                'can_reopen' => $canEdit && ! $active,
                'can_delete' => $this->access->canDelete($user, $objective) && $objective->isDeletable(),
                'can_create_kpi' => $canEdit && $active,
            ],
            'area_options' => $this->areaOptions($editableAreas),
            'owner_options' => $canEdit ? $this->ownerOptions($user, $editableAreas) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        $validated = $this->validated($request);
        $areaId = (int) $validated['business_area_id'];

        // The area is the scope the new objective will live in, so the user must be allowed to
        // edit in *that* area — not merely hold objective.edit somewhere.
        $this->authorizeArea($user, $areaId);
        $this->guardOwner($user, (int) $validated['owner_user_id'], $areaId);

        $objective = Objective::query()->create([
            'customer_id' => (int) $user->customer_id,
            'business_area_id' => $areaId,
            'title' => trim($validated['title']),
            'description' => $this->normalizedText($validated['description'] ?? null),
            'owner_user_id' => (int) $validated['owner_user_id'],
            'target_date' => $validated['target_date'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()
            ->route('app.objectives.show', ['objectiveId' => $objective->id])
            ->with('success', __('procynia.objectives.flash.created'));
    }

    public function update(Request $request, int $objectiveId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $objective = $this->visibleObjectiveOrFail($user, $objectiveId);

        // Edit authority in the area the objective is in now.
        abort_unless($this->access->canEdit($user, $objective), 403);

        if (! $objective->isActive()) {
            return back()->with('error', __('procynia.objectives.validation.reopen_before_edit'));
        }

        $validated = $this->validated($request);
        $targetAreaId = (int) $validated['business_area_id'];

        // Moving an objective changes who can see it, so it takes edit authority on both sides:
        // the old area above, the new one here.
        if ($targetAreaId !== (int) $objective->business_area_id) {
            $this->authorizeArea($user, $targetAreaId);
        }

        // Against the area the objective will be in, so a move cannot keep an owner who would no
        // longer be able to see it.
        $this->guardOwner($user, (int) $validated['owner_user_id'], $targetAreaId);

        if ($targetAreaId !== (int) $objective->business_area_id) {
            $this->guardKpiOwners($objective, $targetAreaId);
        }

        $objective->fill([
            'business_area_id' => $targetAreaId,
            'title' => trim($validated['title']),
            'description' => $this->normalizedText($validated['description'] ?? null),
            'owner_user_id' => (int) $validated['owner_user_id'],
            'target_date' => $validated['target_date'] ?? null,
            'updated_by' => $user->id,
        ])->save();

        // After a move the user may no longer see the objective; the overview is the only safe
        // place to land in that case.
        if ($this->access->findVisible($user, (int) $objective->id) === null) {
            return redirect()->route('app.objectives.index')->with('success', __('procynia.objectives.flash.updated'));
        }

        return back()->with('success', __('procynia.objectives.flash.updated'));
    }

    /**
     * Lukk mål: the chosen outcome is the decision; the note is optional. objective.edit in the
     * objective's area, the same authority that changes it.
     */
    public function close(Request $request, int $objectiveId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $objective = $this->visibleObjectiveOrFail($user, $objectiveId);

        abort_unless($this->access->canEdit($user, $objective), 403);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(Objective::CLOSED_STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ], ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        $this->lifecycle->close($objective, $user, $validated['status'], $validated['note'] ?? null);

        return back()->with('success', __('procynia.objectives.flash.closed'));
    }

    /**
     * Gjenåpne: undoes a closing, so it always says why. objective.edit in the objective's area.
     */
    public function reopen(Request $request, int $objectiveId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $objective = $this->visibleObjectiveOrFail($user, $objectiveId);

        abort_unless($this->access->canEdit($user, $objective), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        $this->lifecycle->reopen($objective, $user, $validated['reason']);

        return back()->with('success', __('procynia.objectives.flash.reopened'));
    }

    public function destroy(int $objectiveId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $objective = $this->visibleObjectiveOrFail($user, $objectiveId);

        abort_unless($this->access->canDelete($user, $objective), 403);

        if (! $objective->isDeletable()) {
            return back()->with('error', __('procynia.objectives.validation.not_deletable'));
        }

        $objective->delete();

        return redirect()->route('app.objectives.index')->with('success', __('procynia.objectives.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleObjectiveOrFail(User $user, int $objectiveId): Objective
    {
        return $this->access->findVisible($user, $objectiveId) ?? abort(404);
    }

    /**
     * A 422 rather than a 403 or 404: the area id came from a form, and whether it names an area of
     * another tenant, an area outside the user's scope or nothing at all, the answer is the same.
     */
    private function authorizeArea(User $user, int $areaId): void
    {
        if (! $this->access->canInArea($user, CustomerPermissionCatalog::OBJECTIVE_EDIT, (int) $user->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'business_area_id' => __('procynia.objectives.validation.area_not_allowed'),
            ]);
        }
    }

    /**
     * The owner must be an active person in the same customer who can read objectives in the
     * given area. An owner who cannot open the objective they own is not an owner.
     */
    private function guardOwner(User $user, int $ownerId, int $areaId): void
    {
        $owner = User::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('is_active', true)
            ->find($ownerId);

        if ($owner === null
            || ! $this->access->canInArea($owner, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $user->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.objectives.validation.owner_not_allowed'),
            ]);
        }
    }

    /**
     * A KPI takes its fagområde from its objective, so moving the objective moves its KPIs. Their
     * own owners must be able to read objectives in the new area too, or the move is refused —
     * the same rule as for the objective's owner, never a silent loss of access.
     */
    private function guardKpiOwners(Objective $objective, int $areaId): void
    {
        $owners = User::query()
            ->whereIn('id', $objective->kpis()->whereNotNull('owner_user_id')->select('owner_user_id'))
            ->get();

        foreach ($owners as $owner) {
            if (! $this->access->canInArea($owner, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $objective->customer_id, $areaId)) {
                throw ValidationException::withMessages([
                    'business_area_id' => __('procynia.objectives.validation.kpi_owner_not_allowed'),
                ]);
            }
        }
    }

    /**
     * The same fields for Nytt mål and Rediger. Status is not among them: an objective is created
     * active and leaves that state only by being closed.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'business_area_id' => ['required', 'integer'],
            'owner_user_id' => ['required', 'integer'],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
        ], ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());
    }

    /**
     * Where System Owner goes from an empty overview, as in Risiko: pointed at Kundemiljø →
     * Tilganger. It grants no objective data — the overview stays empty until a role reaches an area.
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
    private function objectiveRow(Objective $objective): array
    {
        return [
            'id' => (int) $objective->id,
            'title' => $objective->title,
            'description' => $objective->description,
            'status' => $objective->status,
            'target_date' => $objective->target_date?->format('Y-m-d'),
            'business_area_id' => (int) $objective->business_area_id,
            'area_name' => $objective->businessArea?->name,
            'owner_user_id' => $objective->owner_user_id !== null ? (int) $objective->owner_user_id : null,
            'owner_name' => $objective->owner?->name,
            'updated_at' => $objective->updated_at?->toIso8601String(),
            'url' => route('app.objectives.show', ['objectiveId' => $objective->id]),
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
     * People who could own an objective, each with the areas — among those offered to the acting
     * user — in which they can read objectives. The page narrows the list to the chosen area; the
     * server checks the same again in guardOwner(). Areas the acting user cannot reach are never
     * named here.
     *
     * @param  Collection<int, BusinessArea>  $offeredAreas
     * @return list<array{id: int, name: string, area_ids: list<int>}>
     */
    private function ownerOptions(User $user, Collection $offeredAreas): array
    {
        $offeredIds = $offeredAreas->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($offeredIds === []) {
            return [];
        }

        $viewerAreas = $this->access->viewerAreaIdsByUser((int) $user->customer_id);

        return User::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $candidate): array => [
                'id' => (int) $candidate->id,
                'name' => $candidate->name,
                'area_ids' => array_values(array_intersect($viewerAreas[(int) $candidate->id] ?? [], $offeredIds)),
            ])
            ->filter(fn (array $option): bool => $option['area_ids'] !== [])
            ->values()
            ->all();
    }

    private function normalizedText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
