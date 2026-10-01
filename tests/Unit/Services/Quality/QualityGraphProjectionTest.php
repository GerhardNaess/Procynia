<?php

namespace Tests\Unit\Services\Quality;

use App\Models\QualityItem;
use App\Models\QualityItemRelation;
use App\Models\QualityItemWikiLink;
use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use App\Services\Quality\QualityGraphProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Support\RecordingGraphProjectionService;
use Tests\TestCase;

/**
 * The quality domain as a projection.
 *
 * SQL owns the kvalitetssystem; the graph is a derived view of it. What these tests hold onto is
 * that the two stay the same shape, and specifically that the rebuild did what it set out to do:
 * a quality item is its own node, a Wiki page is never typed or labelled by being attached to one,
 * and a Wiki rebuild cannot quietly drop the quality half on its way past.
 */
class QualityGraphProjectionTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_a_quality_item_projects_as_its_own_node(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy', 'POL-01');

        (new QualityGraphProjector($writer))->projectItem($policy->id);

        $this->assertCount(1, $writer->upsertedQualityItems);
        $this->assertSame($policy->id, $writer->upsertedQualityItems[0]['quality_item_id']);
        $this->assertSame(QualityItem::TYPE_POLICY, $writer->upsertedQualityItems[0]['quality_type']);
        $this->assertSame('POL-01', $writer->upsertedQualityItems[0]['code']);

        // The item exists without a single Wiki page behind it, which is the normal state of a
        // policy somebody has just registered.
        $this->assertSame([], $writer->upsertedPages);
    }

    public function test_a_wiki_page_carries_no_quality_type_however_many_items_point_at_it(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->link($customer->id, $policy->id, $page->id);
        $this->link($customer->id, $process->id, $page->id);

        (new EnterpriseWikiGraphProjector($writer))->projectPage($page->id);

        // The retired model could not express this at all: one page, two quality objects, and the
        // page itself unchanged by either.
        $this->assertArrayNotHasKey('quality_type', $writer->upsertedPages[0]);
    }

    public function test_quality_relations_are_projected_as_the_items_outgoing_edges(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->relate($customer->id, $policy->id, $process->id, QualityItemRelation::TYPE_GOVERNS);

        (new QualityGraphProjector($writer))->projectItem($policy->id);

        $replaced = $writer->replacedQualityItemRelations[0];

        $this->assertSame($policy->id, $replaced['from_item_id']);
        $this->assertCount(1, $replaced['relations']);
        $this->assertSame(QualityItemRelation::TYPE_GOVERNS, $replaced['relations'][0]['relation_type']);
        $this->assertSame($process->id, $replaced['relations'][0]['to_item_id']);
    }

    public function test_the_target_item_is_projected_before_the_edge_pointing_at_it(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->relate($customer->id, $policy->id, $process->id, QualityItemRelation::TYPE_GOVERNS);

        (new QualityGraphProjector($writer))->projectItem($policy->id);

        $methods = array_column($writer->calls, 'method');
        $targetUpsert = null;

        foreach ($writer->calls as $index => $call) {
            if ($call['method'] === 'upsertQualityItem' && ($call['quality_item_id'] ?? null) === $process->id) {
                $targetUpsert = $index;
            }
        }

        // The graph writer MATCHes the target rather than creating it, so an edge written before
        // its target node exists is dropped without a trace.
        $this->assertNotNull($targetUpsert);
        $this->assertLessThan(
            array_search('replaceOutgoingQualityItemRelations', $methods, true),
            $targetUpsert,
        );
    }

    public function test_an_edge_to_another_customers_item_never_reaches_the_graph(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $otherCustomer = $this->createWikiCustomer('Annen kunde AS');

        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $foreignProcess = $this->item($otherCustomer->id, QualityItem::TYPE_PROCESS, 'Fremmed prosess');

        // Written straight to the table: the service would refuse it, and the point here is that
        // the projector does not depend on that having happened.
        $this->relate($customer->id, $policy->id, $foreignProcess->id, QualityItemRelation::TYPE_GOVERNS);

        (new QualityGraphProjector($writer))->projectItem($policy->id);

        $this->assertSame([], $writer->replacedQualityItemRelations[0]['relations']);
    }

    public function test_a_wiki_link_is_projected_as_an_edge_from_the_item_to_the_page(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');
        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');

        $this->link($customer->id, $process->id, $page->id, QualityItemWikiLink::LINK_TYPE_DOCUMENTS);

        (new QualityGraphProjector($writer))->projectItem($process->id);

        $replaced = $writer->replacedQualityWikiLinks[0];

        $this->assertSame($process->id, $replaced['quality_item_id']);
        $this->assertCount(1, $replaced['links']);
        $this->assertSame($page->id, $replaced['links'][0]['page_id']);
        $this->assertSame(QualityItemWikiLink::LINK_TYPE_DOCUMENTS, $replaced['links'][0]['link_type']);

        // The page node has to exist before the edge is written, same as the relation targets.
        $this->assertSame($page->id, $writer->upsertedPages[0]['page_id']);
    }

    public function test_an_item_with_nothing_attached_still_clears_its_edges(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');

        (new QualityGraphProjector($writer))->projectItem($policy->id);

        // Always called, including with an empty list: a relation or link removed in SQL leaves no
        // payload behind to carry its own removal.
        $this->assertSame([], $writer->replacedQualityItemRelations[0]['relations']);
        $this->assertSame([], $writer->replacedQualityWikiLinks[0]['links']);
    }

    public function test_a_customer_rebuild_carries_the_whole_quality_domain(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $page = $this->createWikiPageWithVersion($customer, 'Anskaffelsesrutine', 'Text.');
        $policy = $this->item($customer->id, QualityItem::TYPE_POLICY, 'Innkjopspolicy');
        $process = $this->item($customer->id, QualityItem::TYPE_PROCESS, 'Anskaffelsesprosess');

        $this->relate($customer->id, $policy->id, $process->id, QualityItemRelation::TYPE_GOVERNS);
        $this->link($customer->id, $process->id, $page->id);

        (new EnterpriseWikiGraphProjector($writer))->rebuildCustomer($customer->id);

        $rebuild = $writer->rebuilds[0];

        // The rebuild deletes the customer's nodes first, so anything left out of it is dropped by
        // a routine Wiki rebuild and never comes back.
        $this->assertCount(2, $rebuild['quality_items']);
        $this->assertCount(1, $rebuild['quality_item_relations']);
        $this->assertCount(1, $rebuild['quality_wiki_links']);
        $this->assertSame(
            [QualityItem::TYPE_POLICY, QualityItem::TYPE_PROCESS],
            array_column($rebuild['quality_items'], 'quality_type'),
        );
    }

    public function test_deleting_an_item_from_the_graph_names_the_customer_it_belonged_to(): void
    {
        $writer = new RecordingGraphProjectionService;

        // The row is already gone from SQL by the time the job runs, so the customer cannot be
        // looked up — it has to travel with the delete.
        (new QualityGraphProjector($writer))->deleteItem(7, 99);

        $this->assertSame([['customer_id' => 7, 'quality_item_id' => 99]], $writer->deletedQualityItems);
    }

    private function item(int $customerId, string $type, string $title, ?string $code = null): QualityItem
    {
        return QualityItem::query()->create([
            'customer_id' => $customerId,
            'quality_type' => $type,
            'title' => $title,
            'code' => $code,
            'status' => QualityItem::STATUS_ACTIVE,
        ]);
    }

    private function relate(int $customerId, int $fromItemId, int $toItemId, string $relationType): void
    {
        QualityItemRelation::query()->create([
            'customer_id' => $customerId,
            'from_item_id' => $fromItemId,
            'to_item_id' => $toItemId,
            'relation_type' => $relationType,
            'source' => QualityItemRelation::SOURCE_MANUAL,
        ]);
    }

    private function link(
        int $customerId,
        int $itemId,
        int $pageId,
        string $linkType = QualityItemWikiLink::LINK_TYPE_DOCUMENTS,
    ): void {
        QualityItemWikiLink::query()->create([
            'customer_id' => $customerId,
            'quality_item_id' => $itemId,
            'enterprise_wiki_page_id' => $pageId,
            'link_type' => $linkType,
            'source' => QualityItemWikiLink::SOURCE_MANUAL,
        ]);
    }
}
