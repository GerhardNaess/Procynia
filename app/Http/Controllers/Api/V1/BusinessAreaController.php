<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiAccessSummary;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/business-areas — the fagområder the integration identity reads in, with the modules
 * it reads them in (ApiAccessSummary::readableAreas()). The reference data for the business_area
 * of every record and for the business_area_id filters. Needs no module of its own; an area the
 * identity reaches in no readable module is not listed.
 */
class BusinessAreaController extends Controller
{
    public function __invoke(ApiRequestContext $context, ApiAccessSummary $summary): JsonResponse
    {
        $areas = $summary->readableAreas($context->actor, $context->customer);
        $context->rows = count($areas);

        return ApiResponse::ok($areas, $context);
    }
}
