<?php

namespace App\Http\Controllers\Api\V1\Compliance;

use App\Http\Controllers\Controller;
use App\Models\ComplianceFramework;
use App\Models\ComplianceFrameworkNode;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\CompliancePresenter;
use App\Services\Compliance\ComplianceAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/compliance/frameworks (ETR-04) — Kravbiblioteket: the standards Procynia offers, and
 * for each whether the customer has activated it — and GET /api/v1/compliance/frameworks/{frameworkId},
 * one standard's tree with the customer's register entry per requirement. The library is the same
 * for every customer; the activation and the register entries are the identity's own visible ones.
 */
class ComplianceFrameworkController extends Controller
{
    public function index(ApiRequestContext $context, ComplianceAccessService $access, CompliancePresenter $presenter): JsonResponse
    {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $frameworks = ComplianceFramework::query()
            ->withCount([
                'requirementNodes',
                'requirementNodes as control_nodes_count' => fn ($query) => $query->where('applicability_rule', ComplianceFrameworkNode::RULE_CONTROL),
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $context->rows = $frameworks->count();

        return ApiResponse::ok($presenter->frameworks($frameworks, $this->activated($access, $context)), $context);
    }

    public function show(ApiRequestContext $context, ComplianceAccessService $access, CompliancePresenter $presenter, int $frameworkId): JsonResponse
    {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $framework = ComplianceFramework::query()->find($frameworkId)
            ?? throw (new ModelNotFoundException)->setModel(ComplianceFramework::class);
        $context->rows = 1;

        return ApiResponse::ok(
            $presenter->frameworkDetail($context->actor, $framework, $this->activated($access, $context)->get((int) $framework->id)),
            $context,
        );
    }

    /** The identity's visible activations, keyed by framework id — Kravbiblioteket's own rule. */
    private function activated(ComplianceAccessService $access, ApiRequestContext $context): Collection
    {
        return $access->visibleSources($context->actor)
            ->whereNotNull('compliance_sources.framework_id')
            ->get()
            ->keyBy(fn ($source): int => (int) $source->framework_id);
    }
}
