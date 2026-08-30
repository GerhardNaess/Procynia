<?php

namespace App\Http\Controllers\App;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Services\Ai\Wiki\WikiQuestionAnswerAiClient;
use App\Services\Billing\BillingEntitlementService;
use App\Services\EnterpriseWiki\EnterpriseWikiQuestionAnswerService;
use App\Support\Ai\AiCostControlPresenter;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        private readonly BillingEntitlementService $billingEntitlementService,
        private readonly AiCostControlService $costControl,
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

        $customer = $this->customerContext->currentCustomer($user);
        abort_unless(
            $customer !== null && $this->billingEntitlementService->canUseAiOffer($customer),
            403,
            __('procynia.ai.ai_access_unavailable_message'),
        );

        $this->assertWithinRateLimits((int) $customerId, $user?->id);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:'.EnterpriseWikiQuestionAnswerService::MAX_QUESTION_CHARS],
        ]);

        $question = trim((string) $validated['question']);
        $requestCorrelationId = trim((string) $request->header('X-Request-Id'));
        $requestCorrelationId = $requestCorrelationId !== '' ? Str::limit($requestCorrelationId, 128, '') : (string) Str::uuid();

        try {
            // Presentation boundary check gives Wiki Ask a safe hard-stop response. The provider
            // boundary repeats this immediately before every HTTP call for queue/stale-state safety.
            $this->costControl->authorize(new AiCallContext(
                customerId: (int) $customerId,
                userId: $user?->id,
                feature: 'wiki',
                operation: 'wiki.ask',
                resourceType: 'enterprise_wiki',
                requestCorrelationId: $requestCorrelationId,
            ));
            $result = $this->questionAnswerService->ask(
                $question,
                $customerId,
                $this->visibleStatuses($user),
                $this->customerContext->resolveLanguageCode(),
                $user?->id,
                $requestCorrelationId,
            );
        } catch (AiCostControlException $e) {
            Log::warning('[WIKI_ASK] Question blocked by AI cost control.', [
                'customer_id' => $customerId,
                'user_id' => $user?->id,
                'reason' => $e->reason,
                'operation' => 'wiki.ask',
            ]);

            // Wiki Ask never spends a SavedNotice credit, so a quota block here can only come from
            // an entitlement, suspension or platform stop — each of which needs its own wording.
            return back()
                ->withInput()
                ->with('error', app(AiCostControlPresenter::class)->message($e, $customer));
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

    private function assertWithinRateLimits(int $customerId, ?int $userId): void
    {
        $windowSeconds = max(60, (int) config('procynia.ai.wiki_ask.window_seconds', 900));
        $userLimit = max(1, (int) config('procynia.ai.wiki_ask.user_attempts', 10));
        $customerLimit = max(1, (int) config('procynia.ai.wiki_ask.customer_attempts', 60));
        $userKey = sprintf('ai:wiki-ask:user:%d:customer:%d', (int) $userId, $customerId);
        $customerKey = sprintf('ai:wiki-ask:customer:%d', $customerId);

        foreach ([[$userKey, $userLimit, 'user'], [$customerKey, $customerLimit, 'customer']] as [$key, $limit, $scope]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                Log::warning('[WIKI_ASK] AI request rate limited.', [
                    'reason' => 'rate_limit_'.$scope,
                    'customer_id' => $customerId,
                    'user_id' => $userId,
                    'operation' => 'wiki.ask',
                ]);

                abort(429, __('procynia.wiki.ask_rate_limited'));
            }
        }

        RateLimiter::hit($userKey, $windowSeconds);
        RateLimiter::hit($customerKey, $windowSeconds);
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
