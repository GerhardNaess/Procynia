<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class WatchInboxDigestNotification extends Notification
{
    /**
     * @param array<int, array<string, mixed>> $watchProfileSections
     */
    public function __construct(
        private readonly string $recipientName,
        private readonly array $watchProfileSections,
        private readonly int $totalRecords,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'watch_inbox_digest',
            'title' => sprintf('Nye relevante treff siste 24 timer (%d)', $this->totalRecords),
            'summary' => sprintf(
                '%s har %d nye treff fordelt på %d watch lister.',
                $this->recipientName,
                $this->totalRecords,
                count($this->watchProfileSections),
            ),
            'total_records' => $this->totalRecords,
            'watch_profile_sections' => $this->watchProfileSections,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
