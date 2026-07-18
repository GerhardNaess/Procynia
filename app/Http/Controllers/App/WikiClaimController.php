<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Claim review decisions — a System Owner, or any user with the QA capability and
 * effective access to the approve_wiki_claims permission, can record an explicit decision on a
 * Wiki claim (approve, reject, or undo the decision later). See User::canApproveWikiClaims().
 *
 * This is a separate permission from whole-page approval/rejection (WikiController::approve()/
 * reject()), which remains System Owner-only — QA never grants the ability to approve or
 * reject an entire Wiki page.
 *
 * The controller never creates a source reference — a manual approval/rejection is a distinct,
 * explicit review decision (EnterpriseWikiClaim.approval_status/approved_by_user_id/
 * approved_at/approval_comment), not a substitute for real evidence. See
 * EnterpriseWikiClaim::sourceStatus() for how this interacts with a real source reference
 * found later (automatically or otherwise).
 */
class WikiClaimController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
    ) {}

    public function approve(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner() && ! $user?->canApproveWikiClaims()) {
            abort(403);
        }

        $page = $this->resolvePageForClaim($slug, $claim);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->storeDecision(
            $claim,
            $user->id,
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            $validated['comment'] ?? null,
        );

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Påstanden er godkjent.');
    }

    public function reject(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner() && ! $user?->canApproveWikiClaims()) {
            abort(403);
        }

        $page = $this->resolvePageForClaim($slug, $claim);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->storeDecision(
            $claim,
            $user->id,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
            $validated['comment'] ?? null,
        );

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Påstanden er avvist.');
    }

    public function unapprove(string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner() && ! $user?->canApproveWikiClaims()) {
            abort(403);
        }

        $page = $this->resolvePageForClaim($slug, $claim);

        if (! in_array($claim->approval_status, [
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
        ], true)) {
            abort(422, 'Claim is not manually decided.');
        }

        $claim->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_comment' => null,
        ]);

        $this->lintService->reopenClaimMissingSourceFindingIfStillMissing($claim);

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Beslutningen er angret.');
    }

    private function storeDecision(
        EnterpriseWikiClaim $claim,
        int $userId,
        string $status,
        ?string $comment,
    ): void {
        $claim->update([
            'approval_status' => $status,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'approval_comment' => $comment,
        ]);

        $this->lintService->resolveClaimMissingSourceFinding($claim);
    }

    private function resolvePageForClaim(string $slug, EnterpriseWikiClaim $claim): EnterpriseWikiPage
    {
        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        abort_unless($claim->enterprise_wiki_page_id === $page->id, 404);

        return $page;
    }
}
