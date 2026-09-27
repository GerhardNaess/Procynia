<?php

namespace App\Services\Doffin;

use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunitySourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceSearchResult;
use App\Services\OpportunitySources\OpportunityStatus;
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

    public function search(OpportunitySearchCriteria $criteria, int $page, int $perPage): OpportunitySourceSearchResult
    {
        $response = $this->liveSearchService->search($this->toDoffinFilters($criteria), $page, $perPage);
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

    /**
     * The criteria, in the parameters Doffin's API actually takes.
     *
     * This method is the only place those parameter names exist outside DoffinLiveSearchService.
     * `publication_period` counts days the way Doffin counts them, `keywords_mode` is its spelling
     * of all-or-any, and the statuses are its lifecycle words — none of which a second register
     * would share, and none of which a caller should have to know.
     *
     * The service still takes an array, which is left alone on purpose: it is Doffin's own client
     * and the array is its own wire format, so nothing is gained by typing it a second time.
     *
     * @return array<string, string>
     */
    private function toDoffinFilters(OpportunitySearchCriteria $criteria): array
    {
        return [
            'q' => $criteria->query ?? '',
            'organization_name' => $criteria->buyerName ?? '',
            // Doffin takes one comma-separated string; the criteria keep the codes apart, because
            // joining them is a wire format and belongs to whoever owns the wire.
            'cpv' => implode(',', $criteria->cpvCodes),
            // One per line is what the service's own keyword splitter expects.
            'keywords' => implode("\n", $criteria->keywords),
            'keywords_mode' => $criteria->matchAllKeywords ? 'all' : 'any',
            'publication_date_from' => $criteria->publishedFrom ?? '',
            'publication_date_to' => $criteria->publishedTo ?? '',
            // Doffin only understands a fixed set of windows. A window it does not offer is left
            // out rather than rounded to a neighbour: silently searching a different period than
            // the one asked for is worse than not narrowing at all.
            'publication_period' => $this->doffinPublicationPeriod($criteria->publishedWithinDays),
            'status' => $this->doffinStatus($criteria->status),
        ];
    }

    /** The windows Doffin's API accepts, in days. */
    private function doffinPublicationPeriod(?int $days): string
    {
        return in_array($days, [1, 7, 30, 90, 365], true) ? (string) $days : '';
    }

    private function doffinStatus(?OpportunityStatus $status): string
    {
        return match ($status) {
            OpportunityStatus::Open => 'ACTIVE',
            OpportunityStatus::Expired => 'EXPIRED',
            OpportunityStatus::Awarded => 'AWARDED',
            OpportunityStatus::Cancelled => 'CANCELLED',
            null => '',
        };
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
