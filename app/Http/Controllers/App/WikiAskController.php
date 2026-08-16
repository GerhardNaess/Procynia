<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use App\Services\Ai\Wiki\WikiQuestionAnswerAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiQuestionAnswerService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Spør Wiki" — a read-only question/answer surface over the customer's own Enterprise Wiki.
 *
 * Separate from tender answer generation on purpose: this never drafts a bid answer, never writes to
 * a case, and never mutates the Wiki. One question in, one grounded answer out.
 */
class WikiAskController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiQuestionAnswerService $questionAnswerService,
    ) {}

    public function index(): Response
    {
        // Customer scope comes from the resolved context, never from the request — the same rule the
        // rest of the Enterprise Wiki surface follows.
        $this->customerContext->currentCustomerId();

        return Inertia::render('App/Wiki/Ask', [
            'question' => null,
            'result' => null,
            'maxQuestionLength' => EnterpriseWikiQuestionAnswerService::MAX_QUESTION_CHARS,
        ]);
    }

    public function ask(Request $request): Response|RedirectResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:'.EnterpriseWikiQuestionAnswerService::MAX_QUESTION_CHARS],
        ]);

        $question = trim((string) $validated['question']);

        try {
            $result = $this->questionAnswerService->ask(
                $question,
                $customerId,
                $this->visibleStatuses($user),
                $this->customerContext->resolveLanguageCode(),
            );
        } catch (Throwable $e) {
            // Never surface an exception message or an upstream response body to the end user.
            Log::error('[WIKI_ASK] Question could not be answered.', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', __('procynia.wiki.ask_technical_error'));
        }

        return Inertia::render('App/Wiki/Ask', [
            'question' => $question,
            'result' => [
                'answer_status' => $result['answer_status'],
                'answer' => $result['answer'],
                // Only presentation-safe citation fields reach the client. page_version_id and the
                // retrieval ranking stay server-side (log/diagnostics) — internal identifiers are
                // not end-user content.
                'citations' => array_map(static fn (array $citation): array => [
                    'page_title' => $citation['page_title'],
                    'page_slug' => $citation['page_slug'],
                    'heading' => $citation['heading'],
                    'excerpt' => $citation['excerpt'],
                ], $result['citations']),
                'pages_used' => $result['retrieval']['pages_used'],
            ],
            'maxQuestionLength' => EnterpriseWikiQuestionAnswerService::MAX_QUESTION_CHARS,
        ]);
    }

    /**
     * The same read model the Wiki pages themselves use: a user is only ever answered from pages that
     * user is allowed to read. Falls back to approved-only for a user with no explicit rights.
     *
     * Note this can legitimately return archived/superseded/rejected too — those are readable page
     * statuses. RequirementWikiCatalogBuilder::CURRENT_KNOWLEDGE_STATUSES intersects them away, so
     * stale content can never enter the answer context no matter what is passed here.
     *
     * @return list<string>
     */
    private function visibleStatuses(?User $user): array
    {
        $statuses = $user?->visibleEnterpriseWikiPageStatuses() ?? [];

        return $statuses !== [] ? array_values($statuses) : [EnterpriseWikiPage::STATUS_APPROVED];
    }

    /** Exposed for tests/readability: the statuses the answer client can ever report. */
    public static function answerStatuses(): array
    {
        return WikiQuestionAnswerAiClient::STATUSES;
    }
}
