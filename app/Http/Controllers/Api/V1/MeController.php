<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiAccessSummary;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/me — who the calling application is acting as, for which customer, and what it can
 * read through the API (ApiAccessSummary, the same summary the System Owner sees in Kundemiljø).
 */
class MeController extends Controller
{
    public function __invoke(ApiRequestContext $context, ApiAccessSummary $summary): JsonResponse
    {
        $actor = $context->actor;
        $customer = $context->customer;

        return ApiResponse::ok([
            'client' => ['id' => $context->client->id, 'name' => $context->client->name],
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'mode' => $context->mode,
            'actor' => ['id' => $actor->id, 'name' => $actor->name, 'type' => 'integration'],
            'modules' => $summary->for($actor, $customer),
        ], $context);
    }
}
