<?php

namespace App\Services;

use App\Models\SavedNotice;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class UserNotificationService
{
    public const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {}

    public function panelPayload(?User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        if (! $user instanceof User || ! $user->canAccessCustomerFrontend()) {
            return $this->emptyPayload($limit);
        }

        $customerId = $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return $this->emptyPayload($limit);
        }

        if (! Schema::hasTable('user_notifications')) {
            return $this->emptyPayload($limit);
        }

        $query = $this->visibleQuery($user, $customerId);
        $notifications = (clone $query)
            ->orderByRaw('CASE WHEN is_read THEN 1 ELSE 0 END')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'unread_count' => $this->unreadCount($user, $customerId),
            'limit' => $limit,
            // Where the bell re-reads itself from while the person stays on one page.
            'refresh_url' => route('app.notifications.index'),
            'mark_all_read_url' => route('app.notifications.read-all'),
            'delete_unread_url' => route('app.notifications.destroy-unread'),
            'items' => $notifications
                ->map(fn (UserNotification $notification): array => $this->notificationPayload($notification, $customerId))
                ->values()
                ->all(),
        ];
    }

    public function unreadCount(?User $user, ?int $customerId = null): int
    {
        if (! $user instanceof User || ! $user->canAccessCustomerFrontend()) {
            return 0;
        }

        $customerId ??= $this->customerContext->currentCustomerId($user);

        if ($customerId === null) {
            return 0;
        }

        if (! Schema::hasTable('user_notifications')) {
            return 0;
        }

        return $this->visibleQuery($user, $customerId)
            ->where('is_read', false)
            ->count();
    }

    public function markAsRead(UserNotification $notification): UserNotification
    {
        if (! $notification->is_read) {
            $notification->forceFill([
                'is_read' => true,
                'read_at' => $notification->read_at ?? now(),
            ])->save();
        }

        return $notification->refresh();
    }

    public function markAllAsRead(User $user): int
    {
        $customerId = $this->customerContext->currentCustomerId($user);

        if (! $user->canAccessCustomerFrontend() || $customerId === null) {
            return 0;
        }

        if (! Schema::hasTable('user_notifications')) {
            return 0;
        }

        return $this->visibleQuery($user, $customerId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Remove one message from the bell.
     *
     * A message, and only a message. Whether the work it announced is still outstanding is written
     * in the domain — a Wiki review assignment, a QA assignment, a case's own state — and nothing
     * here reads or touches any of it. Deleting "Wiki-side til gjennomgang" clears the alert; the
     * review stays on the reviewer's list until they approve or send the page back.
     *
     * Idempotent by construction: a row that is already gone deletes to nothing, which is the right
     * answer for two clicks or two tabs.
     */
    public function delete(UserNotification $notification): void
    {
        $notification->delete();
    }

    /**
     * Clear every unread message for this person.
     *
     * Read messages are deliberately kept: the person has seen those and may still want them, and
     * "clear what I have not got to" is a different intent from "delete my history". Returns how
     * many went, so an empty bell is a successful no-op rather than an error.
     */
    public function deleteAllUnread(User $user): int
    {
        $customerId = $this->customerContext->currentCustomerId($user);

        if (! $user->canAccessCustomerFrontend() || $customerId === null) {
            return 0;
        }

        if (! Schema::hasTable('user_notifications')) {
            return 0;
        }

        return $this->visibleQuery($user, $customerId)
            ->where('is_read', false)
            ->delete();
    }

    private function visibleQuery(User $user, int $customerId): Builder
    {
        return UserNotification::query()
            ->where('customer_id', $customerId)
            ->where('user_id', $user->id);
    }

    private function notificationPayload(UserNotification $notification, int $customerId): array
    {
        if ($notification->saved_notice_id !== null) {
            $notification->loadMissing([
                'savedNotice:id,customer_id,title,reference_number',
            ]);
        }

        $savedNotice = $notification->savedNotice;

        return [
            'id' => $notification->id,
            'customer_id' => $notification->customer_id,
            'user_id' => $notification->user_id,
            'saved_notice_id' => $notification->saved_notice_id,
            'event_type' => $notification->event_type,
            'severity' => $notification->severity,
            'severity_label' => $notification->severity_label,
            'title' => $notification->title,
            'message' => $notification->message,
            'target_url' => $notification->target_url,
            'is_read' => (bool) $notification->is_read,
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'metadata' => $notification->metadata,
            'created_at' => optional($notification->created_at)?->toIso8601String(),
            'updated_at' => optional($notification->updated_at)?->toIso8601String(),
            'mark_read_url' => route('app.notifications.read', ['userNotification' => $notification->id]),
            'delete_url' => route('app.notifications.destroy', ['userNotification' => $notification->id]),
            'saved_notice' => $savedNotice instanceof SavedNotice && (int) $savedNotice->customer_id === $customerId ? [
                'id' => $savedNotice->id,
                'title' => $savedNotice->title,
                'reference_number' => $savedNotice->reference_number,
            ] : null,
        ];
    }

    private function emptyPayload(int $limit): array
    {
        return [
            'unread_count' => 0,
            'limit' => $limit,
            'refresh_url' => route('app.notifications.index'),
            'mark_all_read_url' => route('app.notifications.read-all'),
            'delete_unread_url' => route('app.notifications.destroy-unread'),
            'items' => [],
        ];
    }
}
