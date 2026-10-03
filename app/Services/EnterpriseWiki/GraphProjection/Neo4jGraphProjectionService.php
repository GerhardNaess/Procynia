<?php

namespace App\Services\EnterpriseWiki\GraphProjection;

use App\Services\EnterpriseWiki\Graph\Neo4jConnection;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;

class Neo4jGraphProjectionService implements GraphProjectionService
{
    /**
     * Neo4j stores the link metadata map as a string property, so the encoding must be stable:
     * the same SQL metadata must always produce the same string, or every projection run would
     * look like a change.
     */
    private const METADATA_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Quality relation type -> Cypher relationship type.
     *
     * Quality edges get real typed relationships rather than the property-carrying single type
     * WIKILINK uses, because that is the whole point of the quality layer:
     * `(:QualityItem)-[:GOVERNS]->(:QualityItem)` is a question the graph can answer directly. The
     * price is that the type must be a literal in the query string, which is what this whitelist is
     * for — nothing outside it ever reaches Cypher.
     *
     * The keys are QualityItemRelation::TYPES. They are repeated rather than imported so the graph
     * writer never silently projects a relation type nobody has chosen a Cypher name for; adding a
     * type to the domain and forgetting this map raises here instead.
     *
     * @var array<string, string>
     */
    private const QUALITY_RELATIONSHIP_TYPES = [
        'governs' => 'GOVERNS',
        'has_procedure' => 'HAS_PROCEDURE',
        'uses' => 'USES',
        'verifies' => 'VERIFIES',
        'depends_on' => 'DEPENDS_ON',
    ];

    /**
     * The edge from a quality item to the Wiki page backing it.
     *
     * One relationship type carrying `link_type` as a property, unlike the quality relations above.
     * The reason is what each is for: quality relation types are the structure of the
     * kvalitetssystem and are traversed by type, whereas documents/supports/evidence are three
     * flavours of the same traversal — "what does the Wiki say about this item" — and splitting
     * them into three Cypher types would make every such query a union.
     */
    private const SUPPORTED_BY = 'SUPPORTED_BY';

    /**
     * The edge from a process to one of its activities.
     *
     * An activity is a node on the process's flow. It is a graph node of its own rather than a
     * property on the process, because the question the knowledge layer exists to answer is about
     * one step and not about the whole process: two activities of one process routinely produce
     * different articles, and an edge from the process could not tell them apart.
     */
    private const HAS_ACTIVITY = 'HAS_ACTIVITY';

    /**
     * The edge from an activity to a Wiki page the activity was the SOURCE of.
     *
     * The direction matters. An activity is where the virksomhet knows something nobody has written
     * down, and Procynia's answer is to write it down: the article exists because of the activity.
     * This records that origin and nothing else — no excerpt, no title, no version. Wiki/SQL owns
     * what the page says, so editing the page never moves the relation.
     */
    private const SOURCE_OF_ARTICLE = 'SOURCE_OF_ARTICLE';

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

    public function ensureSchema(): void
    {
        // Without these, MERGE on (customer_id, <id>) is both a full scan and racy: two workers
        // projecting the same row can each create a node.
        $this->client()->run(
            <<<'CYPHER'
            CREATE CONSTRAINT enterprise_wiki_page_customer_page_unique IF NOT EXISTS
            FOR (p:EnterpriseWikiPage)
            REQUIRE (p.customer_id, p.page_id) IS UNIQUE
            CYPHER,
        );

        $this->client()->run(
            <<<'CYPHER'
            CREATE CONSTRAINT quality_item_customer_item_unique IF NOT EXISTS
            FOR (q:QualityItem)
            REQUIRE (q.customer_id, q.quality_item_id) IS UNIQUE
            CYPHER,
        );

        // An activity is identified by the process it belongs to and the node key it carries on
        // that process's flow. The key is unique within one blueprint, never across them, so the
        // process id is part of the identity rather than a property hanging off it.
        $this->client()->run(
            <<<'CYPHER'
            CREATE CONSTRAINT quality_activity_customer_item_key_unique IF NOT EXISTS
            FOR (a:QualityActivity)
            REQUIRE (a.customer_id, a.quality_item_id, a.activity_key) IS UNIQUE
            CYPHER,
        );
    }

    public function upsertWikiPage(array $page): void
    {
        $this->assertProjectableProperties($page, 'EnterpriseWikiPage');

        $this->client()->run(
            <<<'CYPHER'
            MERGE (p:EnterpriseWikiPage {customer_id: $customer_id, page_id: $page_id})
            SET p.slug = $slug,
                p.title = $title,
                p.page_type = $page_type,
                p.status = $status,
                p.current_version_id = $current_version_id,
                p.updated_at = $updated_at
            CYPHER,
            $page,
        );
    }

    public function deleteWikiPage(int $customerId, int $pageId): void
    {
        $this->client()->run(
            <<<'CYPHER'
            MATCH (p:EnterpriseWikiPage {customer_id: $customer_id, page_id: $page_id})
            DETACH DELETE p
            CYPHER,
            [
                'customer_id' => $customerId,
                'page_id' => $pageId,
            ],
        );
    }

    public function replaceOutgoingWikilinks(int $customerId, int $fromPageId, array $links): void
    {
        $this->client()->run(
            <<<'CYPHER'
            MATCH (from:EnterpriseWikiPage {customer_id: $customer_id, page_id: $from_page_id})
            OPTIONAL MATCH (from)-[old:WIKILINK]->(:EnterpriseWikiPage {customer_id: $customer_id})
            DELETE old
            WITH DISTINCT from
            UNWIND $links AS link
            MATCH (to:EnterpriseWikiPage {customer_id: $customer_id, page_id: link.to_page_id})
            MERGE (from)-[rel:WIKILINK]->(to)
            SET rel.link_id = link.link_id,
                rel.customer_id = $customer_id,
                rel.from_page_id = $from_page_id,
                rel.to_page_id = link.to_page_id,
                rel.link_type = link.link_type,
                rel.source = link.source,
                rel.confidence = link.confidence,
                rel.metadata = link.metadata,
                rel.from_page_version_id = link.from_page_version_id,
                rel.to_page_version_id = link.to_page_version_id,
                rel.updated_at = link.updated_at
            CYPHER,
            [
                'customer_id' => $customerId,
                'from_page_id' => $fromPageId,
                'links' => $this->encodeLinkMetadata($links),
            ],
        );
    }

    public function upsertQualityItem(array $item): void
    {
        $this->assertProjectableProperties($item, 'QualityItem');

        $this->client()->run(
            <<<'CYPHER'
            MERGE (q:QualityItem {customer_id: $customer_id, quality_item_id: $quality_item_id})
            SET q.quality_type = $quality_type,
                q.title = $title,
                q.code = $code,
                q.status = $status,
                q.owner_user_id = $owner_user_id,
                q.next_review_at = $next_review_at,
                q.updated_at = $updated_at
            CYPHER,
            $item,
        );
    }

    public function deleteQualityItem(int $customerId, int $qualityItemId): void
    {
        // The item's activities go with it. They hang off the item and have no meaning without it,
        // so deleting only the item would leave orphan QualityActivity nodes behind — DETACH DELETE
        // removes an edge, never the node at the other end of it.
        $this->client()->run(
            <<<CYPHER
            MATCH (q:QualityItem {customer_id: \$customer_id, quality_item_id: \$quality_item_id})
            OPTIONAL MATCH (q)-[:{$this->hasActivity()}]->(a:QualityActivity)
            DETACH DELETE a, q
            CYPHER,
            [
                'customer_id' => $customerId,
                'quality_item_id' => $qualityItemId,
            ],
        );
    }

    /**
     * The quality edges that leave one item, as the complete set.
     *
     * Grouped by relation type because the Cypher relationship type cannot be a parameter. The
     * literal comes from relationshipTypeFor(), which only ever returns a value from a fixed
     * whitelist — a type the domain does not know raises rather than reaching the query string.
     */
    public function replaceOutgoingQualityItemRelations(int $customerId, int $fromItemId, array $relations): void
    {
        $grouped = [];

        foreach ($relations as $relation) {
            $this->assertProjectableProperties($relation, 'QUALITY_RELATION');

            $grouped[(string) ($relation['relation_type'] ?? '')][] = $relation;
        }

        // Validated before the transaction opens, so an unknown type aborts without having already
        // deleted the item's edges.
        foreach (array_keys($grouped) as $relationType) {
            $this->relationshipTypeFor($relationType);
        }

        // Deleting every typed quality edge first is what makes this a replace rather than an
        // append: a relation removed in SQL has no payload left to carry its own removal.
        $this->client()->writeTransaction(
            function (TransactionInterface $tsx) use ($customerId, $fromItemId, $grouped): void {
                foreach (self::QUALITY_RELATIONSHIP_TYPES as $relationshipType) {
                    $tsx->run(
                        <<<CYPHER
                        MATCH (from:QualityItem {customer_id: \$customer_id, quality_item_id: \$from_item_id})
                              -[old:{$relationshipType}]->(:QualityItem {customer_id: \$customer_id})
                        DELETE old
                        CYPHER,
                        [
                            'customer_id' => $customerId,
                            'from_item_id' => $fromItemId,
                        ],
                    );
                }

                foreach ($grouped as $relationType => $typedRelations) {
                    $tsx->run(
                        $this->qualityRelationMergeQuery($this->relationshipTypeFor($relationType)),
                        ['relations' => array_values($typedRelations)],
                    );
                }
            },
        );
    }

    public function replaceQualityItemWikiLinks(int $customerId, int $qualityItemId, array $links): void
    {
        foreach ($links as $link) {
            $this->assertProjectableProperties($link, 'SUPPORTED_BY');
        }

        $this->client()->run(
            <<<CYPHER
            MATCH (q:QualityItem {customer_id: \$customer_id, quality_item_id: \$quality_item_id})
            OPTIONAL MATCH (q)-[old:{$this->supportedBy()}]->(:EnterpriseWikiPage {customer_id: \$customer_id})
            DELETE old
            WITH DISTINCT q
            UNWIND \$links AS link
            MATCH (p:EnterpriseWikiPage {customer_id: \$customer_id, page_id: link.page_id})
            MERGE (q)-[rel:{$this->supportedBy()}]->(p)
            SET rel.link_id = link.link_id,
                rel.customer_id = \$customer_id,
                rel.quality_item_id = \$quality_item_id,
                rel.page_id = link.page_id,
                rel.link_type = link.link_type,
                rel.source = link.source,
                rel.updated_at = link.updated_at
            CYPHER,
            [
                'customer_id' => $customerId,
                'quality_item_id' => $qualityItemId,
                'links' => array_values($links),
            ],
        );
    }

    /**
     * One process's activities, and the Wiki pages each of them requires, as the complete set.
     *
     * Four statements in one write transaction, in this order because each depends on the one
     * before it: activities that are no longer on the flow go first (with their edges), the ones
     * that are left are upserted and hung off the process, every article edge they still hold is
     * cleared, and the current set is written. A failure halfway through must not leave the process
     * with half its activities or a mix of two flows.
     *
     * The article edges are cleared wholesale rather than diffed for the same reason the Wiki links
     * are: a provenance row that has gone leaves nothing behind to carry its own removal.
     */
    public function replaceProcessActivities(
        int $customerId,
        int $qualityItemId,
        array $activities,
        array $articleLinks,
    ): void {
        foreach ($activities as $activity) {
            $this->assertProjectableProperties($activity, 'QualityActivity');
        }

        foreach ($articleLinks as $link) {
            $this->assertProjectableProperties($link, 'SOURCE_OF_ARTICLE');
        }

        $keys = array_values(array_map(
            static fn (array $activity): string => (string) ($activity['activity_key'] ?? ''),
            $activities,
        ));

        $this->client()->writeTransaction(
            function (TransactionInterface $tsx) use ($customerId, $qualityItemId, $activities, $articleLinks, $keys): void {
                $tsx->run(
                    <<<'CYPHER'
                    MATCH (a:QualityActivity {customer_id: $customer_id, quality_item_id: $quality_item_id})
                    WHERE NOT a.activity_key IN $keys
                    DETACH DELETE a
                    CYPHER,
                    [
                        'customer_id' => $customerId,
                        'quality_item_id' => $qualityItemId,
                        'keys' => $keys,
                    ],
                );

                $tsx->run($this->activityMergeQuery(), ['activities' => array_values($activities)]);

                $tsx->run(
                    <<<CYPHER
                    MATCH (a:QualityActivity {customer_id: \$customer_id, quality_item_id: \$quality_item_id})
                          -[old:{$this->sourceOfArticle()}]->(:EnterpriseWikiPage {customer_id: \$customer_id})
                    DELETE old
                    CYPHER,
                    [
                        'customer_id' => $customerId,
                        'quality_item_id' => $qualityItemId,
                    ],
                );

                $tsx->run($this->articleMergeQuery(), ['links' => array_values($articleLinks)]);
            },
        );
    }

    public function replaceCustomerWikiGraph(
        int $customerId,
        array $pages,
        array $links,
        array $qualityItems = [],
        array $qualityItemRelations = [],
        array $qualityWikiLinks = [],
        array $processActivities = [],
        array $activityArticleLinks = [],
    ): void {
        foreach ($pages as $page) {
            $this->assertProjectableProperties($page, 'EnterpriseWikiPage');
        }

        foreach ($qualityItems as $item) {
            $this->assertProjectableProperties($item, 'QualityItem');
        }

        foreach ($qualityWikiLinks as $link) {
            $this->assertProjectableProperties($link, 'SUPPORTED_BY');
        }

        foreach ($processActivities as $activity) {
            $this->assertProjectableProperties($activity, 'QualityActivity');
        }

        foreach ($activityArticleLinks as $link) {
            $this->assertProjectableProperties($link, 'SOURCE_OF_ARTICLE');
        }

        $encodedLinks = $this->encodeLinkMetadata($links);

        $groupedRelations = [];

        foreach ($qualityItemRelations as $relation) {
            $this->assertProjectableProperties($relation, 'QUALITY_RELATION');

            $groupedRelations[(string) ($relation['relation_type'] ?? '')][] = $relation;
        }

        // Validated before the transaction opens: an unknown relation type must abort the rebuild
        // rather than empty the customer's graph and then fail on the way back up.
        foreach (array_keys($groupedRelations) as $relationType) {
            $this->relationshipTypeFor($relationType);
        }

        // One write transaction: a failure halfway through must not leave the customer with an
        // emptied or half-rebuilt graph.
        $this->client()->writeTransaction(
            function (TransactionInterface $tsx) use (
                $customerId,
                $pages,
                $encodedLinks,
                $qualityItems,
                $groupedRelations,
                $qualityWikiLinks,
                $processActivities,
                $activityArticleLinks,
            ): void {
                $tsx->run(
                    <<<'CYPHER'
                    MATCH (p:EnterpriseWikiPage {customer_id: $customer_id})
                    DETACH DELETE p
                    CYPHER,
                    ['customer_id' => $customerId],
                );

                $tsx->run(
                    <<<'CYPHER'
                    MATCH (q:QualityItem {customer_id: $customer_id})
                    DETACH DELETE q
                    CYPHER,
                    ['customer_id' => $customerId],
                );

                // Deleted in its own statement rather than as part of the item delete above: a
                // DETACH DELETE removes the edge, not the node at the other end, so activities left
                // out here would survive every rebuild as orphans and accumulate forever.
                $tsx->run(
                    <<<'CYPHER'
                    MATCH (a:QualityActivity {customer_id: $customer_id})
                    DETACH DELETE a
                    CYPHER,
                    ['customer_id' => $customerId],
                );

                $tsx->run(
                    <<<'CYPHER'
                    UNWIND $pages AS page
                    MERGE (p:EnterpriseWikiPage {customer_id: page.customer_id, page_id: page.page_id})
                    SET p.slug = page.slug,
                        p.title = page.title,
                        p.page_type = page.page_type,
                        p.status = page.status,
                        p.current_version_id = page.current_version_id,
                        p.updated_at = page.updated_at
                    CYPHER,
                    ['pages' => $pages],
                );

                $tsx->run(
                    <<<'CYPHER'
                    UNWIND $links AS link
                    MATCH (from:EnterpriseWikiPage {customer_id: link.customer_id, page_id: link.from_page_id})
                    MATCH (to:EnterpriseWikiPage {customer_id: link.customer_id, page_id: link.to_page_id})
                    MERGE (from)-[rel:WIKILINK]->(to)
                    SET rel.link_id = link.link_id,
                        rel.customer_id = link.customer_id,
                        rel.from_page_id = link.from_page_id,
                        rel.to_page_id = link.to_page_id,
                        rel.link_type = link.link_type,
                        rel.source = link.source,
                        rel.confidence = link.confidence,
                        rel.metadata = link.metadata,
                        rel.from_page_version_id = link.from_page_version_id,
                        rel.to_page_version_id = link.to_page_version_id,
                        rel.updated_at = link.updated_at
                    CYPHER,
                    ['links' => $encodedLinks],
                );

                $tsx->run(
                    <<<'CYPHER'
                    UNWIND $items AS item
                    MERGE (q:QualityItem {customer_id: item.customer_id, quality_item_id: item.quality_item_id})
                    SET q.quality_type = item.quality_type,
                        q.title = item.title,
                        q.code = item.code,
                        q.status = item.status,
                        q.owner_user_id = item.owner_user_id,
                        q.next_review_at = item.next_review_at,
                        q.updated_at = item.updated_at
                    CYPHER,
                    ['items' => $qualityItems],
                );

                foreach ($groupedRelations as $relationType => $typedRelations) {
                    $tsx->run(
                        $this->qualityRelationMergeQuery($this->relationshipTypeFor($relationType)),
                        ['relations' => array_values($typedRelations)],
                    );
                }

                $tsx->run(
                    <<<CYPHER
                    UNWIND \$links AS link
                    MATCH (q:QualityItem {customer_id: link.customer_id, quality_item_id: link.quality_item_id})
                    MATCH (p:EnterpriseWikiPage {customer_id: link.customer_id, page_id: link.page_id})
                    MERGE (q)-[rel:{$this->supportedBy()}]->(p)
                    SET rel.link_id = link.link_id,
                        rel.customer_id = link.customer_id,
                        rel.quality_item_id = link.quality_item_id,
                        rel.page_id = link.page_id,
                        rel.link_type = link.link_type,
                        rel.source = link.source,
                        rel.updated_at = link.updated_at
                    CYPHER,
                    ['links' => array_values($qualityWikiLinks)],
                );

                // After the items, because an activity hangs off the process it belongs to, and
                // after the pages, because the article merge matches the page rather than
                // creating it.
                $tsx->run($this->activityMergeQuery(), ['activities' => array_values($processActivities)]);
                $tsx->run($this->articleMergeQuery(), ['links' => array_values($activityArticleLinks)]);
            },
        );
    }

    private function supportedBy(): string
    {
        return self::SUPPORTED_BY;
    }

    private function hasActivity(): string
    {
        return self::HAS_ACTIVITY;
    }

    private function sourceOfArticle(): string
    {
        return self::SOURCE_OF_ARTICLE;
    }

    /**
     * One activity, hung off the process it belongs to.
     *
     * The ids travel on the row rather than as call parameters so the same statement serves both
     * paths: one process's activities, and every activity of a customer rebuild. MATCH on the
     * process rather than MERGE — an activity whose process is not in the graph must be skipped,
     * never create a placeholder item node.
     */
    private function activityMergeQuery(): string
    {
        return <<<CYPHER
        UNWIND \$activities AS activity
        MATCH (q:QualityItem {customer_id: activity.customer_id, quality_item_id: activity.quality_item_id})
        MERGE (a:QualityActivity {
            customer_id: activity.customer_id,
            quality_item_id: activity.quality_item_id,
            activity_key: activity.activity_key
        })
        SET a.label = activity.label,
            a.activity_type = activity.activity_type,
            a.role = activity.role,
            a.position = activity.position,
            a.updated_at = activity.updated_at
        MERGE (q)-[:{$this->hasActivity()}]->(a)
        CYPHER;
    }

    /**
     * One article one activity was the source of.
     *
     * Both ends are matched, never created: an activity or a page that is not in the graph leaves
     * the edge out rather than inventing a node for it. The edge carries the identity of the two
     * ends and nothing about what the page says.
     */
    private function articleMergeQuery(): string
    {
        return <<<CYPHER
        UNWIND \$links AS link
        MATCH (a:QualityActivity {
            customer_id: link.customer_id,
            quality_item_id: link.quality_item_id,
            activity_key: link.activity_key
        })
        MATCH (p:EnterpriseWikiPage {customer_id: link.customer_id, page_id: link.page_id})
        MERGE (a)-[rel:{$this->sourceOfArticle()}]->(p)
        SET rel.customer_id = link.customer_id,
            rel.quality_item_id = link.quality_item_id,
            rel.activity_key = link.activity_key,
            rel.page_id = link.page_id,
            rel.updated_at = link.updated_at
        CYPHER;
    }

    private function qualityRelationMergeQuery(string $relationshipType): string
    {
        return <<<CYPHER
        UNWIND \$relations AS relation
        MATCH (from:QualityItem {customer_id: relation.customer_id, quality_item_id: relation.from_item_id})
        MATCH (to:QualityItem {customer_id: relation.customer_id, quality_item_id: relation.to_item_id})
        MERGE (from)-[rel:{$relationshipType}]->(to)
        SET rel.relation_id = relation.relation_id,
            rel.customer_id = relation.customer_id,
            rel.from_item_id = relation.from_item_id,
            rel.to_item_id = relation.to_item_id,
            rel.relation_type = relation.relation_type,
            rel.source = relation.source,
            rel.updated_at = relation.updated_at
        CYPHER;
    }

    private function relationshipTypeFor(string $relationType): string
    {
        $relationshipType = self::QUALITY_RELATIONSHIP_TYPES[$relationType] ?? null;

        if ($relationshipType === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown quality relation type [%s]. A Cypher relationship type cannot be parameterised, '
                .'so every type must be declared in Neo4jGraphProjectionService::QUALITY_RELATIONSHIP_TYPES.',
                $relationType,
            ));
        }

        return $relationshipType;
    }

    /**
     * Neo4j property values may only be primitives or lists of primitives. A payload that breaks
     * that rule must fail here, at the graph writer, with a message naming the property — not as
     * an opaque driver error, and never by being silently reshaped into something else.
     *
     * The projection is rebuildable from SQL, so failing loudly costs nothing: the job logs and
     * retries, and `wiki:graph-project` repairs the customer once the payload is fixed.
     *
     * @param  array<string, mixed>  $properties
     */
    private function assertProjectableProperties(array $properties, string $context): void
    {
        foreach ($properties as $key => $value) {
            if ($this->isProjectableValue($value)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Neo4j cannot store property [%s] of %s: a graph property must be a primitive or a list of primitives, got [%s]. '
                .'Encode it in the projection payload before it reaches the graph writer.',
                (string) $key,
                $context,
                get_debug_type($value),
            ));
        }
    }

    private function isProjectableValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        // A map has no Neo4j property representation, and neither does an object.
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            // Neo4j list properties hold primitives only — no nulls, no nesting.
            if ($item === null || ! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Neo4j property values may only be primitives or arrays of primitives, so the SQL metadata
     * map cannot be written as-is. Encode it to a JSON string before it reaches Cypher.
     *
     * @param  list<array<string, mixed>>  $links
     * @return list<array<string, mixed>>
     */
    private function encodeLinkMetadata(array $links): array
    {
        return array_values(array_map(function (array $link): array {
            $link['metadata'] = $this->encodeMetadata($link['metadata'] ?? []);

            $this->assertProjectableProperties($link, 'WIKILINK');

            return $link;
        }, $links));
    }

    private function encodeMetadata(mixed $metadata): string
    {
        if (! is_array($metadata)) {
            $metadata = $metadata === null ? [] : ['value' => $metadata];
        }

        if ($metadata === []) {
            return '{}';
        }

        return json_encode($this->sortKeysRecursively($metadata), self::METADATA_JSON_FLAGS) ?: '{}';
    }

    /**
     * Key order in an SQL JSON column is not guaranteed, so sort maps before encoding. Lists keep
     * their order, which is meaningful.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortKeysRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeysRecursively($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function client(): ClientInterface
    {
        return $this->connection->client();
    }
}
