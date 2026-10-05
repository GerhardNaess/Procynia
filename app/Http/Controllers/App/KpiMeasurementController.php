<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\User;
use App\Services\Objectives\KpiMeasurementService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Support\CustomerContext;
use App\Support\Objectives\ObjectiveValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Registrer måling and Trekk tilbake, always under the KPI and its objective.
 *
 * Reached like the KPI itself — the objective through ObjectiveAccessService, the KPI among its
 * KPIs, the measurement among the KPI's measurements — so anything outside the user's areas, or
 * asked for under the wrong parent, is a 404.
 *
 * Both take objective.measure in the objective's area; objective.edit neither gives nor needs it.
 * Both need the objective and the KPI active. There is no edit and no delete: a mistake is
 * corrected by a new measurement for the same period, or withdrawn.
 */
class KpiMeasurementController extends Controller
{
    private const DECIMAL = '/^-?\d{1,16}(\.\d{1,4})?$/';

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ObjectiveAccessService $access,
        private readonly KpiMeasurementService $measurements,
    ) {}

    public function store(Request $request, int $objectiveId, int $kpiId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $kpi = $this->measurableKpiOrFail($user, $objectiveId, $kpiId);

        if ($kpi === null) {
            return back()->with('error', __('procynia.objectives.measurement.validation.not_measurable'));
        }

        // Norwegian decimals are written with a comma, and pasted numbers may carry spaces.
        if (is_string($request->input('value'))) {
            $request->merge(['value' => str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim((string) $request->input('value')))]);
        }

        // The målverdi is copied from the KPI by the service; target fields in the request are ignored.
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:20'],
            'measured_on' => ['nullable', 'string', 'max:20'],
            'value' => ['required', 'string', 'regex:'.self::DECIMAL],
            'comment' => ['nullable', 'string', 'max:5000'],
        ], [
            'value.regex' => __('procynia.objectives.measurement.validation.value_number'),
        ] + ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        $period = $this->measurements->periodFor($kpi, $validated['period'] ?? null, $validated['measured_on'] ?? null, now());
        $this->measurements->record($kpi, $user, $period, $validated['value'], $validated['comment'] ?? null);

        return redirect()
            ->route('app.objectives.kpis.show', ['objectiveId' => $objectiveId, 'kpiId' => $kpiId])
            ->with('success', __('procynia.objectives.measurement.flash.recorded'));
    }

    public function withdraw(Request $request, int $objectiveId, int $kpiId, int $measurementId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $kpi = $this->measurableKpiOrFail($user, $objectiveId, $kpiId);
        $measurement = KpiMeasurement::query()
            ->where('kpi_id', $kpiId)
            ->where('customer_id', (int) $user->customer_id)
            ->find($measurementId) ?? abort(404);

        if ($kpi === null) {
            return back()->with('error', __('procynia.objectives.measurement.validation.not_measurable'));
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:5000'],
        ], ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        if ($measurement->isWithdrawn()) {
            throw ValidationException::withMessages([
                'reason' => __('procynia.objectives.measurement.validation.already_withdrawn'),
            ]);
        }

        $this->measurements->withdraw($measurement, $user, $validated['reason'] ?? null);

        return back()->with('success', __('procynia.objectives.measurement.flash.withdrawn'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    /**
     * The KPI to measure: visible (or 404), objective.measure in the objective's area (or 403). Null
     * when the objective is closed or the KPI retired — neither takes measurements.
     */
    private function measurableKpiOrFail(User $user, int $objectiveId, int $kpiId): ?Kpi
    {
        $kpi = $this->access->findVisibleKpi($user, $objectiveId, $kpiId) ?? abort(404);

        abort_unless($this->access->canMeasure($user, $kpi->objective), 403);

        return $kpi->objective->isActive() && $kpi->isActive() ? $kpi : null;
    }
}
