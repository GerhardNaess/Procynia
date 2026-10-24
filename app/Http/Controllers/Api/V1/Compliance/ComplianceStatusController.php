<?php

namespace App\Http\Controllers\Api\V1\Compliance;

use App\Http\Controllers\Controller;
use App\Models\ComplianceRequirement;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\CompliancePresenter;
use App\Services\Compliance\ComplianceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/compliance/status (ETR-02) — where the active requirements stand, in total and per
 * source or standard: the counts of Etterlevelsesvurdering, over the identity's visible register.
 */
class ComplianceStatusController extends Controller
{
    public function __invoke(ApiRequestContext $context, ComplianceAccessService $access, CompliancePresenter $presenter): JsonResponse
    {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $sources = $access->visibleSources($context->actor)->orderBy('name')->orderBy('version')->orderBy('id')->get();
        $requirements = $access->visibleRequirements($context->actor)
            ->where('compliance_requirements.status', ComplianceRequirement::STATUS_ACTIVE)
            ->with(['source', 'frameworkNode:id,applicability_rule'])
            ->get();

        return ApiResponse::ok($presenter->status($requirements, $sources, CarbonImmutable::parse(now()->toDateString())), $context);
    }
}
