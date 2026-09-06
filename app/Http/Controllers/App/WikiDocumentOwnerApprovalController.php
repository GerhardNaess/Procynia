<?php

namespace App\Http\Controllers\App;

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
use App\Services\EnterpriseWiki\EnterpriseWikiReviewNotificationService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WikiDocumentOwnerApprovalController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $approvalService,
        private readonly EnterpriseWikiReviewNotificationService $reviewNotifications,
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

    /**
     * A document owner's refusal sends the version back rather than parking it in review.
     *
     * Leaving the page in pending_review would be misleading: it is not waiting for anyone, it is
     * waiting for the page owner to answer an objection. published_version_id is untouched, so
     * whatever was already approved keeps serving.
     *
     * Other owners' pending requirements are deliberately left as they are. They belong to this
     * version, and if it is resubmitted unchanged they still stand; if the owner edits, the edit
     * produces a new version and the requirement set is derived again from scratch.
     */
    private function returnVersionToOwner(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersionDocumentOwnerApproval $approval,
        User $actor,
        string $reason,
    ): void {
        DB::transaction(function () use ($page, $approval, $actor, $reason): void {
            $event = EnterpriseWikiPageReviewEvent::query()->create([
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_page_version_id' => $approval->enterprise_wiki_page_version_id,
                'actor_user_id' => $actor->id,
                'actor_role' => EnterpriseWikiPageReviewEvent::ACTOR_ROLE_DOCUMENT_OWNER,
                'event_type' => EnterpriseWikiPageReviewEvent::EVENT_TYPE_CHANGES_REQUESTED,
                'reason' => $reason,
            ]);

            $locked = EnterpriseWikiPage::query()->whereKey($page->id)->lockForUpdate()->first();

            if ($locked !== null && $locked->status === EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
                $locked->forceFill(['status' => EnterpriseWikiPage::STATUS_REJECTED])->save();
            }

            $this->reviewNotifications->changesRequested($locked ?? $page, $event);
        });
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

        $isRejection = $decision === EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_REJECTED;

        // Approving needs no explanation; refusing does. The page owner has to know what to fix,
        // and "rejected, no reason given" is not something they can act on.
        $validated = $request->validate([
            'comment' => $isRejection
                ? ['required', 'string', 'min:'.EnterpriseWikiPageReviewEvent::REASON_MIN_LENGTH, 'max:'.EnterpriseWikiPageReviewEvent::REASON_MAX_LENGTH]
                : ['nullable', 'string', 'max:'.EnterpriseWikiPageReviewEvent::REASON_MAX_LENGTH],
        ], [], ['comment' => 'begrunnelse']);

        $comment = isset($validated['comment']) ? trim($validated['comment']) : null;

        $this->approvalService->decide($approval, $user, $decision, $comment);

        $version = EnterpriseWikiPageVersion::query()->find($approval->enterprise_wiki_page_version_id);

        if ($isRejection) {
            $this->returnVersionToOwner($page, $approval, $user, (string) $comment);
        } elseif ($version instanceof EnterpriseWikiPageVersion) {
            // Only when this approval was the last one outstanding does the reviewer hear anything —
            // the service checks the gate itself, so approving the first of three stays quiet.
            $this->reviewNotifications->sourceOwnerGateBecameReady($page, $version, $user);
        }

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
