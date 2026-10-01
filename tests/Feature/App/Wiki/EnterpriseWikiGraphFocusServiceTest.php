<?php

namespace Tests\Feature\App\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\GraphQuery\EnterpriseWikiGraphFocusService;
use App\Services\EnterpriseWiki\GraphQuery\GraphDirection;
use App\Services\EnterpriseWiki\GraphQuery\GraphSubgraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Support\FakeGraphQueryService;
use Tests\TestCase;

/**
 * The division of labour between the graph and SQL.
 *
 * Neo4j says which pages are connected; PostgreSQL says whether this viewer may see them and what
 * they are called. Every test here states a case where the two disagree, because that is the only
 * situation in which the split matters: a projection that is merely correct proves nothing.
 */
class EnterpriseWikiGraphFocusServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const ALL_STATUSES = EnterpriseWikiPage::STATUSES;

    public function test_one_hop_returns_the_focus_page_and_its_neighbours_with_hop_distance(): void
    {
        $customer = $this->createWikiCustomer();
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $neighbour = $this->createWikiPageWithVersion($customer, 'Neighbour', 'Text.');

        $service = $this->service(new GraphSubgraph(
            [$this->graphNode($customer->id, $focus->id), $this->graphNode($customer->id, $neighbour->id)],
            [$this->graphEdge(1, $customer->id, $focus->id, $neighbour->id, ['anchor_text' => 'Nabo'])],
        ));

        $result = $service->focus($customer->id, $focus->id, self::ALL_STATUSES);

        $this->assertSame([$focus->id, $neighbour->id], array_column($result['nodes'], 'page_id'));
        $this->assertSame([0, 1], array_column($result['nodes'], 'depth'));
        $this->assertTrue($result['nodes'][0]['is_focus']);
        $this->assertCount(1, $result['edges']);
        $this->assertSame('Nabo', $result['edges'][0]['anchor_text']);
        $this->assertSame("page-{$focus->id}", $result['edges'][0]['source']);
        $this->assertTrue($result['focus']['projected']);
    }

    public function test_two_hops_mark_the_second_ring_with_depth_two(): void
    {
        $customer = $this->createWikiCustomer();
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $first = $this->createWikiPageWithVersion($customer, 'First', 'Text.');
        $second = $this->createWikiPageWithVersion($customer, 'Second', 'Text.');

        $service = $this->service(new GraphSubgraph(
            [
                $this->graphNode($customer->id, $focus->id),
                $this->graphNode($customer->id, $first->id),
                $this->graphNode($customer->id, $second->id),
            ],
            [
                $this->graphEdge(1, $customer->id, $focus->id, $first->id),
                $this->graphEdge(2, $customer->id, $first->id, $second->id),
            ],
        ));

        $result = $service->focus($customer->id, $focus->id, self::ALL_STATUSES, depth: 2);

        $this->assertSame(
            [$focus->id => 0, $first->id => 1, $second->id => 2],
            array_combine(array_column($result['nodes'], 'page_id'), array_column($result['nodes'], 'depth')),
        );
        $this->assertSame(2, $result['scope']['depth']);
    }

    public function test_direction_is_passed_to_the_graph_and_respected_when_walking_back_out(): void
    {
        $customer = $this->createWikiCustomer();
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $linksHere = $this->createWikiPageWithVersion($customer, 'Links here', 'Text.');

        $graph = new FakeGraphQueryService(new GraphSubgraph(
            [$this->graphNode($customer->id, $focus->id), $this->graphNode($customer->id, $linksHere->id)],
            [$this->graphEdge(1, $customer->id, $linksHere->id, $focus->id)],
        ));

        $outgoing = (new EnterpriseWikiGraphFocusService($graph))
            ->focus($customer->id, $focus->id, self::ALL_STATUSES, direction: GraphDirection::Outgoing);

        $incoming = (new EnterpriseWikiGraphFocusService($graph))
            ->focus($customer->id, $focus->id, self::ALL_STATUSES, direction: GraphDirection::Incoming);

        $this->assertSame(GraphDirection::Outgoing, $graph->queries[0]->direction);
        // The only edge points AT the focus page, so an outgoing-only question has one answer: the
        // focus page itself.
        $this->assertSame([$focus->id], array_column($outgoing['nodes'], 'page_id'));
        $this->assertSame([], $outgoing['edges']);

        $this->assertSame([$focus->id, $linksHere->id], array_column($incoming['nodes'], 'page_id'));
        $this->assertCount(1, $incoming['edges']);
    }

    public function test_a_page_the_viewer_may_not_see_is_dropped_together_with_its_edges(): void
    {
        $customer = $this->createWikiCustomer();
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.', ['status' => EnterpriseWikiPage::STATUS_APPROVED]);
        $hidden = $this->createWikiPageWithVersion($customer, 'Hidden', 'Text.', ['status' => EnterpriseWikiPage::STATUS_DRAFT]);

        $service = $this->service(new GraphSubgraph(
            [$this->graphNode($customer->id, $focus->id), $this->graphNode($customer->id, $hidden->id)],
            [$this->graphEdge(1, $customer->id, $focus->id, $hidden->id)],
        ));

        // The projection stores every page regardless of status; SQL decides who may see it.
        $result = $service->focus($customer->id, $focus->id, [EnterpriseWikiPage::STATUS_APPROVED]);

        $this->assertSame([$focus->id], array_column($result['nodes'], 'page_id'));
        $this->assertSame([], $result['edges']);
    }

    public function test_a_second_hop_reachable_only_through_a_hidden_page_is_not_reachable_at_all(): void
    {
        $customer = $this->createWikiCustomer();
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.', ['status' => EnterpriseWikiPage::STATUS_APPROVED]);
        $hidden = $this->createWikiPageWithVersion($customer, 'Hidden', 'Text.', ['status' => EnterpriseWikiPage::STATUS_DRAFT]);
        $behind = $this->createWikiPageWithVersion($customer, 'Behind', 'Text.', ['status' => EnterpriseWikiPage::STATUS_APPROVED]);

        $service = $this->service(new GraphSubgraph(
            [
                $this->graphNode($customer->id, $focus->id),
                $this->graphNode($customer->id, $hidden->id),
                $this->graphNode($customer->id, $behind->id),
            ],
            [
                $this->graphEdge(1, $customer->id, $focus->id, $hidden->id),
                $this->graphEdge(2, $customer->id, $hidden->id, $behind->id),
            ],
        ));

        $result = $service->focus($customer->id, $focus->id, [EnterpriseWikiPage::STATUS_APPROVED], depth: 2);

        // "Behind" is visible in its own right, but the only path to it runs through a page this
        // viewer may not see — so it is not in this viewer's neighbourhood, and must not appear as
        // an unexplained floating node either.
        $this->assertSame([$focus->id], array_column($result['nodes'], 'page_id'));
        $this->assertSame([], $result['edges']);
    }

    public function test_a_cross_customer_node_in_the_projection_never_reaches_the_payload(): void
    {
        $customer = $this->createWikiCustomer('Customer A');
        $other = $this->createWikiCustomer('Customer B');
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $foreign = $this->createWikiPageWithVersion($other, 'Foreign', 'Text.');

        $service = $this->service(new GraphSubgraph(
            [$this->graphNode($customer->id, $focus->id), $this->graphNode($other->id, $foreign->id)],
            [$this->graphEdge(1, $other->id, $focus->id, $foreign->id)],
        ));

        $result = $service->focus($customer->id, $focus->id, self::ALL_STATUSES);

        $this->assertSame([$focus->id], array_column($result['nodes'], 'page_id'));
        $this->assertSame([], $result['edges']);
    }

    public function test_a_focus_page_belonging_to_another_customer_is_refused(): void
    {
        $customer = $this->createWikiCustomer('Customer A');
        $other = $this->createWikiCustomer('Customer B');
        $foreign = $this->createWikiPageWithVersion($other, 'Foreign', 'Text.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to this customer');

        $this->service()->focus($customer->id, $foreign->id, self::ALL_STATUSES);
    }

    public function test_a_page_the_projection_has_not_caught_up_with_says_so(): void
    {
        $customer = $this->createWikiCustomer();
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');

        $result = $this->service()->focus($customer->id, $focus->id, self::ALL_STATUSES);

        // Projection lag is recoverable (`php artisan wiki:graph-project`) and must be visible as
        // itself, not as a page that genuinely has no relations.
        $this->assertFalse($result['focus']['projected']);
        $this->assertSame([$focus->id], array_column($result['nodes'], 'page_id'));
        $this->assertSame(0, $result['summary']['edge_count']);
    }

    public function test_the_relation_type_whitelist_is_carried_into_the_query(): void
    {
        $customer = $this->createWikiCustomer();
        $focus = $this->createWikiPageWithVersion($customer, 'Focus', 'Text.');
        $graph = new FakeGraphQueryService;

        $result = (new EnterpriseWikiGraphFocusService($graph))
            ->focus($customer->id, $focus->id, self::ALL_STATUSES, relationTypes: ['wikilink']);

        $this->assertSame(['WIKILINK'], $graph->queries[0]->relationTypes);
        $this->assertSame(['WIKILINK'], $result['scope']['relation_types']);
    }

    private function service(?GraphSubgraph $subgraph = null): EnterpriseWikiGraphFocusService
    {
        return new EnterpriseWikiGraphFocusService(new FakeGraphQueryService($subgraph ?? new GraphSubgraph));
    }

    /** @return array<string, mixed> */
    private function graphNode(int $customerId, int $pageId): array
    {
        return [
            'customer_id' => $customerId,
            'page_id' => $pageId,
            'slug' => "projected-{$pageId}",
            'title' => "Projected {$pageId}",
            'page_type' => 'article',
            'status' => 'approved',
            'current_version_id' => null,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function graphEdge(int $linkId, int $customerId, int $from, int $to, array $metadata = []): array
    {
        return [
            'link_id' => $linkId,
            'customer_id' => $customerId,
            'from_page_id' => $from,
            'to_page_id' => $to,
            'relation_type' => 'WIKILINK',
            'link_type' => 'wikilink',
            'source' => 'deterministic',
            'confidence' => 'certain',
            'from_page_version_id' => null,
            'to_page_version_id' => null,
            'metadata' => $metadata,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ];
    }
}
