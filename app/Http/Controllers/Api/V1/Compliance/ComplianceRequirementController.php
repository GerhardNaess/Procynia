<?php

namespace App\Http\Controllers\Api\V1\Compliance;

use App\Http\Controllers\Controller;
use App\Models\ComplianceRequirement;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\CompliancePresenter;
use App\Services\Compliance\ComplianceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/compliance/requirements (ETR-01) — the kravregister the integration identity may
 * read, a page at a time — and GET /api/v1/compliance/requirements/{requirementId}, one requirement
 * with its assessment history.
 *
 * The list is ComplianceAccessService::visibleRequirements() for the identity: the customer's whole
 * register under compliance.view, which the identity holds only through a role the System Owner gave
 * it (an explicit-grant domain). The module is the route's (customer.module). Filters and the cursor
 * narrow the list; another customer's requirement or source finds nothing.
 */
class ComplianceRequirementController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(
        Request $request,
        ApiRequestContext $context,
        ComplianceAccessService $access,
        CompliancePresenter $presenter,
    ): JsonResponse {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(ComplianceRequirement::STATUSES)],
            'source_id' => ['sometimes', 'integer', 'min:1'],
            'applicability' => ['sometimes', 'string', Rule::in(ComplianceRequirement::APPLICABILITIES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $query = $access->visibleRequirements($context->actor)
            ->with(['source', 'owner:id,name', 'frameworkNode:id,applicability_rule'])
            ->orderBy('compliance_requirements.id');

        if (isset($validated['status'])) {
            $query->where('compliance_requirements.status', $validated['status']);
        }

        if (isset($validated['source_id'])) {
            $query->where('compliance_requirements.source_id', (int) $validated['source_id']);
        }

        if (isset($validated['applicability'])) {
            // Relevans is a library requirement's; one registered by hand has none to filter on.
            $query->whereNotNull('compliance_requirements.framework_node_id')
                ->where('compliance_requirements.applicability', $validated['applicability']);
        }

        if (isset($validated['cursor'])) {
            $query->where('compliance_requirements.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok(
            $presenter->requirementRows($page, $this->today()),
            $context,
            [
                'limit' => $limit,
                'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null,
            ],
        );
    }

    public function show(
        ApiRequestContext $context,
        ComplianceAccessService $access,
        CompliancePresenter $presenter,
        int $requirementId,
    ): JsonResponse {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $requirement = $access->visibleRequirements($context->actor)
            ->whereKey($requirementId)
            ->with(['source', 'owner:id,name', 'frameworkNode:id,applicability_rule'])
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(ComplianceRequirement::class);
        $context->rows = 1;

        return ApiResponse::ok($presenter->requirementDetail($requirement, $this->today()), $context);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(now()->toDateString());
    }
}
