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
        $query = Str::squish((string) ($filters['q'] ?? ''));
        $keywords = $this->normalizeKeywords((string) ($filters['keywords'] ?? ''));
        $organizationName = Str::squish((string) ($filters['organization_name'] ?? ''));
        $cpvCodes = $this->normalizeCpvCodes((string) ($filters['cpv'] ?? ''));
        $status = $this->normalizeStatus((string) ($filters['status'] ?? ''));
        $buyerIds = $organizationName !== ''
            ? $this->resolveBuyerIds($organizationName)
            : [];

        return $this->postSearch([
            'numHitsPerPage' => $perPage,
            'page' => max(1, $page),
            'searchString' => $this->buildSearchString($query, $keywords, $organizationName),
            'sortBy' => 'RELEVANCE',
            'facets' => $this->facets(
                (string) ($filters['publication_period'] ?? ''),
                $buyerIds,
                $cpvCodes,
                $status !== null ? [$status] : [],
            ),
        ]);
    }

    private function resolveBuyerIds(string $organizationName): array
    {
        $response = $this->postSearch([
            'numHitsPerPage' => 50,
            'page' => 1,
            'searchString' => $organizationName,
            'sortBy' => 'RELEVANCE',
            'facets' => $this->facets('', [], [], []),
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

    private function facets(string $publicationPeriod, array $buyerIds, array $cpvCodes, array $statuses): array
    {
        [$fromDate, $toDate] = $this->publicationRange($publicationPeriod);

        return [
            'cpvCodesLabel' => ['checkedItems' => []],
            'cpvCodesId' => ['checkedItems' => $cpvCodes],
            'type' => ['checkedItems' => []],
            'status' => ['checkedItems' => $statuses],
            'contractNature' => ['checkedItems' => []],
            'publicationDate' => [
                'from' => $fromDate,
                'to' => $toDate,
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

    private function buildSearchString(string $query, array $keywords, string $organizationName): string
    {
        $parts = [];

        if ($query !== '') {
            $parts[] = $query;
        }

        $normalizedQuery = Str::lower($query);

        foreach ($keywords as $keyword) {
            $normalizedKeyword = Str::lower($keyword);

            if (
                $normalizedQuery !== ''
                && preg_match('/(?:^|\s)'.preg_quote($normalizedKeyword, '/').'(?:$|\s)/u', $normalizedQuery) === 1
            ) {
                continue;
            }

            $parts[] = $keyword;
        }

        $searchString = Str::squish(implode(' ', $parts));

        return $searchString !== '' ? $searchString : $organizationName;
    }

    private function normalizeStatus(string $value): ?string
    {
        $status = strtoupper(trim($value));

        return in_array($status, self::SUPPORTED_STATUSES, true) ? $status : null;
    }

    private function publicationRange(string $publicationPeriod): array
    {
        if (! in_array($publicationPeriod, ['7', '30', '90', '365'], true)) {
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
