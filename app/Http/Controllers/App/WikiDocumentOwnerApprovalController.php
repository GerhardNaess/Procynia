<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Concerns\AuthorizesWikiPermissions;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WikiDocumentOwnerApprovalController extends Controller
{
    use AuthorizesWikiPermissions;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $approvalService,
        private readonly EnterpriseWikiDocumentFlowService $documentFlowService,
        private readonly CustomerPermissionService $customerPermissions,
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

        // Deliberately wiki.view and not wiki.approve. This is the DOCUMENT owner vouching for
        // their own material on a page — an authority that comes from owning the source, not from
        // a role in the Wiki — and requiring wiki.approve here would replace that ownership with a
        // permission rather than add to it. What it does require is being able to read the Wiki at
        // all: nobody signs off on a page they cannot open. approvalService->canDecide() below is
        // still the authority that decides.
        $this->authorizeWikiPermission($user, CustomerPermissionCatalog::WIKI_VIEW);

        $page = $this->resolvePageForApproval($slug, $approval);

        abort_unless($this->approvalService->canDecide($approval, $user), 403);

        $isRejection = $decision === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED;

        // Approving needs no explanation; refusing does. The page owner has to know what to fix,
        // and "rejected, no reason given" is not something they can act on.
        $validated = $request->validate([
            'comment' => $isRejection
                ? ['required', 'string', 'min:'.EnterpriseWikiPageReviewEvent::REASON_MIN_LENGTH, 'max:'.EnterpriseWikiPageReviewEvent::REASON_MAX_LENGTH]
                : ['nullable', 'string', 'max:'.EnterpriseWikiPageReviewEvent::REASON_MAX_LENGTH],
        ], [], ['comment' => 'begrunnelse']);

        $comment = isset($validated['comment']) ? trim($validated['comment']) : null;

        // A new refusal also returns the version to its owner, inside decide() (B9).
        $this->approvalService->decide($approval, $user, $decision, $comment);

        $version = EnterpriseWikiPageVersion::query()->find($approval->enterprise_wiki_page_version_id);

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
