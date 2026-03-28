<?php

namespace App\Services\Doffin;

use App\Models\Department;
use App\Models\Notice;
use App\Models\SyncLog;
use App\Models\User;
use App\Models\WatchProfile;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DoffinRelevanceService
{
    public function scoreNotice(string $noticeId): array
    {
        $startedAt = now();
        $notice = Notice::query()
            ->with('cpvCodes')
            ->where('notice_id', $noticeId)
            ->first();

        try {
            if ($notice === null) {
                throw new RuntimeException("Notice {$noticeId} was not found.");
            }

            $department = $this->resolveAuthenticatedDepartment();
            $result = $department instanceof Department
                ? $this->evaluateForDepartment($notice, $department)
                : $this->evaluateAcrossActiveWatchProfiles($notice);

            DB::transaction(function () use ($notice, $result, $startedAt): void {
                $notice->fill([
                    'relevance_score' => $result['relevance_score'],
                    'relevance_level' => $result['relevance_level'],
                    'score_breakdown' => $result['score_breakdown'],
                ]);
                $notice->save();

                SyncLog::query()->create([
                    'job_type' => 'score',
                    'status' => 'success',
                    'notice_id' => $notice->id,
                    'message' => 'Notice scoring completed',
                    'context' => sprintf(
                        'Score=%d level=%s matched_cpv=%d codes=%s',
                        $result['relevance_score'],
                        $result['relevance_level'],
                        count($result['matched_cpv_codes']),
                        $result['matched_cpv_codes'] === [] ? 'none' : implode(',', $result['matched_cpv_codes']),
                    ),
                    'started_at' => $startedAt,
                    'finished_at' => now(),
                ]);
            });

            Log::info('Scored Doffin notice successfully.', [
                'notice_id' => $noticeId,
                'relevance_score' => $result['relevance_score'],
                'relevance_level' => $result['relevance_level'],
                'matched_cpv_codes' => $result['matched_cpv_codes'],
                'applied_rules' => $result['applied_rules'],
                'score_breakdown' => $result['score_breakdown'],
                'watch_profile_id' => $result['watch_profile_id'] ?? null,
                'watch_profile_name' => $result['watch_profile_name'] ?? null,
            ]);

            return $result;
        } catch (Throwable $throwable) {
            Log::error('Failed to score Doffin notice.', [
                'notice_id' => $noticeId,
                'notice_row_id' => $notice?->id,
                'error' => $throwable->getMessage(),
            ]);

            $this->storeFailureLog($notice, $throwable, $startedAt);

            throw $throwable;
        }
    }

    public function evaluateForDepartment(Notice $notice, Department $department): array
    {
        $notice = $notice->loadMissing('cpvCodes');

        $watchProfiles = $department->relationLoaded('watchProfiles')
            ? $department->watchProfiles
            : $department->watchProfiles()
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

        if ($watchProfiles instanceof EloquentCollection) {
            $watchProfiles->loadMissing(['cpvCodes', 'department']);
        }

        $activeProfiles = collect($watchProfiles)
            ->filter(function (mixed $profile) use ($department): bool {
                if (! $profile instanceof WatchProfile || ! $profile->is_active) {
                    return false;
                }

                if ($department->customer_id === null || $profile->customer_id === null) {
                    return false;
                }

                return (int) $profile->customer_id === (int) $department->customer_id;
            })
            ->values();

        return $this->bestResultForWatchProfiles($notice, $activeProfiles, $department);
    }

    public function evaluateForWatchProfile(Notice $notice, WatchProfile $watchProfile): array
    {
        $notice = $notice->loadMissing('cpvCodes');
        $watchProfile->loadMissing(['cpvCodes', 'department']);

        return $this->evaluateNoticeAgainstInputs(
            $notice,
            $watchProfile->department,
            $watchProfile,
            $this->resolveWatchProfileInputs($watchProfile),
        );
    }

    public function resolveWatchProfileInputs(WatchProfile $watchProfile): array
    {
        $watchProfile->loadMissing('cpvCodes');

        $cpvRules = [];

        foreach ($watchProfile->cpvCodes as $cpvCode) {
            $code = $this->normalizeText($cpvCode->cpv_code);

            if ($code === null) {
                continue;
            }

            $cpvRules[$code] = [
                'code' => $code,
                'weight' => (int) $cpvCode->weight,
            ];
        }

        return [
            'cpv_rules' => array_values($cpvRules),
            'keywords' => $this->normalizeList($watchProfile->keywords ?? [], true),
        ];
    }

    private function evaluateAcrossActiveWatchProfiles(Notice $notice): array
    {
        $watchProfiles = WatchProfile::query()
            ->where('is_active', true)
            ->with(['cpvCodes', 'department'])
            ->orderBy('id')
            ->get();

        return $this->bestResultForWatchProfiles($notice->loadMissing('cpvCodes'), $watchProfiles);
    }

    private function bestResultForWatchProfiles(
        Notice $notice,
        iterable $watchProfiles,
        ?Department $department = null,
    ): array {
        $bestResult = $this->emptyResult($notice, $department);

        foreach ($watchProfiles as $watchProfile) {
            if (! $watchProfile instanceof WatchProfile) {
                continue;
            }

            $candidateResult = $this->evaluateForWatchProfile($notice, $watchProfile);

            if ($this->isBetterResult($candidateResult, $bestResult)) {
                $bestResult = $candidateResult;
            }
        }

        return $bestResult;
    }

    private function isBetterResult(array $candidateResult, array $bestResult): bool
    {
        if (($candidateResult['relevance_score'] ?? 0) > ($bestResult['relevance_score'] ?? 0)) {
            return true;
        }

        return ($candidateResult['relevance_score'] ?? 0) > 0
            && ($candidateResult['relevance_score'] ?? 0) === ($bestResult['relevance_score'] ?? 0)
            && ($bestResult['watch_profile_id'] ?? null) === null
            && ($candidateResult['watch_profile_id'] ?? null) !== null;
    }

    private function evaluateNoticeAgainstInputs(
        Notice $notice,
        ?Department $department,
        ?WatchProfile $watchProfile,
        array $inputs,
    ): array {
        $noticeCpvCodes = $notice->cpvCodes
            ->pluck('cpv_code')
            ->map(fn ($code) => $this->normalizeText($code))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $cpvRuleMap = $this->cpvRulesMap($inputs['cpv_rules'] ?? []);

        $matchedCpvCodes = [];
        $matchedCpvRules = [];
        $appliedRules = [];
        $score = 0;
        $cpvMatchScore = 0;
        $matchedKeywords = [];
        $matchedKeywordRules = [];
        $keywordMatchScore = 0;

        foreach ($noticeCpvCodes as $code) {
            if (! array_key_exists($code, $cpvRuleMap)) {
                continue;
            }

            $matchWeight = $cpvRuleMap[$code];
            $matchedCpvCodes[] = $code;
            $matchedCpvRules[] = [
                'code' => $code,
                'weight' => $matchWeight,
            ];
            $cpvMatchScore += $matchWeight;
            $score += $matchWeight;
            $appliedRules[] = "cpv_match:{$code}={$matchWeight}";
        }

        $matchedKeywords = $this->matchedKeywords($notice, $inputs['keywords'] ?? []);
        $keywordRuleWeight = (int) config('doffin.relevance.weights.keyword_match', 0);
        $keywordMatchScore = $matchedKeywords === []
            ? 0
            : $keywordRuleWeight;

        if ($matchedKeywords !== []) {
            $matchedKeywordRules = collect($matchedKeywords)
                ->map(fn (string $keyword): array => [
                    'keyword' => $keyword,
                    'weight' => $keywordRuleWeight,
                ])
                ->values()
                ->all();
        }

        if ($keywordMatchScore > 0) {
            $score += $keywordMatchScore;
            $appliedRules[] = sprintf(
                'keyword_match=%d keywords=%s',
                $keywordMatchScore,
                implode(',', $matchedKeywords),
            );
        }

        $hasWatchProfileMatch = $cpvMatchScore > 0 || $keywordMatchScore > 0;
        $statusBonus = 0;
        $deadlineBonus = 0;
        $typeBonus = 0;
        $learningData = [
            'adjustment' => 0,
            'interesting_count' => 0,
            'ignored_count' => 0,
            'sample_size' => 0,
        ];
        $learningAdjustment = 0;

        if ($hasWatchProfileMatch) {
            $statusBonus = $this->statusBonus($notice);

            if ($statusBonus > 0) {
                $score += $statusBonus;
                $appliedRules[] = "status_active={$statusBonus}";
            }

            $deadlineBonus = $this->deadlineBonus($notice);

            if ($deadlineBonus > 0) {
                $score += $deadlineBonus;
                $appliedRules[] = "deadline_future={$deadlineBonus}";
            }

            $typeBonus = $this->typeBonus($notice);

            if ($typeBonus > 0) {
                $score += $typeBonus;
                $appliedRules[] = "notice_type_competition={$typeBonus}";
            }

            $learningData = $this->learningAdjustment($notice, $noticeCpvCodes, $department);
            $learningAdjustment = $learningData['adjustment'];

            if ($learningAdjustment !== 0) {
                $score += $learningAdjustment;
                $appliedRules[] = sprintf(
                    'learning_adjustment=%d (interesting=%d ignored=%d)',
                    $learningAdjustment,
                    $learningData['interesting_count'],
                    $learningData['ignored_count'],
                );
            }
        }

        $relevanceLevel = $this->relevanceLevel($score);
        $scoreBreakdown = $this->buildScoreBreakdown(
            $department,
            $watchProfile,
            $cpvMatchScore,
            $keywordMatchScore,
            $deadlineBonus,
            $typeBonus,
            $statusBonus,
            $learningAdjustment,
            $learningData['sample_size'],
            $matchedCpvCodes,
            $matchedCpvRules,
            $matchedKeywords,
            $matchedKeywordRules,
            $appliedRules,
        );

        return [
            'notice_id' => $notice->notice_id,
            'relevance_score' => $score,
            'relevance_level' => $relevanceLevel,
            'matched_cpv_codes' => $matchedCpvCodes,
            'matched_keywords' => $matchedKeywords,
            'applied_rules' => $appliedRules,
            'score_breakdown' => $scoreBreakdown,
            'visible' => $score > 0,
            'watch_profile_id' => $watchProfile?->id,
            'watch_profile_name' => $watchProfile?->name,
        ];
    }

    private function emptyResult(
        Notice $notice,
        ?Department $department = null,
        ?WatchProfile $watchProfile = null,
    ): array {
        return [
            'notice_id' => $notice->notice_id,
            'relevance_score' => 0,
            'relevance_level' => 'low',
            'matched_cpv_codes' => [],
            'applied_rules' => [],
            'score_breakdown' => $this->buildScoreBreakdown(
                $department,
                $watchProfile,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                [],
                [],
                [],
                [],
                [],
            ),
            'visible' => false,
            'watch_profile_id' => $watchProfile?->id,
            'watch_profile_name' => $watchProfile?->name,
        ];
    }

    private function buildScoreBreakdown(
        ?Department $department,
        ?WatchProfile $watchProfile,
        int $cpvMatchScore,
        int $keywordMatchScore,
        int $deadlineBonus,
        int $typeBonus,
        int $statusBonus,
        int $learningAdjustment,
        int $learningSampleSize,
        array $matchedCpvCodes,
        array $matchedCpvRules,
        array $matchedKeywords,
        array $matchedKeywordRules,
        array $appliedRules,
    ): array {
        return [
            'watch_profile_context' => [
                'watch_profile_id' => $watchProfile?->id,
                'watch_profile_name' => $watchProfile?->name,
                'department_id' => $department?->id,
                'department_name' => $department?->name,
                'used_watch_profile_rules' => $watchProfile instanceof WatchProfile,
            ],
            'cpv_match' => $cpvMatchScore,
            'keyword_match' => $keywordMatchScore,
            'deadline_bonus' => $deadlineBonus,
            'type_bonus' => $typeBonus,
            'status_bonus' => $statusBonus,
            'learning_adjustment' => $learningAdjustment,
            'learning_sample_size' => $learningSampleSize,
            'matched_cpv_codes' => $matchedCpvCodes,
            'matched_cpv_rules' => $matchedCpvRules,
            'matched_keywords' => $matchedKeywords,
            'matched_keyword_rules' => $matchedKeywordRules,
            'applied_rules' => $appliedRules,
        ];
    }

    private function cpvRulesMap(array $cpvRules): array
    {
        $ruleMap = [];

        foreach ($cpvRules as $cpvRule) {
            $code = $this->normalizeText(data_get($cpvRule, 'code'));

            if ($code === null) {
                continue;
            }

            $ruleMap[$code] = ($ruleMap[$code] ?? 0) + max(0, (int) data_get($cpvRule, 'weight', 0));
        }

        return $ruleMap;
    }

    private function statusBonus(Notice $notice): int
    {
        $status = $this->normalizeText($notice->status);

        if ($status === null) {
            return 0;
        }

        $activeStatuses = array_map(
            static fn (mixed $value): string => Str::lower(trim((string) $value)),
            config('doffin.relevance.active_statuses', []),
        );

        return in_array(Str::lower($status), $activeStatuses, true)
            ? (int) config('doffin.relevance.weights.status_bonus', 10)
            : 0;
    }

    private function deadlineBonus(Notice $notice): int
    {
        return $notice->deadline !== null && $notice->deadline->isFuture()
            ? (int) config('doffin.relevance.weights.deadline_bonus', 10)
            : 0;
    }

    private function typeBonus(Notice $notice): int
    {
        $noticeType = Str::lower($this->normalizeText($notice->notice_type) ?? '');
        $noticeSubtype = $this->normalizeText($notice->notice_subtype);

        $competitionTypes = array_map(
            static fn (mixed $value): string => Str::lower(trim((string) $value)),
            config('doffin.relevance.competition_types', []),
        );

        $competitionSubtypes = array_map(
            static fn (mixed $value): string => trim((string) $value),
            config('doffin.relevance.competition_subtypes', []),
        );

        if (in_array($noticeType, $competitionTypes, true)) {
            return (int) config('doffin.relevance.weights.type_bonus', 5);
        }

        if ($noticeSubtype !== null && in_array($noticeSubtype, $competitionSubtypes, true)) {
            return (int) config('doffin.relevance.weights.type_bonus', 5);
        }

        return 0;
    }

    private function learningAdjustment(Notice $notice, array $currentCpvCodes, ?Department $department): array
    {
        if (
            $currentCpvCodes === [] ||
            ! $department instanceof Department
        ) {
            return [
                'adjustment' => 0,
                'interesting_count' => 0,
                'ignored_count' => 0,
                'sample_size' => 0,
            ];
        }

        $historyCounts = Notice::query()
            ->whereKeyNot($notice->getKey())
            ->whereIn('internal_status', ['interesting', 'ignored'])
            ->whereHas('decisionByUser', fn ($query) => $query->where('department_id', $department->id))
            ->whereHas('cpvCodes', fn ($query) => $query->whereIn('cpv_code', $currentCpvCodes))
            ->select('internal_status', DB::raw('count(*) as aggregate'))
            ->groupBy('internal_status')
            ->pluck('aggregate', 'internal_status');

        $interestingCount = (int) ($historyCounts['interesting'] ?? 0);
        $ignoredCount = (int) ($historyCounts['ignored'] ?? 0);
        $sampleSize = $interestingCount + $ignoredCount;
        $adjustmentValue = (int) config('doffin.relevance.weights.learning_adjustment', 10);
        $adjustment = 0;

        if ($sampleSize >= 5) {
            if ($interestingCount > $ignoredCount) {
                $adjustment = $adjustmentValue;
            } elseif ($ignoredCount > $interestingCount) {
                $adjustment = -$adjustmentValue;
            }
        }

        return [
            'adjustment' => max(-10, min(10, $adjustment)),
            'interesting_count' => $interestingCount,
            'ignored_count' => $ignoredCount,
            'sample_size' => $sampleSize,
        ];
    }

    private function resolveAuthenticatedDepartment(): ?Department
    {
        $currentUser = auth()->user();

        if (! $currentUser instanceof User || $currentUser->department_id === null) {
            return null;
        }

        return $currentUser->department()->first();
    }

    private function matchedKeywords(Notice $notice, array $keywords): array
    {
        if ($keywords === []) {
            return [];
        }

        $haystack = Str::lower(trim(implode(' ', array_filter([
            $notice->title,
            $notice->description,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''))));

        if ($haystack === '') {
            return [];
        }

        return collect($keywords)
            ->filter(static fn (string $keyword): bool => str_contains($haystack, $keyword))
            ->values()
            ->all();
    }

    private function relevanceLevel(int $score): string
    {
        if ($score >= (int) config('doffin.relevance.levels.high', 40)) {
            return 'high';
        }

        if ($score >= (int) config('doffin.relevance.levels.medium', 15)) {
            return 'medium';
        }

        return 'low';
    }

    private function storeFailureLog(?Notice $notice, Throwable $throwable, $startedAt): void
    {
        try {
            SyncLog::query()->create([
                'job_type' => 'score',
                'status' => 'failed',
                'notice_id' => $notice?->id,
                'message' => 'Notice scoring failed',
                'context' => $throwable->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        } catch (Throwable $loggingThrowable) {
            Log::error('Failed to store Doffin scoring failure log.', [
                'notice_row_id' => $notice?->id,
                'logging_error' => $loggingThrowable->getMessage(),
            ]);
        }
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+$/u', '', $value);

        return $trimmed === null || $trimmed === '' ? null : $trimmed;
    }

    private function normalizeList(array $values, bool $lowercase = false): array
    {
        return collect($values)
            ->map(function (mixed $value) use ($lowercase): ?string {
                $normalized = $this->normalizeText(is_scalar($value) ? (string) $value : null);

                if ($normalized === null) {
                    return null;
                }

                return $lowercase ? Str::lower($normalized) : $normalized;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
