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
 * Holds the page owner's withdrawal today. Submit, approve, return and QA assignment move here in
 * phase 0 step 6 (docs/external-api-phase-0-plan.md §4.4).
 */
class EnterpriseWikiPageReviewService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly CustomerPermissionService $permissions,
        private readonly EnterpriseWikiReviewNotificationService $notifications,
    ) {}

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
