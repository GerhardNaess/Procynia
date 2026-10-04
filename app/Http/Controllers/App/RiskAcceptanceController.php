<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\User;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accepting the residual risk of a risk's latest assessment, and revoking an acceptance.
 *
 * The risk is looked up through RiskAccessService::visibleRisks(), so a risk outside the user's
 * fagområder is a 404 here exactly as on its page. Seeing it is not enough: accepting and revoking
 * take risk.accept from a role that reaches the risk's area. risk.edit and risk.assess grant neither.
 */
class RiskAcceptanceController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly RiskAccessService $access,
        private readonly RiskAcceptanceService $acceptances,
    ) {}

    public function store(Request $request, int $riskId): RedirectResponse
    {
        [$user, $risk] = $this->acceptableRisk($riskId);

        $validated = $request->validate([
            'assessment_id' => ['required', 'integer'],
            'rationale' => ['required', 'string', 'max:5000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        $this->acceptances->accept(
            $user,
            $risk,
            (int) $validated['assessment_id'],
            $validated['rationale'],
            $validated['valid_until'] ?? null,
        );

        return back()->with('success', __('procynia.risk.flash.accepted'));
    }

    public function revoke(int $riskId, int $acceptanceId): RedirectResponse
    {
        [$user, $risk] = $this->acceptableRisk($riskId);

        $acceptance = $this->acceptances->findForRisk($risk, $acceptanceId) ?? abort(404);

        $this->acceptances->revoke($user, $acceptance);

        return back()->with('success', __('procynia.risk.flash.acceptance_revoked'));
    }

    /** @return array{0: User, 1: Risk} */
    private function acceptableRisk(int $riskId): array
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $risk = $this->access->findVisible($user, $riskId) ?? abort(404);

        abort_unless($this->access->can($user, CustomerPermissionCatalog::RISK_ACCEPT, $risk), 403);

        return [$user, $risk];
    }
}
