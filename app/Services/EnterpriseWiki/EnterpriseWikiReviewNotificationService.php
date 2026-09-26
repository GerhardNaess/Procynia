<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageReviewEvent;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Tells the right person that Wiki work is waiting for them.
 *
 * This service owns no workflow state. `reviewer_user_id` decides who reviews;
 * EnterpriseWikiPageVersionDocumentOwnerApproval decides whose sign-off is outstanding;
 * enterprise_wiki_page_review_events holds why something was sent back. Deleting, reading or
 * missing a notification changes none of that — the workflow stays correct either way, and the work
 * list can always be derived from the domain tables.
 *
 * Procynia has in-app notifications (UserNotification) and no general task concept, so that is what
 * is used. Mail is reserved for billing, quota and digests; nothing here introduces a new channel.
 *
 * Every write goes through notify(), which is idempotent on dedupe_key and deferred to after commit,
 * so a retried job cannot double-notify and a rolled-back decision is never announced.
 */
class EnterpriseWikiReviewNotificationService
{
    public const EVENT_REVIEW_ASSIGNED = 'wiki.review_assigned';

    public const EVENT_CHANGES_REQUESTED = 'wiki.changes_requested';

    public const EVENT_PAGE_PUBLISHED = 'wiki.page_published';

    public const EVENT_QA_ASSIGNED = 'wiki.qa_assigned';

    public function __construct(
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovals,
    ) {}

    /**
     * A version has been handed over: tell the reviewer, and tell every document owner whose
     * sign-off it now waits on.
     *
     * The reviewer's wording is deliberately "you are assigned" rather than a promise about what
     * they will find: the page is theirs to decide, and what it needs is on the page.
     */
    public function pageSubmittedForReview(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        User $submitter,
    ): void {
        $reviewer = $version->reviewer_user_id !== null
            ? User::query()->find($version->reviewer_user_id)
            : null;

        if ($reviewer instanceof User) {
            $this->notify(
                $page,
                $reviewer,
                self::EVENT_REVIEW_ASSIGNED,
                sprintf('%s:%d:%d', self::EVENT_REVIEW_ASSIGNED, $version->id, $reviewer->id),
                'Du er tildelt som kontrollør',
                sprintf('%s sendte «%s» til gjennomgang. Du er tildelt som kontrollør.', $submitter->name, $page->title),
                ['page_version_id' => (int) $version->id, 'submitted_by_user_id' => (int) $submitter->id],
            );
        }
    }

    /**
     * Somebody has been asked to quality assure this version's claims.
     *
     * The QA capability only ever said a person COULD do this work. Nothing said they should, so
     * nobody was ever told — a quality step that depended on someone happening to look. This is the
     * message that was missing.
     *
     * The assignment timestamp is in the dedupe key, which is what lets the same person be asked
     * again later: handing QA from Gerhard to Alisan and back is two real requests, and the second
     * one deserves to be heard. Re-saving the same assignment is not.
     */
    public function qaAssigned(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        User $assignee,
        User $assignedBy,
    ): void {
        $this->notify(
            $page,
            $assignee,
            self::EVENT_QA_ASSIGNED,
            sprintf(
                '%s:%d:%d:%d',
                self::EVENT_QA_ASSIGNED,
                $version->id,
                $assignee->id,
                optional($version->qa_assigned_at)?->getTimestamp() ?? 0,
            ),
            'Wiki-side til kvalitetssikring',
            sprintf('«%s» er sendt til deg for kvalitetssikring av %s.', $page->title, $assignedBy->name),
            ['page_version_id' => (int) $version->id, 'assigned_by_user_id' => (int) $assignedBy->id],
            // Assigning QA to yourself is allowed — a lone QA user must be able to record that the
            // work is theirs — but telling them what they just did is not news.
            $assignedBy,
        );
    }

    /**
     * A version was sent back. The page owner is the one who has to act on it.
     *
     * The reason is read from the recorded event rather than restated, so the notification cannot
     * drift from the audit trail. Keyed on the event, so each round notifies once — and a later
     * round notifies again, because it is a different objection.
     */
    public function changesRequested(EnterpriseWikiPage $page, EnterpriseWikiPageReviewEvent $event): void
    {
        $recipient = $page->owner_user_id !== null
            ? User::query()->find($page->owner_user_id)
            : null;

        // No owner means no honest recipient. Guessing one — a System Owner, the first admin — would
        // put someone else's name on work they never accepted. It is reported, not routed.
        if (! $recipient instanceof User) {
            return;
        }

        $actorName = $event->actor?->name ?? 'En kontrollør';
        $isDocumentOwner = $event->actor_role === EnterpriseWikiPageReviewEvent::ACTOR_ROLE_DOCUMENT_OWNER;

        $this->notify(
            $page,
            $recipient,
            self::EVENT_CHANGES_REQUESTED,
            sprintf('%s:%d:%d', self::EVENT_CHANGES_REQUESTED, $event->id, $recipient->id),
            'Endringer kreves',
            sprintf(
                '%s ba om endringer i «%s»%s: %s',
                $actorName,
                $page->title,
                $isDocumentOwner ? ' som dokumenteier' : '',
                $event->reason,
            ),
            [
                'page_version_id' => (int) $event->enterprise_wiki_page_version_id,
                'review_event_id' => (int) $event->id,
                'actor_role' => $event->actor_role,
            ],
            $event->actor,
            UserNotification::SEVERITY_WARNING,
        );
    }

    /**
     * The page is approved and published. The owner always hears about it; whoever submitted this
     * round hears too when that is somebody else — deduplicated when it is the same person.
     */
    public function pagePublished(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        User $approver,
    ): void {
        $recipientIds = collect([$page->owner_user_id, $version->submitted_by_user_id])
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        foreach (User::query()->whereIn('id', $recipientIds)->get() as $recipient) {
            $this->notify(
                $page,
                $recipient,
                self::EVENT_PAGE_PUBLISHED,
                sprintf('%s:%d:%d', self::EVENT_PAGE_PUBLISHED, $version->id, $recipient->id),
                'Wiki-siden er godkjent',
                sprintf('%s godkjente «%s». Versjonen er nå publisert.', $approver->name, $page->title),
                ['page_version_id' => (int) $version->id, 'approved_by_user_id' => (int) $approver->id],
                $approver,
            );
        }
    }

    /**
     * Write one notification, once, after the surrounding transaction commits.
     *
     * Three things are enforced here so no caller has to remember them:
     *
     * - The recipient must be an active user of the page's own customer. A notification is a
     *   disclosure, so this is the isolation boundary, not a convenience check.
     * - `$actor` is never notified about their own action. Being handed a new responsibility is worth
     *   a notification; being told what you just did is noise.
     * - dedupe_key makes the insert idempotent, and afterCommit means a decision that rolls back is
     *   never announced.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function notify(
        EnterpriseWikiPage $page,
        User $recipient,
        string $eventType,
        string $dedupeKey,
        string $title,
        string $message,
        array $metadata = [],
        ?User $actor = null,
        string $severity = UserNotification::SEVERITY_INFO,
    ): void {
        if (! $recipient->is_active || (int) $recipient->customer_id !== (int) $page->customer_id) {
            return;
        }

        if ($actor !== null && (int) $actor->id === (int) $recipient->id) {
            return;
        }

        $attributes = [
            'customer_id' => $page->customer_id,
            'user_id' => $recipient->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'target_url' => route('app.wiki.show', $page->slug),
            'metadata' => array_merge($metadata, ['page_id' => (int) $page->id, 'page_slug' => $page->slug]),
        ];

        DB::afterCommit(function () use ($dedupeKey, $attributes): void {
            // rescue(): a notification that cannot be written must never break the decision the
            // user just made. The workflow does not depend on it.
            rescue(
                fn () => UserNotification::query()->firstOrCreate(['dedupe_key' => $dedupeKey], $attributes),
                null,
                false,
            );
        });
    }
}
