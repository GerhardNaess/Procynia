<?php

namespace App\Filament\Pages;

use App\Models\AdminNotification;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class AdminNotifications extends Page
{
    protected string $view = 'filament.pages.admin-notifications';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Varsler';

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 6;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $unreadNotifications = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $recentNotifications = [];

    public int $unreadCount = 0;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return 'Varsler';
    }

    public function getTitle(): string
    {
        return 'Admin-varsler';
    }

    public function getSubheading(): ?string
    {
        return 'Interne Procynia-varsler · Kun Super Admin';
    }

    public static function getNavigationBadge(): ?string
    {
        if (! app(CustomerContext::class)->isInternalAdmin()) {
            return null;
        }

        $count = AdminNotification::query()->whereNull('read_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        $this->loadNotifications();
    }

    public function markAsRead(int $id): void
    {
        $notification = AdminNotification::query()->find($id);
        $notification?->markAsRead();
        $this->loadNotifications();
    }

    public function markAllAsRead(): void
    {
        AdminNotification::query()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->loadNotifications();
    }

    private function loadNotifications(): void
    {
        $unread = AdminNotification::query()
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $this->unreadCount         = $unread->count();
        $this->unreadNotifications = $unread->map(fn (AdminNotification $n): array => $this->notificationPayload($n))->all();

        $this->recentNotifications = AdminNotification::query()
            ->whereNotNull('read_at')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn (AdminNotification $n): array => $this->notificationPayload($n))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationPayload(AdminNotification $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->type,
            'severity'   => $n->severity,
            'title'      => $n->title,
            'message'    => $n->message,
            'data'       => $n->data,
            'read_at'    => $n->read_at?->format('d.m.Y H:i'),
            'created_at' => $n->created_at?->format('d.m.Y H:i'),
        ];
    }
}
