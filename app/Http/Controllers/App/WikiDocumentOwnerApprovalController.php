<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WikiDocumentOwnerApprovalController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $approvalService,
        private readonly EnterpriseWikiDocumentFlowService $documentFlowService,
    ) {}

    public function approve(Request $request, string $slug, EnterpriseWikiPageVersionDocumentOwnerApproval $approval): RedirectResponse
    {
        return $this->decide($request, $slug, $approval, EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED);
    }

    public function reject(Request $request, string $slug, EnterpriseWikiPageVersionDocumentOwnerApproval $approval): RedirectResponse
    {
        return $this->decide($request, $slug, $approval, EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED);
    }

    private function decide(
        Request $request,
        string $slug,
        EnterpriseWikiPageVersionDocumentOwnerApproval $approval,
        string $decision,
    ): RedirectResponse {
        $user = $this->customerContext->currentUser();

        abort_unless($user instanceof User && $user->is_active && $user->canAccessCustomerFrontend(), 403);

        $page = $this->resolvePageForApproval($slug, $approval);

        abort_unless($this->approvalService->canDecide($approval, $user), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->approvalService->decide($approval, $user, $decision, $validated['comment'] ?? null);

        $runId = $approval->enterprise_wiki_ingest_run_id
            ?? EnterpriseWikiIngestRunPage::query()
                ->where('generated_page_version_id', $approval->enterprise_wiki_page_version_id)
                ->value('enterprise_wiki_ingest_run_id');

        if ($runId !== null) {
            $run = EnterpriseWikiIngestRun::query()->find($runId);

            if ($run instanceof EnterpriseWikiIngestRun && ! $run->isTerminal()) {
                $this->documentFlowService->finalizeFromExistingQaResult($run);
            }
        }

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Dokumenteiergodkjenning er registrert.');
    }

    private function resolvePageForApproval(string $slug, EnterpriseWikiPageVersionDocumentOwnerApproval $approval): EnterpriseWikiPage
    {
        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        abort_unless((int) $approval->enterprise_wiki_page_id === (int) $page->id, 404);

        return $page;
    }
}
