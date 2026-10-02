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

    /** @var list<array<string, mixed>> */
    public array $upsertedQualityItems = [];

    /** @var list<array{customer_id: int, quality_item_id: int}> */
    public array $deletedQualityItems = [];

    /** @var list<array{customer_id: int, from_item_id: int, relations: list<array<string, mixed>>}> */
    public array $replacedQualityItemRelations = [];

    /** @var list<array{customer_id: int, quality_item_id: int, links: list<array<string, mixed>>}> */
    public array $replacedQualityWikiLinks = [];

    /** @var list<array{customer_id: int, quality_item_id: int, activities: list<array<string, mixed>>, knowledge_links: list<array<string, mixed>>}> */
    public array $replacedProcessActivities = [];

    /** @var list<array<string, mixed>> */
    public array $rebuilds = [];

    /**
     * Every call in the order it was made, so a test can assert that a target node was projected
     * before the edge pointing at it.
     *
     * @var list<array<string, mixed>>
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

    public function upsertQualityItem(array $item): void
    {
        $this->upsertedQualityItems[] = $item;
        $this->calls[] = ['method' => 'upsertQualityItem', 'quality_item_id' => (int) $item['quality_item_id']];
    }

    public function deleteQualityItem(int $customerId, int $qualityItemId): void
    {
        $this->deletedQualityItems[] = ['customer_id' => $customerId, 'quality_item_id' => $qualityItemId];
        $this->calls[] = [
            'method' => 'deleteQualityItem',
            'customer_id' => $customerId,
            'quality_item_id' => $qualityItemId,
        ];
    }

    public function replaceOutgoingQualityItemRelations(int $customerId, int $fromItemId, array $relations): void
    {
        $this->replacedQualityItemRelations[] = [
            'customer_id' => $customerId,
            'from_item_id' => $fromItemId,
            'relations' => $relations,
        ];
        $this->calls[] = [
            'method' => 'replaceOutgoingQualityItemRelations',
            'customer_id' => $customerId,
            'from_item_id' => $fromItemId,
        ];
    }

    public function replaceQualityItemWikiLinks(int $customerId, int $qualityItemId, array $links): void
    {
        $this->replacedQualityWikiLinks[] = [
            'customer_id' => $customerId,
            'quality_item_id' => $qualityItemId,
            'links' => $links,
        ];
        $this->calls[] = [
            'method' => 'replaceQualityItemWikiLinks',
            'customer_id' => $customerId,
            'quality_item_id' => $qualityItemId,
        ];
    }

    public function replaceProcessActivities(
        int $customerId,
        int $qualityItemId,
        array $activities,
        array $knowledgeLinks,
    ): void {
        $this->replacedProcessActivities[] = [
            'customer_id' => $customerId,
            'quality_item_id' => $qualityItemId,
            'activities' => $activities,
            'knowledge_links' => $knowledgeLinks,
        ];
        $this->calls[] = [
            'method' => 'replaceProcessActivities',
            'customer_id' => $customerId,
            'quality_item_id' => $qualityItemId,
        ];
    }

    public function replaceCustomerWikiGraph(
        int $customerId,
        array $pages,
        array $links,
        array $qualityItems = [],
        array $qualityItemRelations = [],
        array $qualityWikiLinks = [],
        array $processActivities = [],
        array $activityKnowledgeLinks = [],
    ): void {
        $this->rebuilds[] = [
            'customer_id' => $customerId,
            'pages' => $pages,
            'links' => $links,
            'quality_items' => $qualityItems,
            'quality_item_relations' => $qualityItemRelations,
            'quality_wiki_links' => $qualityWikiLinks,
            'process_activities' => $processActivities,
            'activity_knowledge_links' => $activityKnowledgeLinks,
        ];
        $this->calls[] = ['method' => 'replaceCustomerWikiGraph', 'customer_id' => $customerId];
    }
}
