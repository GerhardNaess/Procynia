<?php

namespace Tests\Unit\Services\EnterpriseWiki\GraphQuery;

use App\Services\EnterpriseWiki\GraphQuery\GraphDirection;
use App\Services\EnterpriseWiki\GraphQuery\GraphFocusQuery;
use App\Services\EnterpriseWiki\GraphQuery\Neo4jGraphQueryService;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\TransactionConfiguration;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Tests\TestCase;

class Neo4jGraphQueryServiceTest extends TestCase
{
    public function test_it_binds_the_customer_and_page_and_scopes_every_node_and_relationship_on_the_path(): void
    {
        $client = new RecordingNeo4jReadClient;
        $service = $this->service($client);

        $service->focusSubgraph(new GraphFocusQuery(10, 20));

        $statement = $client->runs[0]['statement'];

        $this->assertStringContainsString('MATCH (focus:EnterpriseWikiPage {customer_id: $customer_id, page_id: $page_id})', $statement);
        // Customer scope is re-asserted for every hop, not only for the focus node: a traversal is
        // exactly where a stray cross-customer edge would become another tenant's page on screen.
        $this->assertStringContainsString('all(node IN nodes(path) WHERE node.customer_id = $customer_id)', $statement);
        $this->assertStringContainsString('all(rel IN relationships(path) WHERE rel.customer_id = $customer_id)', $statement);
        $this->assertSame(10, $client->runs[0]['parameters']['customer_id']);
        $this->assertSame(20, $client->runs[0]['parameters']['page_id']);
    }

    public function test_it_uses_an_optional_match_so_an_isolated_page_still_answers(): void
    {
        $client = new RecordingNeo4jReadClient([
            ['focus_node' => $this->node(20), 'path_nodes' => [], 'path_edges' => []],
        ]);

        $subgraph = $this->service($client)->focusSubgraph(new GraphFocusQuery(10, 20));

        $this->assertStringContainsString('OPTIONAL MATCH path =', $client->runs[0]['statement']);
        $this->assertSame([20], array_column($subgraph->nodes, 'page_id'));
        $this->assertSame([], $subgraph->edges);
    }

    public function test_depth_is_written_into_the_path_length(): void
    {
        $client = new RecordingNeo4jReadClient;
        $service = $this->service($client);

        $service->focusSubgraph(new GraphFocusQuery(10, 20, 1));
        $service->focusSubgraph(new GraphFocusQuery(10, 20, 2));

        $this->assertStringContainsString('[:WIKILINK*1..1]', $client->runs[0]['statement']);
        $this->assertStringContainsString('[:WIKILINK*1..2]', $client->runs[1]['statement']);
    }

    public function test_direction_is_written_into_the_relationship_pattern(): void
    {
        $client = new RecordingNeo4jReadClient;
        $service = $this->service($client);

        $service->focusSubgraph(new GraphFocusQuery(10, 20, 1, GraphDirection::Outgoing));
        $service->focusSubgraph(new GraphFocusQuery(10, 20, 1, GraphDirection::Incoming));
        $service->focusSubgraph(new GraphFocusQuery(10, 20, 1, GraphDirection::Both));

        $this->assertStringContainsString('(focus)-[:WIKILINK*1..1]->(:EnterpriseWikiPage)', $client->runs[0]['statement']);
        $this->assertStringContainsString('(focus)<-[:WIKILINK*1..1]-(:EnterpriseWikiPage)', $client->runs[1]['statement']);
        $this->assertStringContainsString('(focus)-[:WIKILINK*1..1]-(:EnterpriseWikiPage)', $client->runs[2]['statement']);
    }

    public function test_it_deduplicates_nodes_and_edges_that_appear_on_several_paths(): void
    {
        // A hub node sits on every path through it, so the driver returns it once per path.
        $client = new RecordingNeo4jReadClient([
            [
                'focus_node' => $this->node(20),
                'path_nodes' => [$this->node(20), $this->node(21)],
                'path_edges' => [$this->edge(1, 20, 21)],
            ],
            [
                'focus_node' => $this->node(20),
                'path_nodes' => [$this->node(20), $this->node(21), $this->node(22)],
                'path_edges' => [$this->edge(1, 20, 21), $this->edge(2, 21, 22)],
            ],
        ]);

        $subgraph = $this->service($client)->focusSubgraph(new GraphFocusQuery(10, 20, 2));

        $this->assertSame([20, 21, 22], array_column($subgraph->nodes, 'page_id'));
        $this->assertSame([1, 2], array_column($subgraph->edges, 'link_id'));
    }

    public function test_it_decodes_edge_metadata_back_into_an_array(): void
    {
        $client = new RecordingNeo4jReadClient([
            [
                'focus_node' => $this->node(20),
                'path_nodes' => [$this->node(20), $this->node(21)],
                'path_edges' => [$this->edge(1, 20, 21, '{"anchor_text":"SLA","occurrences":2}')],
            ],
        ]);

        $subgraph = $this->service($client)->focusSubgraph(new GraphFocusQuery(10, 20));

        // The write side stores the metadata map as a JSON string because Neo4j has no map
        // property. That storage detail must not escape the graph layer.
        $this->assertSame(['anchor_text' => 'SLA', 'occurrences' => 2], $subgraph->edges[0]['metadata']);
    }

    public function test_it_decodes_empty_and_unreadable_metadata_as_an_empty_map(): void
    {
        $client = new RecordingNeo4jReadClient([
            [
                'focus_node' => $this->node(20),
                'path_nodes' => [$this->node(20), $this->node(21), $this->node(22)],
                'path_edges' => [
                    $this->edge(1, 20, 21, '{}'),
                    $this->edge(2, 20, 22, 'not json at all'),
                ],
            ],
        ]);

        $subgraph = $this->service($client)->focusSubgraph(new GraphFocusQuery(10, 20));

        $this->assertSame([], $subgraph->edges[0]['metadata']);
        $this->assertSame([], $subgraph->edges[1]['metadata']);
    }

    public function test_no_driver_type_survives_into_the_result(): void
    {
        $client = new RecordingNeo4jReadClient([
            [
                'focus_node' => $this->node(20),
                'path_nodes' => [$this->node(20), $this->node(21)],
                'path_edges' => [$this->edge(1, 20, 21)],
            ],
        ]);

        $subgraph = $this->service($client)->focusSubgraph(new GraphFocusQuery(10, 20));

        // A CypherMap passes casual use and then fails at the first json_encode, which for a JSON
        // endpoint means the failure lands on the client rather than here.
        $this->assertNoObjects($subgraph->nodes);
        $this->assertNoObjects($subgraph->edges);
        $this->assertIsString(json_encode($subgraph->edges, JSON_THROW_ON_ERROR));
    }

    public function test_it_carries_the_edge_direction_from_the_projected_properties(): void
    {
        $client = new RecordingNeo4jReadClient([
            [
                'focus_node' => $this->node(20),
                'path_nodes' => [$this->node(20), $this->node(21)],
                'path_edges' => [$this->edge(7, 21, 20)],
            ],
        ]);

        $subgraph = $this->service($client)->focusSubgraph(new GraphFocusQuery(10, 20, 1, GraphDirection::Incoming));

        $this->assertSame(21, $subgraph->edges[0]['from_page_id']);
        $this->assertSame(20, $subgraph->edges[0]['to_page_id']);
        $this->assertSame('WIKILINK', $subgraph->edges[0]['relation_type']);
    }

    private function service(ClientInterface $client): Neo4jGraphQueryService
    {
        return new Neo4jGraphQueryService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);
    }

    private function assertNoObjects(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertNoObjects($item);
            }

            return;
        }

        $this->assertFalse(is_object($value), 'A driver object leaked out of the graph query layer.');
    }

    /** @return array<string, mixed> */
    private function node(int $pageId): array
    {
        return [
            'customer_id' => 10,
            'page_id' => $pageId,
            'slug' => "page-{$pageId}",
            'title' => "Page {$pageId}",
            'page_type' => 'article',
            'status' => 'approved',
            'current_version_id' => 100 + $pageId,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function edge(int $linkId, int $from, int $to, string $metadata = '{}'): array
    {
        return [
            'link_id' => $linkId,
            'customer_id' => 10,
            'from_page_id' => $from,
            'to_page_id' => $to,
            'link_type' => 'wikilink',
            'source' => 'deterministic',
            'confidence' => 'certain',
            'from_page_version_id' => 500,
            'to_page_version_id' => 501,
            'metadata' => $metadata,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ];
    }
}

/**
 * Returns driver-shaped rows (CypherMap/CypherList) so the normalization under test is actually
 * exercised — plain arrays would make every assertion about leaking driver types vacuous.
 */
class RecordingNeo4jReadClient implements ClientInterface
{
    /** @var list<array{statement: string, parameters: array<string, mixed>}> */
    public array $runs = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows = []) {}

    public function run(string $statement, iterable $parameters = [], ?string $alias = null): SummarizedResult
    {
        $this->runs[] = [
            'statement' => $statement,
            'parameters' => is_array($parameters) ? $parameters : iterator_to_array($parameters),
        ];

        $summary = null;

        return new SummarizedResult($summary, array_map(
            fn (array $row): CypherMap => new CypherMap([
                'focus_node' => new CypherMap($row['focus_node']),
                'path_nodes' => new CypherList(array_map(static fn (array $n): CypherMap => new CypherMap($n), $row['path_nodes'])),
                'path_edges' => new CypherList(array_map(static fn (array $e): CypherMap => new CypherMap($e), $row['path_edges'])),
            ]),
            $this->rows,
        ));
    }

    public function runStatement(Statement $statement, ?string $alias = null): SummarizedResult
    {
        return $this->run($statement->getText(), $statement->getParameters(), $alias);
    }

    public function runStatements(iterable $statements, ?string $alias = null): CypherList
    {
        return new CypherList([]);
    }

    public function beginTransaction(?iterable $statements = null, ?string $alias = null, ?TransactionConfiguration $config = null): UnmanagedTransactionInterface
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function getDriver(?string $alias): DriverInterface
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function hasDriver(string $alias): bool
    {
        return true;
    }

    public function writeTransaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null)
    {
        return $tsxHandler($this);
    }

    public function readTransaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null)
    {
        return $tsxHandler($this);
    }

    public function transaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null)
    {
        return $tsxHandler($this);
    }

    public function verifyConnectivity(?string $driver = null): bool
    {
        return true;
    }

    public function bindTransaction(?string $alias = null, ?TransactionConfiguration $config = null): void
    {
        //
    }

    public function commitBoundTransaction(?string $alias = null, int $depth = 1): void
    {
        //
    }

    public function rollbackBoundTransaction(?string $alias = null, int $depth = 1): void
    {
        //
    }
}
