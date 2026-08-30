<?php

namespace App\Services\Ai\Commercial;

use App\Data\Ai\AiQuotaStatus;
use App\Models\Customer;
use App\Models\CustomerAiNotificationState;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AiQuotaNotification;
use App\Services\Admin\AdminNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a commercial quota state transition into at most one notification per customer, event and
 * period.
 *
 * It never calculates the quota itself — that is AiQuotaStatusService's job — and it never throws
 * back into the AI call that triggered it. A telemetry or mail failure must not be able to break
 * the customer's actual AI work.
 */
class AiQuotaNotificationService
{
    /** Ordered weakest → strongest, which is also the order a customer crosses them. */
    private const THRESHOLD_EVENTS = [
        CustomerAiNotificationState::EVENT_QUOTA_WARNING,
        CustomerAiNotificationState::EVENT_QUOTA_CRITICAL,
        CustomerAiNotificationState::EVENT_QUOTA_EXHAUSTED,
    ];

    public function __construct(
        private readonly AiQuotaStatusService $quotaStatus,
        private readonly AdminNotificationService $adminNotifications,
    ) {}

    /** Evaluate one customer's quota after a real state transition (usage committed, plan changed). */
    public function evaluate(Customer $customer): void
    {
        try {
            $status = $this->quotaStatus->forCustomer($customer);

            // Unlimited and not-included customers have no commercial threshold to cross.
            if (! $status->hasFiniteQuota()) {
                return;
            }

            $qualified = $this->qualifiedEvents($status);
            $unsent = array_values(array_filter(
                $qualified,
                fn (string $event): bool => ! $this->alreadyNotified($customer, $event, $status),
            ));

            if ($unsent === []) {
                return;
            }

            // A jump from 40% to 100% qualifies for all three at once. Sending three near-identical
            // e-mails would be noise, so only the strongest new one is sent; the weaker ones are
            // recorded as passed so they can never fire later in the same period.
            $strongest = $unsent[array_key_last($unsent)];
            $recipients = $this->systemOwners($customer);

            if ($recipients->isEmpty()) {
                // Not recorded as notified: if a System Owner is appointed later in the period,
                // the customer should still hear about the threshold exactly once.
                $this->reportMissingRecipients($customer, $strongest, $status);

                return;
            }

            $this->dispatch($customer, $recipients, $strongest, $status);

            foreach ($unsent as $event) {
                $this->record($customer, $event, $status, $event === $strongest ? $recipients->count() : 0);
            }
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][AI_QUOTA_NOTIFICATION] Quota evaluation failed.', [
                'customer_id' => $customer->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Suspension and resumption are discrete administrative acts, not repeated evaluations, so they
     * carry no period dedupe — a second suspension in the same month is genuinely new information.
     */
    public function notifyAccessChanged(Customer $customer, bool $suspended): void
    {
        try {
            $recipients = $this->systemOwners($customer);
            $event = $suspended
                ? CustomerAiNotificationState::EVENT_AI_SUSPENDED
                : CustomerAiNotificationState::EVENT_AI_RESUMED;

            if ($recipients->isEmpty()) {
                $this->reportMissingRecipients($customer, $event, null);

                return;
            }

            $this->dispatch($customer, $recipients, $event, $this->quotaStatus->forCustomer($customer));
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][AI_QUOTA_NOTIFICATION] Access-change notification failed.', [
                'customer_id' => $customer->id,
                'suspended' => $suspended,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * An administrative capacity change is a discrete act like a suspension, so it carries no
     * period dedupe either: granting credits twice in a month is two pieces of real news.
     */
    public function notifyCreditsAdjusted(Customer $customer, int $amount, AiQuotaStatus $status): void
    {
        try {
            $recipients = $this->systemOwners($customer);

            if ($recipients->isEmpty()) {
                $this->reportMissingRecipients($customer, CustomerAiNotificationState::EVENT_CREDITS_ADJUSTED, $status);

                return;
            }

            $this->dispatch(
                $customer,
                $recipients,
                CustomerAiNotificationState::EVENT_CREDITS_ADJUSTED,
                $status,
                ['amount' => $amount, 'amount_abs' => abs($amount)],
            );
        } catch (Throwable $throwable) {
            Log::warning('[PROCYNIA][AI_QUOTA_NOTIFICATION] Credit-adjustment notification failed.', [
                'customer_id' => $customer->id,
                'amount' => $amount,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /** @return list<string> */
    private function qualifiedEvents(AiQuotaStatus $status): array
    {
        $percentage = $status->percentageUsed ?? 0;
        $events = [];

        if ($percentage >= $this->quotaStatus->warningPercent()) {
            $events[] = CustomerAiNotificationState::EVENT_QUOTA_WARNING;
        }

        if ($percentage >= $this->quotaStatus->criticalPercent()) {
            $events[] = CustomerAiNotificationState::EVENT_QUOTA_CRITICAL;
        }

        if ($status->status === AiQuotaStatus::STATUS_EXHAUSTED) {
            $events[] = CustomerAiNotificationState::EVENT_QUOTA_EXHAUSTED;
        }

        return array_values(array_intersect(self::THRESHOLD_EVENTS, $events));
    }

    private function alreadyNotified(Customer $customer, string $event, AiQuotaStatus $status): bool
    {
        return CustomerAiNotificationState::query()
            ->where('customer_id', $customer->id)
            ->where('event_key', $event)
            ->whereDate('period_start', $status->periodStart)
            ->exists();
    }

    private function record(Customer $customer, string $event, AiQuotaStatus $status, int $recipientCount): void
    {
        CustomerAiNotificationState::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'event_key' => $event,
                'period_start' => $status->periodStart,
            ],
            [
                'period_end' => $status->periodEnd,
                'threshold_percent' => $this->thresholdPercentFor($event),
                'recipient_count' => $recipientCount,
                'notified_at' => now(),
            ],
        );
    }

    private function thresholdPercentFor(string $event): ?int
    {
        return match ($event) {
            CustomerAiNotificationState::EVENT_QUOTA_WARNING => $this->quotaStatus->warningPercent(),
            CustomerAiNotificationState::EVENT_QUOTA_CRITICAL => $this->quotaStatus->criticalPercent(),
            CustomerAiNotificationState::EVENT_QUOTA_EXHAUSTED => 100,
            default => null,
        };
    }

    /** @return Collection<int, User> */
    private function systemOwners(Customer $customer): Collection
    {
        return User::query()
            ->where('customer_id', $customer->id)
            ->where('is_active', true)
            ->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)
            ->get()
            ->filter(fn (User $user): bool => $user->isSystemOwner() && $user->canAccessCustomerFrontend())
            ->values();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array<string, mixed>  $extraReplacements
     */
    private function dispatch(Customer $customer, Collection $recipients, string $event, AiQuotaStatus $status, array $extraReplacements = []): void
    {
        $content = $this->content($event, $status, $customer, $extraReplacements);
        $billingUrl = rescue(fn (): string => route('app.billing.index'), null, false);

        foreach ($recipients as $recipient) {
            $this->createInAppNotification($customer, $recipient, $event, $content, $billingUrl, $status);

            rescue(
                fn () => $recipient->notify(new AiQuotaNotification(
                    subject: $content['subject'],
                    intro: $content['intro'],
                    lines: $content['lines'],
                    actionLabel: $billingUrl !== null ? $content['action'] : null,
                    actionUrl: $billingUrl,
                    eventKey: $event,
                )),
                null,
                false,
            );
        }

        Log::info('[PROCYNIA][AI_QUOTA_NOTIFICATION] Quota notification sent.', [
            'customer_id' => $customer->id,
            'event_key' => $event,
            'recipients' => $recipients->count(),
            'percentage_used' => $status->percentageUsed,
            'period_start' => $status->periodStart,
        ]);
    }

    /** @param array{subject: string, intro: string, lines: list<string>, action: string} $content */
    private function createInAppNotification(
        Customer $customer,
        User $recipient,
        string $event,
        array $content,
        ?string $billingUrl,
        AiQuotaStatus $status,
    ): void {
        rescue(
            fn () => UserNotification::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $recipient->id,
                'event_type' => 'ai_quota.'.$event,
                'severity' => $this->severityFor($event),
                'title' => $content['subject'],
                'message' => trim($content['intro'].' '.implode(' ', $content['lines'])),
                'target_url' => $billingUrl,
                'metadata' => [
                    'event_key' => $event,
                    'used' => $status->used,
                    'allowance' => $status->allowance(),
                    'remaining' => $status->remaining,
                    'percentage_used' => $status->percentageUsed,
                    'period_start' => $status->periodStart,
                    'period_end' => $status->periodEnd,
                ],
            ]),
            null,
            false,
        );
    }

    private function severityFor(string $event): string
    {
        return match ($event) {
            CustomerAiNotificationState::EVENT_QUOTA_WARNING => UserNotification::SEVERITY_WARNING,
            CustomerAiNotificationState::EVENT_QUOTA_CRITICAL => UserNotification::SEVERITY_WARNING,
            CustomerAiNotificationState::EVENT_AI_RESUMED, CustomerAiNotificationState::EVENT_CREDITS_ADJUSTED => UserNotification::SEVERITY_INFO,
            default => UserNotification::SEVERITY_CRITICAL,
        };
    }

    /**
     * @param  array<string, mixed>  $extraReplacements
     * @return array{subject: string, intro: string, lines: list<string>, action: string}
     */
    private function content(string $event, AiQuotaStatus $status, Customer $customer, array $extraReplacements = []): array
    {
        $replacements = [
            'customer' => $customer->name,
            'used' => $status->used + $status->reserved,
            'allowance' => $status->allowance(),
            'remaining' => $status->remaining ?? 0,
            'percent' => $status->percentageUsed ?? 0,
            'period_end' => $status->periodEnd,
            ...$extraReplacements,
        ];
        $key = 'procynia.ai_quota.notifications.'.$event;

        return [
            'subject' => __($key.'.subject', $replacements),
            'intro' => __($key.'.intro', $replacements),
            'lines' => array_values(array_filter([
                __($key.'.detail', $replacements),
                __($key.'.next_step', $replacements),
            ], fn (string $line): bool => ! str_starts_with($line, 'procynia.'))),
            'action' => __('procynia.ai_quota.notifications.action_billing'),
        ];
    }

    private function reportMissingRecipients(Customer $customer, string $event, ?AiQuotaStatus $status): void
    {
        Log::warning('[PROCYNIA][AI_QUOTA_NOTIFICATION] No active System Owner to receive an AI quota notification.', [
            'customer_id' => $customer->id,
            'event_key' => $event,
            'percentage_used' => $status?->percentageUsed,
            'period_start' => $status?->periodStart,
        ]);

        $this->adminNotifications->create(
            type: 'ai_quota_no_recipient',
            severity: 'warning',
            title: 'AI-varsel uten mottaker',
            message: sprintf(
                'Kunden «%s» har ingen aktiv System Owner som kan motta AI-varselet «%s».',
                $customer->name,
                $event,
            ),
            data: [
                'customer_id' => $customer->id,
                'event_key' => $event,
                'period_start' => $status?->periodStart,
            ],
            dedupeKey: sprintf('ai_quota_no_recipient:%d:%s:%s', $customer->id, $event, $status?->periodStart ?? 'n/a'),
        );
    }
}
