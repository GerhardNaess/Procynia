<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * The one write into the bell (user_notifications) for the modules' own notification services.
 *
 * It holds the rules every one of them had restated, so a new module cannot forget one:
 *
 *  - The recipient must be an active user of the customer the notification is about. A notification
 *    is a disclosure, so this is the isolation boundary — a row that fails it is never written, and
 *    no target_url can point across a customer.
 *  - The actor is never told about their own action.
 *  - dedupe_key makes the insert idempotent: a retry, a second click or a re-run sweep writes
 *    nothing new. The caller decides what makes two notifications the same, because only the module
 *    knows what a new situation is.
 *  - The write happens after the surrounding transaction commits, so a change that rolls back is
 *    never announced, and outside any transaction it happens at once.
 *  - A write that fails is rescued: the work that triggered it must not fail because the alert about
 *    it could not be written.
 *
 * It owns no workflow state. Whatever the notification announces is decided, and kept, by the
 * module; reading or deleting the notification changes none of it.
 */
class UserNotificationWriter
{
    /**
     * Queue one notification. Returns whether it passed the recipient checks — true means it will be
     * written after commit unless an identical dedupe_key already exists.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function notify(
        int $customerId,
        User|int|null $recipient,
        string $eventType,
        string $dedupeKey,
        string $title,
        string $message,
        string $targetUrl,
        array $metadata = [],
        string $severity = UserNotification::SEVERITY_INFO,
        ?User $actor = null,
        ?int $savedNoticeId = null,
    ): bool {
        $recipient = is_int($recipient) ? User::query()->find($recipient) : $recipient;

        if (! $recipient instanceof User
            || ! $recipient->is_active
            || (int) $recipient->customer_id !== $customerId) {
            return false;
        }

        if ($actor !== null && (int) $actor->id === (int) $recipient->id) {
            return false;
        }

        $attributes = [
            'customer_id' => $customerId,
            'user_id' => (int) $recipient->id,
            'saved_notice_id' => $savedNoticeId,
            'event_type' => $eventType,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'target_url' => $targetUrl,
            'metadata' => $metadata,
        ];

        DB::afterCommit(function () use ($dedupeKey, $attributes): void {
            rescue(
                fn () => UserNotification::query()->firstOrCreate(['dedupe_key' => $dedupeKey], $attributes),
                null,
                false,
            );
        });

        return true;
    }
}
