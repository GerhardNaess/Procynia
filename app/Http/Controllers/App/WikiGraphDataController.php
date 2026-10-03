<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Concerns\AuthorizesWikiPermissions;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiGraphDataService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WikiGraphDataController extends Controller
{
    use AuthorizesWikiPermissions;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiGraphDataService $graphDataService,
        private readonly CustomerPermissionService $customerPermissions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->customerContext->currentCustomerId();
        $user = $this->customerContext->currentUser();
        $this->authorizeWikiPermission($user, CustomerPermissionCatalog::WIKI_VIEW);
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
