<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\User;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskControlService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Linking a risk to an existing Kvalitet control, and removing that link.
 *
 * The risk is looked up through RiskAccessService::visibleRisks(), so a risk outside the user's
 * fagområder is a 404 here exactly as on its page. Changing what handles a risk is editing
 * the risk: it takes risk.edit in the risk's area. And because the page then shows the control,
 * the person must be able to read controls in Kvalitet as well (RiskControlService).
 */
class RiskControlController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly RiskAccessService $access,
        private readonly RiskControlService $controls,
    ) {}

    public function store(Request $request, int $riskId): RedirectResponse
    {
        [$user, $risk] = $this->editableRisk($riskId);

        $validated = $request->validate([
            'quality_item_id' => ['required', 'integer'],
        ]);

        $this->controls->link($user, $risk, (int) $validated['quality_item_id']);

        return back()->with('success', __('procynia.risk.flash.control_linked'));
    }

    public function destroy(int $riskId, int $controlId): RedirectResponse
    {
        [, $risk] = $this->editableRisk($riskId);

        abort_unless($this->controls->unlink($risk, $controlId), 404);

        return back()->with('success', __('procynia.risk.flash.control_unlinked'));
    }

    /** @return array{0: User, 1: Risk} */
    private function editableRisk(int $riskId): array
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $risk = $this->access->findVisible($user, $riskId) ?? abort(404);

        abort_unless($this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk), 403);
        abort_unless($this->controls->canReadControls($user), 403);

        return [$user, $risk];
    }
}
