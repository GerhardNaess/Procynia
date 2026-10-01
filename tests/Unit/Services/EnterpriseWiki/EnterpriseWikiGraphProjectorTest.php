<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPageLink;
use App\Services\EnterpriseWiki\GraphProjection\EnterpriseWikiGraphProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Support\RecordingGraphProjectionService;
use Tests\TestCase;

class EnterpriseWikiGraphProjectorTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_project_page_upserts_the_page_and_replaces_only_its_outgoing_wikilinks(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $source = $this->createWikiPageWithVersion($customer, 'Source', '[[target|Target]]');
        $target = $this->createWikiPageWithVersion($customer, 'Target', 'Target text.');
        $other = $this->createWikiPageWithVersion($customer, 'Other', 'Other text.');

        $this->createWikilink($customer, $source, $target);
        $this->createWikilink($customer, $other, $target);

        (new EnterpriseWikiGraphProjector($writer))->projectPage($source->id);

        $this->assertSame($source->id, $writer->upsertedPages[0]['page_id']);
        $this->assertSame($customer->id, $writer->replacedOutgoing[0]['customer_id']);
        $this->assertSame($source->id, $writer->replacedOutgoing[0]['from_page_id']);
        $this->assertCount(1, $writer->replacedOutgoing[0]['links']);
        $this->assertSame($target->id, $writer->replacedOutgoing[0]['links'][0]['to_page_id']);
    }

    public function test_rebuild_customer_is_customer_scoped_and_idempotent(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer('Customer A');
        $otherCustomer = $this->createWikiCustomer('Customer B');

        $a = $this->createWikiPageWithVersion($customer, 'A', '[[b|B]]');
        $b = $this->createWikiPageWithVersion($customer, 'B', 'B text.');
        $foreignA = $this->createWikiPageWithVersion($otherCustomer, 'Foreign A', '[[foreign-b|Foreign B]]');
        $foreignB = $this->createWikiPageWithVersion($otherCustomer, 'Foreign B', 'Foreign B text.');

        $this->createWikilink($customer, $a, $b);
        $this->createWikilink($otherCustomer, $foreignA, $foreignB);

        $projector = new EnterpriseWikiGraphProjector($writer);
        $projector->rebuildCustomer($customer->id);
        $first = $writer->rebuilds[0];

        $projector->rebuildCustomer($customer->id);
        $second = $writer->rebuilds[1];

        $this->assertSame($customer->id, $first['customer_id']);
        $this->assertSame($first, $second);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($first['pages'])->pluck('page_id')->all());
        $this->assertCount(1, $first['links']);
        $this->assertSame($a->id, $first['links'][0]['from_page_id']);
        $this->assertSame($b->id, $first['links'][0]['to_page_id']);
    }

    public function test_project_page_projects_a_target_node_before_the_edge_pointing_at_it(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();

        // A is projected while B has never been projected. The graph writer MATCHes the target
        // instead of creating it, so without this the A -> B edge would be dropped silently.
        $a = $this->createWikiPageWithVersion($customer, 'A', '[[b|B]]');
        $b = $this->createWikiPageWithVersion($customer, 'B', 'B text.');
        $this->createWikilink($customer, $a, $b);

        (new EnterpriseWikiGraphProjector($writer))->projectPage($a->id);

        $upsertedPageIds = collect($writer->calls)
            ->where('method', 'upsertWikiPage')
            ->pluck('page_id')
            ->all();

        $this->assertSame([$a->id, $b->id], $upsertedPageIds);

        $edgeCallIndex = collect($writer->calls)->search(fn (array $call): bool => $call['method'] === 'replaceOutgoingWikilinks');
        $targetUpsertIndex = collect($writer->calls)->search(
            fn (array $call): bool => $call['method'] === 'upsertWikiPage' && $call['page_id'] === $b->id,
        );

        $this->assertIsInt($targetUpsertIndex);
        $this->assertLessThan($edgeCallIndex, $targetUpsertIndex);
        $this->assertSame([$b->id], collect($writer->replacedOutgoing[0]['links'])->pluck('to_page_id')->all());
    }

    public function test_project_page_does_not_project_a_cross_customer_target(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer('Customer A');
        $otherCustomer = $this->createWikiCustomer('Customer B');

        $source = $this->createWikiPageWithVersion($customer, 'Source', 'Source text.');
        $foreign = $this->createWikiPageWithVersion($otherCustomer, 'Foreign', 'Foreign text.');

        // A link row that points across the tenant boundary must not create the foreign node and
        // must not produce an edge, however it got into SQL.
        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $source->id,
            'to_page_id' => $foreign->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_WIKILINK,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        (new EnterpriseWikiGraphProjector($writer))->projectPage($source->id);

        $this->assertSame([$source->id], collect($writer->upsertedPages)->pluck('page_id')->all());
        $this->assertSame([], $writer->replacedOutgoing[0]['links']);
    }

    public function test_project_page_still_clears_edges_when_the_page_has_no_wikilinks(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $page = $this->createWikiPageWithVersion($customer, 'Lonely', 'No links here.');

        (new EnterpriseWikiGraphProjector($writer))->projectPage($page->id);

        // The empty call is what removes edges that were valid in an earlier version.
        $this->assertCount(1, $writer->replacedOutgoing);
        $this->assertSame([], $writer->replacedOutgoing[0]['links']);
    }

    public function test_rebuild_ignores_non_wikilink_edges(): void
    {
        $writer = new RecordingGraphProjectionService;
        $customer = $this->createWikiCustomer();
        $article = $this->createWikiPageWithVersion($customer, 'Article', 'Article text.');
        $summary = $this->createWikiPageWithVersion($customer, 'Summary', 'Summary text.');

        EnterpriseWikiPageLink::query()->create([
            'customer_id' => $customer->id,
            'from_page_id' => $article->id,
            'to_page_id' => $summary->id,
            'link_type' => EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_SUMMARY,
            'source' => EnterpriseWikiPageLink::SOURCE_DETERMINISTIC,
            'confidence' => EnterpriseWikiPageLink::CONFIDENCE_CERTAIN,
        ]);

        (new EnterpriseWikiGraphProjector($writer))->rebuildCustomer($customer->id);

        $this->assertCount(0, $writer->rebuilds[0]['links']);
    }
}
