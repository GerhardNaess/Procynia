<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Objective;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\KpiPresenter;
use App\Services\Api\Presenters\ObjectivePresenter;
use App\Services\Objectives\ObjectiveAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/objectives — the mål the integration identity may read, a page at a time — and
 * GET /api/v1/objectives/{objectiveId}, one of them with its KPIs.
 *
 * The list is ObjectiveAccessService::visibleObjectives() for the identity, exactly as the register
 * builds it for a person: the customer, objective.view and the identity's fagområder. Filters and
 * the cursor narrow that list and cannot widen it. One objective is ObjectiveAccessService::findVisible():
 * another customer's, one outside the fagområder and a missing one are the same 404.
 */
class ObjectiveController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(
        Request $request,
        ApiRequestContext $context,
        ObjectiveAccessService $access,
        ObjectivePresenter $presenter,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Objective::STATUSES)],
            'business_area_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        // The day the register and «Trenger oppmerksomhet» judge a måldato by.
        $today = CarbonImmutable::parse(now()->toDateString());

        $query = $access->visibleObjectives($actor)
            ->with(['businessArea:id,name', 'owner:id,name'])
            ->orderBy('objectives.id');

        if (isset($validated['status'])) {
            $query->where('objectives.status', $validated['status']);
        }

        if (isset($validated['business_area_id'])) {
            $query->where('objectives.business_area_id', (int) $validated['business_area_id']);
        }

        if (isset($validated['cursor'])) {
            $query->where('objectives.id', '>', ApiCursor::decode($validated['cursor']));
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

    public function show(
        ApiRequestContext $context,
        ObjectiveAccessService $access,
        ObjectivePresenter $presenter,
        KpiPresenter $kpiPresenter,
        int $objectiveId,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $objective = $access->findVisible($actor, $objectiveId)
            ?? throw (new ModelNotFoundException)->setModel(Objective::class);
        $objective->load(['businessArea:id,name', 'owner:id,name']);
        $today = CarbonImmutable::parse(now()->toDateString());

        // Its KPIs through the same rule as GET /kpis: visible because the objective is.
        $kpis = $access->visibleKpis($actor)
            ->where('kpis.objective_id', (int) $objective->id)
            ->with(['owner:id,name', 'objective.owner:id,name', 'objective.businessArea:id,name'])
            ->orderBy('kpis.id')
            ->get();
        $context->rows = 1;

        return ApiResponse::ok($presenter->detail($objective, $today, $kpiPresenter->rows($kpis, $today)), $context);
    }
}
