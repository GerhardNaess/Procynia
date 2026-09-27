<?php

namespace App\Services\Doffin;

use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\BidWorkflowNotificationService;
use App\Services\OpportunitySources\NormalizedNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DoffinWatchProfileInboxDiscoveryService
{
    public function __construct(
        private readonly DoffinSourceAdapter $sourceAdapter,
        private readonly DoffinImportControlService $importControlService,
        private readonly BidWorkflowNotificationService $notifications,
    ) {}

    public function run(?int $watchProfileId = null, string $trigger = 'manual'): array
    {
        $skipReason = $this->importControlService->watchInboxDiscoverySkipReason();

        if ($skipReason !== null) {
            $environmentEnabled = $this->importControlService->isWatchInboxDiscoveryEnvironmentEnabled();
            $adminEnabled = $environmentEnabled ? $this->importControlService->isWatchInboxDiscoveryAdminEnabled() : null;
            $apiConfigured = $environmentEnabled && $adminEnabled
                ? $this->importControlService->hasRequiredWatchInboxDiscoveryApiConfiguration()
                : null;

            Log::info('[DOFFIN][watch-inbox] Scheduled watch inbox discovery skipped.', [
                'trigger' => $trigger,
                'watch_profile_id' => $watchProfileId,
                'skip_reason' => $skipReason,
                'skip_reason_label' => $this->importControlService->watchInboxDiscoverySkipReasonLabel($skipReason),
                'environment_enabled' => $environmentEnabled,
                'admin_enabled' => $adminEnabled,
                'api_configured' => $apiConfigured,
            ]);

            return [
                'status' => 'skipped',
                'skip_reason' => $skipReason,
                'skip_reason_label' => $this->importControlService->watchInboxDiscoverySkipReasonLabel($skipReason),
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
                    Log::warning('[DOFFIN][watch-inbox] Skipping active watch profile without explicit owner scope.', [
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

                    Log::error('[DOFFIN][watch-inbox] Watch profile discovery failed.', [
                        'watch_profile_id' => $watchProfile->id,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            });

        return $summary;
    }

    public function discoverWatchProfile(WatchProfile $watchProfile): array
    {
        $filters = $this->buildFilters($watchProfile);
        $summary = [
            'records_seen' => 0,
            'records_created' => 0,
            'records_updated' => 0,
            'created_record_ids' => [],
        ];
        $page = 1;
        $perPage = 50;
        $lastPage = 1;

        do {
            $response = $this->sourceAdapter->search($filters, $page, $perPage);
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
            $page++;
        } while ($page <= $lastPage && $hits->isNotEmpty());

        return $summary;
    }

    private function notifyNewMatch(WatchProfile $watchProfile, int $recordId): void
    {
        $record = WatchProfileInboxRecord::query()->find($recordId);

        if ($record instanceof WatchProfileInboxRecord) {
            $this->notifications->watchProfileMatched($watchProfile, $record);
        }
    }

    private function upsertInboxRecord(WatchProfile $watchProfile, NormalizedNotice $notice): ?array
    {
        $noticeId = $this->stringOrNull($notice->externalId);

        if ($noticeId === null) {
            return null;
        }

        $now = now();
        $record = WatchProfileInboxRecord::query()->firstOrNew([
            'watch_profile_id' => $watchProfile->id,
            'doffin_notice_id' => $noticeId,
        ]);
        $isNew = ! $record->exists;

        $record->fill([
            'customer_id' => $watchProfile->customer_id,
            'user_id' => $watchProfile->user_id,
            'department_id' => $watchProfile->department_id,
            'title' => $notice->title ?? $noticeId,
            'buyer_name' => $notice->buyerName,
            'publication_date' => $this->dateTimeOrNull($notice->publicationDate),
            'deadline' => $this->dateTimeOrNull($notice->deadline),
            'external_url' => $notice->sourceUrl,
            'relevance_score' => $this->calculateRelevanceScore($watchProfile, $notice),
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

    private function buildFilters(WatchProfile $watchProfile): array
    {
        return [
            'q' => '',
            'organization_name' => '',
            'cpv' => $watchProfile->cpvCodes
                ->pluck('cpv_code')
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(fn (string $value): string => trim($value))
                ->unique()
                ->sort()
                ->values()
                ->implode(','),
            'keywords' => $this->keywordsFilter($watchProfile),
            'keywords_mode' => 'any',
            'publication_period' => '1',
            'status' => 'ACTIVE',
        ];
    }

    private function shouldIncludeNotice(WatchProfile $watchProfile, NormalizedNotice $notice): bool
    {
        return $this->hasEligibleStatus($notice)
            && $this->publishedWithinLastDay($notice)
            && $this->calculateRelevanceScore($watchProfile, $notice) > 0;
    }

    private function hasEligibleStatus(NormalizedNotice $notice): bool
    {
        $status = strtoupper(trim((string) ($notice->status ?? '')));

        if ($status === '') {
            return true;
        }

        return $status === 'ACTIVE';
    }

    private function publishedWithinLastDay(NormalizedNotice $notice): bool
    {
        $publicationDate = $this->dateTimeOrNull($notice->publicationDate);

        if (! $publicationDate instanceof Carbon) {
            return false;
        }

        return $publicationDate->greaterThanOrEqualTo(now()->subDay());
    }

    private function keywordsFilter(WatchProfile $watchProfile): string
    {
        $rawKeywords = $watchProfile->getRawOriginal('keywords');

        if (is_string($rawKeywords)) {
            $trimmed = trim($rawKeywords);

            if ($trimmed === '') {
                return '';
            }

            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return implode("\n", $this->meaningfulStringValues($decoded));
            }

            return $trimmed;
        }

        if (is_array($watchProfile->keywords)) {
            return implode("\n", $this->meaningfulStringValues($watchProfile->keywords));
        }

        return '';
    }

    private function calculateRelevanceScore(WatchProfile $watchProfile, NormalizedNotice $notice): int
    {
        $keywordMatches = 0;
        $cpvMatches = 0;
        $score = 0;
        $titleAndDescription = Str::lower(Str::squish(
            trim((string) ($notice->title ?? '')).' '.trim((string) ($notice->description ?? ''))
        ));
        $buyerHaystack = Str::lower($notice->buyerName ?? '');
        $keywords = collect($this->resolvedKeywords($watchProfile));
        $hitCpvCodes = $this->noticeCpvCodes($notice);

        foreach ($keywords as $keyword) {
            $normalizedKeyword = Str::lower($keyword);

            if ($normalizedKeyword === '') {
                continue;
            }

            if (str_contains($titleAndDescription, $normalizedKeyword)) {
                $keywordMatches++;
                $score += 20;

                continue;
            }

            if ($buyerHaystack !== '' && str_contains($buyerHaystack, $normalizedKeyword)) {
                $keywordMatches++;
                $score += 8;
            }
        }

        foreach ($watchProfile->cpvCodes as $cpvRule) {
            $cpvCode = preg_replace('/\D+/', '', (string) $cpvRule->cpv_code) ?? '';

            if ($cpvCode === '' || ! $hitCpvCodes->contains($cpvCode)) {
                continue;
            }

            $cpvMatches++;
            $score += max(1, (int) $cpvRule->weight);
        }

        if ($keywordMatches > 0 && $cpvMatches > 0) {
            $score += 10;
        }

        return $score;
    }

    private function noticeCpvCodes(NormalizedNotice $notice): Collection
    {
        return collect($notice->cpvCodes)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (string|int|float|bool $value): string => preg_replace('/\D+/', '', (string) $value) ?? '')
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values();
    }

    private function meaningfulStringValues(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (string|int|float|bool $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function resolvedKeywords(WatchProfile $watchProfile): array
    {
        $rawKeywords = $watchProfile->getRawOriginal('keywords');

        if (is_string($rawKeywords)) {
            $trimmed = trim($rawKeywords);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return $this->meaningfulStringValues($decoded);
            }

            return [$trimmed];
        }

        if (is_array($watchProfile->keywords)) {
            return $this->meaningfulStringValues($watchProfile->keywords);
        }

        return [];
    }

    private function dateTimeOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
