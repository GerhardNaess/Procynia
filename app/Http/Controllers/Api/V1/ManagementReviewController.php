<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\ManagementReviewPresenter;
use App\Services\ManagementReview\ManagementReviewAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/management-reviews — the ledelsens gjennomganger the integration identity may read, a
 * page at a time — and GET /api/v1/management-reviews/{reviewId}, one with its sections and
 * decisions.
 *
 * The list is ManagementReviewAccessService::visibleReviews() for the identity, exactly as the
 * register builds it for a person: the customer, the module and management_review.view. A review is
 * customer-wide; its fagområder narrow what its basis covers, never who may read it. One review is
 * findVisible(): another customer's or a missing one is the same 404.
 */
class ManagementReviewController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(
        Request $request,
        ApiRequestContext $context,
        ManagementReviewAccessService $access,
        ManagementReviewPresenter $presenter,
    ): JsonResponse {
        if (! $access->canOpenModule($context->actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(ManagementReview::STATUSES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $query = $this->reviews($access, $context)->orderBy('management_reviews.id');

        if (isset($validated['status'])) {
            $query->where('management_reviews.status', $validated['status']);
        }

        if (isset($validated['cursor'])) {
            $query->where('management_reviews.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok(
            $presenter->rows($page),
            $context,
            [
                'limit' => $limit,
                'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null,
            ],
        );
    }

    public function show(
        ApiRequestContext $context,
        ManagementReviewAccessService $access,
        ManagementReviewPresenter $presenter,
        int $reviewId,
    ): JsonResponse {
        if (! $access->canOpenModule($context->actor)) {
            throw new AuthorizationException;
        }

        $review = $this->reviews($access, $context)->whereKey($reviewId)->first()
            ?? throw (new ModelNotFoundException)->setModel(ManagementReview::class);
        $context->rows = 1;

        return ApiResponse::ok($presenter->detail($context->actor, $review), $context);
    }

    /** The identity's visible reviews, with what a row needs — the register's counts. */
    private function reviews(ManagementReviewAccessService $access, ApiRequestContext $context): Builder
    {
        return $access->visibleReviews($context->actor)
            ->with(['owner:id,name', 'businessAreas:id,name'])
            ->withCount([
                'decisions',
                'decisions as open_actions_count' => fn ($query) => $query
                    ->where('follow_up', ManagementReviewDecision::FOLLOW_UP_OWN)
                    ->where('status', ManagementReviewDecision::STATUS_OPEN),
            ]);
    }
}
