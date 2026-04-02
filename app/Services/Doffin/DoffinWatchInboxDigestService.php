<?php

namespace App\Services\Doffin;

use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Notifications\WatchInboxDigestNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class DoffinWatchInboxDigestService
{
    public function createAlertsForCreatedRecordIds(array $recordIds): array
    {
        $recordIds = collect($recordIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $summary = [
            'records_considered' => 0,
            'watch_profiles_involved' => 0,
            'records_skipped_no_recipient' => 0,
            'recipients_total' => 0,
            'alerts_created' => 0,
            'alerts_failed' => 0,
        ];

        Log::info('[Procynia][WatchAlerts] Starting watch alert digest build.', [
            'record_ids' => $recordIds,
        ]);

        if ($recordIds === []) {
            Log::info('[Procynia][WatchAlerts] No new watch inbox records found for this run.');

            return $summary;
        }

        $records = WatchProfileInboxRecord::query()
            ->whereIn('id', $recordIds)
            ->with([
                'watchProfile:id,customer_id,user_id,department_id,name',
                'watchProfile.user:id,name,email,role,is_active,customer_id,department_id',
            ])
            ->orderBy('watch_profile_id')
            ->orderByDesc('discovered_at')
            ->orderByDesc('id')
            ->get();

        $summary['records_considered'] = $records->count();
        $summary['watch_profiles_involved'] = $records
            ->pluck('watch_profile_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->unique()
            ->count();

        $recipientBuckets = [];

        foreach ($records as $record) {
            $recipients = $this->resolveRecipients($record);

            if ($recipients->isEmpty()) {
                $summary['records_skipped_no_recipient']++;

                Log::warning('[Procynia][WatchAlerts] Skipping record without valid recipient.', [
                    'record_id' => $record->id,
                    'watch_profile_id' => $record->watch_profile_id,
                    'doffin_notice_id' => $record->doffin_notice_id,
                    'owner_scope' => $record->watchProfile?->ownerScope(),
                    'user_id' => $record->user_id,
                    'department_id' => $record->department_id,
                ]);

                continue;
            }

            foreach ($recipients as $recipient) {
                $recipientBuckets[(int) $recipient->id]['recipient'] = $recipient;
                $recipientBuckets[(int) $recipient->id]['records'][(int) $record->id] = $record;
            }
        }

        $summary['recipients_total'] = count($recipientBuckets);

        Log::info('[Procynia][WatchAlerts] Recipient digest groups prepared.', [
            'records_considered' => $summary['records_considered'],
            'watch_profiles_involved' => $summary['watch_profiles_involved'],
            'recipients_total' => $summary['recipients_total'],
            'records_skipped_no_recipient' => $summary['records_skipped_no_recipient'],
        ]);

        foreach ($recipientBuckets as $bucket) {
            /** @var User $recipient */
            $recipient = $bucket['recipient'];
            /** @var Collection<int, WatchProfileInboxRecord> $recipientRecords */
            $recipientRecords = collect($bucket['records'])->values();
            $sections = $this->watchProfileSections($recipientRecords);

            if ($sections === []) {
                continue;
            }

            try {
                $recipient->notify(
                    new WatchInboxDigestNotification($recipient->name, $sections, $recipientRecords->count())
                );

                $summary['alerts_created']++;

                Log::info('[Procynia][WatchAlerts] Created watch alert digest notification.', [
                    'user_id' => $recipient->id,
                    'records' => $recipientRecords->count(),
                    'watch_profiles' => count($sections),
                ]);
            } catch (Throwable $throwable) {
                $summary['alerts_failed']++;

                report($throwable);

                Log::error('[Procynia][WatchAlerts] Failed to create watch alert digest notification.', [
                    'user_id' => $recipient->id,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        Log::info('[Procynia][WatchAlerts] Completed watch alert digest build.', $summary);

        return $summary;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(WatchProfileInboxRecord $record): Collection
    {
        $watchProfile = $record->watchProfile;

        if (! $watchProfile instanceof WatchProfile) {
            return collect();
        }

        return match ($watchProfile->ownerScope()) {
            WatchProfile::OWNER_SCOPE_USER => $this->personalWatchRecipients($watchProfile),
            WatchProfile::OWNER_SCOPE_DEPARTMENT => $this->departmentWatchRecipients($record),
            default => collect(),
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function personalWatchRecipients(WatchProfile $watchProfile): Collection
    {
        $user = $watchProfile->user;

        if (! $user instanceof User || ! $this->isDeliverableRecipient($user)) {
            return collect();
        }

        return collect([$user]);
    }

    /**
     * @return Collection<int, User>
     */
    private function departmentWatchRecipients(WatchProfileInboxRecord $record): Collection
    {
        if ($record->department_id === null) {
            return collect();
        }

        return User::query()
            ->where('customer_id', $record->customer_id)
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->with('departments:id')
            ->get()
            ->filter(fn (User $user): bool => $this->isDeliverableRecipient($user))
            ->filter(fn (User $user): bool => in_array((int) $record->department_id, $user->membershipDepartmentIds(), true))
            ->unique('id')
            ->values();
    }

    private function isDeliverableRecipient(User $user): bool
    {
        return $user->canAccessCustomerFrontend()
            && is_string($user->email)
            && trim($user->email) !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function watchProfileSections(Collection $records): array
    {
        return $records
            ->groupBy('watch_profile_id')
            ->map(function (Collection $group): array {
                /** @var WatchProfileInboxRecord $first */
                $first = $group->first();

                return [
                    'watch_profile_id' => $first->watch_profile_id,
                    'watch_profile_name' => $first->watchProfile?->name ?? 'Watch list',
                    'records' => $group
                        ->sort(function (WatchProfileInboxRecord $left, WatchProfileInboxRecord $right): int {
                            $leftPublication = $left->publication_date?->getTimestamp() ?? 0;
                            $rightPublication = $right->publication_date?->getTimestamp() ?? 0;

                            if ($leftPublication !== $rightPublication) {
                                return $rightPublication <=> $leftPublication;
                            }

                            $leftDiscovered = $left->discovered_at?->getTimestamp() ?? 0;
                            $rightDiscovered = $right->discovered_at?->getTimestamp() ?? 0;

                            if ($leftDiscovered !== $rightDiscovered) {
                                return $rightDiscovered <=> $leftDiscovered;
                            }

                            return $right->id <=> $left->id;
                        })
                        ->values()
                        ->map(fn (WatchProfileInboxRecord $record): array => [
                            'record_id' => $record->id,
                            'doffin_notice_id' => $record->doffin_notice_id,
                            'title' => $record->title,
                            'buyer_name' => $record->buyer_name,
                            'publication_date' => $record->publication_date?->format('d.m.Y'),
                            'deadline' => $record->deadline?->format('d.m.Y'),
                            'external_url' => $record->external_url,
                        ])
                        ->all(),
                ];
            })
            ->sortBy('watch_profile_name')
            ->values()
            ->all();
    }
}
