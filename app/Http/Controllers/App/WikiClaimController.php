<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Claim review decisions — a System Owner, or any user with the QA capability and effective
 * access to the approve_wiki_claims permission, can record an explicit decision on a Wiki
 * claim (approve, reject, or undo the decision later) and attach a real document-backed source
 * reference when a claim is missing one. See User::canApproveWikiClaims().
 *
 * This is a separate permission from whole-page approval/rejection (WikiController::approve()/
 * reject()), which remains System Owner-only — QA never grants the ability to approve or
 * reject an entire Wiki page.
 *
 * Manual approval/rejection remains a distinct review decision
 * (EnterpriseWikiClaim.approval_status/approved_by_user_id/approved_at/approval_comment), not a
 * substitute for real evidence. A separate source-link action can attach an actual
 * EnterpriseWikiSourceReference later, and EnterpriseWikiClaim::sourceStatus() keeps the
 * evidence state and the decision state separate.
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

    public function storeSourceReference(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        if (! $user?->isSystemOwner() && ! $user?->canApproveWikiClaims()) {
            abort(403);
        }

        $page = $this->resolvePageForClaim($slug, $claim);

        $validated = $request->validate([
            'source_document_id' => ['required', 'integer'],
            'excerpt' => ['required', 'string', 'max:4000'],
            'page_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $this->customerContext->currentCustomerId())
            ->where('id', (int) $validated['source_document_id'])
            ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED)
            ->first();

        abort_unless($document !== null && trim((string) $document->extracted_text) !== '', 404);

        $pageReference = trim((string) ($validated['page_reference'] ?? ''));
        $pageReference = $pageReference !== '' ? $pageReference : null;

        EnterpriseWikiSourceReference::query()->updateOrCreate(
            [
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'page_reference' => $pageReference,
            ],
            [
                'source_label' => $document->original_filename,
                'source_hash' => hash('sha256', 'enterprise_wiki_document:'.$document->id),
                'excerpt' => $validated['excerpt'],
            ],
        );

        $this->lintService->resolveClaimMissingSourceFinding($claim);

        return redirect()
            ->route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claim->id])
            ->with('success', 'Kilden er koblet til påstanden.');
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
