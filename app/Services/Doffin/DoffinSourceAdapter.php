<?php

namespace App\Services\Doffin;

use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunitySourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceSearchResult;
use Illuminate\Support\Str;

class DoffinSourceAdapter implements OpportunitySourceAdapter
{
    public const SOURCE_KEY = 'doffin';

    public function __construct(
        private readonly DoffinLiveSearchService $liveSearchService,
    ) {}

    public function sourceKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function label(): string
    {
        return 'Live søk i Doffin';
    }

    public function search(array $filters, int $page, int $perPage): OpportunitySourceSearchResult
    {
        $response = $this->liveSearchService->search($filters, $page, $perPage);
        $page = max(1, (int) ($response['page'] ?? $page));
        $perPage = max(1, (int) ($response['perPage'] ?? $perPage));
        $fallbackUsed = (bool) ($response['fallback_used'] ?? false);

        if (! ($response['ok'] ?? true)) {
            $errorType = (string) ($response['error_type'] ?? 'unexpected_response');

            return new OpportunitySourceSearchResult(
                ok: false,
                notices: [],
                page: $page,
                perPage: $perPage,
                numHitsTotal: (int) ($response['numHitsTotal'] ?? 0),
                numHitsAccessible: (int) ($response['numHitsAccessible'] ?? 0),
                fallbackUsed: $fallbackUsed,
                errorType: $errorType,
                errorMessage: is_string($response['error_message'] ?? null) ? $response['error_message'] : null,
                userMessage: $this->userMessageForErrorType($errorType),
                upstreamStatus: $response['upstream_status'] ?? null,
            );
        }

        $notices = collect($this->liveSearchItems($response))
            ->map(fn (array $hit): ?NormalizedNotice => $this->normalizeLiveSearchHit($hit))
            ->filter()
            ->values()
            ->all();
        $accessibleTotal = (int) ($response['numHitsAccessible'] ?? $response['numHitsTotal'] ?? count($notices));

        return new OpportunitySourceSearchResult(
            ok: true,
            notices: $notices,
            page: $page,
            perPage: $perPage,
            numHitsTotal: (int) ($response['numHitsTotal'] ?? $accessibleTotal),
            numHitsAccessible: $accessibleTotal,
            fallbackUsed: $fallbackUsed,
        );
    }

    public function normalizeLiveSearchHit(array $hit): ?NormalizedNotice
    {
        $externalId = trim((string) ($hit['id'] ?? ''));

        if ($externalId === '') {
            return null;
        }

        $buyers = collect($hit['buyer'] ?? [])
            ->filter(fn (mixed $buyer): bool => is_array($buyer))
            ->map(fn (array $buyer): string => trim((string) ($buyer['name'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $description = trim((string) ($hit['description'] ?? ''));
        $cpvCodes = collect([
            ...((array) ($hit['cpvCodes'] ?? [])),
            $hit['mainCpvCode'] ?? null,
        ])
            ->filter(fn (mixed $cpv): bool => is_scalar($cpv) && trim((string) $cpv) !== '')
            ->map(fn (mixed $cpv): string => trim((string) $cpv))
            ->unique()
            ->values()
            ->all();
        $status = strtoupper(trim((string) ($hit['status'] ?? '')));

        return new NormalizedNotice(
            sourceKey: $this->sourceKey(),
            externalId: $externalId,
            title: $this->stringOrNull($hit['heading'] ?? null),
            description: $description !== '' ? Str::squish($description) : null,
            buyerName: $buyers->isEmpty() ? null : $buyers->implode(', '),
            publicationDate: $this->stringOrNull($hit['publicationDate'] ?? $hit['issueDate'] ?? null),
            deadline: $this->stringOrNull($hit['deadline'] ?? null),
            status: $status !== '' && $status !== 'ACTIVE' ? $status : ($this->stringOrNull($hit['status'] ?? null)),
            sourceUrl: $this->sourceUrl($externalId),
            cpvCodes: $cpvCodes,
            rawPayload: $hit,
        );
    }

    public function sourceUrl(string $externalId): ?string
    {
        if ($externalId === '') {
            return null;
        }

        return sprintf((string) config('doffin.public_notice_url'), rawurlencode($externalId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function liveSearchItems(array $searchResponse): array
    {
        return collect($searchResponse['items'] ?? $searchResponse['hits'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->all();
    }

    private function userMessageForErrorType(string $errorType): string
    {
        return match ($errorType) {
            'invalid_request' => 'Søket mot Doffin ble avvist. Kontroller filtrene og prøv igjen.',
            'upstream_unavailable' => 'Doffin er midlertidig utilgjengelig. Prøv igjen om litt.',
            'timeout' => 'Doffin svarte ikke i tide. Prøv igjen om litt.',
            'connection_error' => 'Klarte ikke å koble til Doffin. Prøv igjen om litt.',
            'unexpected_response' => 'Doffin returnerte et uventet svar. Prøv igjen om litt.',
            default => 'Doffin-søket kunne ikke fullføres. Prøv igjen om litt.',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
