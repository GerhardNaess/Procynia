<?php

namespace App\Services\EnterpriseWiki\GraphQuery;

use App\Services\EnterpriseWiki\Graph\Neo4jConnection;
use DateTimeInterface;
use Laudis\Neo4j\Contracts\ClientInterface;
use Stringable;
use Traversable;

/**
 * Focus traversal against the Neo4j projection.
 *
 * This class reads the graph and nothing else. It does not open the relational database, does not
 * know what a viewer is allowed to see, and does not decide what a page is called today — all
 * three belong to SQL, which stays the source of truth. What it contributes is the one thing the
 * relational model does not do well: following a relation several hops out in a single query.
 */
class Neo4jGraphQueryService implements GraphQueryService
{
    private readonly Neo4jConnection $connection;

    public function __construct(
        string $uri,
        ?string $database,
        ?string $username,
        ?string $password,
        ?ClientInterface $client = null,
    ) {
        $this->connection = new Neo4jConnection($uri, $database, $username, $password, $client);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function focusSubgraph(GraphFocusQuery $query): GraphSubgraph
    {
        $rows = $this->client()->run(
            $this->focusCypher($query),
            [
                'customer_id' => $query->customerId,
                'page_id' => $query->pageId,
            ],
        );

        $nodes = [];
        $edges = [];

        // One row per matched path, so the same node or relationship arrives once per path it sits
        // on. Deduplicating by page_id/link_id is what makes the result a graph again.
        foreach ($rows as $row) {
            foreach ($this->normalize($row->get('path_nodes', [])) as $node) {
                $this->collectNode($nodes, $node);
            }

            foreach ($this->normalize($row->get('path_edges', [])) as $edge) {
                $this->collectEdge($edges, $edge);
            }

            $this->collectNode($nodes, $this->normalize($row->get('focus_node', [])));
        }

        return new GraphSubgraph(array_values($nodes), array_values($edges));
    }

    /**
     * Neo4j binds values, never structure: a relationship type and a path length are part of the
     * query text. Both come from GraphFocusQuery, which accepts nothing but its own whitelist and
     * an integer depth it has already bounded, so nothing a client sent reaches this string.
     */
    private function focusCypher(GraphFocusQuery $query): string
    {
        $relation = sprintf(':%s*1..%d', $query->relationTypePattern(), $query->depth);

        [$left, $right] = match ($query->direction) {
            GraphDirection::Outgoing => ['-', '->'],
            GraphDirection::Incoming => ['<-', '-'],
            GraphDirection::Both => ['-', '-'],
        };

        // OPTIONAL MATCH, so a page with no projected links still answers "this node exists and
        // has no neighbours" instead of an empty result that reads like a missing page.
        //
        // Both the nodes and the relationships on a path are re-checked against the customer:
        // the projector never writes a cross-customer edge, but a traversal is exactly where such
        // a row would turn into another tenant's page appearing in this one's graph.
        return <<<CYPHER
            MATCH (focus:EnterpriseWikiPage {customer_id: \$customer_id, page_id: \$page_id})
            OPTIONAL MATCH path = (focus){$left}[{$relation}]{$right}(:EnterpriseWikiPage)
            WHERE all(node IN nodes(path) WHERE node.customer_id = \$customer_id)
              AND all(rel IN relationships(path) WHERE rel.customer_id = \$customer_id)
            RETURN focus { .* } AS focus_node,
                   CASE WHEN path IS NULL THEN [] ELSE [n IN nodes(path) | n { .* }] END AS path_nodes,
                   CASE WHEN path IS NULL THEN [] ELSE [r IN relationships(path) | r { .* }] END AS path_edges
            CYPHER;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  mixed  $node
     */
    private function collectNode(array &$nodes, $node): void
    {
        if (! is_array($node) || ! isset($node['page_id'])) {
            return;
        }

        $nodes[(int) $node['page_id']] ??= [
            'customer_id' => isset($node['customer_id']) ? (int) $node['customer_id'] : null,
            'page_id' => (int) $node['page_id'],
            'slug' => $node['slug'] ?? null,
            'title' => $node['title'] ?? null,
            'page_type' => $node['page_type'] ?? null,
            'status' => $node['status'] ?? null,
            'current_version_id' => isset($node['current_version_id']) ? (int) $node['current_version_id'] : null,
            'updated_at' => $node['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $edges
     * @param  mixed  $edge
     */
    private function collectEdge(array &$edges, $edge): void
    {
        if (! is_array($edge) || ! isset($edge['link_id'])) {
            return;
        }

        $edges[(int) $edge['link_id']] ??= [
            'link_id' => (int) $edge['link_id'],
            'customer_id' => isset($edge['customer_id']) ? (int) $edge['customer_id'] : null,
            'from_page_id' => isset($edge['from_page_id']) ? (int) $edge['from_page_id'] : null,
            'to_page_id' => isset($edge['to_page_id']) ? (int) $edge['to_page_id'] : null,
            'relation_type' => 'WIKILINK',
            'link_type' => $edge['link_type'] ?? null,
            'source' => $edge['source'] ?? null,
            'confidence' => $edge['confidence'] ?? null,
            'from_page_version_id' => isset($edge['from_page_version_id']) ? (int) $edge['from_page_version_id'] : null,
            'to_page_version_id' => isset($edge['to_page_version_id']) ? (int) $edge['to_page_version_id'] : null,
            // The write side encodes the SQL metadata map as a JSON string because Neo4j has no
            // map property. Reading it back as a string would push that storage detail out to
            // every consumer, so the round trip closes here.
            'metadata' => $this->decodeMetadata($edge['metadata'] ?? null),
            'updated_at' => $edge['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        // A property that is not the JSON this service wrote is still data someone can read in the
        // browser; it is not worth failing a traversal over, and an empty map is the honest answer
        // to "what does Procynia know about this edge".
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Driver types in, plain PHP out. CypherMap and CypherList are only shallowly convertible, so
     * the recursion is the point: a nested map left untouched survives until the first
     * json_encode() and fails there instead of here.
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        } elseif ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        } elseif (is_object($value)) {
            return $value instanceof Stringable ? (string) $value : null;
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }

    private function client(): ClientInterface
    {
        return $this->connection->client();
    }
}
