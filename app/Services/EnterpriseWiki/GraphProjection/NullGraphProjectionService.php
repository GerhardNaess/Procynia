<?php

namespace App\Services\EnterpriseWiki\GraphProjection;

class NullGraphProjectionService implements GraphProjectionService
{
    public function ensureSchema(): void
    {
        //
    }

    public function upsertWikiPage(array $page): void
    {
        //
    }

    public function deleteWikiPage(int $customerId, int $pageId): void
    {
        //
    }

    public function replaceOutgoingWikilinks(int $customerId, int $fromPageId, array $links): void
    {
        //
    }

    public function upsertQualityItem(array $item): void
    {
        //
    }

    public function deleteQualityItem(int $customerId, int $qualityItemId): void
    {
        //
    }

    public function replaceOutgoingQualityItemRelations(int $customerId, int $fromItemId, array $relations): void
    {
        //
    }

    public function replaceQualityItemWikiLinks(int $customerId, int $qualityItemId, array $links): void
    {
        //
    }

    public function replaceCustomerWikiGraph(
        int $customerId,
        array $pages,
        array $links,
        array $qualityItems = [],
        array $qualityItemRelations = [],
        array $qualityWikiLinks = [],
    ): void {
        //
    }
}
