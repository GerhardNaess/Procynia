<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\RiskAssessment;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskScoringPolicy;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registering a risk assessment. The only write there is: an assessment is never edited or
 * deleted, and a correction is simply the next one.
 *
 * Access is the risk's. The risk is looked up through RiskAccessService::visibleRisks(), so a risk
 * outside the user's fagområder is a 404 here exactly as on its page. Seeing it is not enough
 * to assess it: that takes risk.assess from a role that reaches the risk's area — the same pair
 * rule as every other risk permission. risk.edit is neither needed nor sufficient.
 */
class RiskAssessmentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly RiskAccessService $access,
        private readonly RiskScoringPolicy $scoring,
    ) {}

    public function store(Request $request, int $riskId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $risk = $this->access->findVisible($user, $riskId) ?? abort(404);

        abort_unless($this->access->can($user, CustomerPermissionCatalog::RISK_ASSESS, $risk), 403);

        $criteria = $this->scoring->criteria();

        $validated = $request->validate([
            'inherent_likelihood' => ['required', 'integer', Rule::in($criteria['likelihood'])],
            'inherent_consequence' => ['required', 'integer', Rule::in($criteria['consequence'])],
            // Residual risk is optional, but a half-given residual has no level and means nothing.
            'residual_likelihood' => ['nullable', 'integer', Rule::in($criteria['likelihood']), 'required_with:residual_consequence'],
            'residual_consequence' => ['nullable', 'integer', Rule::in($criteria['consequence']), 'required_with:residual_likelihood'],
            'rationale' => ['required', 'string', 'max:2000'],
        ]);

        RiskAssessment::query()->create([
            'customer_id' => (int) $risk->customer_id,
            'risk_id' => (int) $risk->id,
            'assessed_by' => $user->id,
            'assessed_at' => now(),
            'rationale' => trim($validated['rationale']),
            // What was assessed, as the risk read at this moment. A later edit of the risk leaves it.
            'risk_cause' => $risk->cause,
            'risk_event' => $risk->event,
            'risk_consequence' => $risk->consequence,
            'criteria_key' => $this->scoring->currentCriteriaKey(),
            'inherent_likelihood' => (int) $validated['inherent_likelihood'],
            'inherent_consequence' => (int) $validated['inherent_consequence'],
            'residual_likelihood' => isset($validated['residual_likelihood']) ? (int) $validated['residual_likelihood'] : null,
            'residual_consequence' => isset($validated['residual_consequence']) ? (int) $validated['residual_consequence'] : null,
        ]);

        return back()->with('success', __('procynia.risk.flash.assessed'));
    }
}
