<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\WikiPresenter;
use App\Services\EnterpriseWiki\EnterpriseWikiPublishedKnowledgeReader;
use App\Services\EnterpriseWiki\EnterpriseWikiQuestionAnswerService;
use App\Services\EnterpriseWiki\WikiAskRateLimiter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Enterprise Wiki through the external API — published knowledge only (D18).
 *
 * GET /api/v1/wiki/pages (WIK-01) lists the published pages, a page at a time, and
 * GET /api/v1/wiki/pages/{pageId} (WIK-01, WIK-02) is one published page with the published pages
 * its text links to. Both read EnterpriseWikiPublishedKnowledgeReader under its rule (the `wiki`
 * module and wiki.view). A page that is not published, another customer's page or a missing one is
 * the same 404.
 *
 * POST /api/v1/wiki/ask (WIK-03) is «Spør Wiki» through the Wiki's own retrieval core (D19). It
 * writes nothing, but it spends the customer's AI capacity, so it also needs the operation switched
 * on for the grant. The service decides access and grounding (published versions only for an
 * integration); cost control stops it when the capacity is used up (D20).
 */
class WikiController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(Request $request, ApiRequestContext $context, EnterpriseWikiPublishedKnowledgeReader $reader, WikiPresenter $presenter): JsonResponse
    {
        if (! $reader->canRead($context->actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'page_type' => ['sometimes', 'string', Rule::in(EnterpriseWikiPage::PAGE_TYPES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $after = isset($validated['cursor']) ? ApiCursor::decode($validated['cursor']) : 0;

        $entries = array_values(array_filter(
            $reader->pages($context->actor),
            static fn (array $entry): bool => (int) $entry['page_id'] > $after
                && (! isset($validated['page_type']) || $entry['page_type'] === $validated['page_type']),
        ));
        $page = array_slice($entries, 0, $limit);
        $context->rows = count($page);

        return ApiResponse::ok($presenter->rows($page), $context, [
            'limit' => $limit,
            'next_cursor' => count($entries) > $limit ? ApiCursor::encode((int) $page[array_key_last($page)]['page_id']) : null,
        ]);
    }

    public function show(ApiRequestContext $context, EnterpriseWikiPublishedKnowledgeReader $reader, WikiPresenter $presenter, int $pageId): JsonResponse
    {
        if (! $reader->canRead($context->actor)) {
            throw new AuthorizationException;
        }

        $page = $reader->page($context->actor, $pageId)
            ?? throw (new ModelNotFoundException)->setModel(EnterpriseWikiPage::class);
        $context->rows = 1;

        return ApiResponse::ok($presenter->detail($page['entry'], $page['links']), $context);
    }

    public function ask(Request $request, ApiRequestContext $context, EnterpriseWikiQuestionAnswerService $wiki, WikiAskRateLimiter $rateLimiter, WikiPresenter $presenter): JsonResponse
    {
        $actor = $context->actor;

        // Refused before the request counts against the rate limit; askAs() asks the same again.
        if (! $wiki->mayAsk($actor)) {
            throw new AuthorizationException;
        }

        $rateLimiter->hit((int) $actor->customer_id, (int) $actor->id, WikiAskRateLimiter::CHANNEL_API);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:'.EnterpriseWikiQuestionAnswerService::MAX_QUESTION_CHARS],
        ]);

        $result = $wiki->askAs(
            $actor,
            trim((string) $validated['question']),
            (string) ($actor->customer?->language?->code ?: 'no'),
            $context->requestId,
        );
        $context->rows = 1;

        return ApiResponse::ok($presenter->answer($result), $context);
    }
}
