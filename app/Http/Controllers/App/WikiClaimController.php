<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimCanonicalizationService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageBlockEditService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageContentBlockService;
use App\Support\CustomerContext;
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
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiBuildPageLinksService $pageLinksService,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiPageContentBlockService $contentBlockService,
        private readonly EnterpriseWikiPageBlockEditService $pageBlockEditService,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
    ) {}

    public function approve(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
            'approved_text' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->applyBestPracticeTextEdit($claim, $validated['approved_text'] ?? null);

        $this->storeDecision(
            $claim->fresh(),
            $user->id,
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            $validated['comment'] ?? null,
        );

        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            $this->pageLinksService->materializeWikilinksForPage($page);

            $claim->fresh()->update([
                'review_metadata' => array_merge($claim->fresh()->review_metadata ?? [], [
                    'visible_wiki_link_result' => str_contains((string) $claim->fresh()->claim_text, '[[')
                        ? 'materialized_from_approved_text'
                        : 'no_visible_link_needed',
                ]),
            ]);
        }

        $this->cascadeBestPracticeDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED, $validated['comment'] ?? null);
        $this->cascadeBlockDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED, $validated['comment'] ?? null);

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Påstanden er godkjent.');
    }

    public function reject(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->storeDecision(
            $claim,
            $user->id,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
            $validated['comment'] ?? null,
        );

        // v0.7 binding quality-strategy rule: "avvis" on a best-practice addition means "fjern
        // teksten" — the only two outcomes for text the system added beyond the source are keep
        // (approve) or remove (reject). Strips the block's markdown from the live page; the
        // decision itself (approval_status=rejected) is recorded regardless, above.
        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            $this->removeBestPracticeText($claim);
            $this->pageLinksService->materializeWikilinksForPage($page->fresh());
        }

        $this->cascadeBestPracticeDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED, $validated['comment'] ?? null);
        $this->cascadeBlockDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED, $validated['comment'] ?? null);

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Påstanden er avvist.');
    }

    public function editText(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        $validated = $request->validate([
            'markdown' => ['required', 'string', 'max:20000'],
            'back_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $result = $this->pageBlockEditService->edit(
            $page,
            $claim,
            $user,
            (string) $validated['markdown'],
        );

        $query = [
            'claim_id' => (int) ($result['focus_claim_id'] ?? $claim->id),
        ];

        $backUrl = $this->normalizeReviewBackUrl($validated['back_url'] ?? null);

        if ($backUrl !== null) {
            $query['back_url'] = $backUrl;
        }

        return redirect()
            ->route('app.wiki.show', array_merge(['slug' => $page->slug], $query))
            ->with('success', 'Teksten er lagret som en ny sideversjon.');
    }

    /**
     * Cross-page overgeneration fix (Del 9/10): a best-practice suggestion sharing the same
     * canonical fact across several page occurrences should require one human decision, not one
     * per page. Once the primary claim is decided, apply the SAME decision to every other still-
     * pending best_practice claim linked to the same canonical_fact_id — but only the decision
     * itself (approval_status/who/when/comment), never the primary claim's edited wording; each
     * sibling occurrence keeps its own page/block text. A sibling whose fact link went stale
     * (e.g. this decision's own text edit diverged from the shared fact) is left untouched so it
     * still gets its own independent human decision.
     */
    private function cascadeBestPracticeDecision(EnterpriseWikiClaim $claim, int $userId, string $status, ?string $comment): void
    {
        if ($claim->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE || $claim->canonical_fact_id === null) {
            return;
        }

        $fact = $claim->canonicalFact ?? $claim->canonicalFact()->first();

        if ($fact === null || $fact->is_stale) {
            return;
        }

        $siblings = EnterpriseWikiClaim::query()
            ->where('canonical_fact_id', $fact->id)
            ->where('id', '!=', $claim->id)
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE)
            ->where('approval_status', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING)
            ->get();

        foreach ($siblings as $sibling) {
            if (! $this->canonicalizationService->areEquivalentTexts($sibling->claim_text, $fact->canonical_text)) {
                continue;
            }

            $this->storeDecision($sibling, $userId, $status, $comment);

            if ($status !== EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED) {
                continue;
            }

            $siblingPage = $sibling->page()->first();

            if ($siblingPage === null) {
                continue;
            }

            $this->pageLinksService->materializeWikilinksForPage($siblingPage);

            $sibling->fresh()->update([
                'review_metadata' => array_merge($sibling->fresh()->review_metadata ?? [], [
                    'visible_wiki_link_result' => str_contains((string) $sibling->fresh()->claim_text, '[[')
                        ? 'materialized_from_approved_text'
                        : 'no_visible_link_needed',
                ]),
            ]);
        }
    }

    /**
     * v0.7 binding quality-strategy rule (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat —
     * v0.7", rule #4): several best-practice claims anchored to the same contiguous text block
     * (content_block_key, same page version) are ONE user-facing case
     * (EnterpriseWikiRunFindingsService::additionGroupKey()) — deciding the primary claim must
     * decide the whole case, not leave siblings pending forever. Distinct from
     * cascadeBestPracticeDecision() above, which cascades across PAGES via a shared
     * canonical_fact_id; this cascades within the SAME block on the SAME page. Only the decision
     * itself is cascaded, never the primary claim's edited/removed text — a sibling's own
     * claim_text is left as-is (the block's markdown, which they all describe, already reflects
     * whatever the primary claim's action did to it).
     */
    private function cascadeBlockDecision(EnterpriseWikiClaim $claim, int $userId, string $status, ?string $comment): void
    {
        if ($claim->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return;
        }

        $blockKey = trim((string) $claim->content_block_key);

        if ($blockKey === '') {
            return;
        }

        $siblings = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $claim->enterprise_wiki_page_version_id)
            ->where('content_block_key', $blockKey)
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE)
            ->where('id', '!=', $claim->id)
            ->where('approval_status', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING)
            ->get();

        foreach ($siblings as $sibling) {
            $this->storeDecision($sibling, $userId, $status, $comment);
        }
    }

    /**
     * v0.7 rule: "fjerne teksten" for a best-practice addition — blanks the shared block's
     * markdown (EnterpriseWikiPageContentBlockService::replaceBlockMarkdown()), the same
     * mechanism applyBestPracticeTextEdit() uses to rewrite it, just with an empty replacement.
     * Show.jsx already filters blocks with empty markdown out of the rendered page, so this is
     * enough to make the addition disappear — no separate "delete block" operation is needed.
     * A silent no-op when the claim has no stable block anchor (nothing concrete to remove).
     */
    private function removeBestPracticeText(EnterpriseWikiClaim $claim): void
    {
        $blockKey = trim((string) $claim->content_block_key);

        if ($blockKey === '') {
            return;
        }

        $claim->loadMissing('version');
        $version = $claim->version;

        if ($version === null || (int) $version->id !== (int) $claim->enterprise_wiki_page_version_id) {
            return;
        }

        $this->contentBlockService->replaceBlockMarkdown($version, $blockKey, '');
    }

    public function storeSourceReference(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        $validated = $request->validate([
            'source_document_id' => ['required', 'integer'],
            'source_element_key' => ['nullable', 'string', 'max:255'],
            'source_element_type' => ['nullable', 'string', 'max:50'],
            'source_row_key' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:4000'],
            'page_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $this->customerContext->currentCustomerId())
            ->where('id', (int) $validated['source_document_id'])
            ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED)
            ->first();

        abort_unless($document !== null && trim((string) $document->extracted_text) !== '', 404);
        abort_unless($this->documentOwnerApprovalService->canUseSourceDocumentForClaim($claim, $document, $user, $page->currentVersion()->first()), 403);

        $hadSourceReference = $claim->hasSourceReference();
        $catalog = $this->sourceElementService->inspect($document);
        $sourceElementKey = $this->normalizeNullableString($validated['source_element_key'] ?? null);
        $sourceElementType = $this->normalizeNullableString($validated['source_element_type'] ?? null);
        $sourceRowKey = $this->normalizeNullableString($validated['source_row_key'] ?? null);
        $pageReference = $this->normalizeNullableString($validated['page_reference'] ?? null);
        $excerpt = $this->normalizeNullableString($validated['excerpt'] ?? null);

        $resolvedSelection = null;
        $isManualFallbackRequest = $sourceElementType === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_MANUAL
            || ($sourceElementKey === null && $sourceElementType === null && $sourceRowKey === null);

        if (! $isManualFallbackRequest) {
            $resolvedSelection = $this->sourceElementService->resolveSelection(
                $document,
                $sourceElementKey,
                $sourceElementType,
                $sourceRowKey,
            );

            abort_unless($resolvedSelection !== null, 422, 'Det valgte kildedokumentelementet finnes ikke i dette dokumentet.');

            $sourceElementKey = $resolvedSelection['source_element_key'] ?? null;
            $sourceElementType = $resolvedSelection['source_element_type'] ?? null;
            $sourceRowKey = $resolvedSelection['source_row_key'] ?? null;
            $excerpt = $resolvedSelection['reference_text'] ?? null;
            $pageReference = $resolvedSelection['page_reference'] ?? null;
        } elseif (! ($catalog['manual_source_allowed'] ?? false)) {
            abort(422, 'Velg et konkret kildedokumentelement når dokumentet har strukturerte elementer.');
        } elseif ($excerpt === null) {
            abort(422, 'Et manuelt kildeutdrag kreves når dokumentet ikke har strukturerte elementer.');
        } else {
            $sourceElementType = EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_MANUAL;
            $sourceElementKey = null;
            $sourceRowKey = null;
        }

        EnterpriseWikiSourceReference::query()->updateOrCreate(
            [
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_element_key' => $sourceElementKey,
                'source_element_type' => $sourceElementType,
                'source_row_key' => $sourceRowKey,
            ],
            [
                'source_label' => $document->original_filename,
                'source_hash' => hash('sha256', implode('|', [
                    'enterprise_wiki_document',
                    $document->id,
                    $document->file_hash_sha256,
                    $sourceElementType ?? 'manual',
                    $sourceElementKey ?? 'manual',
                    $sourceRowKey ?? 'manual',
                ])),
                'excerpt' => $excerpt,
                'page_reference' => $pageReference,
            ],
        );

        $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, ! $hadSourceReference);
        $this->lintService->resolveClaimMissingSourceFinding($claim);

        return redirect()
            ->route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claim->id])
            ->with('success', 'Kilden er koblet til påstanden.');
    }

    public function unapprove(string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        if (! in_array($claim->approval_status, [
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
        ], true)) {
            abort(422, 'Claim is not manually decided.');
        }

        $this->recordDecision(
            $claim,
            $user->id,
            EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS,
            ['approval_status' => $claim->approval_status],
            ['approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING],
            null,
        );

        $claim->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_comment' => null,
        ]);

        $this->lintService->reopenClaimMissingSourceFindingIfStillMissing($claim);

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Beslutningen er angret.');
    }

    /**
     * The "behold/fjern blokkering" decision (product rule item 3) — deliberately independent of
     * approval_status: a user can reject a claim yet still remove its blocking (e.g. "this text
     * will be regenerated, no need to hold the run for it"), or keep a claim pending while still
     * confirming its suggested block is warranted. Uses the exact same authorization as approve/
     * reject/unapprove (canHandleClaimForPage()) — no new permission surface.
     */
    public function updateBlockingOverride(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        // Blocking only ever applies to a claim-integrity finding (internal_error/
        // unsupported_generated_content) — best_practice suggestions never block by design and go
        // through their own approve/edit/reject workflow, and a source_based claim's provenance
        // gap is a lint finding, not something this per-claim decision axis applies to.
        abort_unless(in_array($claim->content_origin, [
            EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
        ], true), 422, 'Blocking can only be set for a claim-integrity finding.');

        $validated = $request->validate([
            'blocking' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $newBlocking = (bool) $validated['blocking'];

        $this->recordDecision(
            $claim,
            $user->id,
            EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE,
            ['blocking_override' => $claim->blocking_override],
            ['blocking_override' => $newBlocking],
            $validated['comment'] ?? null,
        );

        $claim->update([
            'blocking_override' => $newBlocking,
            'blocking_override_by_user_id' => $user->id,
            'blocking_override_at' => now(),
        ]);

        return redirect()
            ->route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claim->id])
            ->with('success', $newBlocking ? 'Blokkeringen er beholdt.' : 'Blokkeringen er fjernet.');
    }

    private function storeDecision(
        EnterpriseWikiClaim $claim,
        int $userId,
        string $status,
        ?string $comment,
    ): void {
        $this->recordDecision(
            $claim,
            $userId,
            EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS,
            ['approval_status' => $claim->approval_status],
            ['approval_status' => $status],
            $comment,
        );

        $claim->update([
            'approval_status' => $status,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'approval_comment' => $comment,
        ]);

        $this->lintService->resolveClaimMissingSourceFinding($claim);
    }

    /**
     * Append-only audit trail (product rule: every decision stores who, when, any comment, and
     * the previous/new decision) — never updated or deleted, unlike EnterpriseWikiClaim's own
     * approval_status/blocking_override columns which only hold the CURRENT state. Written before
     * the claim itself is updated so "previous_state" always reflects genuinely pre-decision data.
     *
     * @param  array<string, mixed>  $previousState
     * @param  array<string, mixed>  $newState
     */
    private function recordDecision(
        EnterpriseWikiClaim $claim,
        int $userId,
        string $decisionType,
        array $previousState,
        array $newState,
        ?string $comment,
    ): void {
        EnterpriseWikiClaimDecision::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'decided_by_user_id' => $userId,
            'decision_type' => $decisionType,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'comment' => $comment,
        ]);
    }

    private function applyBestPracticeTextEdit(EnterpriseWikiClaim $claim, ?string $approvedText): void
    {
        if ($claim->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return;
        }

        $approvedText = $this->normalizeNullableString($approvedText);

        if ($approvedText === null || $approvedText === trim((string) $claim->claim_text)) {
            return;
        }

        $claim->loadMissing('version');
        $version = $claim->version;

        if ($version === null || (int) $version->id !== (int) $claim->enterprise_wiki_page_version_id) {
            abort(422, 'Claim is not tied to a valid Wiki page version.');
        }

        $blockKey = $this->normalizeNullableString($claim->content_block_key);

        if ($blockKey === null) {
            abort(422, 'Best-practice text can only be edited when the claim has a stable Wiki text block.');
        }

        abort_unless(
            $this->contentBlockService->replaceBlockMarkdown($version, $blockKey, $approvedText),
            422,
            'Best-practice text can only be edited when the original block is still present in the current Wiki page version.',
        );

        if ($claim->canonical_fact_id !== null) {
            $fact = $claim->canonicalFact ?? $claim->canonicalFact()->first();

            if ($fact !== null) {
                $this->canonicalizationService->markStaleIfDiverged($fact, $approvedText);
            }
        }

        $claim->update([
            'claim_text' => $approvedText,
            'page_excerpt' => $approvedText,
            // Only signal an ordinary user's UI can show — never internal enum/diagnostic data —
            // to distinguish "Godkjent" from "Redigert og godkjent" in the Kjøringer Funn panel
            // (EnterpriseWikiRunFindingsService). The original AI-suggested wording remains
            // recoverable through the page version history; this flag is purely presentational.
            'review_metadata' => array_merge((array) ($claim->review_metadata ?? []), [
                'edited_before_approval' => true,
            ]),
        ]);
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

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeReviewBackUrl(mixed $backUrl): ?string
    {
        if (! is_string($backUrl)) {
            return null;
        }

        $backUrl = trim($backUrl);

        if ($backUrl === '') {
            return null;
        }

        $parsed = parse_url($backUrl);

        if (! is_array($parsed) || ($parsed['path'] ?? null) !== '/app/wiki') {
            return null;
        }

        parse_str($parsed['query'] ?? '', $query);

        return (($query['tab'] ?? null) === 'runs') ? $backUrl : null;
    }

    public function sourceDocumentElements(string $slug, EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): JsonResponse
    {
        $page = $this->resolvePageForClaim($slug, $claim);
        $user = $this->customerContext->currentUser();

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

    private function canHandleClaimForPage(EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, ?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $this->documentOwnerApprovalService->canHandleClaim(
            $claim,
            $user,
            $page->currentVersion()->first(),
        );
    }
}
