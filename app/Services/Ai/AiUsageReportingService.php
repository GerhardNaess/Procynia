<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Billing\BillingEntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiUsageReportingService
{
    public function __construct(
        private readonly BillingEntitlementService $billingEntitlementService,
    ) {
    }

    /**
     * Purpose: Build the internal AI usage and capacity report for Filament.
     * Inputs: None.
     * Returns: A stable array with summary cards and customer, user, and operation breakdowns.
     * Side effects: Reads ai_usage_events, customers, and users without mutating data.
     *
     * @return array{
     *     generated_at: string,
     *     summary_cards: array<int, array{label: string, value: int|string, hint: string, tone: string}>,
     *     customers: array<int, array<string, mixed>>,
     *     users: array<int, array<string, mixed>>,
     *     operations: array<int, array<string, mixed>>,
     * }
     */
    public function report(): array
    {
        $now = now();
        $events = $this->loadEvents($now);

        return [
            'generated_at' => $now->format('d.m.Y H:i'),
            'summary_cards' => $this->summaryCards($events, $now),
            'customers' => $this->customerRows($events, $now),
            'users' => $this->userRows($events, $now),
            'operations' => $this->operationRows($events, $now),
        ];
    }

    /**
     * Purpose: Build the lightweight overview needed for the AI capacity page header.
     * Inputs: None.
     * Returns: A stable array with summary cards, generated timestamp, and operation rows.
     * Side effects: Reads ai_usage_events, customers, and users without mutating data.
     *
     * @return array{
     *     generated_at: string,
     *     summary_cards: array<int, array{label: string, value: int|string, hint: string, tone: string}>,
     *     operations: array<int, array<string, mixed>>,
     * }
     */
    public function overview(): array
    {
        $now = now();
        $events = $this->loadEvents($now);

        return [
            'generated_at' => $now->format('d.m.Y H:i'),
            'summary_cards' => $this->summaryCards($events, $now),
            'operations' => $this->operationRows($events, $now),
        ];
    }

    /**
     * Purpose: Load the safe AI usage events needed for 30-day operational reporting.
     * Inputs: The current timestamp.
     * Returns: A collection of safe usage events with lightweight relations loaded.
     * Side effects: Executes a read-only query.
     */
    private function loadEvents(Carbon $now): Collection
    {
        return AiUsageEvent::query()
            ->with([
                'customer:id,name,subscription_plan,included_ai_credits',
                'user:id,name,email,customer_id',
            ])
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Purpose: Build summary cards for the internal AI report.
     * Inputs: The event collection and the current timestamp.
     * Returns: A list of summary card payloads.
     * Side effects: None.
     *
     * @return array<int, array{label: string, value: int, hint: string, tone: string}>
     */
    private function summaryCards(Collection $events, Carbon $now): array
    {
        $events24h = $this->eventsInWindow($events, $now->copy()->subDay());
        $events7d = $this->eventsInWindow($events, $now->copy()->subDays(7));
        $events30d = $this->eventsInWindow($events, $now->copy()->subDays(30));

        $blockedCustomers30d = $this->countDistinctActors($events30d, AiUsageEvent::STATUS_BLOCKED, 'customer_id');
        $blockedUsers30d = $this->countDistinctActors($events30d, AiUsageEvent::STATUS_BLOCKED, 'user_id');

        return [
            [
                'label' => __('procynia.ai_usage_capacity.summary.usage_24h'),
                'value' => $this->operationCount($events24h),
                'hint' => __('procynia.ai_usage_capacity.summary.hint_24h'),
                'tone' => 'primary',
            ],
            [
                'label' => __('procynia.ai_usage_capacity.summary.usage_7d'),
                'value' => $this->operationCount($events7d),
                'hint' => __('procynia.ai_usage_capacity.summary.hint_7d'),
                'tone' => 'success',
            ],
            [
                'label' => __('procynia.ai_usage_capacity.summary.blocked_7d'),
                'value' => $this->operationCount($events7d->where('status', AiUsageEvent::STATUS_BLOCKED)),
                'hint' => __('procynia.ai_usage_capacity.summary.hint_blocked'),
                'tone' => 'warning',
            ],
            [
                'label' => __('procynia.ai_usage_capacity.summary.blocked_customers_30d'),
                'value' => $blockedCustomers30d,
                'hint' => __('procynia.ai_usage_capacity.summary.hint_blocked_customers'),
                'tone' => 'danger',
            ],
            [
                'label' => __('procynia.ai_usage_capacity.summary.blocked_users_30d'),
                'value' => $blockedUsers30d,
                'hint' => __('procynia.ai_usage_capacity.summary.hint_blocked_users'),
                'tone' => 'danger',
            ],
        ];
    }

    /**
     * Purpose: Build per-customer AI usage rows.
     * Inputs: The event collection and the current timestamp.
     * Returns: An ordered list of customer report rows.
     * Side effects: Queries customers and reads billing entitlement settings.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customerRows(Collection $events, Carbon $now): array
    {
        $eventsByCustomer = $events->groupBy(fn (AiUsageEvent $event): string => (string) $event->customer_id);
        $customers = Customer::query()
            ->select(['id', 'name', 'subscription_plan', 'included_ai_credits'])
            ->orderBy('name')
            ->get();

        $rows = $customers->map(function (Customer $customer) use ($eventsByCustomer, $now): array {
            $customerEvents = $eventsByCustomer->get((string) $customer->id, collect());
            $events24h = $this->eventsInWindow($customerEvents, $now->copy()->subDay());
            $events7d = $this->eventsInWindow($customerEvents, $now->copy()->subDays(7));
            $events30d = $this->eventsInWindow($customerEvents, $now->copy()->subDays(30));
            $capacity = $this->capacityInfo($customer, $events30d);

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'plan' => $customer->planName(),
                'capacity' => $capacity,
                'periods' => [
                    '24h' => $this->operationCount($events24h),
                    '7d' => $this->operationCount($events7d),
                    '30d' => $this->operationCount($events30d),
                ],
                'counts' => [
                    'allowed' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_ALLOWED)),
                    'blocked' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED)),
                    'blocked_user' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED)->where('limit_type', AiUsageEvent::LIMIT_TYPE_USER)),
                    'blocked_customer' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED)->where('limit_type', AiUsageEvent::LIMIT_TYPE_CUSTOMER)),
                ],
                'top_operation' => $this->topOperation($events30d),
                'last_blocked_at' => $this->latestBlockedAt($events30d),
                'tone' => $capacity['tone'],
            ];
        })->all();

        usort($rows, function (array $left, array $right): int {
            $usageComparison = $right['periods']['30d'] <=> $left['periods']['30d'];

            if ($usageComparison !== 0) {
                return $usageComparison;
            }

            return strcmp($left['name'], $right['name']);
        });

        return $rows;
    }

    /**
     * Purpose: Build per-user AI usage rows.
     * Inputs: The event collection and the current timestamp.
     * Returns: An ordered list of user report rows.
     * Side effects: Queries users and their customer relations.
     *
     * @return array<int, array<string, mixed>>
     */
    private function userRows(Collection $events, Carbon $now): array
    {
        $eventsByUser = $events->groupBy(fn (AiUsageEvent $event): string => (string) $event->user_id);
        $userIds = $eventsByUser->keys()->map(fn (string $id): int => (int) $id)->all();

        if ($userIds === []) {
            return [];
        }

        $users = User::query()
            ->select(['id', 'name', 'email', 'customer_id'])
            ->with(['customer:id,name,subscription_plan,included_ai_credits'])
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get();

        $rows = $users->map(function (User $user) use ($eventsByUser, $now): array {
            $userEvents = $eventsByUser->get((string) $user->id, collect());
            $events24h = $this->eventsInWindow($userEvents, $now->copy()->subDay());
            $events7d = $this->eventsInWindow($userEvents, $now->copy()->subDays(7));
            $events30d = $this->eventsInWindow($userEvents, $now->copy()->subDays(30));

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'customer_id' => $user->customer?->id,
                'customer_name' => $user->customer?->name ?? __('procynia.common.none'),
                'periods' => [
                    '24h' => $this->operationCount($events24h),
                    '7d' => $this->operationCount($events7d),
                    '30d' => $this->operationCount($events30d),
                ],
                'counts' => [
                    'allowed' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_ALLOWED)),
                    'blocked' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED)),
                    'blocked_user' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED)->where('limit_type', AiUsageEvent::LIMIT_TYPE_USER)),
                    'blocked_customer' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED)->where('limit_type', AiUsageEvent::LIMIT_TYPE_CUSTOMER)),
                ],
                'last_operation' => $this->lastOperation($events30d),
                'last_blocked_at' => $this->latestBlockedAt($events30d),
            ];
        })->all();

        usort($rows, function (array $left, array $right): int {
            $usageComparison = $right['periods']['30d'] <=> $left['periods']['30d'];

            if ($usageComparison !== 0) {
                return $usageComparison;
            }

            return strcmp($left['name'], $right['name']);
        });

        return $rows;
    }

    /**
     * Purpose: Build the operation-key breakdown.
     * Inputs: The event collection and the current timestamp.
     * Returns: An ordered list of operation rows.
     * Side effects: None.
     *
     * @return array<int, array<string, mixed>>
     */
    private function operationRows(Collection $events, Carbon $now): array
    {
        $eventsByOperation = $events->groupBy(fn (AiUsageEvent $event): string => (string) $event->operation_key);

        $rows = $eventsByOperation->map(function (Collection $operationEvents, string $operationKey) use ($now): array {
            $events24h = $this->eventsInWindow($operationEvents, $now->copy()->subDay());
            $events7d = $this->eventsInWindow($operationEvents, $now->copy()->subDays(7));
            $events30d = $this->eventsInWindow($operationEvents, $now->copy()->subDays(30));

            return [
                'operation_key' => $operationKey,
                'label' => $this->operationLabel($operationKey),
                'periods' => [
                    '24h' => $this->operationCount($events24h),
                    '7d' => $this->operationCount($events7d),
                    '30d' => $this->operationCount($events30d),
                ],
                'counts' => [
                    'allowed' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_ALLOWED)),
                    'blocked' => $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED)),
                ],
            ];
        })->all();

        usort($rows, function (array $left, array $right): int {
            $usageComparison = $right['periods']['30d'] <=> $left['periods']['30d'];

            if ($usageComparison !== 0) {
                return $usageComparison;
            }

            return strcmp($left['label'], $right['label']) ?: strcmp($left['operation_key'], $right['operation_key']);
        });

        return $rows;
    }

    /**
     * Purpose: Select the events in a given time window.
     * Inputs: The source events and the lower time bound.
     * Returns: A filtered collection of events inside the window.
     * Side effects: None.
     */
    private function eventsInWindow(Collection $events, Carbon $from): Collection
    {
        return $events->filter(
            fn (AiUsageEvent $event): bool => $event->created_at !== null && $event->created_at->greaterThanOrEqualTo($from),
        );
    }

    /**
     * Purpose: Sum the safe operation counts in a collection of usage events.
     * Inputs: A collection of AiUsageEvent records.
     * Returns: The summed operation count.
     * Side effects: None.
     */
    private function operationCount(Collection $events): int
    {
        return (int) $events->sum(fn (AiUsageEvent $event): int => (int) $event->operation_count);
    }

    /**
     * Purpose: Count distinct actors that have blocked usage in a period.
     * Inputs: The events, a status filter, and the actor column name.
     * Returns: A distinct actor count.
     * Side effects: None.
     */
    private function countDistinctActors(Collection $events, string $status, string $column): int
    {
        return (int) $events
            ->where('status', $status)
            ->pluck($column)
            ->filter(fn (mixed $value): bool => filled($value))
            ->unique()
            ->count();
    }

    /**
     * Purpose: Build capacity information for one customer.
     * Inputs: A customer and the 30-day event window.
     * Returns: A structured capacity payload.
     * Side effects: Reads plan settings and billing entitlement state.
     *
     * @return array{defined: bool, value: int|null, label: string, source_label: string, status: string, status_label: string, tone: string}
     */
    private function capacityInfo(Customer $customer, Collection $events30d): array
    {
        $capacity = $this->includedAiCapacity($customer);

        if ($capacity === null) {
            return [
                'defined' => false,
                'value' => null,
                'label' => __('procynia.ai_usage_capacity.capacity.not_defined'),
                'source_label' => '',
                'status' => 'undefined',
                'status_label' => __('procynia.ai_usage_capacity.capacity_status.undefined'),
                'tone' => 'gray',
            ];
        }

        $allowedUsage = $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_ALLOWED));
        $blockedUsage = $this->operationCount($events30d->where('status', AiUsageEvent::STATUS_BLOCKED));

        if ($capacity['value'] === 0) {
            $status = $allowedUsage > 0 || $blockedUsage > 0 ? 'over' : 'within';
            $tone = $status === 'over' ? 'danger' : 'success';
        } elseif ($blockedUsage > 0 || $allowedUsage > $capacity['value']) {
            $status = 'over';
            $tone = 'danger';
        } elseif ($capacity['value'] > 0 && $allowedUsage >= (int) ceil($capacity['value'] * 0.8)) {
            $status = 'near';
            $tone = 'warning';
        } else {
            $status = 'within';
            $tone = 'success';
        }

        return [
            'defined' => true,
            'value' => $capacity['value'],
            'label' => number_format($capacity['value'], 0, ',', ' '),
            'source_label' => $capacity['source_label'],
            'status' => $status,
            'status_label' => __('procynia.ai_usage_capacity.capacity_status.'.$status),
            'tone' => $tone,
        ];
    }

    /**
     * Purpose: Resolve the customer AI capacity without guessing when it is undefined.
     * Inputs: The customer.
     * Returns: Capacity metadata or null when the value is not explicitly defined.
     * Side effects: None.
     *
     * @return array{value: int, source_label: string}|null
     */
    private function includedAiCapacity(Customer $customer): ?array
    {
        $planConfig = $customer->planConfig();

        if ($customer->included_ai_credits !== null) {
            if (
                (int) $customer->included_ai_credits === 0
                && (! array_key_exists('included_ai_credits', $planConfig) || $planConfig['included_ai_credits'] === null)
            ) {
                return null;
            }

            return [
                'value' => $this->billingEntitlementService->includedAiCredits($customer),
                'source_label' => __('procynia.ai_usage_capacity.capacity.customer_source'),
            ];
        }

        if (! array_key_exists('included_ai_credits', $planConfig) || $planConfig['included_ai_credits'] === null) {
            return null;
        }

        return [
            'value' => $this->billingEntitlementService->includedAiCredits($customer),
            'source_label' => sprintf(
                '%s: %s',
                __('procynia.ai_usage_capacity.capacity.plan_source'),
                $customer->planName(),
            ),
        ];
    }

    /**
     * Purpose: Resolve a human-readable label for a tracked AI operation.
     * Inputs: The canonical operation key.
     * Returns: A readable label for admin reporting.
     * Side effects: None.
     */
    private function operationLabel(string $operationKey): string
    {
        $translationKey = 'procynia.ai_usage_capacity.operations.'.$operationKey;
        $translated = __($translationKey);

        if ($translated !== $translationKey) {
            return (string) $translated;
        }

        return match ($operationKey) {
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD => 'Dokumentopplasting',
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT => 'Svarutkast for krav',
            AiUsageGuard::OPERATION_SAVED_NOTICE_EVIDENCE_REFRESH => 'Oppdater bevisgrunnlag',
            AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH => 'Oppdater vurdering',
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD => 'Kunnskapsdokument',
            AiUsageGuard::OPERATION_KNOWLEDGE_CHUNK_METADATA_UPDATE => 'Oppdater chunk-metadata',
            AiUsageGuard::OPERATION_KNOWLEDGE_VOCABULARY_ANALYSIS_BATCH => 'Analysebatch for vokabular',
            default => Str::headline(str_replace(['_', '-'], ' ', $operationKey)),
        };
    }

    /**
     * Purpose: Resolve the latest operation key for a user or customer row.
     * Inputs: The relevant event collection.
     * Returns: An array with the last operation key and its label.
     * Side effects: None.
     *
     * @return array{key: string, label: string}|null
     */
    private function lastOperation(Collection $events): ?array
    {
        $event = $events->sortByDesc(
            fn (AiUsageEvent $item): int => $item->created_at?->getTimestamp() ?? 0,
        )->first();

        if (! $event instanceof AiUsageEvent) {
            return null;
        }

        return [
            'key' => $event->operation_key,
            'label' => $this->operationLabel($event->operation_key),
        ];
    }

    /**
     * Purpose: Resolve the top operation key for a customer row.
     * Inputs: The relevant event collection.
     * Returns: An array with the top operation key and its label.
     * Side effects: None.
     *
     * @return array{key: string, label: string, count: int}|null
     */
    private function topOperation(Collection $events): ?array
    {
        $counts = $events
            ->groupBy('operation_key')
            ->map(function (Collection $operationEvents, string $operationKey): array {
                return [
                    'operation_key' => $operationKey,
                    'count' => $this->operationCount($operationEvents),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];

                if ($countComparison !== 0) {
                    return $countComparison;
                }

                return strcmp($left['operation_key'], $right['operation_key']);
            })
            ->values();

        if ($counts->isEmpty()) {
            return null;
        }

        $top = $counts->first();

        return [
            'key' => (string) $top['operation_key'],
            'label' => $this->operationLabel((string) $top['operation_key']),
            'count' => (int) $top['count'],
        ];
    }

    /**
     * Purpose: Resolve the latest blocked event timestamp for a row.
     * Inputs: The relevant event collection.
     * Returns: A formatted timestamp or null when there was no block.
     * Side effects: None.
     */
    private function latestBlockedAt(Collection $events): ?string
    {
        $event = $events
            ->where('status', AiUsageEvent::STATUS_BLOCKED)
            ->sortByDesc(fn (AiUsageEvent $item): int => $item->created_at?->getTimestamp() ?? 0)
            ->first();

        if (! $event instanceof AiUsageEvent || $event->created_at === null) {
            return null;
        }

        return $event->created_at->format('d.m.Y H:i');
    }
}
