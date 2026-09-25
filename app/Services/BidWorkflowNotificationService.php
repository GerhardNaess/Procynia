<?php

namespace App\Services;

use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use Illuminate\Support\Facades\DB;

/**
 * The bid workflow's own notifications, written into the panel Procynia already has.
 *
 * Nothing here is a new notification system. It writes UserNotification rows the same way
 * EnterpriseWikiReviewNotificationService does — idempotent on dedupe_key, after commit, and
 * rescued so a notification that cannot be written never breaks the work that triggered it.
 *
 * What the four events answer, in the user's terms:
 *   task assigned      — this is yours now
 *   deadline soon      — this is coming
 *   case inactive      — this one has gone quiet
 *   watch profile hit  — a new opportunity matched what you asked to watch
 *
 * The hard part is not sending them; it is not sending them twice. Every dedupe_key below encodes
 * the SITUATION rather than the check that found it, so a daily sweep over an unchanged case writes
 * nothing, while a genuinely new situation — a moved deadline, a reassignment, a case that went
 * quiet again after someone worked on it — produces a new key and a new notification.
 *
 * Deliberately not here: who wants which notification. With four event types, a preferences screen
 * would be more configuration than there is behaviour to configure.
 */
class BidWorkflowNotificationService
{
    public const EVENT_TASK_ASSIGNED = 'bid.task_assigned';

    public const EVENT_DEADLINE_APPROACHING = 'bid.deadline_approaching';

    public const EVENT_CASE_INACTIVE = 'bid.case_inactive';

    public const EVENT_WATCH_PROFILE_MATCH = 'watch_profile.match_found';

    public function __construct(
        private readonly SavedNoticeWorkflowSignals $signals,
    ) {}

    /**
     * Someone is now responsible for a requirement.
     *
     * Called from RequirementResponsibilityTaskService, which already decides when responsibility
     * actually moves. The task row is updated in place on a reassignment, so its updated_at is what
     * separates one assignment from the next: assigning to Alisan after Gerhard writes a new key,
     * and re-running the same sync does not.
     *
     * The actor is skipped, matching the Wiki notifications: telling someone what they just did
     * themselves is noise, not news.
     */
    public function taskAssigned(SavedNoticeInfoItem $task, ?User $actor = null): void
    {
        $recipientId = $task->owner_user_id !== null ? (int) $task->owner_user_id : null;

        if ($recipientId === null || ($actor !== null && (int) $actor->id === $recipientId)) {
            return;
        }

        $notice = $task->savedNotice;

        if (! $notice instanceof SavedNotice) {
            return;
        }

        $this->notify(
            $notice,
            $recipientId,
            self::EVENT_TASK_ASSIGNED,
            sprintf(
                '%s:%d:%d:%d',
                self::EVENT_TASK_ASSIGNED,
                $task->id,
                $recipientId,
                optional($task->updated_at)?->getTimestamp() ?? 0,
            ),
            'Ny oppgave',
            sprintf('Du har fått ansvar for «%s» i %s.', $task->subject, $notice->title),
            UserNotification::SEVERITY_INFO,
            ['info_item_id' => (int) $task->id],
        );
    }

    /**
     * One daily sweep over one customer's active cases.
     *
     * Deadlines and inactivity are checked together because they ask the same question of the same
     * rows — splitting them would mean loading every case twice to say two things about it.
     *
     * @return array{deadline: int, inactive: int}
     */
    public function sweepCustomer(int $customerId): array
    {
        $notices = SavedNotice::query()
            ->where('customer_id', $customerId)
            ->whereNull('archived_at')
            ->whereIn('bid_status', SavedNoticeWorkflowSignals::ACTIVE_BID_STATUSES)
            ->with(['phaseComments:id,saved_notice_id,created_at', 'submissions:id,saved_notice_id,submitted_at'])
            ->get();

        $counts = ['deadline' => 0, 'inactive' => 0];

        foreach ($notices as $notice) {
            $counts['deadline'] += $this->notifyUpcomingDeadline($notice);
            $counts['inactive'] += $this->notifyInactiveCase($notice);
        }

        return $counts;
    }

    /**
     * The soonest deadline, to the people answerable for the case.
     *
     * Only the nearest one: a case with a question deadline on Tuesday and a submission on Friday
     * has one thing to do first, and naming both would make the panel a list rather than a prompt.
     */
    private function notifyUpcomingDeadline(SavedNotice $notice): int
    {
        // Actionable only: the cockpit lists the award date as a milestone, but nobody has work to
        // do five days before the buyer decides.
        $deadlines = $this->signals->actionableDeadlinesWithin($notice);

        if ($deadlines === []) {
            return 0;
        }

        $deadline = $deadlines[0];
        $sent = 0;

        // Bid manager and commercial owner are often the same person; array_unique is what makes
        // that one notification rather than two identical ones.
        foreach ($this->caseResponsibleIds($notice) as $recipientId) {
            $sent += (int) $this->notify(
                $notice,
                $recipientId,
                self::EVENT_DEADLINE_APPROACHING,
                sprintf(
                    '%s:%d:%s:%s:%d',
                    self::EVENT_DEADLINE_APPROACHING,
                    $notice->id,
                    $deadline['type'],
                    $deadline['date']->toDateString(),
                    $recipientId,
                ),
                'Frist nærmer seg',
                sprintf(
                    '%s har %s %s.',
                    $notice->title,
                    mb_strtolower($deadline['label']),
                    $deadline['date']->translatedFormat('j. F Y'),
                ),
                UserNotification::SEVERITY_WARNING,
                ['deadline_type' => $deadline['type'], 'deadline_at' => $deadline['date']->toIso8601String()],
            );
        }

        return $sent;
    }

    /**
     * A case nobody has touched, to the person answerable for its progress.
     *
     * The bid manager alone: progress is their job, and telling the whole team would spread the
     * responsibility rather than place it.
     *
     * The last activity date is part of the key, which is what separates one quiet period from the
     * next. A case that goes quiet, gets worked on, and goes quiet again produces two notifications;
     * one that simply stays quiet produces one. No new column is needed — the activity data already
     * says which period this is.
     */
    private function notifyInactiveCase(SavedNotice $notice): int
    {
        if ($notice->bid_manager_user_id === null || ! $this->signals->isInactive($notice)) {
            return 0;
        }

        $latestActivityAt = $this->signals->latestActivityAt($notice);

        return (int) $this->notify(
            $notice,
            (int) $notice->bid_manager_user_id,
            self::EVENT_CASE_INACTIVE,
            sprintf(
                '%s:%d:%d:%s',
                self::EVENT_CASE_INACTIVE,
                $notice->id,
                (int) $notice->bid_manager_user_id,
                $latestActivityAt?->toDateString() ?? 'never',
            ),
            'Sak uten fremdrift',
            sprintf(
                '%s har ikke hatt aktivitet de siste %d dagene.',
                $notice->title,
                SavedNoticeWorkflowSignals::INACTIVE_DAYS,
            ),
            UserNotification::SEVERITY_WARNING,
            ['last_activity_at' => $latestActivityAt?->toIso8601String()],
        );
    }

    /**
     * A new notice matched a watch profile.
     *
     * Called from the discovery service at the one point where "new" is actually known — the insert,
     * not the re-sighting. A profile with no named owner reaches nobody rather than everybody: a
     * department-wide profile has no one person whose opportunity this is, and guessing would put
     * the alert in front of people who never asked for it.
     */
    public function watchProfileMatched(WatchProfile $watchProfile, WatchProfileInboxRecord $record): void
    {
        $recipientId = $watchProfile->user_id !== null ? (int) $watchProfile->user_id : null;

        if ($recipientId === null) {
            return;
        }

        $this->write(
            (int) $watchProfile->customer_id,
            $recipientId,
            null,
            self::EVENT_WATCH_PROFILE_MATCH,
            sprintf(
                '%s:%d:%s:%d',
                self::EVENT_WATCH_PROFILE_MATCH,
                $watchProfile->id,
                (string) $record->doffin_notice_id,
                $recipientId,
            ),
            'Ny relevant kunngjøring',
            sprintf('Et nytt treff ble funnet for «%s»: %s.', $watchProfile->name, $record->title),
            UserNotification::SEVERITY_INFO,
            route('app.notices.index', ['mode' => 'live', 'tab' => 'alerts'], false),
            [
                'watch_profile_id' => (int) $watchProfile->id,
                'doffin_notice_id' => (string) $record->doffin_notice_id,
                'watch_profile_inbox_record_id' => (int) $record->id,
            ],
        );
    }

    /**
     * The people answerable for a case, each counted once.
     *
     * Explicit assignments only. A main role is not a stand-in for them: a case with a named bid
     * manager is that person's, and notifying every bid manager at the customer because the field
     * happens to be empty would be addressing the wrong people confidently.
     *
     * @return list<int>
     */
    private function caseResponsibleIds(SavedNotice $notice): array
    {
        return array_values(array_unique(array_filter([
            $notice->bid_manager_user_id !== null ? (int) $notice->bid_manager_user_id : null,
            $notice->opportunity_owner_user_id !== null ? (int) $notice->opportunity_owner_user_id : null,
        ])));
    }

    /** @param array<string, mixed> $metadata */
    private function notify(
        SavedNotice $notice,
        int $recipientId,
        string $eventType,
        string $dedupeKey,
        string $title,
        string $message,
        string $severity,
        array $metadata = [],
    ): bool {
        return $this->write(
            (int) $notice->customer_id,
            $recipientId,
            (int) $notice->id,
            $eventType,
            $dedupeKey,
            $title,
            $message,
            $severity,
            route('app.notices.saved.show', ['savedNotice' => $notice->id], false),
            $metadata,
        );
    }

    /**
     * The single write, with the two checks that keep a notification honest: the recipient is an
     * active user, and they belong to the customer the notification is about. A row that fails
     * either is not written, so no target_url can ever point across a customer boundary.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function write(
        int $customerId,
        int $recipientId,
        ?int $savedNoticeId,
        string $eventType,
        string $dedupeKey,
        string $title,
        string $message,
        string $severity,
        string $targetUrl,
        array $metadata = [],
    ): bool {
        $recipient = User::query()->find($recipientId);

        if (! $recipient instanceof User
            || ! $recipient->is_active
            || (int) $recipient->customer_id !== $customerId) {
            return false;
        }

        $attributes = [
            'customer_id' => $customerId,
            'user_id' => $recipientId,
            'saved_notice_id' => $savedNoticeId,
            'event_type' => $eventType,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'target_url' => $targetUrl,
            'metadata' => $metadata,
        ];

        DB::afterCommit(function () use ($dedupeKey, $attributes): void {
            // rescue(): the work that triggered this must not fail because a notification could not
            // be written. The user's case is what matters; the alert about it is not.
            rescue(
                fn () => UserNotification::query()->firstOrCreate(['dedupe_key' => $dedupeKey], $attributes),
                null,
                false,
            );
        });

        return true;
    }
}
