<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail half of an AI-quota event. Deliberately says nothing about tokens, models, providers
 * or internal cost: a System Owner needs to know how much of their plan is left and what to do,
 * not how Procynia buys inference.
 */
class AiQuotaNotification extends Notification
{
    /** @param array<string, mixed> $lines */
    public function __construct(
        private readonly string $subject,
        private readonly string $intro,
        private readonly array $lines,
        private readonly ?string $actionLabel = null,
        private readonly ?string $actionUrl = null,
        private readonly string $eventKey = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject)
            ->line($this->intro);

        foreach ($this->lines as $line) {
            $message->line((string) $line);
        }

        if ($this->actionLabel !== null && $this->actionUrl !== null) {
            $message->action($this->actionLabel, $this->actionUrl);
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ai_quota',
            'event_key' => $this->eventKey,
            'title' => $this->subject,
            'summary' => $this->intro,
        ];
    }
}
