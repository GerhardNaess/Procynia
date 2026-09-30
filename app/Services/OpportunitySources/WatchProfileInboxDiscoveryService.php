<?php

namespace App\Services\OpportunitySources;

use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\BidWorkflowNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A nightly watch sweep, with the register left out of it.
 *
 * Everything that made Doffin's worker Doffin's has been pushed down into the two subclasses: the
 * criteria it builds, the switches that decide whether it may run at all, and what it accepts as
 * an opportunity somebody can still bid on. What is left is the part that was never about a
 * register — walk the active watch profiles, ask, score, upsert, and tell the owner once.
 *
 * "Tell the owner once" is the load-bearing sentence. A standing watch is swept every night, and
 * the same notice comes back every night until it closes. The insert is the only place newness is
 * actually known, so that is the only place a notification is written; a re-sighting takes the
 * updated branch and says nothing. That was true for Doffin, it is true for TED, and it is true
 * because the identity a record is found by — (source, external_id) — is the same pair for both.
 */
abstract class WatchProfileInboxDiscoveryService
{
    public function __construct(
        protected readonly OpportunitySourceAdapter $sourceAdapter,
        protected readonly WatchProfileRelevanceScorer $relevance,
        protected readonly BidWorkflowNotificationService $notifications,
    ) {}

    /** The log prefix this source writes under, e.g. '[DOFFIN][watch-inbox]'. */
    abstract protected function logChannel(): string;

    /** Why this source may not sweep at all right now, or null when it may. */
    abstract protected function skipReason(): ?string;

    abstract protected function skipReasonLabel(?string $reason): ?string;

    /** @return array<string, mixed> */
    abstract protected function skipLogContext(string $reason): array;

    abstract protected function buildCriteria(WatchProfile $watchProfile): OpportunitySearchCriteria;

    abstract protected function shouldIncludeNotice(WatchProfile $watchProfile, NormalizedNotice $notice): bool;

    /**
     * @return array<string, mixed>
     */
    public function run(?int $watchProfileId = null, string $trigger = 'manual'): array
    {
        $skipReason = $this->skipReason();

        if ($skipReason !== null) {
            Log::info($this->logChannel().' Scheduled watch inbox discovery skipped.', array_merge([
                'trigger' => $trigger,
                'watch_profile_id' => $watchProfileId,
                'skip_reason' => $skipReason,
                'skip_reason_label' => $this->skipReasonLabel($skipReason),
            ], $this->skipLogContext($skipReason)));

            return [
                'status' => 'skipped',
                'source' => $this->sourceAdapter->sourceKey(),
                'skip_reason' => $skipReason,
                'skip_reason_label' => $this->skipReasonLabel($skipReason),
                'trigger' => $trigger,
                'profiles_processed' => 0,
                'profiles_failed' => 0,
                'records_seen' => 0,
                'records_created' => 0,
                'records_updated' => 0,
                'created_record_ids' => [],
            ];
        }

        $summary = [
            'status' => 'success',
            'source' => $this->sourceAdapter->sourceKey(),
            'skip_reason' => null,
            'skip_reason_label' => null,
            'trigger' => $trigger,
            'profiles_processed' => 0,
            'profiles_failed' => 0,
            'records_seen' => 0,
            'records_created' => 0,
            'records_updated' => 0,
            'created_record_ids' => [],
        ];

        WatchProfile::query()
            ->with(['cpvCodes', 'user:id,name', 'department:id,name'])
            ->active()
            ->when($watchProfileId !== null, fn ($query) => $query->whereKey($watchProfileId))
            ->orderBy('id')
            ->get()
            ->each(function (WatchProfile $watchProfile) use (&$summary): void {
                if ($watchProfile->ownerScope() === null) {
                    Log::warning($this->logChannel().' Skipping active watch profile without explicit owner scope.', [
                        'watch_profile_id' => $watchProfile->id,
                    ]);

                    return;
                }

                $summary['profiles_processed']++;

                try {
                    $profileSummary = $this->discoverWatchProfile($watchProfile);

                    $summary['records_seen'] += $profileSummary['records_seen'];
                    $summary['records_created'] += $profileSummary['records_created'];
                    $summary['records_updated'] += $profileSummary['records_updated'];
                    $summary['created_record_ids'] = [
                        ...$summary['created_record_ids'],
                        ...$profileSummary['created_record_ids'],
                    ];
                } catch (Throwable $throwable) {
                    $summary['profiles_failed']++;

                    report($throwable);

                    Log::error($this->logChannel().' Watch profile discovery failed.', [
                        'watch_profile_id' => $watchProfile->id,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            });

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function discoverWatchProfile(WatchProfile $watchProfile): array
    {
        $summary = [
            'records_seen' => 0,
            'records_created' => 0,
            'records_updated' => 0,
            'created_record_ids' => [],
        ];

        $profileSkipReason = $this->profileSkipReason($watchProfile);

        if ($profileSkipReason !== null) {
            Log::info($this->logChannel().' Watch profile not searched.', [
                'watch_profile_id' => $watchProfile->id,
                'reason' => $profileSkipReason,
            ]);

            return $summary;
        }

        $criteria = $this->buildCriteria($watchProfile);
        $page = 1;
        $perPage = $this->perPage();
        $lastPage = 1;

        do {
            $response = $this->sourceAdapter->search($criteria, $page, $perPage);
            $hits = collect($response->notices)
                ->filter(fn (NormalizedNotice $notice): bool => $this->shouldIncludeNotice($watchProfile, $notice))
                ->values();

            $summary['records_seen'] += $hits->count();

            foreach ($hits as $hit) {
                $result = $this->upsertInboxRecord($watchProfile, $hit);

                if (($result['state'] ?? null) === 'created') {
                    $summary['records_created']++;
                    $summary['created_record_ids'][] = $result['record_id'];

                    // The one point where "new" is actually known: the insert, not the re-sighting.
                    // A notice seen again on tomorrow's sweep takes the 'updated' branch and says
                    // nothing, which is what keeps a standing watch from becoming a daily alarm.
                    $this->notifyNewMatch($watchProfile, (int) $result['record_id']);
                }

                if (($result['state'] ?? null) === 'updated') {
                    $summary['records_updated']++;
                }
            }

            $total = $response->numHitsAccessible > 0 ? $response->numHitsAccessible : $hits->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $lastPage = $this->cappedLastPage($watchProfile, $lastPage, $total);
            $page++;
        } while ($page <= $lastPage && $hits->isNotEmpty());

        return $summary;
    }

    /**
     * Why this particular profile is not worth asking about, or null to go ahead.
     *
     * Doffin has no such reason and never did; TED does, because a profile that names nothing is a
     * request for the whole EU.
     */
    protected function profileSkipReason(WatchProfile $watchProfile): ?string
    {
        return null;
    }

    protected function perPage(): int
    {
        return 50;
    }

    /**
     * How many pages a single profile may pull, or null for as many as the register reports.
     *
     * Null is Doffin's answer and is left alone: a Norwegian day's publications are a known
     * quantity, and this has fetched all of them every night for as long as the feature has
     * existed.
     */
    protected function maxPages(): ?int
    {
        return null;
    }

    /** How far back a sweep looks. One night, for both registers. */
    protected function publicationWindowDays(): int
    {
        return 1;
    }

    /**
     * The legacy Doffin column, written only by the register it names.
     *
     * Dual-written for Doffin so anything still reading it keeps working; null for every other
     * source, because borrowing Doffin's vocabulary for a notice that was never in Doffin is how
     * a legacy column turns into a lie.
     */
    protected function legacyDoffinNoticeId(string $externalId): ?string
    {
        return null;
    }

    private function cappedLastPage(WatchProfile $watchProfile, int $lastPage, int $total): int
    {
        $maxPages = $this->maxPages();

        if ($maxPages === null || $lastPage <= $maxPages) {
            return $lastPage;
        }

        Log::info($this->logChannel().' Result set capped for this watch profile.', [
            'watch_profile_id' => $watchProfile->id,
            'reported_total' => $total,
            'pages_available' => $lastPage,
            'pages_fetched' => $maxPages,
        ]);

        return $maxPages;
    }

    private function notifyNewMatch(WatchProfile $watchProfile, int $recordId): void
    {
        $record = WatchProfileInboxRecord::query()->find($recordId);

        if ($record instanceof WatchProfileInboxRecord) {
            $this->notifications->watchProfileMatched($watchProfile, $record);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function upsertInboxRecord(WatchProfile $watchProfile, NormalizedNotice $notice): ?array
    {
        $noticeId = $this->stringOrNull($notice->externalId);

        if ($noticeId === null) {
            return null;
        }

        $now = now();
        // The identity a record is found by is the source and what that source calls it — not a
        // Doffin id. The adapter is the authority for the key, so there is one spelling of it.
        $record = WatchProfileInboxRecord::query()->firstOrNew([
            'watch_profile_id' => $watchProfile->id,
            'source' => $this->sourceAdapter->sourceKey(),
            'external_id' => $noticeId,
        ]);
        $isNew = ! $record->exists;

        $record->fill([
            'doffin_notice_id' => $this->legacyDoffinNoticeId($noticeId),
            'customer_id' => $watchProfile->customer_id,
            'user_id' => $watchProfile->user_id,
            'department_id' => $watchProfile->department_id,
            'title' => $notice->title ?? $noticeId,
            'buyer_name' => $notice->buyerName,
            'publication_date' => $this->dateTimeOrNull($notice->publicationDate),
            'deadline' => $this->dateTimeOrNull($notice->deadline),
            'external_url' => $notice->sourceUrl,
            'relevance_score' => $this->relevance->score($watchProfile, $notice),
            'discovered_at' => $record->discovered_at ?? $now,
            'last_seen_at' => $now,
            'raw_payload' => $notice->rawPayload,
        ]);

        $record->save();

        return [
            'state' => $isNew ? 'created' : 'updated',
            'record_id' => (int) $record->id,
        ];
    }

    protected function publishedWithinWindow(NormalizedNotice $notice): bool
    {
        $publicationDate = $this->dateTimeOrNull($notice->publicationDate);

        if (! $publicationDate instanceof Carbon) {
            return false;
        }

        return $publicationDate->greaterThanOrEqualTo(now()->subDays($this->publicationWindowDays()));
    }

    protected function dateTimeOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
