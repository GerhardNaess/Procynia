<?php

namespace App\Services\Doffin;

use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DoffinWatchProfileInboxDiscoveryService
{
    public function __construct(
        private readonly DoffinLiveSearchService $liveSearchService,
    ) {
    }

    public function run(?int $watchProfileId = null): array
    {
        $summary = [
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
            $response = $this->liveSearchService->search($filters, $page, $perPage);
            $hits = collect($response['hits'] ?? [])
                ->filter(fn (mixed $hit): bool => is_array($hit))
                ->filter(fn (array $hit): bool => $this->shouldIncludeHit($watchProfile, $hit))
                ->values();

            $summary['records_seen'] += $hits->count();

            foreach ($hits as $hit) {
                $result = $this->upsertInboxRecord($watchProfile, $hit);

                if (($result['state'] ?? null) === 'created') {
                    $summary['records_created']++;
                    $summary['created_record_ids'][] = $result['record_id'];
                }

                if (($result['state'] ?? null) === 'updated') {
                    $summary['records_updated']++;
                }
            }

            $total = (int) ($response['numHitsAccessible'] ?? $response['numHitsTotal'] ?? $hits->count());
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page++;
        } while ($page <= $lastPage && $hits->isNotEmpty());

        return $summary;
    }

    private function upsertInboxRecord(WatchProfile $watchProfile, array $hit): ?array
    {
        $noticeId = $this->stringOrNull($hit['id'] ?? null);

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
            'title' => $this->stringOrNull($hit['heading'] ?? null) ?? $noticeId,
            'buyer_name' => $this->buyerName($hit),
            'publication_date' => $this->dateTimeOrNull($hit['publicationDate'] ?? $hit['issueDate'] ?? null),
            'deadline' => $this->dateTimeOrNull($hit['deadline'] ?? null),
            'external_url' => $this->publicNoticeUrl($noticeId),
            'relevance_score' => $this->calculateRelevanceScore($watchProfile, $hit),
            'discovered_at' => $record->discovered_at ?? $now,
            'last_seen_at' => $now,
            'raw_payload' => $hit,
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
            'publication_period' => '1',
            'status' => 'ACTIVE',
        ];
    }

    private function shouldIncludeHit(WatchProfile $watchProfile, array $hit): bool
    {
        return $this->hasEligibleStatus($hit)
            && $this->publishedWithinLastDay($hit)
            && $this->calculateRelevanceScore($watchProfile, $hit) > 0;
    }

    private function hasEligibleStatus(array $hit): bool
    {
        $status = strtoupper(trim((string) ($hit['status'] ?? '')));

        if ($status === '') {
            return true;
        }

        return $status === 'ACTIVE';
    }

    private function publishedWithinLastDay(array $hit): bool
    {
        $publicationDate = $this->dateTimeOrNull($hit['publicationDate'] ?? $hit['issueDate'] ?? null);

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

    private function calculateRelevanceScore(WatchProfile $watchProfile, array $hit): int
    {
        $keywordMatches = 0;
        $cpvMatches = 0;
        $score = 0;
        $titleAndDescription = Str::lower(Str::squish(
            trim((string) ($hit['heading'] ?? '')).' '.trim((string) ($hit['description'] ?? ''))
        ));
        $buyerHaystack = Str::lower($this->buyerName($hit) ?? '');
        $keywords = collect($this->resolvedKeywords($watchProfile));
        $hitCpvCodes = $this->hitCpvCodes($hit);

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

    private function hitCpvCodes(array $hit): Collection
    {
        return collect([
            ...((array) ($hit['cpvCodes'] ?? [])),
            $hit['mainCpvCode'] ?? null,
        ])
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

    private function buyerName(array $hit): ?string
    {
        $buyers = collect($hit['buyer'] ?? [])
            ->filter(fn (mixed $buyer): bool => is_array($buyer))
            ->map(fn (array $buyer): string => trim((string) ($buyer['name'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        return $buyers->isEmpty() ? null : $buyers->implode(', ');
    }

    private function publicNoticeUrl(string $noticeId): ?string
    {
        if ($noticeId === '') {
            return null;
        }

        return sprintf((string) config('doffin.public_notice_url'), rawurlencode($noticeId));
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
