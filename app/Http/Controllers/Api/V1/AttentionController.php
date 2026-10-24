<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiAttentionOverview;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/attention — every record the integration identity may read that needs following up,
 * across Risiko, Avvik og forbedringer and Mål og KPI (ApiAttentionOverview). Needs no module of its
 * own: a module the customer has not ordered, or the identity cannot open, is reported as not
 * readable and contributes nothing.
 */
class AttentionController extends Controller
{
    public function __invoke(ApiRequestContext $context, ApiAttentionOverview $overview): JsonResponse
    {
        $data = $overview->for($context->actor, $context->customer, CarbonImmutable::parse(now()->toDateString()));
        $context->rows = count($data['items']);

        return ApiResponse::ok($data, $context);
    }
}
