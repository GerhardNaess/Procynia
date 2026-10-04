<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskTreatmentService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tiltak on a risk: create, edit, complete, reopen.
 *
 * The risk is looked up through RiskAccessService::visibleRisks(), so a risk outside the user's
 * fagområder is a 404 here exactly as on its page, and an action of another risk is a 404 too.
 * Changing what is done about a risk is editing the risk: every write takes risk.edit in its area.
 */
class RiskTreatmentActionController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly RiskAccessService $access,
        private readonly RiskTreatmentService $treatments,
    ) {}

    public function store(Request $request, int $riskId): RedirectResponse
    {
        [$user, $risk] = $this->editableRisk($riskId);

        $this->treatments->create($user, $risk, $this->validated($request));

        return back()->with('success', __('procynia.risk.flash.action_created'));
    }

    public function update(Request $request, int $riskId, int $actionId): RedirectResponse
    {
        [$user, $risk] = $this->editableRisk($riskId);
        $action = $this->actionOrFail($risk, $actionId);

        $this->treatments->update($user, $action, $risk, $this->validated($request));

        return back()->with('success', __('procynia.risk.flash.action_updated'));
    }

    public function complete(Request $request, int $riskId, int $actionId): RedirectResponse
    {
        [$user, $risk] = $this->editableRisk($riskId);
        $action = $this->actionOrFail($risk, $actionId);

        $validated = $request->validate([
            'outcome_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->treatments->complete($user, $action, $validated['outcome_note'] ?? null);

        return back()->with('success', __('procynia.risk.flash.action_completed'));
    }

    public function reopen(int $riskId, int $actionId): RedirectResponse
    {
        [$user, $risk] = $this->editableRisk($riskId);
        $action = $this->actionOrFail($risk, $actionId);

        $this->treatments->reopen($user, $action);

        return back()->with('success', __('procynia.risk.flash.action_reopened'));
    }

    /** @return array{0: User, 1: Risk} */
    private function editableRisk(int $riskId): array
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $risk = $this->access->findVisible($user, $riskId) ?? abort(404);

        abort_unless($this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk), 403);

        return [$user, $risk];
    }

    private function actionOrFail(Risk $risk, int $actionId): RiskTreatmentAction
    {
        return $this->treatments->findForRisk($risk, $actionId) ?? abort(404);
    }

    /** @return array{title: string, owner_user_id: int, due_at: string, outcome_note?: ?string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'owner_user_id' => ['required', 'integer'],
            'due_at' => ['required', 'date_format:Y-m-d'],
            'outcome_note' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
