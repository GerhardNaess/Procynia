<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\RiskPresenter;
use App\Services\Risk\RiskAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/risks — the risks the integration identity may read, a page at a time.
 *
 * The list is RiskAccessService::visibleRisks() for the identity, exactly as the register builds
 * it for a person: the customer, the risk.view permission and the identity's fagområder. Filters
 * and the cursor narrow that list; none of them can widen it. A business area or cursor from
 * another customer therefore finds nothing.
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
        $after = isset($validated['cursor']) ? $this->decodeCursor($validated['cursor']) : null;

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
                'next_cursor' => $found->count() > $limit ? $this->encodeCursor((int) $page->last()->id) : null,
            ],
        );
    }

    /**
     * Opaque to the client: the last id of the previous page, nothing else. It carries no
     * customer, so it can only continue the list the access service already scoped.
     */
    private function encodeCursor(int $lastId): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['after' => $lastId])), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): int
    {
        $json = preg_match('/^[A-Za-z0-9_-]+$/', $cursor) === 1
            ? base64_decode(strtr($cursor, '-_', '+/'), true)
            : false;
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $after = is_array($decoded) ? ($decoded['after'] ?? null) : null;

        if (! is_int($after) || $after < 1 || count($decoded) !== 1) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return $after;
    }
}
