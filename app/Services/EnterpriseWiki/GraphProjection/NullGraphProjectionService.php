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

    public function replaceOutgoingQualityRelations(int $customerId, int $fromPageId, array $relations): void
    {
        //
    }

    public function replaceCustomerWikiGraph(
        int $customerId,
        array $pages,
        array $links,
        array $qualityRelations = [],
    ): void {
        //
    }
}
