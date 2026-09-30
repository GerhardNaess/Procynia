<?php

namespace App\Services\OpportunitySources;

interface OpportunitySourceAdapter
{
    public function sourceKey(): string;

    public function label(): string;

    /**
     * What this register is called, for a reader.
     *
     * Not label(): that names an action in a menu ("Live søk i Doffin") and reads wrong the moment
     * it is printed as one of the places a procurement was found. This is the register's own short
     * name — Doffin, TED — and it lives on the adapter because the adapter is the thing that speaks
     * for the register, so there is no second list to keep in step with the first.
     */
    public function registerName(): string;

    /**
     * Ask this register what it has. The criteria name no register's parameters; translating them
     * into the ones this adapter's API takes is the adapter's own work.
     */
    public function search(OpportunitySearchCriteria $criteria, int $page, int $perPage): OpportunitySourceSearchResult;

    public function normalizeLiveSearchHit(array $hit): ?NormalizedNotice;

    public function sourceUrl(string $externalId): ?string;
}
