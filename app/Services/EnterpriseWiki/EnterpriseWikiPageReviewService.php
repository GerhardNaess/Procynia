<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiReviewConflict;
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

    private function mayHandOver(?User $actor, EnterpriseWikiPage $page): bool
    {
        return $actor instanceof User
            && $this->permissions->has($actor, CustomerPermissionCatalog::WIKI_EDIT)
            && $actor->canSubmitEnterpriseWikiPage($page);
    }
}
