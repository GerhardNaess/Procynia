<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskWikiKnowledgeService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «Lag kunnskapsartikkel» on a risk: what the person wrote becomes an ordinary Enterprise Wiki
 * source. The risk is found through visibleRisks() first, so a hidden or foreign risk is a 404
 * whatever Wiki permissions the person holds; then risk.edit in its area and wiki.source.manage.
 */
class RiskWikiKnowledgeController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly RiskAccessService $access,
        private readonly RiskWikiKnowledgeService $knowledge,
    ) {}

    public function store(Request $request, int $riskId): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        $risk = $this->access->findVisible($user, $riskId) ?? abort(404);

        abort_unless($this->knowledge->canHandOff($user, $risk), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.RiskWikiKnowledgeService::MAX_TITLE_LENGTH],
            'markdown' => ['required', 'string', 'max:'.RiskWikiKnowledgeService::MAX_MARKDOWN_LENGTH],
        ], [
            'title.required' => __('procynia.risk.validation.knowledge_title_required'),
            'markdown.required' => __('procynia.risk.validation.knowledge_markdown_required'),
        ]);

        if ($this->knowledge->countForRisk($risk) >= RiskWikiKnowledgeService::MAX_PER_RISK) {
            throw ValidationException::withMessages([
                'title' => __('procynia.risk.validation.knowledge_limit_reached'),
            ]);
        }

        $this->knowledge->create($user, $risk, (string) $validated['title'], (string) $validated['markdown']);

        return back()->with('success', __('procynia.risk.flash.knowledge_queued'));
    }
}
