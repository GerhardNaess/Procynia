<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedNotice;
use App\Services\Ai\Requirements\RequirementLoader;
use App\Services\Api\ApiCursor;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiResponse;
use App\Services\Api\Presenters\TenderPresenter;
use App\Services\SavedNoticeAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Anbud through the external API — reading only (D23–D28).
 *
 * GET /api/v1/tender/cases (ANB-01) lists the anbudssaker, a page at a time; active cases unless
 * archived=true asks for the archived ones. GET /api/v1/tender/cases/{caseId} (ANB-01) is one case,
 * and GET /api/v1/tender/cases/{caseId}/requirements (ANB-02) its published requirements' metadata.
 *
 * All three read SavedNoticeAccessService::integrationReadableQuery(): tender.read switched on for
 * the grant, the customer's Anbud, the identity's own customer and every case in it. Requirements
 * are loaded only for a case found that way. Another customer's case and a missing one are the
 * same 404. Nothing here works on a case: an integration is a reader (D26).
 */
class TenderController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function index(Request $request, ApiRequestContext $context, SavedNoticeAccessService $access, TenderPresenter $presenter): JsonResponse
    {
        if (! $access->integrationMayRead($context->actor)) {
            throw new AuthorizationException;
        }

        $validated = $request->validate([
            'bid_status' => ['sometimes', 'string', Rule::in(SavedNotice::BID_STATUSES)],
            'archived' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $query = $this->cases($access, $context)->orderBy('saved_notices.id');

        if (($validated['archived'] ?? 'false') === 'true') {
            $query->whereNotNull('saved_notices.archived_at');
        } else {
            $query->whereNull('saved_notices.archived_at');
        }

        if (isset($validated['bid_status'])) {
            $query->where('saved_notices.bid_status', $validated['bid_status']);
        }

        if (isset($validated['cursor'])) {
            $query->where('saved_notices.id', '>', ApiCursor::decode($validated['cursor']));
        }

        $found = $query->limit($limit + 1)->get();
        $page = $found->take($limit);
        $context->rows = $page->count();

        return ApiResponse::ok($presenter->rows($page), $context, [
            'limit' => $limit,
            'next_cursor' => $found->count() > $limit ? ApiCursor::encode((int) $page->last()->id) : null,
        ]);
    }

    public function show(ApiRequestContext $context, SavedNoticeAccessService $access, TenderPresenter $presenter, int $caseId): JsonResponse
    {
        $case = $this->find($access, $context, $caseId);
        $context->rows = 1;

        return ApiResponse::ok($presenter->case($case), $context);
    }

    public function requirements(ApiRequestContext $context, SavedNoticeAccessService $access, RequirementLoader $loader, TenderPresenter $presenter, int $caseId): JsonResponse
    {
        $case = $this->find($access, $context, $caseId);
        $requirements = $presenter->requirements($loader->loadForCase((int) $case->id));
        $context->rows = count($requirements);

        return ApiResponse::ok($requirements, $context, ['case_id' => (int) $case->id]);
    }

    private function find(SavedNoticeAccessService $access, ApiRequestContext $context, int $caseId): SavedNotice
    {
        if (! $access->integrationMayRead($context->actor)) {
            throw new AuthorizationException;
        }

        return $this->cases($access, $context)->whereKey($caseId)->first()
            ?? throw (new ModelNotFoundException)->setModel(SavedNotice::class);
    }

    /** The identity's readable cases, with what the presenter reads — never comment or submission text. */
    private function cases(SavedNoticeAccessService $access, ApiRequestContext $context): Builder
    {
        return $access->integrationReadableQuery($context->actor)->with([
            'organizationalDepartment:id,name',
            'bidManager:id,name',
            'opportunityOwner:id,name',
            'notice:id,notice_type',
            // For the last activity only (SavedNoticeWorkflowSignals::latestActivityAt()).
            'phaseComments:id,saved_notice_id,created_at',
            'submissions:id,saved_notice_id,submitted_at',
        ]);
    }
}
