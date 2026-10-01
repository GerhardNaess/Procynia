<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\GraphProjection\Neo4jGraphProjectionService;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\TransactionConfiguration;
use Laudis\Neo4j\Types\CypherList;
use RuntimeException;
use Tests\TestCase;

class Neo4jGraphProjectionServiceTest extends TestCase
{
    public function test_it_upserts_a_wiki_page_with_customer_scope(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->upsertWikiPage([
            'customer_id' => 10,
            'page_id' => 20,
            'slug' => 'incident-management',
            'title' => 'Incident Management',
            'page_type' => 'article',
            'status' => 'approved',
            'current_version_id' => 30,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ]);

        $this->assertCount(1, $client->runs);
        $this->assertStringContainsString('MERGE (p:EnterpriseWikiPage {customer_id: $customer_id, page_id: $page_id})', $client->runs[0]['statement']);
        $this->assertSame(10, $client->runs[0]['parameters']['customer_id']);
        $this->assertSame(20, $client->runs[0]['parameters']['page_id']);
    }

    public function test_it_replaces_outgoing_wikilinks_with_customer_scoped_edges(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceOutgoingWikilinks(10, 20, [$this->link()]);

        $this->assertCount(1, $client->runs);
        $statement = $client->runs[0]['statement'];

        $this->assertStringContainsString('MATCH (from:EnterpriseWikiPage {customer_id: $customer_id, page_id: $from_page_id})', $statement);
        $this->assertStringContainsString('OPTIONAL MATCH (from)-[old:WIKILINK]->(:EnterpriseWikiPage {customer_id: $customer_id})', $statement);
        $this->assertStringContainsString('MATCH (to:EnterpriseWikiPage {customer_id: $customer_id, page_id: link.to_page_id})', $statement);
        $this->assertSame(10, $client->runs[0]['parameters']['customer_id']);
        $this->assertSame(20, $client->runs[0]['parameters']['from_page_id']);
    }

    public function test_it_carries_the_from_node_forward_only_once_before_unwinding_links(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceOutgoingWikilinks(10, 20, [$this->link()]);

        // Without DISTINCT, one row per deleted edge survives into UNWIND and the link MERGE runs
        // once per pre-existing edge instead of once per link.
        $this->assertStringContainsString('WITH DISTINCT from', $client->runs[0]['statement']);
    }

    public function test_it_encodes_link_metadata_as_a_deterministic_json_string(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceOutgoingWikilinks(10, 20, [
            $this->link(['metadata' => ['anchor_text' => 'SLA', 'occurrences' => 2, 'section' => 'Drift']]),
        ]);
        $service->replaceOutgoingWikilinks(10, 20, [
            $this->link(['metadata' => ['section' => 'Drift', 'anchor_text' => 'SLA', 'occurrences' => 2]]),
        ]);

        $metadata = $client->runs[0]['parameters']['links'][0]['metadata'];

        // Neo4j rejects a map as a relationship property, so this must reach Cypher as a string.
        $this->assertIsString($metadata);
        $this->assertSame('{"anchor_text":"SLA","occurrences":2,"section":"Drift"}', $metadata);
        $this->assertSame(['anchor_text' => 'SLA', 'occurrences' => 2, 'section' => 'Drift'], json_decode($metadata, true));

        // Same metadata in a different key order must serialise identically, or every projection
        // run would look like a change.
        $this->assertSame($metadata, $client->runs[1]['parameters']['links'][0]['metadata']);
    }

    public function test_it_encodes_empty_link_metadata_as_an_empty_json_object(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceOutgoingWikilinks(10, 20, [$this->link(['metadata' => []])]);

        $this->assertSame('{}', $client->runs[0]['parameters']['links'][0]['metadata']);
    }

    public function test_it_encodes_nested_link_metadata_without_reordering_lists(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceOutgoingWikilinks(10, 20, [
            $this->link(['metadata' => [
                'positions' => [30, 10, 20],
                'context' => ['heading' => 'Drift', 'anchor_text' => 'SLA'],
            ]]),
        ]);

        $this->assertSame(
            '{"context":{"anchor_text":"SLA","heading":"Drift"},"positions":[30,10,20]}',
            $client->runs[0]['parameters']['links'][0]['metadata'],
        );
    }

    public function test_it_creates_an_idempotent_composite_uniqueness_constraint(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->ensureSchema();

        $this->assertCount(1, $client->runs);
        $statement = $client->runs[0]['statement'];

        $this->assertStringContainsString('IF NOT EXISTS', $statement);
        $this->assertStringContainsString('FOR (p:EnterpriseWikiPage)', $statement);
        $this->assertStringContainsString('REQUIRE (p.customer_id, p.page_id) IS UNIQUE', $statement);
    }

    public function test_it_rejects_a_page_property_neo4j_cannot_store(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        // A map has no Neo4j property representation. It must fail here, naming the property,
        // rather than reaching the driver or being silently reshaped.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[labels]');

        $service->upsertWikiPage($this->page(['labels' => ['primary' => 'Drift']]));
    }

    public function test_it_rejects_a_page_property_holding_an_object(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $this->expectException(InvalidArgumentException::class);

        $service->upsertWikiPage($this->page(['updated_at' => new \DateTimeImmutable('2026-10-01')]));
    }

    public function test_it_rejects_a_page_property_holding_a_nested_list(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $this->expectException(InvalidArgumentException::class);

        $service->upsertWikiPage($this->page(['tags' => [['drift']]]));
    }

    public function test_it_accepts_a_page_property_holding_a_flat_list_of_primitives(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->upsertWikiPage($this->page(['tags' => ['drift', 'sla']]));

        $this->assertSame(['drift', 'sla'], $client->runs[0]['parameters']['tags']);
    }

    public function test_it_rejects_an_unprojectable_page_property_during_a_customer_rebuild(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $this->expectException(InvalidArgumentException::class);

        $service->replaceCustomerWikiGraph(10, [$this->page(['labels' => ['primary' => 'Drift']])], []);
    }

    public function test_it_rejects_an_unprojectable_page_property_before_touching_the_graph(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        try {
            $service->replaceCustomerWikiGraph(10, [$this->page(['labels' => ['primary' => 'Drift']])], []);
        } catch (InvalidArgumentException) {
            // The rebuild deletes the customer's graph first, so validation must happen before
            // the transaction opens — otherwise a bad payload empties the customer.
        }

        $this->assertSame(0, $client->writeTransactions);
        $this->assertSame([], $client->runs);
    }

    public function test_customer_rebuild_deletes_only_that_customers_projection(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceCustomerWikiGraph(10, [], []);

        $this->assertCount(3, $client->runs);
        $this->assertStringContainsString('MATCH (p:EnterpriseWikiPage {customer_id: $customer_id})', $client->runs[0]['statement']);
        $this->assertSame(10, $client->runs[0]['parameters']['customer_id']);
    }

    public function test_customer_rebuild_runs_inside_a_single_write_transaction(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceCustomerWikiGraph(10, [], []);

        $this->assertSame(1, $client->writeTransactions);
        $this->assertSame([true, true, true], array_column($client->runs, 'in_transaction'));
    }

    public function test_customer_rebuild_lets_a_failing_write_transaction_surface(): void
    {
        $client = new RecordingNeo4jClient(failOnRunNumber: 2);
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        // The caller (job or command) must see the failure so the half-applied transaction is
        // rolled back by Neo4j rather than silently leaving an emptied customer graph.
        $this->expectException(RuntimeException::class);

        $service->replaceCustomerWikiGraph(10, [[
            'customer_id' => 10,
            'page_id' => 20,
            'slug' => 'incident-management',
            'title' => 'Incident Management',
            'page_type' => 'article',
            'status' => 'approved',
            'current_version_id' => 30,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ]], []);
    }

    public function test_customer_rebuild_encodes_link_metadata_as_a_json_string(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceCustomerWikiGraph(10, [], [
            $this->link(['metadata' => ['section' => 'Drift', 'anchor_text' => 'SLA']]),
        ]);

        $this->assertSame(
            '{"anchor_text":"SLA","section":"Drift"}',
            $client->runs[2]['parameters']['links'][0]['metadata'],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function page(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 10,
            'page_id' => 20,
            'slug' => 'incident-management',
            'title' => 'Incident Management',
            'page_type' => 'article',
            'status' => 'approved',
            'current_version_id' => 30,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function link(array $overrides = []): array
    {
        return array_merge([
            'link_id' => 30,
            'customer_id' => 10,
            'from_page_id' => 20,
            'to_page_id' => 21,
            'from_page_version_id' => 40,
            'to_page_version_id' => 41,
            'link_type' => 'wikilink',
            'source' => 'deterministic',
            'confidence' => 'certain',
            'metadata' => ['anchor_text' => 'SLA'],
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ], $overrides);
    }
}

/**
 * Doubles as both client and transaction handle so the same recorder captures statements run
 * directly and statements run inside writeTransaction().
 */
class RecordingNeo4jClient implements ClientInterface, TransactionInterface
{
    /** @var list<array{statement: string, parameters: array<string, mixed>, alias: ?string, in_transaction: bool}> */
    public array $runs = [];

    public int $writeTransactions = 0;

    private bool $inTransaction = false;

    public function __construct(private readonly ?int $failOnRunNumber = null) {}

    public function run(string $statement, iterable $parameters = [], ?string $alias = null): SummarizedResult
    {
        $this->runs[] = [
            'statement' => $statement,
            'parameters' => is_array($parameters) ? $parameters : iterator_to_array($parameters),
            'alias' => $alias,
            'in_transaction' => $this->inTransaction,
        ];

        if ($this->failOnRunNumber !== null && count($this->runs) === $this->failOnRunNumber) {
            throw new RuntimeException('Neo4j write failed.');
        }

        $summary = null;

        return new SummarizedResult($summary);
    }

    public function runStatement(Statement $statement, ?string $alias = null): SummarizedResult
    {
        $summary = null;

        return new SummarizedResult($summary);
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
        $this->writeTransactions++;
        $this->inTransaction = true;

        try {
            return $tsxHandler($this);
        } finally {
            $this->inTransaction = false;
        }
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
