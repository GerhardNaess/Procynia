<?php

namespace App\Services\Doffin;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        if (($validationFailure = $this->validatePublicationDateRange($publicationDateFrom, $publicationDateTo, $page, $perPage)) !== null) {
            return $validationFailure;
        }

        [$publicationDateFrom, $publicationDateTo] = $this->normalizePublicationDateRange(
            $publicationDateFrom,
            $publicationDateTo,
            $publicationPeriod,
        );
        $buyerIds = [];
        $buyerFallbackUsed = false;

        if ($organizationName !== '') {
            $buyerLookupResponse = $this->postSearch([
                'numHitsPerPage' => 50,
                'page' => 1,
                'searchString' => $organizationName,
                'sortBy' => 'RELEVANCE',
                'facets' => $this->facets('', '', [], [], []),
            ], 'buyer_lookup', [
                'organization_name' => $organizationName,
                'query' => $query,
            ]);

            if (! ($buyerLookupResponse['ok'] ?? true)) {
                if (($buyerLookupResponse['error_type'] ?? null) === 'invalid_request') {
                    return $this->withResponsePagination($buyerLookupResponse, $page, $perPage);
                }

                $buyerFallbackUsed = true;

                Log::warning('[DOFFIN][service] Buyer lookup failed, continuing without a buyer facet.', [
                    'endpoint' => $this->endpoint(),
                    'organization_name' => $organizationName,
                    'query' => $query,
                    'error_type' => $buyerLookupResponse['error_type'] ?? null,
                    'upstream_status' => $buyerLookupResponse['upstream_status'] ?? null,
                    'request_id' => $buyerLookupResponse['meta']['request_id'] ?? null,
                    'live_search' => true,
                ]);
            } else {
                $buyerIds = $this->resolveBuyerIdsFromHits($buyerLookupResponse['items'] ?? [], $organizationName);
            }
        }

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
            return $this->fetchSinglePageSearch($payload, $page, $perPage, $buyerFallbackUsed);
        }

        return $this->harvestAndFilterKeywordSearch($payload, $keywords, $page, $perPage, $buyerFallbackUsed);
    }

    private function resolveBuyerIdsFromHits(array $hits, string $organizationName): array
    {
        $needle = Str::lower($organizationName);
        $digitsNeedle = preg_replace('/\D+/', '', $organizationName) ?? '';

        return collect($hits)
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
        return $this->sanitizePayload([
            'searchString' => $this->buildSearchString($query, $organizationName),
            'sortBy' => 'RELEVANCE',
            'facets' => $this->facets(
                $publicationDateFrom,
                $publicationDateTo,
                $buyerIds,
                $cpvCodes,
                $status !== null ? [$status] : [],
            ),
        ]);
    }

    private function fetchSinglePageSearch(array $payload, int $page, int $perPage, bool $fallbackUsed = false): array
    {
        $response = $this->postSearch($payload + [
            'numHitsPerPage' => $perPage,
            'page' => $page,
        ], 'live_search', [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        if (! ($response['ok'] ?? true)) {
            return $this->markFallbackUsed($response, $fallbackUsed);
        }

        $items = $this->filterResultsByKeywords($response['items'] ?? [], []);
        $numHitsTotal = (int) ($response['numHitsTotal'] ?? count($items));
        $numHitsAccessible = (int) ($response['numHitsAccessible'] ?? count($items));

        $response = $this->successResponse($items, $page, $perPage, $numHitsTotal, $numHitsAccessible, $fallbackUsed, $response['meta'] ?? []);
        $response['page'] = $page;
        $response['perPage'] = $perPage;

        return $response;
    }

    private function harvestAndFilterKeywordSearch(array $payload, array $keywords, int $page, int $perPage, bool $fallbackUsed = false): array
    {
        $firstPageResponse = $this->postSearch($payload + [
            'numHitsPerPage' => $perPage,
            'page' => 1,
        ], 'live_search', [
            'page' => 1,
            'per_page' => $perPage,
        ]);

        if (! ($firstPageResponse['ok'] ?? true)) {
            return $this->markFallbackUsed($firstPageResponse, $fallbackUsed);
        }

        $allHits = $this->normalizeHits($firstPageResponse['items'] ?? []);
        $accessibleTotal = $this->searchResultTotal($firstPageResponse);
        $lastPage = max(1, (int) ceil($accessibleTotal / $perPage));

        for ($currentPage = 2; $currentPage <= $lastPage; $currentPage++) {
            $pageResponse = $this->postSearch($payload + [
                'numHitsPerPage' => $perPage,
                'page' => $currentPage,
            ], 'live_search', [
                'page' => $currentPage,
                'per_page' => $perPage,
            ]);

            if (! ($pageResponse['ok'] ?? true)) {
                return $this->markFallbackUsed($pageResponse, $fallbackUsed);
            }

            $allHits = array_merge($allHits, $this->normalizeHits($pageResponse['items'] ?? []));
        }

        $filteredHits = $this->filterResultsByKeywords($this->deduplicateHits($allHits), $keywords);
        $filteredTotal = count($filteredHits);
        $currentPage = max(1, min($page, max(1, (int) ceil($filteredTotal / $perPage))));
        $offset = ($currentPage - 1) * $perPage;

        return $this->successResponse(array_slice($filteredHits, $offset, $perPage), $currentPage, $perPage, $filteredTotal, $filteredTotal, $fallbackUsed, $firstPageResponse['meta'] ?? []);
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
        $accessibleTotal = (int) ($response['numHitsAccessible'] ?? $response['meta']['numHitsAccessible'] ?? 0);

        if ($accessibleTotal > 0) {
            return $accessibleTotal;
        }

        $total = (int) ($response['numHitsTotal'] ?? $response['meta']['numHitsTotal'] ?? 0);

        if ($total > 0) {
            return $total;
        }

        return count($this->normalizeHits($response['items'] ?? $response['hits'] ?? []));
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

    private function isValidDateString(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private function postSearch(array $payload, string $operation, array $context = []): array
    {
        $payload = $this->sanitizePayload($payload);
        $page = max(1, (int) ($payload['page'] ?? $context['page'] ?? 1));
        $perPage = max(1, (int) ($payload['numHitsPerPage'] ?? $context['per_page'] ?? 15));
        $logContext = array_merge($context, [
            'endpoint' => $this->endpoint(),
            'operation' => $operation,
            'page' => $page,
            'per_page' => $perPage,
            'payload' => $payload,
            'live_search' => true,
        ]);

        Log::debug('[DOFFIN][service] Sending live search request to Doffin.', $logContext);

        try {
            $response = $this->request()
                ->acceptJson()
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $exception) {
            $errorType = $this->connectionErrorType($exception);
            $requestId = null;

            Log::error('[DOFFIN][service] Live search request failed before a response was received.', $logContext + [
                'error_type' => $errorType,
                'message' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);

            return $this->failureResponse(
                $errorType,
                $this->errorMessageForType($errorType),
                null,
                $page,
                $perPage,
                false,
                $logContext + [
                    'request_id' => $requestId,
                    'exception_message' => $exception->getMessage(),
                ],
            );
        }

        $status = $response->status();
        $requestId = $this->upstreamRequestId($response);

        if (! $response->successful()) {
            $errorType = $status >= 500 ? 'upstream_unavailable' : 'invalid_request';
            $logLevel = $status >= 500 ? 'error' : 'warning';

            Log::$logLevel('[DOFFIN][service] Live search request returned an error response.', $logContext + [
                'error_type' => $errorType,
                'status' => $status,
                'request_id' => $requestId,
                'response_body' => $response->body(),
            ]);

            return $this->failureResponse(
                $errorType,
                $this->errorMessageForType($errorType),
                $status,
                $page,
                $perPage,
                false,
                $logContext + [
                    'request_id' => $requestId,
                    'response_body' => $response->body(),
                ],
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! array_key_exists('hits', $data) || ! is_array($data['hits'])) {
            Log::error('[DOFFIN][service] Live search returned an unexpected response payload.', $logContext + [
                'status' => $status,
                'request_id' => $requestId,
                'response_body' => $response->body(),
            ]);

            return $this->failureResponse(
                'unexpected_response',
                $this->errorMessageForType('unexpected_response'),
                $status,
                $page,
                $perPage,
                false,
                $logContext + [
                    'request_id' => $requestId,
                    'response_body' => $response->body(),
                ],
            );
        }

        $items = $this->normalizeHits($data['hits']);
        $numHitsTotal = (int) ($data['numHitsTotal'] ?? $data['numHitsAccessible'] ?? count($items));
        $numHitsAccessible = (int) ($data['numHitsAccessible'] ?? $numHitsTotal);

        Log::debug('[DOFFIN][service] Received live search response from Doffin.', [
            'endpoint' => $this->endpoint(),
            'operation' => $operation,
            'status' => $status,
            'request_id' => $requestId,
            'hit_count' => count($items),
            'num_hits_total' => $numHitsTotal,
            'num_hits_accessible' => $numHitsAccessible,
            'live_search' => true,
        ]);

        return $this->successResponse(
            $items,
            (int) ($data['page'] ?? $page),
            (int) ($data['numHitsPerPage'] ?? $perPage),
            $numHitsTotal,
            $numHitsAccessible,
            false,
            $logContext + [
                'request_id' => $requestId,
            ],
            $status,
        );
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

    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalized = $this->sanitizePayloadValue($value);

            if ($this->isEmptyPayloadValue($normalized)) {
                continue;
            }

            $sanitized[$key] = $normalized;
        }

        return $sanitized;
    }

    private function sanitizePayloadValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = Str::squish($value);

            return $value === '' ? null : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            $normalized = $this->sanitizePayloadValue($item);

            if ($this->isEmptyPayloadValue($normalized)) {
                continue;
            }

            $sanitized[$key] = $normalized;
        }

        if ($sanitized === []) {
            return null;
        }

        return array_is_list($sanitized) ? array_values($sanitized) : $sanitized;
    }

    private function isEmptyPayloadValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function successResponse(array $items, int $page, int $perPage, int $numHitsTotal, int $numHitsAccessible, bool $fallbackUsed, array $meta = [], ?int $upstreamStatus = null): array
    {
        $response = [
            'ok' => true,
            'items' => $items,
            'hits' => $items,
            'error_type' => null,
            'error_message' => null,
            'upstream_status' => $upstreamStatus,
            'fallback_used' => $fallbackUsed,
            'page' => $page,
            'perPage' => $perPage,
            'numHitsTotal' => $numHitsTotal,
            'numHitsAccessible' => $numHitsAccessible,
            'meta' => array_merge([
                'page' => $page,
                'perPage' => $perPage,
                'numHitsTotal' => $numHitsTotal,
                'numHitsAccessible' => $numHitsAccessible,
                'fallback_used' => $fallbackUsed,
            ], array_filter($meta, fn (mixed $value): bool => $value !== null)),
        ];

        if ($upstreamStatus !== null) {
            $response['meta']['upstream_status'] = $upstreamStatus;
        }

        return $response;
    }

    private function failureResponse(string $errorType, string $errorMessage, ?int $upstreamStatus, int $page, int $perPage, bool $fallbackUsed = false, array $meta = []): array
    {
        $response = [
            'ok' => false,
            'items' => [],
            'hits' => [],
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'upstream_status' => $upstreamStatus,
            'fallback_used' => $fallbackUsed,
            'page' => $page,
            'perPage' => $perPage,
            'numHitsTotal' => 0,
            'numHitsAccessible' => 0,
            'meta' => array_merge([
                'page' => $page,
                'perPage' => $perPage,
                'fallback_used' => $fallbackUsed,
            ], array_filter($meta, fn (mixed $value): bool => $value !== null)),
        ];

        if ($upstreamStatus !== null) {
            $response['meta']['upstream_status'] = $upstreamStatus;
        }

        return $response;
    }

    private function markFallbackUsed(array $response, bool $fallbackUsed): array
    {
        if (! $fallbackUsed) {
            return $response;
        }

        $response['fallback_used'] = true;
        $response['meta']['fallback_used'] = true;

        return $response;
    }

    private function withResponsePagination(array $response, int $page, int $perPage): array
    {
        $response['page'] = $page;
        $response['perPage'] = $perPage;
        $response['meta']['page'] = $page;
        $response['meta']['perPage'] = $perPage;

        return $response;
    }

    private function validatePublicationDateRange(string $publicationDateFrom, string $publicationDateTo, int $page, int $perPage): ?array
    {
        if ($publicationDateFrom === '' && $publicationDateTo === '') {
            return null;
        }

        $invalidFrom = $publicationDateFrom !== '' && ! $this->isValidDateString($publicationDateFrom);
        $invalidTo = $publicationDateTo !== '' && ! $this->isValidDateString($publicationDateTo);

        if ($invalidFrom || $invalidTo) {
            Log::warning('[DOFFIN][service] Live search rejected due to invalid publication date filters.', [
                'endpoint' => $this->endpoint(),
                'publication_date_from' => $publicationDateFrom,
                'publication_date_to' => $publicationDateTo,
                'page' => $page,
                'per_page' => $perPage,
                'live_search' => true,
            ]);

            return $this->failureResponse(
                'invalid_request',
                'Doffin avviste søket fordi datoene var ugyldige.',
                null,
                $page,
                $perPage,
            );
        }

        if ($publicationDateFrom !== '' && $publicationDateTo !== '' && $publicationDateFrom > $publicationDateTo) {
            Log::warning('[DOFFIN][service] Live search rejected due to an invalid publication date range.', [
                'endpoint' => $this->endpoint(),
                'publication_date_from' => $publicationDateFrom,
                'publication_date_to' => $publicationDateTo,
                'page' => $page,
                'per_page' => $perPage,
                'live_search' => true,
            ]);

            return $this->failureResponse(
                'invalid_request',
                'Doffin avviste søket fordi datoperioden var ugyldig.',
                null,
                $page,
                $perPage,
            );
        }

        return null;
    }

    private function errorMessageForType(string $errorType): string
    {
        return match ($errorType) {
            'invalid_request' => 'Doffin avviste søket.',
            'upstream_unavailable' => 'Doffin er midlertidig utilgjengelig.',
            'timeout' => 'Doffin svarte ikke i tide.',
            'connection_error' => 'Klarte ikke å koble til Doffin.',
            'unexpected_response' => 'Doffin returnerte et uventet svar.',
            default => 'Doffin-søket kunne ikke fullføres.',
        };
    }

    private function connectionErrorType(ConnectionException $exception): string
    {
        $message = Str::lower($exception->getMessage());

        if (
            str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28')
        ) {
            return 'timeout';
        }

        return 'connection_error';
    }

    private function upstreamRequestId(Response $response): ?string
    {
        foreach (['x-request-id', 'x-correlation-id', 'request-id', 'traceparent'] as $header) {
            $value = trim((string) $response->header($header));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
