<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessArea;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/me — who the calling application is acting as, for which customer, and what it can
 * read through the API.
 *
 * Only the modules the API serves are described, and only through the same rules the endpoints
 * apply: a module the customer has not ordered, or the integration identity cannot open, reads as
 * not readable with no fagområder.
 */
class MeController extends Controller
{
    public function __invoke(
        ApiRequestContext $context,
        ModuleEntitlementService $entitlements,
        RiskAccessService $risks,
    ): JsonResponse {
        $actor = $context->actor;
        $customer = $context->customer;

        $riskEntitled = $entitlements->hasModule($customer, 'risk');
        $riskReadable = $riskEntitled && $risks->canOpenModule($actor);

        return ApiResponse::ok([
            'client' => ['id' => $context->client->id, 'name' => $context->client->name],
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'mode' => $context->mode,
            'actor' => ['id' => $actor->id, 'name' => $actor->name, 'type' => 'integration'],
            'modules' => [
                'risk' => [
                    'entitled' => $riskEntitled,
                    'readable' => $riskReadable,
                    'business_areas' => match (true) {
                        ! $riskReadable => [],
                        $risks->reachesAllAreas($actor, CustomerPermissionCatalog::RISK_VIEW) => 'all',
                        default => $risks->areasFor($actor, CustomerPermissionCatalog::RISK_VIEW)
                            ->map(fn (BusinessArea $area): array => ['id' => (int) $area->id, 'name' => $area->name])
                            ->values()
                            ->all(),
                    },
                ],
            ],
        ], $context);
    }
}
