<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Improvements\ImprovementCaseQualityContextService;
use App\Support\CustomerContext;
use App\Support\Improvements\ImprovementValidationMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endre kobling: which Kvalitet process, and which activities in it, an avvik or a forbedring
 * concerns.
 *
 * The case is reached like everywhere else, through ImprovementCaseAccessService (hidden ⇒ 404).
 * Changing what it concerns is changing the case: improvement.edit in its area, with the case open
 * or in progress. Because the form shows Kvalitet's processes, the person must also be able to read
 * Kvalitet; improvement.edit never implies that.
 */
class ImprovementCaseContextController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly ImprovementCaseAccessService $access,
        private readonly ImprovementCaseQualityContextService $context,
    ) {}

    public function update(Request $request, int $caseId, int $processId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $case = $this->access->findVisible($user, $caseId) ?? abort(404);

        abort_unless($this->access->canEdit($user, $case), 403);
        abort_unless($this->context->canReadQuality($user), 403);

        if (! $case->isActive()) {
            return back()->with('error', __('procynia.improvements.validation.reopen_before_edit'));
        }

        $validated = $request->validate([
            'whole_process' => ['boolean'],
            'activity_keys' => ['array', 'max:200'],
            'activity_keys.*' => ['string', 'max:80'],
        ], ImprovementValidationMessages::messages(), ImprovementValidationMessages::attributes());

        $this->context->syncProcess(
            $user,
            $case,
            $processId,
            (bool) ($validated['whole_process'] ?? false),
            array_map('strval', $validated['activity_keys'] ?? []),
        );

        return back()->with('success', __('procynia.improvements.context.flash.updated'));
    }
}
