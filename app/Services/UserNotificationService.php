<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class UserNotificationService
{
    public const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

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
            'mark_all_read_url' => route('app.notifications.read-all'),
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
            'saved_notice' => $savedNotice instanceof \App\Models\SavedNotice && (int) $savedNotice->customer_id === $customerId ? [
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
            'mark_all_read_url' => route('app.notifications.read-all'),
            'items' => [],
        ];
    }
}
