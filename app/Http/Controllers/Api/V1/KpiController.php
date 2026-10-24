<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Kpi;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\KpiPresenter;
use App\Services\Objectives\ObjectiveAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/kpis — the KPI-er the integration identity may read, with their latest measured
 * periods, a page at a time.
 *
 * The list is ObjectiveAccessService::visibleKpis() for the identity: the KPIs of the objectives it
 * may read, and nothing else. A KPI has no fagområde of its own, so business_area_id filters on its
 * objective's. Filters and the cursor narrow that list and cannot widen it.
 *
 * GET /api/v1/kpis/{kpiId}/measurements is one visible KPI's whole målehistorikk: a KPI of another
 * customer, under an objective the identity cannot read, or not there at all is the same 404.
 */
class KpiController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(
        Request $request,
        ApiRequestContext $context,
        ObjectiveAccessService $access,
        KpiPresenter $presenter,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'objective_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(Kpi::STATUSES)],
            'business_area_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        // The day the KPI page and «Trenger oppmerksomhet» judge a missing measurement by.
        $today = CarbonImmutable::parse(now()->toDateString());

        $query = $access->visibleKpis($actor)
            ->with(['owner:id,name', 'objective.owner:id,name', 'objective.businessArea:id,name'])
            ->orderBy('kpis.id');

        if (isset($validated['objective_id'])) {
            $query->where('kpis.objective_id', (int) $validated['objective_id']);
        }

        if (isset($validated['status'])) {
            $query->where('kpis.status', $validated['status']);
        }

        if (isset($validated['business_area_id'])) {
            // Narrows the visible set further: the objectives already passed the access rule.
            $query->whereIn('kpis.objective_id', $access->visibleObjectives($actor)
                ->where('objectives.business_area_id', (int) $validated['business_area_id'])
                ->select('objectives.id'));
        }

        if (isset($validated['cursor'])) {
            $query->where('kpis.id', '>', ApiCursor::decode($validated['cursor']));
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

    public function measurements(
        ApiRequestContext $context,
        ObjectiveAccessService $access,
        KpiPresenter $presenter,
        int $kpiId,
    ): JsonResponse {
        $actor = $context->actor;

        if (! $access->canOpenModule($actor)) {
            throw new AuthorizationException;
        }

        $kpi = $access->visibleKpis($actor)->whereKey($kpiId)->first()
            ?? throw (new ModelNotFoundException)->setModel(Kpi::class);
        $history = $presenter->history($kpi);
        $context->rows = count($history);

        return ApiResponse::ok($history, $context);
    }
}
