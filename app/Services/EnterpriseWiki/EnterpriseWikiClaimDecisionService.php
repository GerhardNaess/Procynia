<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiReviewConflict;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Support\Facades\DB;

/**
 * The decisions people take on one Wiki claim: keep it (optionally with edited text), remove it,
 * undo a decision, set its blocking, and link a real source to it.
 *
 * Moved unchanged from WikiClaimController in phase 0 step 7 (docs/external-api-phase-0-plan.md
 * §4.4, conflict K2), so the rules that protect a claim decision — who may handle the claim, F1c
 * (in review only the assigned reviewer changes text), F1d (never in the published version), the
 * cascades and the audit trail — hold for every entry point, not only a web request. Every
 * decision passes HumanDecisionGuard (WIKI_CLAIM_DECIDE) and canHandle() here. Refusals stay
 * abort() calls, so the status codes people get are unchanged.
 */
class EnterpriseWikiClaimDecisionService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly CustomerPermissionService $permissions,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiBuildPageLinksService $pageLinksService,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiPageContentBlockService $contentBlockService,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovals,
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
        private readonly EnterpriseWikiBestPracticeSectionService $bestPracticeSectionService,
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
        private readonly EnterpriseWikiPageReviewService $pageReviews,
        private readonly EnterpriseWikiClaimContentRepairService $claimContentRepair,
        private readonly EnterpriseWikiClaimDecisionRecorder $recorder,
    ) {}

    /**
     * Deciding a claim is reviewing Wiki content, so wiki.review is required on top of everything
     * the Wiki already asks. The one gate every claim action in this controller passes through —
     * approve, reject, unapprove, attach a source reference, override blocking — so a role without
     * wiki.review cannot act on a claim by any route.
     *
     * Added, never substituted: canHandleClaim() still decides whether THIS claim is this user's
     * to touch, through claim approval authority or document ownership. A permission says the
     * customer gave this person review work; it does not say the work includes this claim.
     */
    public function canHandle(?User $user, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        // The page's customer, and the claim on this page. The rules below answer within a customer
        // — a System Owner or a QA passes them in their own Wiki — so the boundary is drawn here,
        // not left to the caller having looked the page up in the right Wiki (§2m).
        if ((int) $user->customer_id !== (int) $page->customer_id || (int) $claim->enterprise_wiki_page_id !== (int) $page->id) {
            return false;
        }

        if (! $this->permissions->has($user, CustomerPermissionCatalog::WIKI_REVIEW)) {
            return false;
        }

        return $this->documentOwnerApprovals->canHandleClaim(
            $claim,
            $user,
            $page->currentVersion()->first(),
        );
    }

    /**
     * Keep the claim — with edited text when it is a best-practice suggestion or a finding on a
     * source_based block, which is «Rediger og godkjenn».
     *
     * @return array{claim: EnterpriseWikiClaim, text_edited: bool, forked: bool}
     */
    public function approve(User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, ?string $approvedText, ?string $comment): array
    {
        $user = $this->authorizeDecision($actor, $page, $claim);

        $this->abortIfAlreadyDecided($claim);

        $changesText = $this->approvalWouldChangeText($claim, $approvedText);

        // One transaction: a decision that forks a published version (F1d) and then fails leaves
        // neither the new working version nor half a decision behind.
        return DB::transaction(function () use ($claim, $page, $user, $approvedText, $comment, $changesText): array {
            $forked = false;

            if ($changesText) {
                // F1c: in review, only the assigned reviewer changes the text. F1d: never in the
                // published version — the decision moves to a new working version first.
                $this->pageReviews->assertMayChangeClaimText($user, $page, $claim);
                [$claim, $forked] = $this->claimForTextChange($page, $claim, $user);
            }

            $textBeforeBestPracticeEdit = (string) $claim->claim_text;
            $this->applyBestPracticeTextEdit($claim, $approvedText);
            $bestPracticeEdited = (string) $claim->fresh()->claim_text !== $textBeforeBestPracticeEdit;

            $textBeforeEdit = (string) $claim->claim_text;
            $sourceFindingEdited = $this->applySourceBasedFindingTextEdit($claim, $approvedText);
            $textEdited = $sourceFindingEdited || $bestPracticeEdited;

            // The edit happens in place on the (working) version, so the decision row is where the
            // replaced wording survives: who changed it, when, and from what to what.
            $this->storeDecision(
                $claim->fresh(),
                $user->id,
                EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
                $comment,
                $textEdited ? ['claim_text' => $bestPracticeEdited ? $textBeforeBestPracticeEdit : $textBeforeEdit] : [],
                $textEdited ? ['claim_text' => (string) $claim->fresh()->claim_text] : [],
            );

            if ($sourceFindingEdited) {
                $this->pageLinksService->materializeWikilinksForPage($page->fresh());
            }

            if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
                $this->pageLinksService->materializeWikilinksForPage($page->fresh());

                $claim->fresh()->update([
                    'review_metadata' => array_merge($claim->fresh()->review_metadata ?? [], [
                        'visible_wiki_link_result' => str_contains((string) $claim->fresh()->claim_text, '[[')
                            ? 'materialized_from_approved_text'
                            : 'no_visible_link_needed',
                    ]),
                ]);
            }

            $this->cascadeBestPracticeDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED, $comment);
            $this->cascadeBlockDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED, $comment);

            return ['claim' => $claim->fresh(), 'text_edited' => $textEdited, 'forked' => $forked];
        });

    }

    /**
     * Reject the claim — «Fjern teksten» for a best-practice suggestion or a source_based block
     * the source supports none of.
     *
     * @return array{claim: EnterpriseWikiClaim, text_removed: bool, forked: bool}
     */
    public function reject(User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, ?string $comment): array
    {
        $user = $this->authorizeDecision($actor, $page, $claim);

        $this->abortIfAlreadyDecided($claim);

        $claim->loadMissing('version');
        $isSourceBasedFinding = $claim->version !== null
            && $this->claimFindingExplainer->presentsUnsupportedTextAsSourceBased($claim, $claim->version);

        // A partially supported paragraph also carries text the source does back. Removing the
        // whole block would throw that away, and guessing which fragment is the unsupported one is
        // not something to do automatically — the reviewer edits the block instead (approve with
        // approved_text), which Show.jsx opens when "Fjern teksten" is chosen.
        abort_if(
            $isSourceBasedFinding && $claim->generation_issue === 'claim_partially_supported',
            422,
            'Avsnittet inneholder også tekst som kilden støtter. Rediger teksten for å fjerne bare den udokumenterte delen.',
        );

        // Rejecting removes the text for exactly these two kinds of claim (below).
        $removesText = $isSourceBasedFinding || $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE;

        // One transaction, for the same reason as approve().
        return DB::transaction(function () use ($claim, $page, $user, $comment, $removesText, $isSourceBasedFinding): array {
            $forked = false;

            // F1c: removing text while the page is in review is the assigned reviewer's alone. F1d:
            // never from the published version. Both before the decision is stored, so a refusal
            // leaves nothing half-written.
            if ($removesText) {
                $this->pageReviews->assertMayChangeClaimText($user, $page, $claim);
                [$claim, $forked] = $this->claimForTextChange($page, $claim, $user);
            }

            $this->storeDecision(
                $claim,
                $user->id,
                EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
                $comment,
                $removesText ? ['claim_text' => (string) $claim->claim_text] : [],
                $removesText ? ['text_removed' => true] : [],
            );

            if ($removesText) {
                // What the page owner reads on the card: this decision took text out of the page.
                $claim->fresh()->update([
                    'review_metadata' => array_merge((array) ($claim->fresh()->review_metadata ?? []), [
                        'text_removed_by_decision' => true,
                    ]),
                ]);
            }

            // The claim covers the whole block and the source supports none of it, so "fjern
            // teksten" removes exactly what the reviewer was shown — the same blanking a rejected
            // best-practice block gets.
            if ($isSourceBasedFinding) {
                $this->removeSourceBasedFindingText($claim);
                $this->pageLinksService->materializeWikilinksForPage($page->fresh());
            }

            // v0.7 binding quality-strategy rule: "avvis" on a best-practice addition means "fjern
            // teksten" — the only two outcomes for text the system added beyond the source are keep
            // (approve) or remove (reject). Strips the block's markdown from the live page; the
            // decision itself (approval_status=rejected) is recorded regardless, above.
            if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
                $this->removeBestPracticeText($claim);
                $this->pageLinksService->materializeWikilinksForPage($page->fresh());
            }

            $this->cascadeBestPracticeDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED, $comment);
            $this->cascadeBlockDecision($claim->fresh(), $user->id, EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED, $comment);

            return ['claim' => $claim->fresh(), 'text_removed' => $removesText, 'forked' => $forked];
        });

    }

    /** «Angre beslutning»: back to pending, recorded like every decision. */
    public function unapprove(User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): EnterpriseWikiClaim
    {
        $user = $this->authorizeDecision($actor, $page, $claim);

        if (! in_array($claim->approval_status, [
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
        ], true)) {
            abort(422, 'Claim is not manually decided.');
        }

        DB::transaction(function () use ($claim, $user): void {
            $this->recorder->change($claim, EnterpriseWikiClaimActor::user($user), EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS, [
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'approval_comment' => null,
            ]);

            $this->lintService->reopenClaimMissingSourceFindingIfStillMissing($claim);
        });

        return $claim->fresh();
    }

    /**
     * The "behold/fjern blokkering" decision (product rule item 3) — deliberately independent of
     * approval_status. Same authorization as the other claim decisions.
     */
    public function setBlockingOverride(User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, bool $newBlocking, ?string $comment): EnterpriseWikiClaim
    {
        $user = $this->authorizeDecision($actor, $page, $claim);

        // Blocking only ever applies to a claim-integrity finding (internal_error/
        // unsupported_generated_content) — best_practice suggestions never block by design and go
        // through their own approve/edit/reject workflow, and a source_based claim's provenance
        // gap is a lint finding, not something this per-claim decision axis applies to.
        abort_unless(in_array($claim->content_origin, [
            EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
        ], true), 422, 'Blocking can only be set for a claim-integrity finding.');

        $this->recorder->change($claim, EnterpriseWikiClaimActor::user($user), EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE, [
            'blocking_override' => $newBlocking,
            'blocking_override_by_user_id' => $user->id,
            'blocking_override_at' => now(),
        ], $comment);

        return $claim->fresh();
    }

    /**
     * Link a real document-backed source to the claim: a structured element of the document, or a
     * manual excerpt when the document has no structured elements.
     *
     * @param  array<string, mixed>  $selection  source_element_key, source_element_type, source_row_key, excerpt, page_reference
     */
    public function attachSourceReference(User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, int $documentId, array $selection): void
    {
        $user = $this->authorizeDecision($actor, $page, $claim);

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $page->customer_id)
            ->where('id', $documentId)
            ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED)
            ->first();

        abort_unless($document !== null && trim((string) $document->extracted_text) !== '', 404);
        abort_unless($this->documentOwnerApprovals->canUseSourceDocumentForClaim($claim, $document, $user, $page->currentVersion()->first()), 403);

        $hadSourceReference = $claim->hasSourceReference();
        $catalog = $this->sourceElementService->inspect($document);
        $sourceElementKey = $this->normalizeNullableString($selection['source_element_key'] ?? null);
        $sourceElementType = $this->normalizeNullableString($selection['source_element_type'] ?? null);
        $sourceRowKey = $this->normalizeNullableString($selection['source_row_key'] ?? null);
        $pageReference = $this->normalizeNullableString($selection['page_reference'] ?? null);
        $excerpt = $this->normalizeNullableString($selection['excerpt'] ?? null);

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

        // One transaction: the link, its event and the reset it may cause are there together or not at all.
        DB::transaction(function () use ($claim, $user, $document, $sourceElementKey, $sourceElementType, $sourceRowKey, $excerpt, $pageReference, $hadSourceReference): void {
            $reference = EnterpriseWikiSourceReference::query()->updateOrCreate(
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
                ] + EnterpriseWikiSourceReference::linkedByUser($user),
            );

            $this->recorder->note($claim, EnterpriseWikiClaimActor::user($user), EnterpriseWikiClaimDecision::TYPE_SOURCE_REFERENCE,
                ['had_source_reference' => $hadSourceReference],
                [
                    'source_reference_id' => (int) $reference->id,
                    'source_id' => (int) $document->id,
                    'source_element_type' => $sourceElementType,
                    'source_element_key' => $sourceElementKey,
                    'source_row_key' => $sourceRowKey,
                    'relinked' => ! $reference->wasRecentlyCreated,
                ],
            );

            $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, ! $hadSourceReference, $user);
            $this->lintService->resolveClaimMissingSourceFinding($claim);
        });

    }

    private function authorizeDecision(User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): User
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_CLAIM_DECIDE);

        abort_unless($this->canHandle($actor, $page, $claim), 403);

        return $actor;
    }

    /**
     * The claim a text-changing decision acts on.
     *
     * Only a claim of the page's current version can change its text: one of an older version is
     * history, and editing it would rewrite what a version once said (409, the page re-reads
     * itself). When the current version is also the published one, the decision moves to a new
     * working version first (F1d) and acts on the claim's copy there.
     *
     * @return array{0: EnterpriseWikiClaim, 1: bool} the claim to act on, and whether a working version was created
     */
    private function claimForTextChange(EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, User $user): array
    {
        $versionId = (int) $claim->enterprise_wiki_page_version_id;
        $currentId = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->value('id');

        if ((int) $currentId !== $versionId) {
            throw new EnterpriseWikiReviewConflict(__('procynia.wiki.claim_version_changed'));
        }

        if ((int) ($page->fresh()?->published_version_id ?? 0) !== $versionId) {
            return [$claim, false];
        }

        return [$this->claimContentRepair->forkPublishedVersionForClaimDecision($page, $claim, $user), true];
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
            ->whereIn('content_block_key', $this->sectionBlockKeysFor($claim))
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE)
            ->where('id', '!=', $claim->id)
            ->where('approval_status', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING)
            ->get();

        foreach ($siblings as $sibling) {
            $this->storeDecision($sibling, $userId, $status, $comment);
        }
    }

    /**
     * Every content_block_key belonging to the SAME visible "Beste praksis" section as this claim,
     * in block order — the review/decision unit the user actually sees and acts on.
     *
     * Reuses EnterpriseWikiBestPracticeSectionService::mapBlocksToSections(), the single source of
     * truth the Wiki page itself renders from (via WikiController::renderedContentBlocks()), so the
     * decision unit and the visible section can never disagree. That map derives its boundaries
     * from data that always exists — block order, each block's own leading heading, and
     * content_origin — and anchors a stable key on the first block of every best-practice run, so
     * it never depends on a stored section_key being present.
     *
     * Falls back to the claim's own block alone whenever the block is not part of a mapped section
     * (no stable anchor, missing version, or a block the map deliberately excludes), which is
     * exactly the previous per-block behaviour.
     *
     * @return list<string>
     */
    private function sectionBlockKeysFor(EnterpriseWikiClaim $claim): array
    {
        $blockKey = trim((string) $claim->content_block_key);

        if ($blockKey === '') {
            return [];
        }

        $claim->loadMissing('version');
        $version = $claim->version;

        if ($version === null || (int) $version->id !== (int) $claim->enterprise_wiki_page_version_id) {
            return [$blockKey];
        }

        $map = $this->bestPracticeSectionService->mapBlocksToSections($version);
        $sectionKey = $map[$blockKey]['section_key'] ?? null;

        if ($sectionKey === null) {
            return [$blockKey];
        }

        $keys = [];

        foreach ($map as $key => $section) {
            if (($section['section_key'] ?? null) === $sectionKey) {
                $keys[] = (string) $key;
            }
        }

        return $keys === [] ? [$blockKey] : $keys;
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

        // "Fjern teksten" applies to the whole visible section, matching the decision that was
        // just cascaded across it — otherwise rejecting a section would leave its other paragraphs
        // standing on the page with no claim backing them.
        foreach ($this->sectionBlockKeysFor($claim) as $sectionBlockKey) {
            $this->contentBlockService->replaceBlockMarkdown($version, $sectionBlockKey, '');
        }
    }

    /**
     * Two people deciding the same claim from two open tabs: the second decision would silently
     * overwrite the first. A decided claim is changed only through «Angre beslutning», so approve and
     * reject refuse it as a conflict — the page reloads and shows the decision that stands.
     */
    private function abortIfAlreadyDecided(EnterpriseWikiClaim $claim): void
    {
        abort_unless($claim->isPending(), 409);
    }

    /**
     * @param  array<string, mixed>  $previousExtra  further pre-decision state worth keeping (an
     *                                               edited claim's original wording)
     * @param  array<string, mixed>  $newExtra
     */
    private function storeDecision(
        EnterpriseWikiClaim $claim,
        int $userId,
        string $status,
        ?string $comment,
        array $previousExtra = [],
        array $newExtra = [],
    ): void {
        $this->recorder->change($claim, EnterpriseWikiClaimActor::user($userId), EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS, [
            'approval_status' => $status,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'approval_comment' => $comment,
        ], $comment, $previousExtra, $newExtra);

        $this->lintService->resolveClaimMissingSourceFinding($claim);
    }

    /**
     * Whether approving with this text would change the page: the same two conditions under which
     * applyBestPracticeTextEdit() and applySourceBasedFindingTextEdit() write. Sending the text back
     * unchanged is «Behold teksten», not an edit.
     */
    private function approvalWouldChangeText(EnterpriseWikiClaim $claim, ?string $approvedText): bool
    {
        $approvedText = $this->normalizeNullableString($approvedText);

        if ($approvedText === null) {
            return false;
        }

        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return $approvedText !== trim((string) $claim->claim_text);
        }

        $claim->loadMissing('version');
        $version = $claim->version;

        if ($version === null || ! $this->claimFindingExplainer->presentsUnsupportedTextAsSourceBased($claim, $version)) {
            return false;
        }

        $blockKey = trim((string) $claim->content_block_key);
        $blockMarkdown = collect((array) ($version->content_blocks_json ?? []))
            ->first(static fn (mixed $block): bool => is_array($block) && ($block['block_key'] ?? null) === $blockKey)['markdown'] ?? '';

        return $approvedText !== trim((string) $blockMarkdown);
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

        // The reviewer edits the whole visible section (Show.jsx seeds the field with the section's
        // combined markdown), so the edited text replaces the SECTION: it is written to the
        // section's first block and the remaining blocks are blanked. Writing it to one block while
        // leaving the others standing would duplicate the section's other paragraphs on the page.
        $sectionBlockKeys = $this->sectionBlockKeysFor($claim);
        $primaryBlockKey = $sectionBlockKeys[0] ?? $blockKey;

        // Blank the section's other blocks BEFORE writing the new text. replaceBlockMarkdown()
        // locates a block by its current markdown inside content_markdown, and the edited section
        // text legitimately keeps some of those paragraphs verbatim — writing it first would make
        // their lookup ambiguous and silently leave them duplicated on the page.
        foreach ($sectionBlockKeys as $sectionBlockKey) {
            if ($sectionBlockKey !== $primaryBlockKey) {
                $this->contentBlockService->replaceBlockMarkdown($version, $sectionBlockKey, '');
            }
        }

        abort_unless(
            $this->contentBlockService->replaceBlockMarkdown($version, $primaryBlockKey, $approvedText),
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

    /**
     * "Rediger og godkjenn" for a finding on a source_based block: the reviewer rewrote the block —
     * typically removing an inferred purpose or effect the source does not state — and takes
     * responsibility for the result. The rewrite is authoritative, exactly like ordinary editing: the
     * block becomes human_authored and is not sent back through AI verification.
     *
     * Unchanged text is not an edit; approving it is the plain "Behold teksten" decision and the
     * block keeps its source_based provenance.
     *
     * @return bool whether the block text was replaced
     */
    private function applySourceBasedFindingTextEdit(EnterpriseWikiClaim $claim, ?string $approvedText): bool
    {
        $approvedText = $this->normalizeNullableString($approvedText);

        if ($approvedText === null) {
            return false;
        }

        $claim->loadMissing('version');
        $version = $claim->version;

        if ($version === null || ! $this->claimFindingExplainer->presentsUnsupportedTextAsSourceBased($claim, $version)) {
            return false;
        }

        $blockKey = trim((string) $claim->content_block_key);
        $blockMarkdown = collect((array) ($version->content_blocks_json ?? []))
            ->first(static fn (mixed $block): bool => is_array($block) && ($block['block_key'] ?? null) === $blockKey)['markdown'] ?? '';

        if ($approvedText === trim((string) $blockMarkdown)) {
            return false;
        }

        abort_unless($version->is_current, 422, 'Teksten kan bare redigeres i sidens gjeldende arbeidsversjon.');
        abort_unless(
            $this->contentBlockService->replaceBlockMarkdownAsHumanAuthored($version, $blockKey, $approvedText),
            422,
            'Avsnittet finnes ikke lenger i sidens gjeldende arbeidsversjon.',
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
            'review_metadata' => array_merge((array) ($claim->review_metadata ?? []), [
                'edited_before_approval' => true,
            ]),
        ]);

        return true;
    }

    /**
     * "Fjern teksten" for a source_based block the source supports none of — blanks the block,
     * the same mechanism removeBestPracticeText() uses. Never reached for a partially supported
     * block; reject() refuses that case before any decision is stored.
     */
    private function removeSourceBasedFindingText(EnterpriseWikiClaim $claim): void
    {
        $blockKey = trim((string) $claim->content_block_key);
        $version = $claim->version;

        if ($blockKey === '' || $version === null || ! $version->is_current) {
            return;
        }

        $this->contentBlockService->replaceBlockMarkdown($version, $blockKey, '');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
