<?php

namespace App\Services\EnterpriseWiki\GraphProjection;

interface GraphProjectionService
{
    /**
     * Create whatever schema the projection store needs, idempotently. Safe to run repeatedly.
     */
    public function ensureSchema(): void;

    /**
     * @param  array<string, mixed>  $page
     */
    public function upsertWikiPage(array $page): void;

    public function deleteWikiPage(int $customerId, int $pageId): void;

    /**
     * @param  list<array<string, mixed>>  $links
     */
    public function replaceOutgoingWikilinks(int $customerId, int $fromPageId, array $links): void;

    /**
     * The quality edges that leave one page, as the complete set. Called with an empty list to
     * clear them, exactly like replaceOutgoingWikilinks.
     *
     * @param  list<array<string, mixed>>  $relations
     */
    public function replaceOutgoingQualityRelations(int $customerId, int $fromPageId, array $relations): void;

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $qualityRelations
     */
    public function replaceCustomerWikiGraph(
        int $customerId,
        array $pages,
        array $links,
        array $qualityRelations = [],
    ): void;
}
