<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskAccessArea;
use App\Models\User;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Risiko — the risk register.
 *
 * Every read starts from RiskAccessService::visibleRisks(), so a risk outside the user's
 * tilgangsområder never enters a list, a search, a count or a lookup. Asking for one by URL is
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
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), Risk::STATUSES, true) ? (string) $request->query('status') : '';

        $query = $this->access->visibleRisks($user)->with(['accessArea:id,name', 'owner:id,name']);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->whereRaw('lower(risks.title) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(risks.description, \'\')) like ?', [$needle]);
            });
        }

        if ($status !== '') {
            $query->where('risks.status', $status);
        }

        $creatableAreas = $this->access->areasFor($user, CustomerPermissionCatalog::RISK_CREATE);

        return Inertia::render('App/Risk/Index', [
            'risks' => $query->orderBy('risks.title')->get()->map(fn (Risk $risk): array => $this->riskRow($risk))->all(),
            // Only what the user can see. A total over hidden risks would reveal that they exist.
            'visible_count' => $this->access->visibleRisks($user)->count(),
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => Risk::STATUSES,
            'has_areas' => $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW) !== [],
            'permissions' => ['can_create' => $creatableAreas->isNotEmpty()],
            'area_options' => $this->areaOptions($creatableAreas),
            'owner_options' => $this->ownerOptions($user, $creatableAreas),
        ]);
    }

    public function show(int $riskId): Response
    {
        $user = $this->authorizedUser();
        $risk = $this->visibleRiskOrFail($user, $riskId);
        $risk->loadMissing(['accessArea:id,name', 'owner:id,name']);

        $canEdit = $this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk);
        $editableAreas = $canEdit
            ? $this->access->areasFor($user, CustomerPermissionCatalog::RISK_EDIT)
            : new EloquentCollection;

        return Inertia::render('App/Risk/Show', [
            'risk' => $this->riskRow($risk),
            'statuses' => Risk::STATUSES,
            'permissions' => [
                'can_edit' => $canEdit,
                'can_delete' => $this->access->can($user, CustomerPermissionCatalog::RISK_DELETE, $risk),
            ],
            'area_options' => $this->areaOptions($editableAreas),
            'owner_options' => $canEdit ? $this->ownerOptions($user, $editableAreas) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        $validated = $this->validated($request);

        // The area is the scope the new risk will live in, so the user must be allowed to create
        // in *that* area — not merely hold risk.create somewhere.
        $this->authorizeArea($user, CustomerPermissionCatalog::RISK_CREATE, (int) $validated['risk_access_area_id']);
        $this->guardOwner($user, $validated);

        $risk = Risk::query()->create([
            'customer_id' => (int) $user->customer_id,
            'risk_access_area_id' => (int) $validated['risk_access_area_id'],
            'title' => trim($validated['title']),
            'description' => $this->normalizedText($validated['description'] ?? null),
            'owner_user_id' => $validated['owner_user_id'] ?? null,
            'status' => $validated['status'],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

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
        $targetAreaId = (int) $validated['risk_access_area_id'];

        // Moving a risk changes who can see it, so it takes edit authority on both sides.
        if ($targetAreaId !== (int) $risk->risk_access_area_id) {
            $this->authorizeArea($user, CustomerPermissionCatalog::RISK_EDIT, $targetAreaId);
        }

        $this->guardOwner($user, $validated);

        $risk->fill([
            'risk_access_area_id' => $targetAreaId,
            'title' => trim($validated['title']),
            'description' => $this->normalizedText($validated['description'] ?? null),
            'owner_user_id' => $validated['owner_user_id'] ?? null,
            'status' => $validated['status'],
            'updated_by' => $user->id,
        ])->save();

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
     * A 422 rather than a 403 or 404: the area id came from a form, and whether it names an area
     * of another tenant, an area outside the user's scope or nothing at all, the answer is the
     * same and says nothing about which.
     */
    private function authorizeArea(User $user, string $permissionKey, int $areaId): void
    {
        if (! $this->access->canInArea($user, $permissionKey, (int) $user->customer_id, $areaId)) {
            throw ValidationException::withMessages([
                'risk_access_area_id' => __('procynia.risk.validation.area_not_allowed'),
            ]);
        }
    }

    /**
     * The owner must be an active person in the same customer who can read risks in the chosen
     * area. An owner who cannot open the risk they own is not an owner.
     *
     * @param  array<string, mixed>  $validated
     */
    private function guardOwner(User $user, array $validated): void
    {
        $ownerId = $validated['owner_user_id'] ?? null;

        if ($ownerId === null) {
            return;
        }

        $owner = User::query()
            ->where('customer_id', (int) $user->customer_id)
            ->where('is_active', true)
            ->find((int) $ownerId);

        if ($owner === null
            || ! $this->access->canInArea($owner, CustomerPermissionCatalog::RISK_VIEW, (int) $user->customer_id, (int) $validated['risk_access_area_id'])) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('procynia.risk.validation.owner_not_allowed'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'risk_access_area_id' => ['required', 'integer'],
            'owner_user_id' => ['nullable', 'integer'],
            'status' => ['required', 'string', Rule::in(Risk::STATUSES)],
        ]);
    }

    /** @return array<string, mixed> */
    private function riskRow(Risk $risk): array
    {
        return [
            'id' => (int) $risk->id,
            'title' => $risk->title,
            'description' => $risk->description,
            'status' => $risk->status,
            'risk_access_area_id' => (int) $risk->risk_access_area_id,
            'area_name' => $risk->accessArea?->name,
            'owner_user_id' => $risk->owner_user_id !== null ? (int) $risk->owner_user_id : null,
            'owner_name' => $risk->owner?->name,
            'updated_at' => $risk->updated_at?->toIso8601String(),
            'url' => route('app.risk.show', ['riskId' => $risk->id]),
        ];
    }

    /**
     * @param  Collection<int, RiskAccessArea>  $areas
     * @return list<array{id: int, name: string}>
     */
    private function areaOptions(Collection $areas): array
    {
        return $areas
            ->map(fn (RiskAccessArea $area): array => ['id' => (int) $area->id, 'name' => $area->name])
            ->values()
            ->all();
    }

    /**
     * People who could own a risk, each with the areas — among those offered to the acting user —
     * in which they can read risks. The page narrows the list to the area chosen in the form; the
     * server checks the same thing again in guardOwner(). Areas the acting user cannot reach are
     * never named here.
     *
     * @param  Collection<int, RiskAccessArea>  $offeredAreas
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
