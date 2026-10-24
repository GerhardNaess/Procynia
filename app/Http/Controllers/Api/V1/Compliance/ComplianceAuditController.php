<?php

namespace App\Http\Controllers\Api\V1\Compliance;

use App\Http\Controllers\Controller;
use App\Models\ComplianceAudit;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\CompliancePresenter;
use App\Services\Compliance\ComplianceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/compliance/audits (ETR-03) — the revisjoner the integration identity may read, a page
 * at a time — and GET /api/v1/compliance/audits/{auditId}, one with its scope, kontrollhandlinger
 * and findings. Reading only: the whole revisjonsløp is a human decision (COMPLIANCE_AUDIT).
 *
 * The list is ComplianceAccessService::visibleAudits() for the identity: the customer's audits under
 * compliance.view. Another customer's audit or a missing one is the same 404.
 */
class ComplianceAuditController extends Controller
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
            'status' => ['sometimes', 'string', Rule::in(ComplianceAudit::STATUSES)],
            'audit_type' => ['sometimes', 'string', Rule::in(ComplianceAudit::TYPES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $query = $this->audits($access, $context)->orderBy('compliance_audits.id');

        if (isset($validated['status'])) {
            $query->where('compliance_audits.status', $validated['status']);
        }

        if (isset($validated['audit_type'])) {
            $query->where('compliance_audits.audit_type', $validated['audit_type']);
        }

        if (isset($validated['cursor'])) {
            $query->where('compliance_audits.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok(
            $presenter->auditRows($page, $this->today()),
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
        int $auditId,
    ): JsonResponse {
        if (! $access->canReadFromAnotherModule($context->actor)) {
            throw new AuthorizationException;
        }

        $audit = $this->audits($access, $context)->whereKey($auditId)->first()
            ?? throw (new ModelNotFoundException)->setModel(ComplianceAudit::class);
        $context->rows = 1;

        return ApiResponse::ok($presenter->auditDetail($context->actor, $audit, $this->today()), $context);
    }

    private function audits(ComplianceAccessService $access, ApiRequestContext $context): Builder
    {
        return $access->visibleAudits($context->actor)
            ->with('responsible:id,name')
            ->withCount(['requirementLinks', 'checks', 'findings']);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(now()->toDateString());
    }
}
