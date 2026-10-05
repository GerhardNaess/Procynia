<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\Objectives\KpiQualityContextService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Support\CustomerContext;
use App\Support\Objectives\ObjectiveValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endre kobling: which Kvalitet process, and which activities in it, a KPI measures.
 *
 * The KPI is reached like everywhere else — the objective through ObjectiveAccessService, the KPI
 * among its KPIs (hidden ⇒ 404). Changing what it measures is changing the KPI: objective.edit in
 * the objective's area, with the objective and the KPI active. Because the form shows Kvalitet's
 * processes, the person must also be able to read Kvalitet; objective.edit never implies that.
 */
class KpiContextController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ObjectiveAccessService $access,
        private readonly KpiQualityContextService $context,
    ) {}

    public function update(Request $request, int $objectiveId, int $kpiId, int $processId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $kpi = $this->access->findVisibleKpi($user, $objectiveId, $kpiId) ?? abort(404);
        $objective = $kpi->objective;

        abort_unless($this->access->canEdit($user, $objective), 403);
        abort_unless($this->context->canReadQuality($user), 403);

        if (! $objective->isActive()) {
            return back()->with('error', __('procynia.objectives.kpi.validation.objective_closed'));
        }

        if (! $kpi->isActive()) {
            return back()->with('error', __('procynia.objectives.kpi.validation.reopen_before_edit'));
        }

        $validated = $request->validate([
            'whole_process' => ['boolean'],
            'activity_keys' => ['array', 'max:200'],
            'activity_keys.*' => ['string', 'max:80'],
        ], ObjectiveValidationMessages::messages(), ObjectiveValidationMessages::attributes());

        $this->context->syncProcess(
            $user,
            $kpi,
            $processId,
            (bool) ($validated['whole_process'] ?? false),
            array_map('strval', $validated['activity_keys'] ?? []),
        );

        return back()->with('success', __('procynia.objectives.kpi.context.flash.updated'));
    }
}
