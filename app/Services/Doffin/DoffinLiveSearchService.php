<?php

namespace App\Services\Doffin;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class DoffinLiveSearchService
{
    private const SUPPORTED_STATUSES = [
        'ACTIVE',
        'EXPIRED',
        'AWARDED',
        'CANCELLED',
    ];

    public function search(array $filters, int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $query = Str::squish((string) ($filters['q'] ?? ''));
        $keywords = $this->normalizeKeywords((string) ($filters['keywords'] ?? ''));
        $organizationName = Str::squish((string) ($filters['organization_name'] ?? ''));
        $cpvCodes = $this->normalizeCpvCodes((string) ($filters['cpv'] ?? ''));
        $status = $this->normalizeStatus((string) ($filters['status'] ?? ''));
        $publicationDateFrom = Str::squish((string) ($filters['publication_date_from'] ?? ''));
        $publicationDateTo = Str::squish((string) ($filters['publication_date_to'] ?? ''));
        $publicationPeriod = Str::squish((string) ($filters['publication_period'] ?? ''));
        [$publicationDateFrom, $publicationDateTo] = $this->normalizePublicationDateRange(
            $publicationDateFrom,
            $publicationDateTo,
            $publicationPeriod,
        );
        $buyerIds = $organizationName !== ''
            ? $this->resolveBuyerIds($organizationName)
            : [];

        $payload = $this->buildSearchPayload(
            $query,
            $organizationName,
            $publicationDateFrom,
            $publicationDateTo,
            $buyerIds,
            $cpvCodes,
            $status,
        );

        if ($keywords === []) {
            return $this->fetchSinglePageSearch($payload, $page, $perPage);
        }

        return $this->harvestAndFilterKeywordSearch($payload, $keywords, $page, $perPage);
    }

    private function resolveBuyerIds(string $organizationName): array
    {
        $response = $this->postSearch([
            'numHitsPerPage' => 50,
            'page' => 1,
            'searchString' => $organizationName,
            'sortBy' => 'RELEVANCE',
            'facets' => $this->facets('', '', [], [], []),
        ]);

        $needle = Str::lower($organizationName);
        $digitsNeedle = preg_replace('/\D+/', '', $organizationName) ?? '';

        return collect($response['hits'] ?? [])
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->flatMap(fn (array $hit): array => is_array($hit['buyer'] ?? null) ? $hit['buyer'] : [])
            ->filter(fn (mixed $buyer): bool => is_array($buyer) && $this->buyerMatches($buyer, $needle, $digitsNeedle))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function buyerMatches(array $buyer, string $needle, string $digitsNeedle): bool
    {
        $name = Str::lower(Str::squish((string) ($buyer['name'] ?? '')));
        $organizationId = preg_replace('/\D+/', '', (string) ($buyer['organizationId'] ?? '')) ?? '';

        if ($needle !== '' && $name !== '' && str_contains($name, $needle)) {
            return true;
        }

        return $digitsNeedle !== ''
            && $organizationId !== ''
            && str_contains($organizationId, $digitsNeedle);
    }

    private function facets(string $publicationDateFrom, string $publicationDateTo, array $buyerIds, array $cpvCodes, array $statuses): array
    {
        return [
            'cpvCodesLabel' => ['checkedItems' => []],
            'cpvCodesId' => ['checkedItems' => $cpvCodes],
            'type' => ['checkedItems' => []],
            'status' => ['checkedItems' => $statuses],
            'contractNature' => ['checkedItems' => []],
            'publicationDate' => [
                'from' => $publicationDateFrom !== '' ? $publicationDateFrom : null,
                'to' => $publicationDateTo !== '' ? $publicationDateTo : null,
            ],
            'location' => ['checkedItems' => []],
            'buyer' => ['checkedItems' => $buyerIds],
            'winner' => ['checkedItems' => []],
        ];
    }

    private function normalizeCpvCodes(string $value): array
    {
        return collect(preg_split('/[\s,;]+/', $value) ?: [])
            ->map(fn (string $code): string => preg_replace('/\D+/', '', $code) ?? '')
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeKeywords(string $value): array
    {
        $seen = [];

        return collect(preg_split('/[,;\n]+/', $value) ?: [])
            ->map(fn (string $keyword): string => Str::squish($keyword))
            ->filter(fn (string $keyword): bool => $keyword !== '')
            ->filter(function (string $keyword) use (&$seen): bool {
                $normalized = Str::lower($keyword);

                if (in_array($normalized, $seen, true)) {
                    return false;
                }

                $seen[] = $normalized;

                return true;
            })
            ->values()
            ->all();
    }

    private function buildSearchString(string $query, string $organizationName): string
    {
        if ($query !== '') {
            return $query;
        }

        return $organizationName !== '' ? $organizationName : '';
    }

    private function buildSearchPayload(
        string $query,
        string $organizationName,
        string $publicationDateFrom,
        string $publicationDateTo,
        array $buyerIds,
        array $cpvCodes,
        ?string $status,
    ): array {
        return [
            'searchString' => $this->buildSearchString($query, $organizationName),
            'sortBy' => 'RELEVANCE',
            'facets' => $this->facets(
                $publicationDateFrom,
                $publicationDateTo,
                $buyerIds,
                $cpvCodes,
                $status !== null ? [$status] : [],
            ),
        ];
    }

    private function fetchSinglePageSearch(array $payload, int $page, int $perPage): array
    {
        $response = $this->postSearch($payload + [
            'numHitsPerPage' => $perPage,
            'page' => $page,
        ]);

        $response['hits'] = $this->filterResultsByKeywords($response['hits'] ?? [], []);
        $response['page'] = $page;
        $response['perPage'] = $perPage;

        return $response;
    }

    private function harvestAndFilterKeywordSearch(array $payload, array $keywords, int $page, int $perPage): array
    {
        $firstPageResponse = $this->postSearch($payload + [
            'numHitsPerPage' => $perPage,
            'page' => 1,
        ]);

        $allHits = $this->normalizeHits($firstPageResponse['hits'] ?? []);
        $accessibleTotal = $this->searchResultTotal($firstPageResponse);
        $lastPage = max(1, (int) ceil($accessibleTotal / $perPage));

        for ($currentPage = 2; $currentPage <= $lastPage; $currentPage++) {
            $pageResponse = $this->postSearch($payload + [
                'numHitsPerPage' => $perPage,
                'page' => $currentPage,
            ]);

            $allHits = array_merge($allHits, $this->normalizeHits($pageResponse['hits'] ?? []));
        }

        $filteredHits = $this->filterResultsByKeywords($this->deduplicateHits($allHits), $keywords);
        $filteredTotal = count($filteredHits);
        $currentPage = max(1, min($page, max(1, (int) ceil($filteredTotal / $perPage))));
        $offset = ($currentPage - 1) * $perPage;

        return [
            'page' => $currentPage,
            'perPage' => $perPage,
            'numHitsTotal' => $filteredTotal,
            'numHitsAccessible' => $filteredTotal,
            'hits' => array_slice($filteredHits, $offset, $perPage),
        ];
    }

    private function normalizeHits(mixed $hits): array
    {
        return collect(is_array($hits) ? $hits : [])
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->values()
            ->all();
    }

    private function deduplicateHits(array $hits): array
    {
        $seen = [];
        $uniqueHits = [];

        foreach ($hits as $hit) {
            $key = (string) ($hit['id'] ?? '');

            if ($key === '') {
                $key = md5(serialize($hit));
            }

            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $uniqueHits[] = $hit;
        }

        return $uniqueHits;
    }

    private function searchResultTotal(array $response): int
    {
        $accessibleTotal = (int) ($response['numHitsAccessible'] ?? 0);

        if ($accessibleTotal > 0) {
            return $accessibleTotal;
        }

        $total = (int) ($response['numHitsTotal'] ?? 0);

        if ($total > 0) {
            return $total;
        }

        return count($this->normalizeHits($response['hits'] ?? []));
    }

    /**
     * Apply the keyword filter locally so Doffin only handles the canonical broad search string.
     */
    private function filterResultsByKeywords(array $hits, array $keywords): array
    {
        $normalizedKeywords = collect($keywords)
            ->map(fn (string $keyword): string => Str::lower(Str::squish($keyword)))
            ->filter(fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values()
            ->all();

        if ($normalizedKeywords === []) {
            return $hits;
        }

        return collect($hits)
            ->filter(function (mixed $hit) use ($normalizedKeywords): bool {
                return is_array($hit) && $this->hitMatchesAllKeywords($hit, $normalizedKeywords);
            })
            ->values()
            ->all();
    }

    private function hitMatchesAllKeywords(array $hit, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (! $this->hitContainsKeyword($hit, $keyword)) {
                return false;
            }
        }

        return true;
    }

    private function hitContainsKeyword(array $hit, string $keyword): bool
    {
        foreach ($this->searchableTextForHit($hit) as $text) {
            if ($text !== '' && str_contains(Str::lower($text), $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function searchableTextForHit(array $hit): array
    {
        $buyerNames = collect($hit['buyer'] ?? [])
            ->filter(fn (mixed $buyer): bool => is_array($buyer))
            ->map(fn (array $buyer): string => Str::squish((string) ($buyer['name'] ?? '')))
            ->filter(fn (string $buyerName): bool => $buyerName !== '')
            ->values()
            ->all();

        return array_values(array_filter([
            Str::squish((string) ($hit['heading'] ?? '')),
            Str::squish((string) ($hit['description'] ?? '')),
            ...$buyerNames,
        ], fn (string $text): bool => $text !== ''));
    }

    private function normalizeStatus(string $value): ?string
    {
        $status = strtoupper(trim($value));

        return in_array($status, self::SUPPORTED_STATUSES, true) ? $status : null;
    }

    private function normalizePublicationDateRange(string $publicationDateFrom, string $publicationDateTo, string $publicationPeriod): array
    {
        if ($publicationDateFrom !== '' || $publicationDateTo !== '') {
            return [$publicationDateFrom, $publicationDateTo];
        }

        [$fromDate, $toDate] = $this->publicationRange($publicationPeriod);

        return [
            $fromDate ?? '',
            $toDate ?? '',
        ];
    }

    private function publicationRange(string $publicationPeriod): array
    {
        if (! in_array($publicationPeriod, ['1', '7', '30', '90', '365'], true)) {
            return [null, null];
        }

        $days = (int) $publicationPeriod;

        return [
            now()->subDays($days)->toDateString(),
            now()->toDateString(),
        ];
    }

    private function postSearch(array $payload): array
    {
        Log::debug('[DOFFIN][service] Sending live search request to Doffin.', [
            'endpoint' => $this->endpoint(),
            'payload' => $payload,
        ]);

        $response = $this->request()
            ->acceptJson()
            ->post($this->endpoint(), $payload);

        $this->ensureSuccessfulResponse($response, $payload);

        $data = $response->json();

        if (! is_array($data)) {
            Log::error('Doffin live search returned a non-array JSON payload.', [
                'payload' => $payload,
            ]);

            throw new RuntimeException('Doffin live search returned invalid JSON data.');
        }

        Log::debug('[DOFFIN][service] Received live search response from Doffin.', [
            'status' => $response->status(),
            'hit_count' => count($data['hits'] ?? []),
            'num_hits_total' => $data['numHitsTotal'] ?? null,
            'num_hits_accessible' => $data['numHitsAccessible'] ?? null,
        ]);

        return $data;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl((string) config('doffin.live_search_base_url'))
            ->timeout((int) config('doffin.timeout'))
            ->withUserAgent((string) config('doffin.user_agent'))
            ->withHeaders([
                'Accept-Language' => 'nb-NO,nb;q=0.9,en;q=0.8',
                'Content-Type' => 'application/json',
            ]);

        $apiKey = config('doffin.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withHeaders([
                'Ocp-Apim-Subscription-Key' => $apiKey,
            ]);
        }

        return $request;
    }

    private function endpoint(): string
    {
        return '/'.ltrim((string) config('doffin.live_search_endpoint'), '/');
    }

    private function ensureSuccessfulResponse(Response $response, array $payload): void
    {
        if ($response->successful()) {
            return;
        }

        Log::error('Doffin live search request failed.', [
            'status' => $response->status(),
            'body' => $response->body(),
            'payload' => $payload,
        ]);

        throw new RuntimeException("Doffin live search failed with status {$response->status()}.");
    }
}
