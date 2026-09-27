<?php

namespace App\Services\OpportunitySources;

interface OpportunitySourceAdapter
{
    public function sourceKey(): string;

    public function label(): string;

    /**
     * Ask this register what it has. The criteria name no register's parameters; translating them
     * into the ones this adapter's API takes is the adapter's own work.
     */
    public function search(OpportunitySearchCriteria $criteria, int $page, int $perPage): OpportunitySourceSearchResult;

    public function normalizeLiveSearchHit(array $hit): ?NormalizedNotice;

    public function sourceUrl(string $externalId): ?string;
}
