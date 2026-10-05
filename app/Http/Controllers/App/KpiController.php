<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Kpi;
use App\Models\KpiStatusChange;
use App\Models\Objective;
use App\Models\User;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\ObjectiveAccessService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use App\Support\Objectives\KpiPresenter;
use App\Support\Objectives\ObjectiveValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * KPIs, always under their objective.
 *
 * A KPI has no fagområde and no access rules of its own. Every request resolves the objective
 * through ObjectiveAccessService first, and the KPI only among that objective's KPIs: a KPI whose
 * objective is hidden, or asked for under another objective, is a 404 like one that does not exist.
 *
 *  - read: objective.view in the objective's area
 *  - create, change, retire, reopen: objective.edit there, while the objective is active
 *  - delete: objective.delete there, for a KPI registered by mistake (Kpi::isDeletable()): never
 *    once it has a measurement, withdrawn or not — then it is retired
 *  - measurements: KpiMeasurementController, with objective.measure
 *
 * Status changes only through retire() and reopen(), never through update().
 */
class KpiController extends Controller
{
    private const DECIMAL = '/^-?\d{1,16}(\.\d{1,4})?$/';

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ObjectiveAccessService $access,
        private readonly KpiLifecycleService $lifecycle,
        private readonly KpiPresenter $presenter,
    ) {}

    public function show(int $objectiveId, int $kpiId): Response
    {
        $user = $this->authorizedUser();
        $kpi = $this->visibleKpiOrFail($user, $objectiveId, $kpiId);
        $kpi->loadMissing(['owner:id,name', 'objective.owner:id,name', 'objective.businessArea:id,name']);
        $objective = $kpi->objective;

        $canEdit = $this->access->canEdit($user, $objective) && $objective->isActive();
        // Measuring is its own permission, and only an active KPI under an active objective takes it.
        $canMeasure = $this->access->canMeasure($user, $objective) && $objective->isActive() && $kpi->isActive();
        $measurements = $kpi->measurements()->with(['recordedBy:id,name', 'withdrawnBy:id,name'])->get();
        $today = now();

        return Inertia::render('App/Objectives/KpiShow', [
            'kpi' => $this->presenter->detail($kpi, $measurements, $today),
            'measurements' => $this->presenter->history($kpi, $measurements, $canMeasure),
            'measurement_form' => $canMeasure ? $this->presenter->measurementForm($kpi, $measurements, $today) : null,
            'objective' => [
                'id' => (int) $objective->id,
                'title' => $objective->title,
                'status' => $objective->status,
                'area_name' => $objective->businessArea?->name,
                'url' => route('app.objectives.show', ['objectiveId' => $objective->id]),
            ],
            'status_history' => $kpi->statusChanges()->with('changedBy:id,name')->get()
                ->map(fn (KpiStatusChange $change): array => [
                    'id' => (int) $change->id,
                    'from_status' => $change->from_status,
                    'to_status' => $change->to_status,
                    'note' => $change->note,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                    'changed_by_name' => $change->changedBy?->name,
                ])
                ->all(),
            'permissions' => [
                // A retired KPI is reopened before it is changed.
                'can_edit' => $canEdit && $kpi->isActive(),
                'can_retire' => $canEdit && $kpi->isActive(),
                'can_reopen' => $canEdit && ! $kpi->isActive(),
                'can_delete' => $this->access->canDelete($user, $objective) && $measurements->isEmpty(),
                'can_measure' => $canMeasure,
            ],
            'form_options' => $canEdit ? $this->presenter->formOptions($objective) : null,
        ]);
    }

    public function store(Request $request, int $objectiveId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $objective = $this->editableObjectiveOrFail($user, $objectiveId);

        if (! $objective->isActive()) {
            return back()->with('error', __('procynia.objectives.kpi.validation.objective_closed'));
        }

        $attributes = $this->validated($request, $objective);

        $kpi = Kpi::query()->create($attributes + [
            'customer_id' => (int) $objective->customer_id,
            'objective_id' => (int) $objective->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()
            ->route('app.objectives.kpis.show', ['objectiveId' => $objective->id, 'kpiId' => $kpi->id])
            ->with('success', __('procynia.objectives.kpi.flash.created'));
    }

    public function update(Request $request, int $objectiveId, int $kpiId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $kpi = $this->visibleKpiOrFail($user, $objectiveId, $kpiId);
        $objective = $kpi->objective;

        abort_unless($this->access->canEdit($user, $objective), 403);

        if (! $objective->isActive()) {
            return back()->with('error', __('procynia.objectives.kpi.validation.objective_closed'));
        }

        if (! $kpi->isActive()) {
            return back()->with('error', __('procynia.objectives.kpi.validation.reopen_before_edit'));
        }

        $attributes = $this->validated($request, $objective);
        $this->guardUnitDefinition($kpi, $attributes);

        $kpi->fill($attributes + ['updated_by' => $user->id])->save();

        return back()->with('success', __('procynia.objectives.kpi.flash.updated'));
    }

    /** Avslutt KPI: the decision to stop measuring it. The comment is optional. */
    public function retire(Request $request, int $objectiveId, int $kpiId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $kpi = $this->changeableKpiOrFail($user, $objectiveId, $kpiId);

        if ($kpi === null) {
            return back()->with('error', __('procynia.objectives.kpi.validation.objective_closed'));
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
        ], ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        $this->lifecycle->retire($kpi, $user, $validated['note'] ?? null);

        return back()->with('success', __('procynia.objectives.kpi.flash.retired'));
    }

    /** Gjenåpne: undoes a retirement, so it always says why. */
    public function reopen(Request $request, int $objectiveId, int $kpiId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $kpi = $this->changeableKpiOrFail($user, $objectiveId, $kpiId);

        if ($kpi === null) {
            return back()->with('error', __('procynia.objectives.kpi.validation.objective_closed'));
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        $this->lifecycle->reopen($kpi, $user, $validated['reason']);

        return back()->with('success', __('procynia.objectives.kpi.flash.reopened'));
    }

    public function destroy(int $objectiveId, int $kpiId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $kpi = $this->visibleKpiOrFail($user, $objectiveId, $kpiId);

        abort_unless($this->access->canDelete($user, $kpi->objective), 403);

        // A KPI with measurement history is retired, never deleted.
        if (! $kpi->isDeletable()) {
            return back()->with('error', __('procynia.objectives.kpi.validation.not_deletable'));
        }

        $kpi->delete();

        return redirect()
            ->route('app.objectives.show', ['objectiveId' => $objectiveId])
            ->with('success', __('procynia.objectives.kpi.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleKpiOrFail(User $user, int $objectiveId, int $kpiId): Kpi
    {
        return $this->access->findVisibleKpi($user, $objectiveId, $kpiId) ?? abort(404);
    }

    private function editableObjectiveOrFail(User $user, int $objectiveId): Objective
    {
        $objective = $this->access->findVisible($user, $objectiveId) ?? abort(404);

        abort_unless($this->access->canEdit($user, $objective), 403);

        return $objective;
    }

    /**
     * The KPI for a status change: objective.edit in the objective's area, or 403. Null when the
     * objective is closed — a closed objective's KPIs are left as they were until it is reopened.
     */
    private function changeableKpiOrFail(User $user, int $objectiveId, int $kpiId): ?Kpi
    {
        $kpi = $this->visibleKpiOrFail($user, $objectiveId, $kpiId);

        abort_unless($this->access->canEdit($user, $kpi->objective), 403);

        return $kpi->objective->isActive() ? $kpi : null;
    }

    /**
     * The same fields for Ny KPI and Rediger, checked as a whole: the unit decides which of
     * currency code and unit label belong, and the bounds and tolerance are checked together by
     * KpiTarget — the same rules the model enforces before saving. Status is not among them.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Objective $objective): array
    {
        // Norwegian decimals are written with a comma, and pasted numbers may carry spaces.
        $request->merge(collect(['target_min', 'target_max', 'tolerance'])
            ->filter(fn (string $field): bool => is_string($request->input($field)))
            ->mapWithKeys(fn (string $field): array => [
                $field => str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim((string) $request->input($field))),
            ])
            ->all());

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'integer'],
            'unit' => ['required', 'string', Rule::in(Kpi::UNITS)],
            'unit_label' => ['nullable', 'string', 'max:60'],
            'currency_code' => ['nullable', 'string'],
            'target_min' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'target_max' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'tolerance' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'frequency' => ['nullable', 'string', Rule::in(Kpi::FREQUENCIES)],
            'reporting_grace_days' => ['required', 'integer', 'min:0', 'max:365'],
        ], [
            'reporting_grace_days.integer' => __('procynia.objectives.validation.rules.number'),
        ] + ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        $unit = $validated['unit'];
        $unitLabel = $this->normalizedText($validated['unit_label'] ?? null);
        $currencyCode = $this->normalizedText($validated['currency_code'] ?? null);
        $currencyCode = $currencyCode !== null ? mb_strtoupper($currencyCode) : null;
        $errors = [];

        if ($unit === Kpi::UNIT_CURRENCY && $currencyCode === null) {
            $errors['currency_code'] = __('procynia.objectives.kpi.validation.currency_required');
        } elseif ($unit !== Kpi::UNIT_CURRENCY && $currencyCode !== null) {
            $errors['currency_code'] = __('procynia.objectives.kpi.validation.currency_not_allowed');
        } elseif ($currencyCode !== null && preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            $errors['currency_code'] = __('procynia.objectives.kpi.validation.currency_code');
        }

        if ($unitLabel !== null && ! in_array($unit, Kpi::LABELLED_UNITS, true)) {
            $errors['unit_label'] = __('procynia.objectives.kpi.validation.unit_label_not_allowed');
        }

        $min = $this->normalizedText($validated['target_min'] ?? null);
        $max = $this->normalizedText($validated['target_max'] ?? null);
        $tolerance = $this->normalizedText($validated['tolerance'] ?? null);

        foreach (KpiTarget::errorsFor($min, $max, $tolerance) as $field => $code) {
            $errors[$field] = __("procynia.objectives.kpi.validation.{$code}");
        }

        $ownerId = isset($validated['owner_user_id']) ? (int) $validated['owner_user_id'] : null;

        if ($ownerId !== null && ! $this->ownerAllowed($ownerId, $objective)) {
            $errors['owner_user_id'] = __('procynia.objectives.kpi.validation.owner_not_allowed');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => trim($validated['title']),
            'description' => $this->normalizedText($validated['description'] ?? null),
            'owner_user_id' => $ownerId,
            'unit' => $unit,
            'unit_label' => $unitLabel,
            'currency_code' => $currencyCode,
            'target_min' => $min,
            'target_max' => $max,
            'tolerance' => $tolerance,
            'frequency' => $validated['frequency'] ?? null,
            'reporting_grace_days' => (int) $validated['reporting_grace_days'],
        ];
    }

    /**
     * Once a measurement exists, what its numbers mean — unit, unit label, currency — is fixed, or
     * the history would change meaning. The target and tolerance stay editable: each measurement
     * keeps a snapshot of the ones it was registered against. The model refuses the same.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardUnitDefinition(Kpi $kpi, array $attributes): void
    {
        $changed = array_filter(
            Kpi::UNIT_DEFINITION,
            fn (string $field): bool => ($attributes[$field] ?? null) !== $kpi->getAttribute($field),
        );

        if ($changed !== [] && $kpi->hasMeasurementHistory()) {
            throw ValidationException::withMessages(array_fill_keys(
                array_values($changed),
                __('procynia.objectives.kpi.validation.unit_locked'),
            ));
        }
    }

    /**
     * A KPI owner is an active person of the objective's customer who can read objectives in its
     * area. An owner who cannot open the KPI they own is not an owner.
     */
    private function ownerAllowed(int $ownerId, Objective $objective): bool
    {
        $owner = User::query()
            ->where('customer_id', (int) $objective->customer_id)
            ->where('is_active', true)
            ->find($ownerId);

        return $owner !== null
            && $this->access->canInArea($owner, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $objective->customer_id, (int) $objective->business_area_id);
    }

    private function normalizedText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
