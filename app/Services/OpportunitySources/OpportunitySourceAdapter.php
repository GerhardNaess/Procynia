<?php

namespace App\Services\OpportunitySources;

interface OpportunitySourceAdapter
{
    public function sourceKey(): string;

    public function label(): string;

    public function search(array $filters, int $page, int $perPage): OpportunitySourceSearchResult;

    public function normalizeLiveSearchHit(array $hit): ?NormalizedNotice;

    public function sourceUrl(string $externalId): ?string;
}
