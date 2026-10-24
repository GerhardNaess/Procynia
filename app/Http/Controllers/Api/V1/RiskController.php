<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\RiskPresenter;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskControlService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/risks — the risks the integration identity may read, a page at a time — and
 * GET /api/v1/risks/{riskId}, one of them with its history and follow-up.
 *
 * The list is RiskAccessService::visibleRisks() for the identity, exactly as the register builds
 * it for a person: the customer, the risk.view permission and the identity's fagområder. Filters
 * and the cursor narrow that list; none of them can widen it. A business area or cursor from
 * another customer therefore finds nothing. One risk is RiskAccessService::findVisible(): a risk of
 * another customer, outside the identity's fagområder or not there at all is the same 404.
 */
class RiskController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(
        Request $request,
        ApiRequestContext $context,
        RiskAccessService $access,
        RiskPresenter $presenter,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Risk::STATUSES)],
            'business_area_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $after = isset($validated['cursor']) ? ApiCursor::decode($validated['cursor']) : null;

        $query = $access->visibleRisks($actor)
            ->with(['businessArea:id,name', 'owner:id,name'])
            ->orderBy('risks.id');

        if (isset($validated['status'])) {
            $query->where('risks.status', $validated['status']);
        }

        if (isset($validated['business_area_id'])) {
            $query->where('risks.business_area_id', (int) $validated['business_area_id']);
        }

        if ($after !== null) {
            $query->where('risks.id', '>', $after);
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok(
            $presenter->rows($page, (int) $actor->customer_id, now()),
            $context,
            [
                'limit' => $limit,
                'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null,
            ],
        );
    }

    public function show(
        ApiRequestContext $context,
        RiskAccessService $access,
        RiskPresenter $presenter,
        RiskControlService $controls,
        int $riskId,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $risk = $access->findVisible($actor, $riskId)
            ?? throw (new ModelNotFoundException)->setModel(Risk::class);
        $risk->load(['businessArea:id,name', 'owner:id,name']);
        $context->rows = 1;

        return ApiResponse::ok($presenter->detail($risk, now(), $controls->canReadQuality($actor)), $context);
    }
}
