<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiReviewConflict;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The review workflow of one Wiki page: the decisions people take on it, each checked here as well
 * as in the controller, so no other entry point can take them without the same rules.
 *
 * Submit (and reopen a returned page), withdraw, approve and publish, send back, and the QA
 * assignment. Every decision passes HumanDecisionGuard and its own authority rule here, under the
 * same page lock as before; WikiController keeps a fast pre-check for a good answer and the HTTP
 * shape around it. Moved here unchanged from the controller in phase 0 step 6
 * (docs/external-api-phase-0-plan.md §4.4) — status codes and messages included, which is why the
 * refusals are still abort() calls.
 *
 * approve() is the one place a page is published (EnterpriseWikiPublicationInvariantArchitectureTest).
 */
class EnterpriseWikiPageReviewService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly CustomerPermissionService $permissions,
        private readonly EnterpriseWikiReviewNotificationService $notifications,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovals,
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
    ) {}

    // =========================================================================
    // Submit, reopen
    // =========================================================================

    /**
     * May this reviewer be handed this page by this submitter? wiki.review, the Wiki's capability,
     * the same customer, and never the submitter (User::canBeEnterpriseWikiReviewerFor()).
     */
    public function isEligibleReviewer(User $reviewer, EnterpriseWikiPage $page, int $submitterId): bool
    {
        return $this->permissions->has($reviewer, CustomerPermissionCatalog::WIKI_REVIEW)
            && $reviewer->canBeEnterpriseWikiReviewerFor($page, $submitterId);
    }

    /**
     * Hand the working version to a named reviewer (draft → pending_review).
     *
     * $version is the version the person is handing over — read by the caller BEFORE anything else,
     * because a version written by an automated writer in between must not be the one that gets
     * handed over. Under the page lock it has to still be the current one, or the handover is refused
     * (409) rather than given a version nobody looked at.
     *
     * Refusing rather than silently reassigning: an assignment already in flight belongs to whoever
     * is acting on it.
     */
    public function submit(User $actor, EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, User $reviewer): EnterpriseWikiPageVersion
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_PAGE_SUBMIT);
        $this->assertMayHandOver($actor, $page);

        if ($page->status !== EnterpriseWikiPage::STATUS_DRAFT || (int) $version->enterprise_wiki_page_id !== (int) $page->id) {
            abort(422);
        }

        if ($version->reviewer_user_id !== null) {
            abort(409);
        }

        if (! $this->isEligibleReviewer($reviewer, $page, (int) $actor->id)) {
            abort(422, 'Velg en gyldig kontrollør: en aktiv bruker hos samme kunde som kan godkjenne Wiki-sider, og som ikke er deg selv.');
        }

        DB::transaction(function () use ($page, $version, $actor, $reviewer): void {
            // The same page row lock EnterpriseWikiPageVersionWriter writes under. Everything above
            // was read without it, so an automated version written in between would otherwise make
            // this hand over a version that is no longer the working one — a review stranded from
            // the start. Re-read under the lock and refuse instead.
            $locked = EnterpriseWikiPage::query()->whereKey($page->id)->lockForUpdate()->first();
            $currentVersionId = $locked?->currentVersion()->value('id');

            if ($locked?->status !== EnterpriseWikiPage::STATUS_DRAFT || (int) $currentVersionId !== (int) $version->id) {
                abort(409, __('procynia.wiki.submit_working_version_changed'));
            }

            $version->forceFill([
                'submitted_by_user_id' => $actor->id,
                'submitted_at' => now(),
                'reviewer_user_id' => $reviewer->id,
            ])->save();

            // Re-derive who must vouch for the source-based content in THIS version, so the people
            // asked are today's document owners rather than whoever owned a document earlier.
            // A version with no source-based content produces no requirements, which is a valid
            // state — there is simply nobody to ask.
            $this->documentOwnerApprovals->syncForPageVersion($version);

            $this->notifications->pageSubmittedForReview($page, $version->fresh(), $actor);

            // published_version_id is untouched: sending new work for review must not withdraw
            // whatever was already approved.
            $locked->forceFill(['status' => EnterpriseWikiPage::STATUS_PENDING_REVIEW])->save();
        });

        return $version->fresh();
    }

    /**
     * Return a returned (rejected) page to draft so its owner can work on it again. Not a handover —
     * it carries no reviewer and clears any assignment the rejected round left.
     */
    public function reopenRejected(User $actor, EnterpriseWikiPage $page): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_PAGE_SUBMIT);
        $this->assertMayHandOver($actor, $page);

        DB::transaction(function () use ($page): void {
            $locked = EnterpriseWikiPage::query()->whereKey($page->id)->lockForUpdate()->first();

            if ($locked?->status !== EnterpriseWikiPage::STATUS_REJECTED) {
                abort(409, __('procynia.wiki.claim_version_changed'));
            }

            $version = $locked->currentVersion()->first();

            if ($version !== null) {
                $version->forceFill([
                    'submitted_by_user_id' => null,
                    'submitted_at' => null,
                    'reviewer_user_id' => null,
                ])->save();
            }

            $locked->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();
        });
    }

    // =========================================================================
    // Approve and publish, send back
    // =========================================================================

    /**
     * Why this user may not publish this version now, or null when they may.
     *
     * Publishing is the end of a review, for everyone. A draft has to be sent to somebody else
     * first — the four-eyes rule leaves no direct route, System Owner included. The page reads the
     * same function to decide what to offer, so what it shows and what approve() accepts cannot
     * drift apart.
     */
    public function finalApprovalBlocker(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, User $user): ?string
    {
        return match (true) {
            $page->status !== EnterpriseWikiPage::STATUS_PENDING_REVIEW => 'not_in_review',
            $version->reviewer_user_id === null
                || $version->submitted_by_user_id === null
                || $version->submitted_at === null => 'missing_assignment',
            // Same blocker for both halves of the answer: the customer never gave this person
            // approval work, or the Wiki's own capability matrix does not. Either way the page
            // says the capability is missing rather than silently offering nothing.
            ! $this->permissions->has($user, CustomerPermissionCatalog::WIKI_APPROVE) => 'missing_capability',
            ! $user->canApproveWikiPages() => 'missing_capability',
            // Reported separately from not_assigned because the two can need different sentences:
            // one is "somebody else is holding this", the other is "you cannot sign off your own
            // work". Once a reviewer is named, both mean the same thing to the reader and the page
            // says so by naming them.
            (int) $version->submitted_by_user_id === (int) $user->id => 'own_submission',
            ! $user->canFinalApproveEnterpriseWikiVersion($version, $page) => 'not_assigned',
            // The system sent a finding to a person because text presented as document content
            // goes beyond its source. Publishing it with nobody having decided would publish that
            // misrepresentation. Best-practice suggestions stay advisory and are not counted.
            $this->hasUnresolvedSourceBasedFindings($version) => 'unresolved_source_findings',
            // Source documents are provenance, not a level of approval. Whether a document owner
            // has vouched for their own material is recorded and visible, and it no longer decides
            // whether the Wiki page may be published — the page is the thing being approved.
            default => null,
        };
    }

    /**
     * Publish the version the reviewer was handed (pending_review → approved). The only place a
     * page's published_version_id is written.
     */
    public function approve(User $actor, EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_PAGE_APPROVE);

        // Authorized by capability, not by job title. System Owner still passes, because
        // roleHasPermission() short-circuits for them — an override, not the normal route.
        if (! $this->permissions->has($actor, CustomerPermissionCatalog::WIKI_APPROVE) || ! $actor->canApproveWikiPages()) {
            abort(403);
        }

        $this->assertMayFinalApprove($actor, $page);

        // Everything above is a fast pre-check for a good error message. The decision itself is made
        // again under a row lock, because two reviewers — or a reviewer and a System Owner stepping
        // in — can press approve at the same moment. The lock serialises them: the first commits,
        // the second re-reads a page that is no longer pending_review and is told so.
        return DB::transaction(function () use ($page, $actor): EnterpriseWikiPageVersion {
            $locked = EnterpriseWikiPage::query()
                ->whereKey($page->id)
                ->lockForUpdate()
                ->first();

            $this->assertMayFinalApprove($actor, $locked);

            $workingVersion = $locked->currentVersion()->first();

            // A page cannot become approved without naming what was approved. Aborting here rather
            // than publishing nothing keeps "approved implies published_version_id" true.
            if ($workingVersion === null) {
                abort(422, 'Siden har ingen arbeidsversjon å godkjenne.');
            }

            // Status and publication move together, in one write. The previously published version
            // stays in the table untouched — it simply stops being the one the page points at.
            $locked->forceFill([
                'status' => EnterpriseWikiPage::STATUS_APPROVED,
                'published_version_id' => $workingVersion->id,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $actor->id,
            ])->save();

            $this->notifications->pagePublished($locked, $workingVersion, $actor);

            // submitted_by_user_id, submitted_at and reviewer_user_id are deliberately left alone:
            // they are the record of who handed this version over and to whom, and that history is
            // worth more after the decision than before it.
            return $workingVersion;
        });
    }

    /**
     * Whose turn is it on this version — the question both the endpoint and the page have to answer.
     *
     * Shared so that what the panel offers and what returnToOwner() accepts cannot drift apart.
     *
     * Only the person the version was handed to, or a System Owner sending it back
     * (User::canReviewEnterpriseWikiVersion). A version nobody was handed — a page stranded by an
     * edit during review before decision B5 — is decided by nobody: a general Wiki approver does not
     * take over a review they were never given. Its owner withdraws it («Trekk tilbake») instead.
     */
    public function mayActOnCurrentVersion(User $user, EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version): bool
    {
        if ($page->status !== EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
            return false;
        }

        // Acting on a version in review — sending it back — is review work.
        if (! $this->permissions->has($user, CustomerPermissionCatalog::WIKI_REVIEW)) {
            return false;
        }

        if (! $user->canApproveWikiPages()) {
            return false;
        }

        return $user->canReviewEnterpriseWikiVersion($version, $page);
    }

    /**
     * Send the page back to its owner with a reason (pending_review → rejected).
     *
     * wiki.review and NOT wiki.approve: sending a page back is a review decision, and the two
     * permissions are deliberately not interchangeable. published_version_id is untouched: sending
     * new work back must never withdraw content that was already approved.
     */
    public function returnToOwner(User $actor, EnterpriseWikiPage $page, string $reason): EnterpriseWikiPageReviewEvent
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_PAGE_RETURN);

        if (! $this->permissions->has($actor, CustomerPermissionCatalog::WIKI_REVIEW) || ! $actor->canApproveWikiPages()) {
            abort(403);
        }

        $this->assertMayReturn($actor, $page);

        $reason = trim($reason);

        // No return without a reason: the page owner has to know what to fix.
        if (mb_strlen($reason) < EnterpriseWikiPageReviewEvent::REASON_MIN_LENGTH) {
            abort(422, 'Begrunnelse må fylles ut.');
        }

        return DB::transaction(function () use ($page, $actor, $reason): EnterpriseWikiPageReviewEvent {
            $locked = EnterpriseWikiPage::query()
                ->whereKey($page->id)
                ->lockForUpdate()
                ->first();

            $this->assertPendingReview($locked);
            $version = $this->assertMayActOnCurrentVersion($actor, $locked);

            $event = EnterpriseWikiPageReviewEvent::query()->create([
                'enterprise_wiki_page_id' => $locked->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'actor_user_id' => $actor->id,
                'actor_role' => EnterpriseWikiPageReviewEvent::ACTOR_ROLE_REVIEWER,
                'event_type' => EnterpriseWikiPageReviewEvent::EVENT_TYPE_CHANGES_REQUESTED,
                'reason' => $reason,
            ]);

            $this->notifications->changesRequested($locked, $event);

            $locked->forceFill([
                'status' => EnterpriseWikiPage::STATUS_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $actor->id,
            ])->save();

            return $event;
        });
    }

    /**
     * The page is out for review and it is this person's turn on it — checked before the reason, so
     * "not yours" and "already decided" are answered as such rather than as a missing reason.
     */
    public function assertMayReturn(User $actor, EnterpriseWikiPage $page): void
    {
        $this->assertPendingReview($page);
        $this->assertMayActOnCurrentVersion($actor, $page);
    }

    // =========================================================================
    // QA assignment
    // =========================================================================

    /**
     * Whether this person may be asked to quality assure the page.
     *
     * canApproveWikiClaims() is the authority, not is_qa on its own: the permission matrix is what
     * decides, and it grants the claim permission to System Owner plus the QA capability by
     * default. A Contributor who is QA therefore qualifies, which is the ordinary case.
     */
    public function isEligibleQaUser(User $candidate, EnterpriseWikiPage $page): bool
    {
        return $candidate->is_active
            && (int) $candidate->customer_id === (int) $page->customer_id
            // Only somebody who may actually review can be asked to. Offering a name the claim
            // actions would then refuse is a request that cannot be answered.
            && $this->permissions->has($candidate, CustomerPermissionCatalog::WIKI_REVIEW)
            && $candidate->canApproveWikiClaims();
    }

    /**
     * Ask somebody to quality assure the working version. Asking for QA is review work being
     * organised: wiki.review on top of the Wiki's rule about who may hand the page onward.
     *
     * @return bool whether the assignment changed (the same person again is a no-op)
     */
    public function assignQa(User $actor, EnterpriseWikiPage $page, User $assignee): bool
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_QA_ASSIGN);

        if (! $this->permissions->has($actor, CustomerPermissionCatalog::WIKI_REVIEW) || ! $actor->canSubmitEnterpriseWikiPage($page)) {
            abort(403);
        }

        $version = $page->currentVersion()->first();

        if (! $version instanceof EnterpriseWikiPageVersion) {
            abort(422, 'Siden har ingen arbeidsversjon å kvalitetssikre.');
        }

        if (! $this->isEligibleQaUser($assignee, $page)) {
            abort(422, 'Velg en gyldig kvalitetssikrer: en aktiv bruker hos samme kunde som kan godkjenne Wiki-påstander.');
        }

        // Re-assigning to the same person is a no-op rather than a second request. Rewriting the
        // timestamp would make the dedupe key change and send them the same message again.
        if ((int) $version->qa_user_id === (int) $assignee->id) {
            return false;
        }

        DB::transaction(function () use ($version, $assignee, $actor, $page): void {
            // Claims already approved or rejected keep their actor and timestamp. A handover changes
            // who is asked next, never what somebody already decided.
            $version->forceFill([
                'qa_user_id' => $assignee->id,
                'qa_assigned_at' => now(),
                'qa_assigned_by_user_id' => $actor->id,
            ])->save();

            $this->notifications->qaAssigned($page, $version->fresh(), $assignee, $actor);
        });

        return true;
    }

    // =========================================================================
    // Guards shared by the decisions
    // =========================================================================

    /**
     * Whether the version carries a finding EnterpriseWikiClaimFindingExplainer::blocksPublication()
     * holds up final approval for.
     */
    private function hasUnresolvedSourceBasedFindings(EnterpriseWikiPageVersion $version): bool
    {
        return EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->where('content_origin', EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT)
            ->where('approval_status', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING)
            ->with(['canonicalFact', 'sourceReferences'])
            ->get()
            ->contains(fn (EnterpriseWikiClaim $claim): bool => $this->claimFindingExplainer->blocksPublication($claim, $version));
    }

    /**
     * The one check standing between a request and publication.
     *
     * Deliberately reads finalApprovalBlocker() — the same function the page reads to decide what
     * to offer — rather than re-expressing the rule.
     *
     * The status code carries the distinction the caller needs. 403 is "not you"; 409 is "you, but
     * not yet", which is the reviewer's cue to send the page back instead.
     */
    private function assertMayFinalApprove(User $user, ?EnterpriseWikiPage $page): void
    {
        if ($page === null) {
            abort(404);
        }

        $version = $page->currentVersion()->first();

        if ($version === null) {
            abort(422, 'Siden har ingen arbeidsversjon å vurdere.');
        }

        $blocker = $this->finalApprovalBlocker($page, $version, $user);

        if ($blocker === null) {
            return;
        }

        abort(match ($blocker) {
            'missing_assignment', 'unresolved_source_findings' => 409,
            'not_in_review' => 422,
            default => 403,
        }, match ($blocker) {
            'missing_assignment' => __('procynia.wiki.approve_refused_missing_assignment'),
            'unresolved_source_findings' => __('procynia.wiki.publication_blocker_unresolved_source_findings'),
            'own_submission' => 'Du kan ikke godkjenne en versjon du selv har sendt til gjennomgang.',
            'missing_capability' => 'Du har ikke tilgang til å godkjenne Wiki-sider.',
            'not_in_review' => 'Versjonen er ikke sendt til gjennomgang.',
            default => 'Du er ikke tildelt som kontrollør for denne versjonen.',
        });
    }

    /**
     * A version can only be decided once, and only while it is actually out for review.
     */
    private function assertPendingReview(?EnterpriseWikiPage $page): void
    {
        if ($page === null) {
            abort(404);
        }

        if ($page->status === EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
            return;
        }

        abort(422, match ($page->status) {
            EnterpriseWikiPage::STATUS_APPROVED, EnterpriseWikiPage::STATUS_REJECTED => 'Denne versjonen er allerede avgjort.',
            default => 'Versjonen er ikke sendt til gjennomgang.',
        });
    }

    /**
     * The part of the review check that applies to ANY review decision: there has to be a version,
     * and this user has to be the one whose turn it is.
     */
    private function assertMayActOnCurrentVersion(User $user, EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        $version = $page->currentVersion()->first();

        if ($version === null) {
            abort(422, 'Siden har ingen arbeidsversjon å vurdere.');
        }

        if ($this->mayActOnCurrentVersion($user, $page, $version)) {
            return $version;
        }

        abort(403, $version->submitted_by_user_id !== null && (int) $version->submitted_by_user_id === (int) $user->id
            ? 'Du kan ikke godkjenne en versjon du selv har sendt til gjennomgang.'
            : 'Du er ikke tildelt som kontrollør for denne versjonen.');
    }

    private function assertMayHandOver(User $actor, EnterpriseWikiPage $page): void
    {
        if (! $this->mayHandOver($actor, $page)) {
            abort(403);
        }
    }

    /**
     * May this person take the page back from review? The same people who may hand it over
     * (wiki.edit, and the page is theirs or they are System Owner), and only while it is out.
     *
     * Not the reviewer and not another approver: withdrawing is the submitting side changing its
     * mind. The reviewer's own way out is «Send tilbake».
     */
    public function canWithdraw(?User $actor, EnterpriseWikiPage $page): bool
    {
        return $page->status === EnterpriseWikiPage::STATUS_PENDING_REVIEW
            && $this->guard->allows($actor)
            && $this->mayHandOver($actor, $page);
    }

    /**
     * Take a page back from review — decision B5.
     *
     * Ends the handover rather than changing its subject: the assignment on the version is cleared,
     * so the reviewer has nothing left to decide and nobody else gains anything to decide either.
     * The page returns to draft, where it can be edited and sent again; published_version_id and the
     * published content it points to are untouched. Who withdrew it, when, and who it had been
     * handed to are kept as a review event.
     */
    public function withdraw(User $actor, EnterpriseWikiPage $page): EnterpriseWikiPageReviewEvent
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_PAGE_WITHDRAW);

        if (! $this->mayHandOver($actor, $page)) {
            throw new AuthorizationException('Only whoever may send this page for review may withdraw it.');
        }

        return DB::transaction(function () use ($actor, $page): EnterpriseWikiPageReviewEvent {
            // The page row lock every version writer and every review decision takes.
            $locked = EnterpriseWikiPage::query()->whereKey($page->id)->lockForUpdate()->first();

            if ($locked?->status !== EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
                throw new EnterpriseWikiReviewConflict(__('procynia.wiki.withdraw_not_in_review'));
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $locked->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if ($version === null) {
                throw new EnterpriseWikiReviewConflict(__('procynia.wiki.withdraw_not_in_review'));
            }

            $event = EnterpriseWikiPageReviewEvent::query()->create([
                'enterprise_wiki_page_id' => $locked->id,
                'enterprise_wiki_page_version_id' => $version->id,
                'actor_user_id' => $actor->id,
                'actor_role' => EnterpriseWikiPageReviewEvent::ACTOR_ROLE_PAGE_OWNER,
                'assigned_reviewer_user_id' => $version->reviewer_user_id,
                'event_type' => EnterpriseWikiPageReviewEvent::EVENT_TYPE_WITHDRAWN,
                // Withdrawing asks nobody to act, so there is no reason to give.
                'reason' => null,
            ]);

            $version->forceFill([
                'submitted_by_user_id' => null,
                'submitted_at' => null,
                'reviewer_user_id' => null,
            ])->save();

            // published_version_id, reviewed_* and the QA assignment are deliberately untouched:
            // withdrawing a newer version must never withdraw what was already approved.
            $locked->forceFill(['status' => EnterpriseWikiPage::STATUS_DRAFT])->save();

            $this->notifications->pageWithdrawnFromReview($locked, $event);

            return $event;
        });
    }

    /**
     * May this person change the page's text through a claim decision right now? — finding F1c.
     *
     * Outside review this rule adds nothing; the claim decision's own authorization applies. While
     * the page is in review, text is changed only by the reviewer the current version was handed
     * to: the edit lands in the version they approve, the handover stays intact, and the four-eyes
     * line is not crossed by anyone else. A quality assurer, the page owner and the submitter keep
     * every decision that does not change the text (keep it, undo, blocking, source references).
     * Not a System Owner either — they may send a page back, but not rewrite somebody else's review.
     */
    public function mayChangeClaimText(?User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): bool
    {
        return $this->mayChangeVersionText($actor, $page, (int) $claim->enterprise_wiki_page_version_id);
    }

    /**
     * For the page view: is changing text through claim decisions closed to this viewer because
     * the page is in review and they are not its reviewer? The claim cards then offer only the
     * decisions that leave the text as it is.
     */
    public function isClaimTextLockedFor(?User $actor, EnterpriseWikiPage $page, ?EnterpriseWikiPageVersion $currentVersion): bool
    {
        return $page->status === EnterpriseWikiPage::STATUS_PENDING_REVIEW
            && ($currentVersion === null || ! $this->mayChangeVersionText($actor, $page, (int) $currentVersion->id));
    }

    private function mayChangeVersionText(?User $actor, EnterpriseWikiPage $page, int $versionId): bool
    {
        if (! $this->guard->allows($actor)) {
            return false;
        }

        // Re-read: the instance the caller holds may predate a submit or a withdrawal.
        $status = EnterpriseWikiPage::query()->whereKey($page->id)->value('status');

        if ($status !== EnterpriseWikiPage::STATUS_PENDING_REVIEW) {
            return true;
        }

        $reviewerId = EnterpriseWikiPageVersion::query()
            ->whereKey($versionId)
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->value('reviewer_user_id');

        return $reviewerId !== null && (int) $reviewerId === (int) $actor->id;
    }

    /** Refuses a text-changing claim decision before anything is written (see mayChangeClaimText()). */
    public function assertMayChangeClaimText(User $actor, EnterpriseWikiPage $page, EnterpriseWikiClaim $claim): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::WIKI_CLAIM_DECIDE);

        if (! $this->mayChangeClaimText($actor, $page, $claim)) {
            throw new AuthorizationException(__('procynia.wiki.claim_text_locked_in_review'));
        }
    }

    private function mayHandOver(?User $actor, EnterpriseWikiPage $page): bool
    {
        return $actor instanceof User
            && $this->permissions->has($actor, CustomerPermissionCatalog::WIKI_EDIT)
            && $actor->canSubmitEnterpriseWikiPage($page);
    }
}
