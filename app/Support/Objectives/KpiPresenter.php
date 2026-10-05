<?php

namespace App\Support\Objectives;

use App\Models\Kpi;
use App\Models\Objective;
use App\Models\User;
use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\ObjectiveAccessService;

/**
 * What a KPI looks like on a page, in one place for the objective's KPI list and the KPI page.
 *
 * The responsible person is the KPI's own owner or, failing that, the objective's owner — marked
 * as the fallback so the page can say so. That is decided here, on the way out; the objective's
 * owner is never written into the KPI.
 *
 * No measured value, target status or next period appears here: there are no measurements yet, and
 * the page shows nothing it cannot back.
 */
final class KpiPresenter
{
    public function __construct(private readonly ObjectiveAccessService $access) {}

    /**
     * A row for the objective's KPI list. Expects owner and objective.owner loaded.
     *
     * @return array<string, mixed>
     */
    public function row(Kpi $kpi): array
    {
        $formatter = new KpiTargetFormatter;
        $responsible = $kpi->responsible();

        return [
            'id' => (int) $kpi->id,
            'title' => $kpi->title,
            'status' => $kpi->status,
            'unit' => $kpi->unit,
            'unit_display' => $formatter->unit($kpi),
            'target_display' => $formatter->target($kpi),
            'frequency' => $kpi->frequency,
            'responsible_name' => $responsible?->name,
            'responsible_is_fallback' => $kpi->owner_user_id === null && $responsible !== null,
            'url' => route('app.objectives.kpis.show', ['objectiveId' => $kpi->objective_id, 'kpiId' => $kpi->id]),
        ];
    }

    /**
     * Everything the KPI page shows, plus the stored values its edit form starts from.
     *
     * @return array<string, mixed>
     */
    public function detail(Kpi $kpi): array
    {
        $formatter = new KpiTargetFormatter;

        return $this->row($kpi) + [
            'objective_id' => (int) $kpi->objective_id,
            'description' => $kpi->description,
            'owner_user_id' => $kpi->owner_user_id !== null ? (int) $kpi->owner_user_id : null,
            'unit_label' => $kpi->unit_label,
            'currency_code' => $kpi->currency_code,
            // Plain decimals for the form, without the trailing zeros of the column.
            'target_min' => $this->plain($kpi->target_min),
            'target_max' => $this->plain($kpi->target_max),
            'tolerance' => $this->plain($kpi->tolerance),
            'tolerance_display' => $kpi->target()->hasTolerance()
                ? $formatter->amount($kpi, $kpi->tolerance)
                : null,
            'reporting_grace_days' => (int) $kpi->reporting_grace_days,
            // The rule, not a date: which period is due is a question for when measurements exist.
            'deadline_display' => $kpi->frequency !== null
                ? trans_choice('procynia.objectives.kpi.deadline_value', (int) $kpi->reporting_grace_days, ['count' => (int) $kpi->reporting_grace_days])
                : __('procynia.objectives.kpi.deadline_without_frequency'),
            'updated_at' => $kpi->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The people who may own a KPI under this objective: active users of the same customer who can
     * read objectives in its fagområde. The server checks the same again on save.
     *
     * @return list<array{id: int, name: string}>
     */
    public function ownerOptions(Objective $objective): array
    {
        $viewerAreas = $this->access->viewerAreaIdsByUser((int) $objective->customer_id);
        $areaId = (int) $objective->business_area_id;

        return User::query()
            ->where('customer_id', (int) $objective->customer_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $candidate): bool => in_array($areaId, $viewerAreas[(int) $candidate->id] ?? [], true))
            ->map(fn (User $candidate): array => ['id' => (int) $candidate->id, 'name' => $candidate->name])
            ->values()
            ->all();
    }

    /**
     * What the KPI form chooses from.
     *
     * @return array<string, mixed>
     */
    public function formOptions(Objective $objective): array
    {
        return [
            'units' => Kpi::UNITS,
            'labelled_units' => Kpi::LABELLED_UNITS,
            'frequencies' => Kpi::FREQUENCIES,
            'default_currency_code' => Kpi::DEFAULT_CURRENCY_CODE,
            'default_grace_days' => Kpi::DEFAULT_REPORTING_GRACE_DAYS,
            'owner_options' => $this->ownerOptions($objective),
            'objective_owner_name' => $objective->owner?->name,
        ];
    }

    private function plain(?string $value): ?string
    {
        $decimal = KpiTarget::decimal($value);

        return $decimal !== null ? (string) $decimal->strippedOfTrailingZeros() : null;
    }
}
