<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Kpi;
use App\Models\KpiStatusChange;
use App\Models\Objective;
use App\Models\User;
use App\Services\Objectives\KpiGrowth;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiQualityContextService;
use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\KpiUnitRules;
use App\Services\Objectives\ObjectiveAccessService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use App\Support\Objectives\KpiPresenter;
use App\Support\Objectives\ObjectiveValidationMessages;
use Brick\Math\BigDecimal;
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
 *  - process/activity links: KpiContextController, with objective.edit and Kvalitet read
 *
 * Status changes only through retire() and reopen(), never through update().
 */
class KpiController extends Controller
{
    private const DECIMAL = '/^-?\d{1,16}(\.\d{1,4})?$/';

    /** The fifth way to set a målverdi: as change from a startverdi (KpiGrowth). Not a KpiTarget type. */
    private const TYPE_GROWTH = 'growth';

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ObjectiveAccessService $access,
        private readonly KpiLifecycleService $lifecycle,
        private readonly KpiPresenter $presenter,
        private readonly KpiQualityContextService $qualityContext,
    ) {}

    public function show(int $objectiveId, int $kpiId): Response
    {
        $user = $this->authorizedUser();
        $kpi = $this->visibleKpiOrFail($user, $objectiveId, $kpiId);
        $kpi->loadMissing(['owner:id,name', 'objective.owner:id,name', 'objective.businessArea:id,name']);
        $objective = $kpi->objective;

        $canEdit = $this->access->canEdit($user, $objective) && $objective->isActive();
        $canReadQuality = $this->qualityContext->canReadQuality($user);
        $canLinkContext = $canReadQuality && $canEdit && $kpi->isActive();
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
                'can_link_context' => $canLinkContext,
            ],
            'form_options' => $canEdit ? $this->presenter->formOptions($objective) : null,
            // null, not empty: the person cannot read Kvalitet, so nothing is said about context.
            'quality_context' => $canReadQuality ? $this->qualityContext->linkedContext($kpi) : null,
            'quality_context_options' => $canLinkContext ? $this->qualityContext->contextOptions($kpi) : [],
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

        $attributes = $this->validated($request, $objective, $kpi);
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
     * MÅLTYPE. target_type says which fields the målverdi is read from, and the rest are dropped:
     *  - at_least: target_min · at_most: target_max · range: both, min below max
     *  - exact: target_min, stored as both bounds
     *  - growth: baseline_value, baseline_date and change_percent; the målverdi is computed (KpiGrowth)
     * Without target_type the bounds are taken as they come, as before the type existed.
     *
     * Every number must fit the unit (KpiUnitRules) — the målverdi, the startverdi and, for its
     * precision, the tolerance.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Objective $objective, ?Kpi $kpi = null): array
    {
        // Norwegian decimals are written with a comma, and pasted numbers may carry spaces.
        $request->merge(collect(['target_min', 'target_max', 'tolerance', 'baseline_value', 'change_percent'])
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
            'target_type' => ['nullable', 'string', Rule::in([...KpiTarget::TYPES, self::TYPE_GROWTH])],
            'target_min' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'target_max' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'tolerance' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'baseline_value' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'baseline_date' => ['nullable', 'date_format:Y-m-d'],
            'change_percent' => ['nullable', 'string', 'regex:'.self::DECIMAL],
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

        $target = $this->targetFields($validated, $unit, $kpi);
        $errors += $target['errors'];

        if ($target['errors'] === []) {
            foreach (KpiTarget::errorsFor($target['min'], $target['max'], $target['tolerance']) as $field => $code) {
                $errors[$field] ??= __("procynia.objectives.kpi.validation.{$code}");
            }
        }

        foreach (['target_min' => $target['min'], 'target_max' => $target['max'], 'baseline_value' => $target['baseline']] as $field => $value) {
            $message = $value !== null ? KpiUnitRules::message($unit, BigDecimal::of($value)) : null;

            if ($message !== null) {
                // A computed målverdi is reported where it was typed: the startverdi and the change.
                $errors[$target['baseline'] !== null && $field !== 'baseline_value' ? 'change_percent' : $field] ??= $message;
            }
        }

        if ($target['tolerance'] !== null && ($message = KpiUnitRules::message($unit, BigDecimal::of($target['tolerance']), true)) !== null) {
            $errors['tolerance'] ??= $message;
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
            'target_min' => $target['min'],
            'target_max' => $target['max'],
            'tolerance' => $target['tolerance'],
            'baseline_value' => $target['baseline'],
            'baseline_date' => $target['baseline'] !== null ? $target['baseline_date'] : null,
            'frequency' => $validated['frequency'] ?? null,
            'reporting_grace_days' => (int) $validated['reporting_grace_days'],
        ];
    }

    /**
     * The målverdi as the chosen type reads it: the bounds to store, the startverdi when there is one,
     * and what is missing, keyed by field.
     *
     * A growth målverdi is computed from the startverdi and the change. Saved again with the same
     * startverdi and the change the page showed, the stored målverdi is kept as it is — the change
     * shown is rounded to two decimals, and recomputing from it must not move the target.
     *
     * @param  array<string, mixed>  $validated
     * @return array{min: ?string, max: ?string, tolerance: ?string, baseline: ?string, baseline_date: ?string, errors: array<string, string>}
     */
    private function targetFields(array $validated, string $unit, ?Kpi $kpi): array
    {
        $type = $validated['target_type'] ?? null;
        $min = $this->normalizedText($validated['target_min'] ?? null);
        $max = $this->normalizedText($validated['target_max'] ?? null);
        $tolerance = $this->normalizedText($validated['tolerance'] ?? null);
        $result = ['min' => $min, 'max' => $max, 'tolerance' => $tolerance, 'baseline' => null, 'baseline_date' => null, 'errors' => []];
        $missing = fn (string $field): array => [$field => __('procynia.objectives.kpi.validation.target_required')];

        switch ($type) {
            case KpiTarget::TYPE_AT_LEAST:
                $result['max'] = null;
                $result['errors'] = $min === null ? $missing('target_min') : [];
                break;

            case KpiTarget::TYPE_AT_MOST:
                $result['min'] = null;
                $result['errors'] = $max === null ? $missing('target_max') : [];
                break;

            case KpiTarget::TYPE_RANGE:
                $result['errors'] = ($min === null ? $missing('target_min') : []) + ($max === null ? $missing('target_max') : []);

                if ($result['errors'] === [] && BigDecimal::of($min)->isEqualTo($max)) {
                    $result['errors'] = ['target_max' => __('procynia.objectives.kpi.validation.range_equal')];
                }
                break;

            case KpiTarget::TYPE_EXACT:
                $result['max'] = $min;
                $result['errors'] = $min === null ? $missing('target_min') : [];
                break;

            case self::TYPE_GROWTH:
                $baseline = KpiTarget::decimal($this->normalizedText($validated['baseline_value'] ?? null));
                $date = $validated['baseline_date'] ?? null;
                $change = KpiTarget::decimal($this->normalizedText($validated['change_percent'] ?? null));
                $errors = KpiGrowth::errors($unit, $baseline, $date, $change);

                if ($errors !== []) {
                    $result['errors'] = array_map(fn (string $code): string => __("procynia.objectives.kpi.validation.{$code}"), $errors);
                    break;
                }

                $computed = KpiGrowth::target($unit, $baseline, $change);
                $stored = $kpi?->hasBaseline() ? KpiTarget::decimal($kpi->target_min ?? $kpi->target_max) : null;

                if ($stored !== null
                    && $kpi->unit === $unit
                    && KpiTarget::decimal($kpi->baseline_value)->isEqualTo($baseline)
                    && KpiGrowth::bound($change) === ($kpi->target_min !== null ? 'target_min' : 'target_max')
                    && KpiGrowth::changePercent($baseline, $stored)->isEqualTo($change)) {
                    $computed = $stored;
                }

                $bound = (string) $computed->strippedOfTrailingZeros();
                $result['min'] = KpiGrowth::bound($change) === 'target_min' ? $bound : null;
                $result['max'] = KpiGrowth::bound($change) === 'target_max' ? $bound : null;
                $result['baseline'] = (string) $baseline->strippedOfTrailingZeros();
                $result['baseline_date'] = $date;
                break;
        }

        return $result;
    }

    /**
     * Once a measurement exists, what its numbers mean — unit, unit label, currency — is fixed, or
     * the history would change meaning. Prosentandel and prosentvis endring may still be swapped:
     * both are written in per cent. The target and tolerance stay editable: each measurement
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

        if ($changed !== [] && $kpi->unitDefinitionChanges($attributes) && $kpi->hasMeasurementHistory()) {
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
