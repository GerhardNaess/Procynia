<?php

namespace Tests\Unit\Services\Quality;

use App\Models\QualityPageClassification;
use App\Models\QualityRelation;
use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Support\RecordingGraphProjectionService;
use Tests\TestCase;

/**
 * The quality layer as a projection.
 *
 * SQL owns the kvalitetssystem; the graph is a derived view of it. What these tests hold onto is
 * that the two stay the same shape: a classified page is the SAME node as the Wiki page (Kvalitet
 * is a layer, not a parallel graph), an unclassified page carries no quality type at all, and a
 * Wiki rebuild cannot quietly drop the quality edges on its way past.
 */
class QualityGraphProjectionTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_a_classified_page_is_projected_as_the_same_node_carrying_its_quality_type(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $policy = $this->createWikiPageWithVersion($customer, 'Innkjopspolicy', 'Policy text.');

        $this->classify($customer->id, $policy->id, QualityPageClassification::TYPE_POLICY);

        (new EnterpriseWikiGraphProjector($writer))->projectPageQuality($policy->id);

        $this->assertSame($policy->id, $writer->upsertedPages[0]['page_id']);
        $this->assertSame(QualityPageClassification::TYPE_POLICY, $writer->upsertedPages[0]['quality_type']);
    }

    public function test_an_unclassified_page_is_projected_with_a_null_quality_type(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Vanlig side', 'Text.');

        // The key must be present and null rather than absent: the graph writer reads it to decide
        // whether to REMOVE the :QualityItem label, so a missing key would strand a stale one.
        (new EnterpriseWikiGraphProjector($writer))->projectPage($page->id);

        $this->assertArrayHasKey('quality_type', $writer->upsertedPages[0]);
        $this->assertNull($writer->upsertedPages[0]['quality_type']);
    }

    public function test_quality_relations_are_projected_as_the_pages_outgoing_edges(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->createWikiPageWithVersion($customer, 'Innkjopspolicy', 'Policy text.');
        $process = $this->createWikiPageWithVersion($customer, 'Anskaffelsesprosess', 'Process text.');

        $this->classify($customer->id, $policy->id, QualityPageClassification::TYPE_POLICY);
        $this->classify($customer->id, $process->id, QualityPageClassification::TYPE_PROCESS);
        $this->relate($customer->id, $policy->id, $process->id, QualityRelation::TYPE_GOVERNS);

        (new EnterpriseWikiGraphProjector($writer))->projectPageQuality($policy->id);

        $replaced = $writer->replacedOutgoingQualityRelations[0];

        $this->assertSame($customer->id, $replaced['customer_id']);
        $this->assertSame($policy->id, $replaced['from_page_id']);
        $this->assertCount(1, $replaced['relations']);
        $this->assertSame($process->id, $replaced['relations'][0]['to_page_id']);
        $this->assertSame(QualityRelation::TYPE_GOVERNS, $replaced['relations'][0]['relation_type']);
    }

    public function test_the_target_node_is_projected_before_the_edge_pointing_at_it(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->createWikiPageWithVersion($customer, 'Innkjopspolicy', 'Policy text.');
        $process = $this->createWikiPageWithVersion($customer, 'Anskaffelsesprosess', 'Process text.');

        $this->classify($customer->id, $policy->id, QualityPageClassification::TYPE_POLICY);
        $this->classify($customer->id, $process->id, QualityPageClassification::TYPE_PROCESS);
        $this->relate($customer->id, $policy->id, $process->id, QualityRelation::TYPE_GOVERNS);

        (new EnterpriseWikiGraphProjector($writer))->projectPageQuality($policy->id);

        $methods = array_column($writer->calls, 'method');
        $upsertedIds = collect($writer->calls)->where('method', 'upsertWikiPage')->pluck('page_id')->all();

        // The graph writer MATCHes the target instead of creating it, so an unprojected target
        // means a silently dropped edge.
        $this->assertContains($process->id, $upsertedIds);
        $this->assertLessThan(
            array_search('replaceOutgoingQualityRelations', $methods, true),
            array_search('upsertWikiPage', $methods, true),
        );
    }

    public function test_an_edge_to_another_customers_page_never_reaches_the_graph(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer('Customer A');
        $otherCustomer = $this->createWikiCustomer('Customer B');

        $policy = $this->createWikiPageWithVersion($customer, 'Innkjopspolicy', 'Policy text.');
        $foreignProcess = $this->createWikiPageWithVersion($otherCustomer, 'Fremmed prosess', 'Process text.');

        $this->classify($customer->id, $policy->id, QualityPageClassification::TYPE_POLICY);
        $this->classify($otherCustomer->id, $foreignProcess->id, QualityPageClassification::TYPE_PROCESS);
        // Written straight to the table: the service refuses this, and the projector must refuse it
        // again rather than trust that nothing ever got past it.
        $this->relate($customer->id, $policy->id, $foreignProcess->id, QualityRelation::TYPE_GOVERNS);

        (new EnterpriseWikiGraphProjector($writer))->projectPageQuality($policy->id);

        $this->assertSame([], $writer->replacedOutgoingQualityRelations[0]['relations']);
    }

    public function test_rebuilding_a_customer_carries_the_quality_layer_with_it(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        $policy = $this->createWikiPageWithVersion($customer, 'Innkjopspolicy', 'Policy text.');
        $process = $this->createWikiPageWithVersion($customer, 'Anskaffelsesprosess', 'Process text.');

        $this->classify($customer->id, $policy->id, QualityPageClassification::TYPE_POLICY);
        $this->classify($customer->id, $process->id, QualityPageClassification::TYPE_PROCESS);
        $this->relate($customer->id, $policy->id, $process->id, QualityRelation::TYPE_GOVERNS);

        (new EnterpriseWikiGraphProjector($writer))->rebuildCustomer($customer->id);

        $rebuild = $writer->rebuilds[0];

        // replaceCustomerWikiGraph deletes the customer's nodes first, so a rebuild that left the
        // quality edges out would silently wipe them on any ordinary Wiki reprojection.
        $this->assertCount(1, $rebuild['quality_relations']);
        $this->assertSame(QualityRelation::TYPE_GOVERNS, $rebuild['quality_relations'][0]['relation_type']);
        $this->assertSame(
            [QualityPageClassification::TYPE_POLICY, QualityPageClassification::TYPE_PROCESS],
            collect($rebuild['pages'])->pluck('quality_type')->sort()->values()->all(),
        );
    }

    private function classify(int $customerId, int $pageId, string $qualityType): void
    {
        QualityPageClassification::query()->create([
            'customer_id' => $customerId,
            'enterprise_wiki_page_id' => $pageId,
            'quality_type' => $qualityType,
            'source' => QualityPageClassification::SOURCE_MANUAL,
            'classified_at' => now(),
        ]);
    }

    private function relate(int $customerId, int $fromPageId, int $toPageId, string $relationType): void
    {
        QualityRelation::query()->create([
            'customer_id' => $customerId,
            'from_page_id' => $fromPageId,
            'to_page_id' => $toPageId,
            'relation_type' => $relationType,
            'source' => QualityRelation::SOURCE_MANUAL,
        ]);
    }
}
