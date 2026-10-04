<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Models\User;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskControlService;
use App\Services\Risk\RiskQualityContextService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Linking a risk to the Kvalitet processes and activities it belongs to, and removing those links.
 *
 * Same gate as RiskControlController: the risk is looked up through visibleRisks() (hidden ⇒ 404),
 * changing its context is editing it (risk.edit in its area), and because the page then shows the
 * process, the person must be able to read Kvalitet as well.
 */
class RiskContextController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly RiskAccessService $access,
        private readonly RiskControlService $controls,
        private readonly RiskQualityContextService $context,
    ) {}

    public function store(Request $request, int $riskId): RedirectResponse
    {
        [$user, $risk] = $this->editableRisk($riskId);

        $validated = $request->validate([
            'quality_item_id' => ['required', 'integer'],
            'activity_key' => ['nullable', 'string', 'max:80'],
        ]);

        $activityKey = isset($validated['activity_key']) && $validated['activity_key'] !== '' ? (string) $validated['activity_key'] : null;

        $this->context->link($user, $risk, (int) $validated['quality_item_id'], $activityKey);

        return back()->with('success', __('procynia.risk.flash.context_linked'));
    }

    public function destroyProcess(int $riskId, int $processId): RedirectResponse
    {
        [, $risk] = $this->editableRisk($riskId);

        abort_unless($this->context->unlinkProcess($risk, $processId), 404);

        return back()->with('success', __('procynia.risk.flash.context_unlinked'));
    }

    public function destroyActivity(int $riskId, int $linkId): RedirectResponse
    {
        [, $risk] = $this->editableRisk($riskId);

        abort_unless($this->context->unlinkActivity($risk, $linkId), 404);

        return back()->with('success', __('procynia.risk.flash.context_unlinked'));
    }

    /** @return array{0: User, 1: Risk} */
    private function editableRisk(int $riskId): array
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $risk = $this->access->findVisible($user, $riskId) ?? abort(404);

        abort_unless($this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $risk), 403);
        abort_unless($this->controls->canReadQuality($user), 403);

        return [$user, $risk];
    }
}
