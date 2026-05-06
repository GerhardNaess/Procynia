<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionPaymentFailedNotification extends Notification
{
    public function __construct(
        private readonly string $customerName,
        private readonly ?string $invoiceNumber,
        private readonly int $amountDue,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Betaling mislyktes for ' . $this->customerName)
            ->line('En betaling for abonnementet til ' . $this->customerName . ' mislyktes.')
            ->when($this->invoiceNumber, fn ($msg) => $msg->line('Fakturanummer: ' . $this->invoiceNumber))
            ->line('Beløp: ' . number_format($this->amountDue / 100, 2, ',', ' ') . ' NOK')
            ->line('Logg inn i Stripe-dashbordet for å se detaljer og følge opp.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_payment_failed',
            'title' => 'Betaling mislyktes',
            'summary' => sprintf(
                'Betaling for %s mislyktes. Fakturanummer: %s.',
                $this->customerName,
                $this->invoiceNumber ?? 'ukjent',
            ),
            'customer_name' => $this->customerName,
            'invoice_number' => $this->invoiceNumber,
            'amount_due' => $this->amountDue,
        ];
    }
}
