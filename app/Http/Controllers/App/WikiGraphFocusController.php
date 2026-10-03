<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Concerns\AuthorizesWikiPermissions;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\GraphQuery\EnterpriseWikiGraphFocusService;
use App\Services\EnterpriseWiki\GraphQuery\GraphDirection;
use App\Services\EnterpriseWiki\GraphQuery\GraphFocusQuery;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/**
 * GET /app/wiki/graph-focus — the projected neighbourhood around one page.
 *
 * Deliberately a second, narrow endpoint rather than another scope on /app/wiki/graph-data: that
 * one is the SQL-backed graph the UI already depends on, and it must keep working byte for byte
 * whether or not the Neo4j pilot is running. This one answers a different question — "walk the
 * relations outward from here" — and is allowed to be unavailable.
 */
class WikiGraphFocusController extends Controller
{
    use AuthorizesWikiPermissions;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiGraphFocusService $focusService,
        private readonly CustomerPermissionService $customerPermissions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->customerContext->currentCustomerId();

        $this->authorizeWikiPermission($this->customerContext->currentUser(), CustomerPermissionCatalog::WIKI_VIEW);

        if ($customerId === null) {
            return response()->json(['error' => 'No customer context.'], 403);
        }

        $validated = $request->validate([
            'page_id' => ['required', 'integer', 'min:1'],
            'depth' => ['sometimes', 'integer', 'between:1,'.GraphFocusQuery::MAX_DEPTH],
            'direction' => ['sometimes', 'string', Rule::in(array_column(GraphDirection::cases(), 'value'))],
            'relation_types' => ['sometimes', 'array', 'min:1'],
            'relation_types.*' => ['string', Rule::in(GraphFocusQuery::ALLOWED_RELATION_TYPES)],
        ]);

        if (! $this->focusService->isAvailable()) {
            // 503 and not an empty graph: a neighbourhood with no neighbours is a real answer for a
            // real page, and a client must not have to guess which one it got.
            return response()->json([
                'available' => false,
                'error' => 'The graph projection is not enabled in this environment.',
            ], 503);
        }

        $user = $this->customerContext->currentUser();
        $visibleStatuses = $user?->visibleEnterpriseWikiPageStatuses() ?? [EnterpriseWikiPage::STATUS_APPROVED];

        try {
            $data = $this->focusService->focus(
                customerId: $customerId,
                pageId: (int) $validated['page_id'],
                visibleStatuses: $visibleStatuses,
                depth: (int) ($validated['depth'] ?? 1),
                direction: GraphDirection::from($validated['direction'] ?? GraphDirection::Both->value),
                relationTypes: $validated['relation_types'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            // The graph store is rebuildable infrastructure; losing it degrades this one endpoint
            // and must not surface driver internals to the browser.
            Log::error('[WIKI_GRAPH_QUERY] Focus query failed.', [
                'customer_id' => $customerId,
                'page_id' => (int) $validated['page_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'available' => false,
                'error' => 'The graph projection could not be queried.',
            ], 503);
        }

        return response()->json($data);
    }
}
