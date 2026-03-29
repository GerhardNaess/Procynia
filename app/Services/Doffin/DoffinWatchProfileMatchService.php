<?php

namespace App\Services\Doffin;

use App\Models\Notice;
use App\Models\WatchProfile;
use App\Models\WatchProfileMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DoffinWatchProfileMatchService
{
    public function __construct(
        private readonly DoffinRelevanceService $relevanceService,
    ) {
    }

    public function run(?int $customerId = null, ?int $watchProfileId = null): array
    {
        $profiles = $this->watchProfilesQuery($customerId, $watchProfileId)->get();

        if ($profiles->isEmpty()) {
            return [
                'profiles_processed' => 0,
                'notices_evaluated' => 0,
                'matches_created' => 0,
                'matches_updated' => 0,
            ];
        }

        $preparedProfiles = $profiles
            ->map(fn (WatchProfile $profile): array => [
                'profile' => $profile,
                'inputs' => $this->relevanceService->resolveWatchProfileInputs($profile),
            ])
            ->values();

        $noticeQuery = $this->noticesQuery();
        $summary = [
            'profiles_processed' => $preparedProfiles->count(),
            'notices_evaluated' => (clone $noticeQuery)->count(),
            'matches_created' => 0,
            'matches_updated' => 0,
        ];
        $matchedAt = now();

        $noticeQuery->chunkById(100, function (Collection $notices) use (&$summary, $matchedAt, $preparedProfiles): void {
            foreach ($notices as $notice) {
                if (! $notice instanceof Notice) {
                    continue;
                }

                $preparedNotice = $this->prepareNotice($notice);

                foreach ($preparedProfiles as $preparedProfile) {
                    $profile = $preparedProfile['profile'];
                    $match = $this->scorePreparedNotice($preparedNotice, $preparedProfile['inputs']);

                    if ($match['score'] <= 0) {
                        continue;
                    }

                    $wasCreated = $this->upsertMatch($profile, $notice, $match, $matchedAt);
                    $summary[$wasCreated ? 'matches_created' : 'matches_updated']++;
                }
            }
        }, 'id');

        return $summary;
    }

    public function newHitsLastDayCount(int $customerId): int
    {
        return WatchProfileMatch::query()
            ->where('customer_id', $customerId)
            ->where('first_seen_at', '>=', now()->subDay())
            ->count();
    }

    private function watchProfilesQuery(?int $customerId = null, ?int $watchProfileId = null): Builder
    {
        return WatchProfile::query()
            ->where('is_active', true)
            ->whereNotNull('customer_id')
            ->when($customerId !== null, fn (Builder $query): Builder => $query->where('customer_id', $customerId))
            ->when($watchProfileId !== null, fn (Builder $query): Builder => $query->whereKey($watchProfileId))
            ->with(['cpvCodes', 'department:id,customer_id'])
            ->orderBy('id');
    }

    private function noticesQuery(): Builder
    {
        return Notice::query()
            ->with('cpvCodes')
            ->orderBy('id');
    }

    private function prepareNotice(Notice $notice): array
    {
        return [
            'search_text' => $this->noticeSearchText($notice),
            'cpv_codes' => $notice->cpvCodes
                ->pluck('cpv_code')
                ->map(fn (mixed $code): ?string => $this->normalizeCpvCode($code))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    private function scorePreparedNotice(array $preparedNotice, array $watchProfileInputs): array
    {
        $score = 0;
        $matchedCpvCount = 0;
        $matchedKeywordCount = 0;
        $cpvRuleMap = $this->cpvRuleMap($watchProfileInputs['cpv_rules'] ?? []);

        foreach ($preparedNotice['cpv_codes'] as $cpvCode) {
            if (! array_key_exists($cpvCode, $cpvRuleMap)) {
                continue;
            }

            $score += $cpvRuleMap[$cpvCode];
            $matchedCpvCount++;
        }

        $keywordWeight = max(1, (int) config('doffin.relevance.weights.keyword_match', 20));

        foreach ($watchProfileInputs['keywords'] ?? [] as $keyword) {
            $normalizedKeyword = Str::lower(trim((string) $keyword));

            if ($normalizedKeyword === '') {
                continue;
            }

            if (! str_contains($preparedNotice['search_text'], $normalizedKeyword)) {
                continue;
            }

            $score += $keywordWeight;
            $matchedKeywordCount++;
        }

        return [
            'score' => $score,
            'matched_cpv_count' => $matchedCpvCount,
            'matched_keywords_count' => $matchedKeywordCount,
        ];
    }

    private function upsertMatch(WatchProfile $profile, Notice $notice, array $match, Carbon $matchedAt): bool
    {
        $record = WatchProfileMatch::query()->firstOrNew([
            'watch_profile_id' => $profile->id,
            'notice_id' => $notice->id,
        ]);
        $wasCreated = ! $record->exists;

        $record->fill([
            'customer_id' => $profile->customer_id,
            'department_id' => $profile->department_id,
            'score' => (int) $match['score'],
            'matched_keywords_count' => (int) $match['matched_keywords_count'],
            'matched_cpv_count' => (int) $match['matched_cpv_count'],
            'last_seen_at' => $matchedAt,
        ]);

        if ($wasCreated) {
            $record->first_seen_at = $matchedAt;
        }

        $record->save();

        return $wasCreated;
    }

    private function cpvRuleMap(array $rules): array
    {
        $map = [];

        foreach ($rules as $rule) {
            $code = $this->normalizeCpvCode(data_get($rule, 'code'));

            if ($code === null) {
                continue;
            }

            $map[$code] = ($map[$code] ?? 0) + max(0, (int) data_get($rule, 'weight', 0));
        }

        return $map;
    }

    private function noticeSearchText(Notice $notice): string
    {
        return Str::lower(trim(implode(' ', array_filter([
            $notice->title,
            $notice->description,
            $notice->buyer_name,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''))));
    }

    private function normalizeCpvCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
