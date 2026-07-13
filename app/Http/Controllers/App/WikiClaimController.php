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
 * Manual claim source approval — a System Owner can approve a claim that has no source
 * reference (recording who/when/why) so it no longer shows the "missing source" warning, and
 * can undo that approval later. Uses the same access control as the existing Wiki page
 * approval flow (WikiController::approve()/reject()) — System Owner only, Bid Manager remains
 * read-only.
 *
 * Never creates a source reference — a manual approval is a distinct, explicit review decision
 * (EnterpriseWikiClaim.approval_status/approved_by_user_id/approved_at/approval_comment), not a
 * substitute for real evidence. See EnterpriseWikiClaim::sourceStatus() for how this interacts
 * with a real source reference found later (automatically or otherwise).
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

        if (! $user?->isSystemOwner()) {
            abort(403);
        }

        $page = $this->resolvePageForClaim($slug, $claim);

        if ($claim->hasSourceReference()) {
            abort(422, 'Claim already has a source reference — manual approval is only for claims without one.');
        }

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $claim->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
            'approval_comment' => $validated['comment'] ?? null,
        ]);

        $this->lintService->resolveClaimMissingSourceFinding($claim);

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Påstanden er godkjent manuelt.');
    }

    public function unapprove(string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner()) {
            abort(403);
        }

        $page = $this->resolvePageForClaim($slug, $claim);

        if (! $claim->isApproved()) {
            abort(422, 'Claim is not manually approved.');
        }

        $claim->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_comment' => null,
        ]);

        $this->lintService->reopenClaimMissingSourceFindingIfStillMissing($claim);

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Manuell godkjenning er angret.');
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
