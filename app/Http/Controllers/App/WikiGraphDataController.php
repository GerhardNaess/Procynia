<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiGraphDataService;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WikiGraphDataController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiGraphDataService $graphDataService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();
        $visibleStatuses = $user?->visibleEnterpriseWikiPageStatuses() ?? [EnterpriseWikiPage::STATUS_APPROVED];

        $rawRunId = $request->query('run_id');
        $rawPageId = $request->query('page_id');

        $runId = $rawRunId !== null && $rawRunId !== '' ? (int) $rawRunId : null;
        $pageId = $rawPageId !== null && $rawPageId !== '' ? (int) $rawPageId : null;

        try {
            $data = $this->graphDataService->build($customerId, $visibleStatuses, $runId, $pageId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($data);
    }
}
