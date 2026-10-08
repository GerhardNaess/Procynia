<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeHandoffService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «Lag kunnskapsartikkel» for every module. Each module registers its own route under its own
 * route-name prefix (so the module gate applies) with a fixed `sourceType` default; they all land
 * here.
 *
 * Order matters: the record is found through the module's own access rules first, so a hidden or
 * foreign record is a 404 whatever Wiki permissions the person holds; only then are the module's
 * write permission and the Wiki permission checked.
 */
class WikiKnowledgeHandoffController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly WikiKnowledgeHandoffService $handoff,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        // Read by name: the route's `sourceType` is a default, and defaults are passed positionally.
        $sourceType = (string) $request->route('sourceType');
        $sourceId = (int) $request->route('sourceId');
        $user = $this->customerContext->currentUser();
        $source = $this->handoff->source($sourceType);

        abort_unless($source->canOpenModule($user), 403);

        $record = $source->findForUser($user, $sourceId) ?? abort(404);

        abort_unless($this->handoff->canHandOff($user, $source, $record), 403);

        $offered = array_column($source->draft($user, $record)->toArray()['sections'], 'key');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.WikiKnowledgeHandoffService::MAX_TITLE_LENGTH],
            'learning' => ['required', 'string', 'max:'.WikiKnowledgeHandoffService::MAX_LEARNING_LENGTH],
            'sections' => ['present', 'array'],
            'sections.*' => ['string', 'in:'.implode(',', $offered)],
        ], [
            'title.required' => __('procynia.knowledge_handoff.validation.title_required'),
            'title.max' => __('procynia.knowledge_handoff.validation.title_max', ['max' => WikiKnowledgeHandoffService::MAX_TITLE_LENGTH]),
            'learning.required' => __('procynia.knowledge_handoff.validation.learning_required'),
            'learning.max' => __('procynia.knowledge_handoff.validation.learning_max', ['max' => WikiKnowledgeHandoffService::MAX_LEARNING_LENGTH]),
            'sections.*.in' => __('procynia.knowledge_handoff.validation.section_unknown'),
        ]);

        if ($this->handoff->countForSource($sourceType, $record) >= WikiKnowledgeHandoffService::MAX_PER_SOURCE) {
            throw ValidationException::withMessages([
                'title' => __('procynia.knowledge_handoff.validation.limit_reached'),
            ]);
        }

        $this->handoff->handOff(
            $user,
            $sourceType,
            $record,
            (string) $validated['title'],
            array_values(array_unique($validated['sections'])),
            (string) $validated['learning'],
        );

        return back()->with('success', __('procynia.knowledge_handoff.flash.queued'));
    }
}
