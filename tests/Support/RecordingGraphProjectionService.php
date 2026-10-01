<?php

namespace Tests\Support;

use App\Services\EnterpriseWiki\GraphProjection\GraphProjectionService;

class RecordingGraphProjectionService implements GraphProjectionService
{
    /** @var list<array<string, mixed>> */
    public array $upsertedPages = [];

    /** @var list<array{customer_id: int, page_id: int}> */
    public array $deletedPages = [];

    /** @var list<array{customer_id: int, from_page_id: int, links: list<array<string, mixed>>}> */
    public array $replacedOutgoing = [];

    /** @var list<array{customer_id: int, from_page_id: int, relations: list<array<string, mixed>>}> */
    public array $replacedOutgoingQualityRelations = [];

    /** @var list<array{customer_id: int, pages: list<array<string, mixed>>, links: list<array<string, mixed>>, quality_relations: list<array<string, mixed>>}> */
    public array $rebuilds = [];

    /**
     * Every call in the order it was made, so a test can assert that a target node was projected
     * before the edge pointing at it.
     *
     * @var list<array{method: string, page_id?: int, customer_id?: int, from_page_id?: int}>
     */
    public array $calls = [];

    public int $schemaBootstraps = 0;

    public function ensureSchema(): void
    {
        $this->schemaBootstraps++;
        $this->calls[] = ['method' => 'ensureSchema'];
    }

    public function upsertWikiPage(array $page): void
    {
        $this->upsertedPages[] = $page;
        $this->calls[] = ['method' => 'upsertWikiPage', 'page_id' => (int) $page['page_id']];
    }

    public function deleteWikiPage(int $customerId, int $pageId): void
    {
        $this->deletedPages[] = ['customer_id' => $customerId, 'page_id' => $pageId];
        $this->calls[] = ['method' => 'deleteWikiPage', 'customer_id' => $customerId, 'page_id' => $pageId];
    }

    public function replaceOutgoingWikilinks(int $customerId, int $fromPageId, array $links): void
    {
        $this->replacedOutgoing[] = [
            'customer_id' => $customerId,
            'from_page_id' => $fromPageId,
            'links' => $links,
        ];
        $this->calls[] = [
            'method' => 'replaceOutgoingWikilinks',
            'customer_id' => $customerId,
            'from_page_id' => $fromPageId,
        ];
    }

    public function replaceOutgoingQualityRelations(int $customerId, int $fromPageId, array $relations): void
    {
        $this->replacedOutgoingQualityRelations[] = [
            'customer_id' => $customerId,
            'from_page_id' => $fromPageId,
            'relations' => $relations,
        ];
        $this->calls[] = [
            'method' => 'replaceOutgoingQualityRelations',
            'customer_id' => $customerId,
            'from_page_id' => $fromPageId,
        ];
    }

    public function replaceCustomerWikiGraph(
        int $customerId,
        array $pages,
        array $links,
        array $qualityRelations = [],
    ): void {
        $this->rebuilds[] = [
            'customer_id' => $customerId,
            'pages' => $pages,
            'links' => $links,
            'quality_relations' => $qualityRelations,
        ];
        $this->calls[] = ['method' => 'replaceCustomerWikiGraph', 'customer_id' => $customerId];
    }
}
