<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Concerns\AuthorizesWikiPermissions;
use App\Http\Controllers\Concerns\PreservesWikiReviewReturnUrl;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimFindingExplainer;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerContext;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Http\JsonResponse;
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
    use AuthorizesWikiPermissions;
    use PreservesWikiReviewReturnUrl;

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
        private readonly CustomerPermissionService $customerPermissions,
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
        private readonly EnterpriseWikiClaimDecisionService $claimDecisions,
    ) {}

    public function approve(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->claimDecisions->canHandle($user, $page, $claim), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
            'approved_text' => ['nullable', 'string', 'max:20000'],
            'back_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $result = $this->claimDecisions->approve($user, $page, $claim, $validated['approved_text'] ?? null, $validated['comment'] ?? null);

        return redirect()
            ->route('app.wiki.show', $this->wikiShowRouteParamsForReviewReturn($request, $page->slug, $result['claim']->id))
            ->with('success', $this->withForkNote($this->decisionMessage(
                $result['text_edited'] ? 'claim_flash_approved_edited' : 'claim_flash_approved',
                $result['claim']->fresh(),
            ), $result['forked']));
    }

    public function reject(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->claimDecisions->canHandle($user, $page, $claim), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
            'back_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $result = $this->claimDecisions->reject($user, $page, $claim, $validated['comment'] ?? null);

        return redirect()
            ->route('app.wiki.show', $this->wikiShowRouteParamsForReviewReturn($request, $page->slug, $result['claim']->id))
            ->with('success', $this->withForkNote($this->decisionMessage(
                $result['text_removed'] ? 'claim_flash_rejected_removed' : 'claim_flash_rejected',
                $result['claim']->fresh(),
            ), $result['forked']));
    }

    /** The flash says so when the decision went into a new working version instead of the published one. */
    private function withForkNote(string $message, bool $forked): string
    {
        return $forked ? $message.' '.__('procynia.wiki.claim_decision_new_working_version') : $message;
    }

    public function storeSourceReference(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->claimDecisions->canHandle($user, $page, $claim), 403);

        $validated = $request->validate([
            'source_document_id' => ['required', 'integer'],
            'source_element_key' => ['nullable', 'string', 'max:255'],
            'source_element_type' => ['nullable', 'string', 'max:50'],
            'source_row_key' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:4000'],
            'page_reference' => ['nullable', 'string', 'max:255'],
            'back_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $this->claimDecisions->attachSourceReference($user, $page, $claim, (int) $validated['source_document_id'], $validated);

        return redirect()
            ->route('app.wiki.show', $this->wikiShowRouteParamsWithReviewContext(
                $page->slug,
                $claim->id,
                $this->reviewBackUrlFromRequest($request),
            ))
            ->with('success', __('procynia.wiki.claim_flash_source_linked'));
    }

    public function unapprove(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->claimDecisions->canHandle($user, $page, $claim), 403);

        $claim = $this->claimDecisions->unapprove($user, $page, $claim);

        return redirect()
            ->route('app.wiki.show', $this->wikiShowRouteParamsForReviewReturn($request, $page->slug, $claim->id))
            ->with('success', $this->decisionMessage('claim_flash_unapproved', $claim, reopened: true));
    }

    /**
     * The "behold/fjern blokkering" decision (product rule item 3) — deliberately independent of
     * approval_status: a user can reject a claim yet still remove its blocking (e.g. "this text
     * will be regenerated, no need to hold the run for it"), or keep a claim pending while still
     * confirming its suggested block is warranted. Uses the exact same authorization as approve/
     * reject/unapprove (EnterpriseWikiClaimDecisionService::canHandle()) — no new permission surface.
     */
    public function updateBlockingOverride(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->claimDecisions->canHandle($user, $page, $claim), 403);

        // Blocking only ever applies to a claim-integrity finding — refused before the request is
        // read, as before; the service applies the same rule again.
        abort_unless(in_array($claim->content_origin, [
            EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
        ], true), 422, 'Blocking can only be set for a claim-integrity finding.');

        $validated = $request->validate([
            'blocking' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'back_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $newBlocking = (bool) $validated['blocking'];
        $claim = $this->claimDecisions->setBlockingOverride($user, $page, $claim, $newBlocking, $validated['comment'] ?? null);

        return redirect()
            ->route('app.wiki.show', $this->wikiShowRouteParamsWithReviewContext(
                $page->slug,
                $claim->id,
                $this->reviewBackUrlFromRequest($request),
            ))
            ->with('success', $this->decisionMessage(
                $newBlocking ? 'claim_flash_blocking_kept' : 'claim_flash_blocking_removed',
                $claim,
            ));
    }

    /**
     * The confirmation after a decision: what happened to the claim and its text, then — read back
     * from the stored state, never assumed — whether the QA finding it belongs to is now handled or
     * still needs follow-up. A claim that is no QA finding gets the first sentence only.
     */
    private function decisionMessage(string $actionKey, EnterpriseWikiClaim $claim, bool $reopened = false): string
    {
        $message = __('procynia.wiki.'.$actionKey);
        $findingIsOpen = $this->qaFindingIsOpen($claim);

        if ($findingIsOpen === null) {
            return $message;
        }

        $outcomeKey = match (true) {
            $findingIsOpen && $reopened => 'claim_flash_finding_reopened',
            $findingIsOpen => 'claim_flash_finding_still_open',
            default => 'claim_flash_finding_closed',
        };

        return $message.' '.__('procynia.wiki.'.$outcomeKey);
    }

    /**
     * Whether the Kjøringer QA finding this claim belongs to is still open, by the same rules
     * EnterpriseWikiRunFindingsService lists it with: a best-practice suggestion while its section
     * is undecided, a claim finding while EnterpriseWikiClaimFindingExplainer::findingResolution()
     * says so — for any occurrence of the same fact on a current page version, since the list shows
     * them as one finding. null when the claim is no user-facing finding at all.
     */
    private function qaFindingIsOpen(EnterpriseWikiClaim $claim): ?bool
    {
        if (! $this->claimFindingExplainer->isUserFacingAddition($claim)) {
            return null;
        }

        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return $claim->isPending();
        }

        $occurrences = $claim->canonical_fact_id === null
            ? collect([$claim])
            : EnterpriseWikiClaim::query()
                ->where('canonical_fact_id', $claim->canonical_fact_id)
                ->whereHas('version', fn ($query) => $query->where('is_current', true))
                ->whereHas('page', fn ($query) => $query->where('customer_id', $this->customerContext->currentCustomerId()))
                ->get()
                ->push($claim)
                ->unique('id');

        return $occurrences->contains(
            fn (EnterpriseWikiClaim $occurrence): bool => $this->claimFindingExplainer->isUserFacingAddition($occurrence)
                && $this->claimFindingExplainer->findingResolution($occurrence) === EnterpriseWikiClaimFindingExplainer::RESOLUTION_OPEN,
        );
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

    public function sourceDocumentElements(string $slug, EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): JsonResponse
    {
        $page = $this->resolvePageForClaim($slug, $claim);
        $user = $this->customerContext->currentUser();

        // The verification basis a reviewer reads before deciding a claim — gated with the
        // decision it serves, not with ordinary page reading.
        $this->authorizeWikiPermission($user, CustomerPermissionCatalog::WIKI_REVIEW);

        abort_unless($document->customer_id === $this->customerContext->currentCustomerId(), 404);
        abort_unless($claim->enterprise_wiki_page_id === $page->id, 404);
        abort_unless($document->document_status === EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, 404);
        abort_unless(trim((string) $document->extracted_text) !== '', 404);
        abort_unless($this->documentOwnerApprovalService->canUseSourceDocumentForClaim($claim, $document, $user, $page->currentVersion()->first()), 403);

        $catalog = $this->sourceElementService->inspect($document);

        return response()->json([
            'document_id' => $document->id,
            'document_name' => $document->original_filename,
            'supports_structured_elements' => $catalog['supports_structured_elements'],
            'manual_source_allowed' => $catalog['manual_source_allowed'],
            'manual_source_reason' => $catalog['manual_source_reason'],
            'elements' => $catalog['elements'],
        ]);
    }
}
