<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Concerns\AuthorizesWikiPermissions;
use App\Http\Controllers\Controller;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WikiGraphController extends Controller
{
    use AuthorizesWikiPermissions;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly CustomerPermissionService $customerPermissions,
    ) {}

    public function __invoke(Request $request): Response
    {
        // The graph is how people read the Wiki, so it is gated by the same permission as the
        // page list rather than by one of its own.
        $this->authorizeWikiPermission($this->customerContext->currentUser(), CustomerPermissionCatalog::WIKI_VIEW);

        $rawRunId = $request->query('run_id');
        $rawPageId = $request->query('page_id');

        return Inertia::render('App/Wiki/Graph', [
            'initialRunId' => $rawRunId !== null && $rawRunId !== '' ? (int) $rawRunId : null,
            'initialPageId' => $rawPageId !== null && $rawPageId !== '' ? (int) $rawPageId : null,
        ]);
    }
}
