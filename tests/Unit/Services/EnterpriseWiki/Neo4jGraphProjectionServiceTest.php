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

    public function test_a_quality_item_is_its_own_node_and_not_a_label_on_a_page(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->upsertQualityItem($this->qualityItem());

        $statement = $client->runs[0]['statement'];

        // The whole point of the rebuild: Kvalitet owns governance objects, Wiki owns pages. A
        // quality item must never be expressible as a property or label on an EnterpriseWikiPage,
        // because that was exactly the model this replaced.
        $this->assertStringContainsString(
            'MERGE (q:QualityItem {customer_id: $customer_id, quality_item_id: $quality_item_id})',
            $statement,
        );
        $this->assertStringNotContainsString('EnterpriseWikiPage', $statement);
        $this->assertSame('policy', $client->runs[0]['parameters']['quality_type']);
    }

    public function test_a_wiki_page_upsert_carries_no_quality_property_at_all(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->upsertWikiPage($this->page());

        $statement = $client->runs[0]['statement'];

        // Attaching a page to a quality item must leave the page untouched — no type, no label.
        $this->assertStringNotContainsString('quality_type', $statement);
        $this->assertStringNotContainsString('QualityItem', $statement);
    }

    public function test_it_projects_quality_relations_as_typed_edges_between_quality_items(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceOutgoingQualityItemRelations(10, 20, [$this->qualityRelation()]);

        $statements = array_column($client->runs, 'statement');
        $merge = array_values(array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'MERGE')));

        // A real relationship type, not a property on a generic edge:
        // `(:QualityItem)-[:GOVERNS]->(:QualityItem)` is the question the quality graph exists to
        // answer.
        $this->assertCount(1, $merge);
        $this->assertStringContainsString('MERGE (from)-[rel:GOVERNS]->(to)', $merge[0]);
        $this->assertStringContainsString('MATCH (from:QualityItem', $merge[0]);
        $this->assertStringContainsString('MATCH (to:QualityItem', $merge[0]);
    }

    public function test_it_deletes_every_quality_edge_type_before_writing_the_new_set(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceOutgoingQualityItemRelations(10, 20, []);

        $statements = implode("\n", array_column($client->runs, 'statement'));

        // A relation removed in SQL leaves no payload behind to carry its own removal, so every
        // type has to be cleared — including the ones the new set does not mention.
        foreach (['GOVERNS', 'HAS_PROCEDURE', 'USES', 'VERIFIES', 'DEPENDS_ON'] as $relationshipType) {
            $this->assertStringContainsString("[old:{$relationshipType}]", $statements);
        }

        $this->assertSame(1, $client->writeTransactions);
    }

    public function test_it_refuses_a_quality_relation_type_it_has_no_cypher_name_for(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $this->expectException(InvalidArgumentException::class);

        $service->replaceOutgoingQualityItemRelations(10, 20, [$this->qualityRelation(['relation_type' => 'supersedes'])]);
    }

    public function test_it_refuses_an_unknown_relation_type_before_clearing_the_items_edges(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        try {
            $service->replaceOutgoingQualityItemRelations(10, 20, [$this->qualityRelation(['relation_type' => 'supersedes'])]);
        } catch (InvalidArgumentException) {
            // The replace deletes the item's edges first, so a late failure would strip every
            // relation off the item and write nothing back.
        }

        $this->assertSame([], $client->runs);
        $this->assertSame(0, $client->writeTransactions);
    }

    public function test_a_wiki_link_joins_the_two_domains_without_merging_them(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceQualityItemWikiLinks(10, 20, [$this->qualityWikiLink()]);

        $statement = $client->runs[0]['statement'];

        // Two node kinds, one edge between them. The link type travels as a property because
        // documents/supports/evidence are three flavours of the same traversal.
        $this->assertStringContainsString('MATCH (q:QualityItem', $statement);
        $this->assertStringContainsString('MATCH (p:EnterpriseWikiPage', $statement);
        $this->assertStringContainsString('MERGE (q)-[rel:SUPPORTED_BY]->(p)', $statement);
        $this->assertStringContainsString('rel.link_type = link.link_type', $statement);
    }

    public function test_it_clears_wiki_links_that_are_gone_from_sql(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceQualityItemWikiLinks(10, 20, []);

        $this->assertStringContainsString('[old:SUPPORTED_BY]', $client->runs[0]['statement']);
    }

    public function test_deleting_a_quality_item_leaves_the_wiki_pages_standing(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->deleteQualityItem(10, 20);

        $statement = $client->runs[0]['statement'];

        // DETACH DELETE on the QualityItem drops its SUPPORTED_BY edges; the pages at the other
        // end are a different node kind and are not matched at all.
        $this->assertStringContainsString('MATCH (q:QualityItem {customer_id: $customer_id, quality_item_id: $quality_item_id})', $statement);
        $this->assertStringNotContainsString('EnterpriseWikiPage', $statement);

        // The item's activities do go with it. They hang off the item and mean nothing without it,
        // and a DETACH DELETE removes the edge rather than the node at the other end of it — so
        // deleting only the item would leave orphan activities behind for good.
        $this->assertStringContainsString('(q)-[:HAS_ACTIVITY]->(a:QualityActivity)', $statement);
        $this->assertStringContainsString('DETACH DELETE a, q', $statement);
    }

    public function test_an_activity_is_its_own_node_between_the_process_and_the_wiki_page(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceProcessActivities(10, 20, [$this->activity()], [$this->knowledgeLink()]);

        $statements = implode("\n", array_column($client->runs, 'statement'));

        // The requested model, as the graph expresses it: prosess -> aktivitet -> Wiki-artikkel.
        // An edge straight from the process could not say which of its steps needs the page.
        $this->assertStringContainsString('MERGE (q)-[:HAS_ACTIVITY]->(a)', $statements);
        $this->assertStringContainsString('MERGE (a)-[rel:REQUIRES_KNOWLEDGE]->(p)', $statements);
        $this->assertStringContainsString('MATCH (p:EnterpriseWikiPage {customer_id: link.customer_id, page_id: link.page_id})', $statements);

        // Matched, never merged: an activity whose process is not in the graph must be skipped
        // rather than create a placeholder item node.
        $this->assertStringContainsString(
            'MATCH (q:QualityItem {customer_id: activity.customer_id, quality_item_id: activity.quality_item_id})',
            $statements,
        );

        $this->assertSame(1, $client->writeTransactions);
    }

    public function test_the_knowledge_edge_carries_no_wiki_content(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceProcessActivities(10, 20, [$this->activity()], [$this->knowledgeLink()]);

        $merge = $this->statementContaining($client, 'MERGE (a)-[rel:REQUIRES_KNOWLEDGE]->(p)');

        // Wiki/SQL owns what the page says. The relation records the dependency and nothing else,
        // so editing the page changes the knowledge without the relation moving at all.
        foreach (['title', 'markdown', 'content', 'excerpt', 'version_number'] as $content) {
            $this->assertStringNotContainsString($content, $merge);
        }
    }

    public function test_an_activity_that_left_the_flow_is_deleted_with_its_edges(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceProcessActivities(10, 20, [$this->activity(['activity_key' => 'kontroller'])], []);

        $statements = array_column($client->runs, 'statement');

        // A node removed from a flow leaves no payload behind to carry its own removal, so the
        // complete set is what decides which activities survive.
        $this->assertStringContainsString('WHERE NOT a.activity_key IN $keys', $statements[0]);
        $this->assertStringContainsString('DETACH DELETE a', $statements[0]);
        $this->assertSame(['kontroller'], $client->runs[0]['parameters']['keys']);

        // And the knowledge edges of the activities that did survive are cleared before the new set
        // is written, for the same reason the Wiki links are.
        $this->assertStringContainsString('[old:REQUIRES_KNOWLEDGE]', implode("\n", $statements));
    }

    public function test_clearing_a_flow_removes_every_activity_it_had(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceProcessActivities(10, 20, [], []);

        // `NOT x IN []` is true for every row, so an empty set deletes the lot — which is what a
        // process whose flow was emptied has to look like.
        $this->assertSame([], $client->runs[0]['parameters']['keys']);
        $this->assertStringContainsString('DETACH DELETE a', $client->runs[0]['statement']);
    }

    public function test_the_activity_schema_is_keyed_on_the_process_as_well_as_the_node(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->ensureSchema();

        $statements = implode("\n", array_column($client->runs, 'statement'));

        // A node key is unique within one blueprint and nowhere else, so the process is part of the
        // identity rather than a property hanging off it.
        $this->assertStringContainsString('REQUIRE (a.customer_id, a.quality_item_id, a.activity_key) IS UNIQUE', $statements);
    }

    public function test_a_customer_rebuild_refuses_an_unknown_relation_type_before_emptying_the_graph(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        try {
            $service->replaceCustomerWikiGraph(
                10,
                [$this->page()],
                [],
                [$this->qualityItem()],
                [$this->qualityRelation(['relation_type' => 'supersedes'])],
            );
            $this->fail('An unknown relation type must abort the rebuild.');
        } catch (InvalidArgumentException) {
            // The rebuild deletes the customer's nodes first, so failing afterwards would leave an
            // emptied graph behind.
            $this->assertSame([], $client->runs);
        }
    }

    public function test_a_customer_rebuild_carries_the_quality_domain_with_it(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceCustomerWikiGraph(
            10,
            [$this->page()],
            [],
            [$this->qualityItem()],
            [$this->qualityRelation()],
            [$this->qualityWikiLink()],
        );

        $statements = implode("\n", array_column($client->runs, 'statement'));

        // The rebuild deletes the customer's nodes first, so anything it leaves out is dropped by a
        // routine Wiki rebuild and never comes back.
        $this->assertStringContainsString('MATCH (q:QualityItem {customer_id: $customer_id})', $statements);
        $this->assertStringContainsString('MERGE (q:QualityItem {customer_id: item.customer_id, quality_item_id: item.quality_item_id})', $statements);
        $this->assertStringContainsString('MERGE (from)-[rel:GOVERNS]->(to)', $statements);
        $this->assertStringContainsString('MERGE (q)-[rel:SUPPORTED_BY]->(p)', $statements);
    }

    public function test_a_customer_rebuild_carries_the_activities_and_the_knowledge_behind_them(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceCustomerWikiGraph(
            10,
            [$this->page()],
            [],
            [$this->qualityItem(['quality_type' => 'process'])],
            [],
            [],
            [$this->activity()],
            [$this->knowledgeLink()],
        );

        $statements = implode("\n", array_column($client->runs, 'statement'));

        // Activities are deleted in a statement of their own: DETACH DELETE on the item removes the
        // edge, not the activity, so one left out here would survive every rebuild as an orphan.
        $this->assertStringContainsString('MATCH (a:QualityActivity {customer_id: $customer_id})', $statements);
        $this->assertStringContainsString('MERGE (q)-[:HAS_ACTIVITY]->(a)', $statements);
        $this->assertStringContainsString('MERGE (a)-[rel:REQUIRES_KNOWLEDGE]->(p)', $statements);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function qualityItem(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 10,
            'quality_item_id' => 20,
            'quality_type' => 'policy',
            'title' => 'Innkjopspolicy',
            'code' => 'POL-01',
            'status' => 'active',
            'owner_user_id' => 5,
            'next_review_at' => '2027-01-01',
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function activity(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 10,
            'quality_item_id' => 20,
            'activity_key' => 'kontroller-leverandoren',
            'label' => 'Kontroller leverandoren',
            'activity_type' => 'step',
            'role' => 'Innkjoper',
            'position' => 1,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function knowledgeLink(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 10,
            'quality_item_id' => 20,
            'activity_key' => 'kontroller-leverandoren',
            'page_id' => 20,
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ], $overrides);
    }

    /** The one recorded statement that holds $needle. */
    private function statementContaining(RecordingNeo4jClient $client, string $needle): string
    {
        foreach ($client->runs as $run) {
            if (str_contains($run['statement'], $needle)) {
                return $run['statement'];
            }
        }

        $this->fail(sprintf('No statement contained [%s].', $needle));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function qualityRelation(array $overrides = []): array
    {
        return array_merge([
            'relation_id' => 1,
            'customer_id' => 10,
            'from_item_id' => 20,
            'to_item_id' => 21,
            'relation_type' => 'governs',
            'source' => 'manual',
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function qualityWikiLink(array $overrides = []): array
    {
        return array_merge([
            'link_id' => 7,
            'customer_id' => 10,
            'quality_item_id' => 20,
            'page_id' => 20,
            'link_type' => 'documents',
            'source' => 'manual',
            'updated_at' => '2026-10-01T10:00:00+00:00',
        ], $overrides);
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

        // One per node kind. Without them, MERGE on (customer_id, id) is both a full scan and racy.
        $this->assertCount(3, $client->runs);

        $this->assertStringContainsString('IF NOT EXISTS', $client->runs[0]['statement']);
        $this->assertStringContainsString('FOR (p:EnterpriseWikiPage)', $client->runs[0]['statement']);
        $this->assertStringContainsString('REQUIRE (p.customer_id, p.page_id) IS UNIQUE', $client->runs[0]['statement']);

        $this->assertStringContainsString('IF NOT EXISTS', $client->runs[1]['statement']);
        $this->assertStringContainsString('FOR (q:QualityItem)', $client->runs[1]['statement']);
        $this->assertStringContainsString('REQUIRE (q.customer_id, q.quality_item_id) IS UNIQUE', $client->runs[1]['statement']);

        $this->assertStringContainsString('IF NOT EXISTS', $client->runs[2]['statement']);
        $this->assertStringContainsString('FOR (a:QualityActivity)', $client->runs[2]['statement']);
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

        // Nine statements: delete pages, quality items and activities, then merge pages, wikilinks,
        // quality items, the SUPPORTED_BY edges, the activities and their REQUIRES_KNOWLEDGE edges.
        // All three deletes are customer-scoped — a rebuild for one customer must never touch
        // another's nodes.
        $this->assertCount(9, $client->runs);
        $this->assertStringContainsString('MATCH (p:EnterpriseWikiPage {customer_id: $customer_id})', $client->runs[0]['statement']);
        $this->assertStringContainsString('MATCH (q:QualityItem {customer_id: $customer_id})', $client->runs[1]['statement']);
        $this->assertStringContainsString('MATCH (a:QualityActivity {customer_id: $customer_id})', $client->runs[2]['statement']);
        $this->assertSame(10, $client->runs[0]['parameters']['customer_id']);
        $this->assertSame(10, $client->runs[1]['parameters']['customer_id']);
        $this->assertSame(10, $client->runs[2]['parameters']['customer_id']);
    }

    public function test_customer_rebuild_runs_inside_a_single_write_transaction(): void
    {
        $client = new RecordingNeo4jClient;
        $service = new Neo4jGraphProjectionService('bolt://example:7687', 'neo4j', 'neo4j', 'secret', $client);

        $service->replaceCustomerWikiGraph(10, [], []);

        $this->assertSame(1, $client->writeTransactions);
        $this->assertSame(array_fill(0, 9, true), array_column($client->runs, 'in_transaction'));
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

        // Run 4: after the three deletes and the page merge.
        $this->assertSame(
            '{"anchor_text":"SLA","section":"Drift"}',
            $client->runs[4]['parameters']['links'][0]['metadata'],
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
