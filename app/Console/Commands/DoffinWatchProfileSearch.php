<?php

namespace App\Console\Commands;

use App\Models\WatchProfile;
use App\Services\Doffin\DoffinLiveSearchService;
use Illuminate\Console\Command;
use Throwable;

class DoffinWatchProfileSearch extends Command
{
    protected $signature = 'doffin:watch-profile-search {watchProfileId}';

    protected $description = 'Run live Doffin search for a single watch profile and return scoped live results.';

    public function handle(DoffinLiveSearchService $liveSearchService): int
    {
        $watchProfileId = $this->resolveWatchProfileId();

        if ($watchProfileId === false) {
            return self::INVALID;
        }

        $watchProfile = WatchProfile::query()
            ->with('cpvCodes')
            ->whereKey($watchProfileId)
            ->first();

        if (! $watchProfile instanceof WatchProfile) {
            $this->error("Watch profile {$watchProfileId} was not found.");

            return self::FAILURE;
        }

        if (! $watchProfile->is_active) {
            $this->warn("Watch profile {$watchProfile->id} is inactive. No live Doffin search was run.");

            return self::SUCCESS;
        }

        $filters = $this->buildFilters($watchProfile);

        $this->line("watch_profile_id: {$watchProfile->id}");
        $this->line("name: {$watchProfile->name}");
        $this->line('customer_id: '.($watchProfile->customer_id === null ? 'null' : (string) $watchProfile->customer_id));
        $this->line('department_id: '.($watchProfile->department_id === null ? 'null' : (string) $watchProfile->department_id));
        $this->line('keywords_filter: '.($filters['keywords'] !== '' ? str_replace(["\r", "\n"], ['', ' | '], $filters['keywords']) : 'none'));
        $this->line('cpv_filter: '.($filters['cpv'] !== '' ? $filters['cpv'] : 'none'));
        $this->line("status_filter: {$filters['status']}");

        try {
            $response = $liveSearchService->search($filters, 1, 15);
        } catch (Throwable $throwable) {
            report($throwable);

            $this->error('Live Doffin watch profile search failed.');
            $this->line($throwable->getMessage());

            return self::FAILURE;
        }

        $hits = collect($response['hits'] ?? [])
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->values();

        $results = $hits
            ->map(fn (array $hit): array => $this->transformHit($hit, $watchProfile))
            ->all();

        $total = (int) ($response['numHitsAccessible'] ?? $response['numHitsTotal'] ?? count($results));

        $this->line("hits: {$total}");

        if ($results === []) {
            $this->line('results: []');

            return self::SUCCESS;
        }

        $encodedResults = json_encode(
            $results,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($encodedResults)) {
            $this->error('Unable to encode live Doffin search results.');

            return self::FAILURE;
        }

        $this->line('results:');
        $this->line($encodedResults);

        return self::SUCCESS;
    }

    private function resolveWatchProfileId(): int|false
    {
        $value = $this->argument('watchProfileId');

        if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value <= 0) {
            $this->error('The watchProfileId argument must be a positive integer.');

            return false;
        }

        return (int) $value;
    }

    private function buildFilters(WatchProfile $watchProfile): array
    {
        return [
            'q' => '',
            'organization_name' => '',
            'cpv' => $this->resolveCpvFilter($watchProfile),
            'keywords' => $this->resolveKeywordsFilter($watchProfile),
            'publication_period' => '',
            'status' => 'ACTIVE',
        ];
    }

    private function resolveKeywordsFilter(WatchProfile $watchProfile): string
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

    private function resolveCpvFilter(WatchProfile $watchProfile): string
    {
        return $watchProfile->cpvCodes
            ->pluck('cpv_code')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->implode(',');
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

    private function transformHit(array $hit, WatchProfile $watchProfile): array
    {
        $buyers = collect($hit['buyer'] ?? [])
            ->filter(fn (mixed $buyer): bool => is_array($buyer))
            ->map(fn (array $buyer): string => trim((string) ($buyer['name'] ?? '')))
            ->filter(fn (string $buyer): bool => $buyer !== '')
            ->unique()
            ->values();
        $noticeId = $this->stringOrNull($hit['id'] ?? null);

        return [
            'notice_id' => $noticeId,
            'title' => $this->stringOrNull($hit['heading'] ?? null),
            'buyer_name' => $buyers->isEmpty() ? null : $buyers->implode(', '),
            'publication_date' => $this->stringOrNull($hit['publicationDate'] ?? $hit['issueDate'] ?? null),
            'deadline' => $this->stringOrNull($hit['deadline'] ?? null),
            'external_url' => $noticeId === null ? null : $this->publicNoticeUrl($noticeId),
            'customer_id' => $watchProfile->customer_id === null ? null : (int) $watchProfile->customer_id,
            'department_id' => $watchProfile->department_id === null ? null : (int) $watchProfile->department_id,
            'watch_profile_id' => (int) $watchProfile->id,
        ];
    }

    private function publicNoticeUrl(string $noticeId): ?string
    {
        if ($noticeId === '') {
            return null;
        }

        return sprintf((string) config('doffin.public_notice_url'), rawurlencode($noticeId));
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
