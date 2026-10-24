<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\DeviationPresenter;
use App\Services\Improvements\ImprovementCaseAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/deviations — the avvik the integration identity may read, a page at a time.
 *
 * Avvik only: forbedringer live in the same module (ImprovementCase::type) but are not part of
 * this endpoint. The list is ImprovementCaseAccessService::visibleCases() for the identity, exactly
 * as the register builds it for a person: the customer, improvement.view and the identity's
 * fagområder. Filters and the cursor narrow that list and cannot widen it, so another customer's
 * fagområde or cursor finds nothing.
 */
class DeviationController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(
        Request $request,
        ApiRequestContext $context,
        ImprovementCaseAccessService $access,
        DeviationPresenter $presenter,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(ImprovementCase::STATUSES)],
            'business_area_id' => ['sometimes', 'integer', 'min:1'],
            'overdue' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        // The day the register and «Trenger oppmerksomhet» judge a frist by.
        $today = CarbonImmutable::parse(now()->toDateString());

        $query = $access->visibleCases($actor)
            ->where('improvement_cases.type', ImprovementCase::TYPE_DEVIATION)
            ->with(['businessArea:id,name', 'owner:id,name'])
            ->withCount([
                'actions as action_count',
                'actions as open_action_count' => fn ($actions) => $actions->whereIn('status', ImprovementAction::ACTIVE_STATUSES),
            ])
            ->orderBy('improvement_cases.id');

        if (isset($validated['status'])) {
            $query->where('improvement_cases.status', $validated['status']);
        }

        if (isset($validated['business_area_id'])) {
            $query->where('improvement_cases.business_area_id', (int) $validated['business_area_id']);
        }

        if (isset($validated['overdue'])) {
            // Frist passert: an active case whose frist is before today — the attention rule.
            $overdue = fn ($inner) => $inner
                ->whereIn('improvement_cases.status', ImprovementCase::ACTIVE_STATUSES)
                ->whereNotNull('improvement_cases.due_date')
                ->where('improvement_cases.due_date', '<', $today->toDateString());

            $validated['overdue'] === 'true' ? $query->where($overdue) : $query->whereNot($overdue);
        }

        if (isset($validated['cursor'])) {
            $query->where('improvement_cases.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok(
            $presenter->rows($page, $today),
            $context,
            [
                'limit' => $limit,
                'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null,
            ],
        );
    }
}
