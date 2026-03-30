<?php

namespace App\Services\Doffin;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DoffinPublicClient
{
    public function search(array $filters, int $page = 1, ?int $perPage = null): array
    {
        $payload = [
            'numHitsPerPage' => $perPage ?? $this->defaultPerPage(),
            'page' => max(1, $page),
            'searchString' => Str::squish((string) ($filters['q'] ?? '')),
            'sortBy' => (string) ($filters['sort_by'] ?? 'RELEVANCE'),
            'facets' => $this->facets($filters),
        ];

        return $this->postJson(
            'search',
            $this->searchRequest(),
            $this->searchEndpoint(),
            $payload,
        );
    }

    public function suggest(string $searchString, array $filters = []): array
    {
        $payload = [
            'searchString' => Str::squish($searchString),
            'facets' => $this->facets($filters),
        ];

        return $this->postJson(
            'suggest',
            $this->searchRequest(),
            $this->suggestEndpoint(),
            $payload,
        );
    }

    public function noticeDetail(string $noticeId): array
    {
        $normalizedNoticeId = trim($noticeId);

        if ($normalizedNoticeId === '') {
            throw new RuntimeException('Doffin notice detail requires a notice id.');
        }

        Log::debug('[DOFFIN][public-client] Fetching notice detail.', [
            'endpoint' => $this->noticeEndpoint(),
            'notice_id' => $normalizedNoticeId,
        ]);

        try {
            $response = $this->noticeRequest()
                ->acceptJson()
                ->get($this->noticeEndpoint().'/'.rawurlencode($normalizedNoticeId));
        } catch (Throwable $throwable) {
            Log::error('[DOFFIN][public-client] Notice detail request failed before response.', [
                'notice_id' => $normalizedNoticeId,
                'message' => $throwable->getMessage(),
            ]);

            throw new RuntimeException('Doffin notice detail request failed.', 0, $throwable);
        }

        $this->ensureSuccessfulResponse($response, 'notice_detail', [
            'notice_id' => $normalizedNoticeId,
        ]);

        $data = $response->json();

        if (! is_array($data)) {
            Log::error('[DOFFIN][public-client] Notice detail returned invalid JSON.', [
                'notice_id' => $normalizedNoticeId,
            ]);

            throw new RuntimeException('Doffin notice detail returned invalid JSON data.');
        }

        return $data;
    }

    private function postJson(string $operation, PendingRequest $request, string $endpoint, array $payload): array
    {
        Log::debug('[DOFFIN][public-client] Sending request.', [
            'operation' => $operation,
            'endpoint' => $endpoint,
            'payload' => $payload,
        ]);

        try {
            $response = $request
                ->acceptJson()
                ->post($endpoint, $payload);
        } catch (Throwable $throwable) {
            Log::error('[DOFFIN][public-client] Request failed before response.', [
                'operation' => $operation,
                'endpoint' => $endpoint,
                'message' => $throwable->getMessage(),
            ]);

            throw new RuntimeException("Doffin public {$operation} request failed.", 0, $throwable);
        }

        $this->ensureSuccessfulResponse($response, $operation, [
            'endpoint' => $endpoint,
            'payload' => $payload,
        ]);

        $data = $response->json();

        if (! is_array($data)) {
            Log::error('[DOFFIN][public-client] Request returned invalid JSON.', [
                'operation' => $operation,
                'endpoint' => $endpoint,
            ]);

            throw new RuntimeException("Doffin public {$operation} returned invalid JSON data.");
        }

        return $data;
    }

    private function searchRequest(): PendingRequest
    {
        return $this->request($this->searchBaseUrl());
    }

    private function noticeRequest(): PendingRequest
    {
        return $this->request($this->noticeBaseUrl());
    }

    private function request(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->timeout((int) config('doffin.timeout'))
            ->retry(
                (int) config('doffin.public_client.retry_times', 3),
                (int) config('doffin.public_client.retry_sleep_ms', 250),
            )
            ->withUserAgent((string) config('doffin.user_agent'))
            ->withHeaders([
                'Accept-Language' => 'nb-NO,nb;q=0.9,en;q=0.8',
                'Content-Type' => 'application/json',
            ]);
    }

    private function searchBaseUrl(): string
    {
        $baseUrl = trim((string) config('doffin.public_search_base_url', ''));

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        return 'https://api.doffin.no/webclient/api/v2/search-api';
    }

    private function noticeBaseUrl(): string
    {
        $baseUrl = trim((string) config('doffin.public_notice_base_url', ''));

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        return 'https://api.doffin.no/webclient/api/v2/notices-api';
    }

    private function searchEndpoint(): string
    {
        return $this->normalizeEndpoint(config('doffin.public_search_endpoint', '/search'), '/search');
    }

    private function suggestEndpoint(): string
    {
        return $this->normalizeEndpoint(config('doffin.public_suggest_endpoint', '/search/suggest'), '/search/suggest');
    }

    private function noticeEndpoint(): string
    {
        return $this->normalizeEndpoint(config('doffin.public_notice_endpoint', '/notices'), '/notices');
    }

    private function normalizeEndpoint(mixed $value, string $fallback): string
    {
        $endpoint = trim((string) $value);

        if ($endpoint === '') {
            $endpoint = $fallback;
        }

        return '/'.ltrim($endpoint, '/');
    }

    private function defaultPerPage(): int
    {
        return max(1, (int) config('doffin.public_client.per_page', 50));
    }

    private function facets(array $filters): array
    {
        return [
            'cpvCodesLabel' => ['checkedItems' => []],
            'cpvCodesId' => ['checkedItems' => $this->normalizeFacetItems($filters['cpv_codes'] ?? [])],
            'type' => ['checkedItems' => $this->normalizeFacetItems($filters['types'] ?? [])],
            'status' => ['checkedItems' => $this->normalizeFacetItems($filters['statuses'] ?? [])],
            'contractNature' => ['checkedItems' => $this->normalizeFacetItems($filters['contract_natures'] ?? [])],
            'publicationDate' => [
                'from' => $this->normalizeDate($filters['publication_from'] ?? null),
                'to' => $this->normalizeDate($filters['publication_to'] ?? null),
            ],
            'location' => ['checkedItems' => $this->normalizeFacetItems($filters['location_ids'] ?? [])],
            'buyer' => ['checkedItems' => $this->normalizeFacetItems($filters['buyer_ids'] ?? [])],
            'winner' => ['checkedItems' => $this->normalizeFacetItems($filters['winner_ids'] ?? [])],
        ];
    }

    private function normalizeFacetItems(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeDate(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function ensureSuccessfulResponse(Response $response, string $operation, array $context = []): void
    {
        if ($response->successful()) {
            return;
        }

        Log::error('[DOFFIN][public-client] Request failed.', array_merge($context, [
            'operation' => $operation,
            'status' => $response->status(),
            'body' => $response->body(),
        ]));

        throw new RuntimeException("Doffin public {$operation} failed with status {$response->status()}.");
    }
}
