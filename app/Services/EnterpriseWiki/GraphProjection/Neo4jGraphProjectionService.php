<?php

namespace App\Services\EnterpriseWiki\GraphProjection;

use InvalidArgumentException;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\AuthenticateInterface;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Databags\SessionConfiguration;

class Neo4jGraphProjectionService implements GraphProjectionService
{
    /**
     * Neo4j stores the link metadata map as a string property, so the encoding must be stable:
     * the same SQL metadata must always produce the same string, or every projection run would
     * look like a change.
     */
    private const METADATA_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private ?ClientInterface $client = null;

    public function __construct(
        private readonly string $uri,
        private readonly ?string $database,
        private readonly ?string $username,
        private readonly ?string $password,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client;
    }

    public function ensureSchema(): void
    {
        // Without this, MERGE on (customer_id, page_id) is both a full scan and racy: two
        // enterprise-wiki workers projecting the same page can each create a node.
        $this->client()->run(
            <<<'CYPHER'
            CREATE CONSTRAINT enterprise_wiki_page_customer_page_unique IF NOT EXISTS
            FOR (p:EnterpriseWikiPage)
            REQUIRE (p.customer_id, p.page_id) IS UNIQUE
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

    public function replaceCustomerWikiGraph(int $customerId, array $pages, array $links): void
    {
        foreach ($pages as $page) {
            $this->assertProjectableProperties($page, 'EnterpriseWikiPage');
        }

        $encodedLinks = $this->encodeLinkMetadata($links);

        // One write transaction: a failure halfway through must not leave the customer with an
        // emptied or half-rebuilt graph.
        $this->client()->writeTransaction(
            function (TransactionInterface $tsx) use ($customerId, $pages, $encodedLinks): void {
                $tsx->run(
                    <<<'CYPHER'
                    MATCH (p:EnterpriseWikiPage {customer_id: $customer_id})
                    DETACH DELETE p
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
            },
        );
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
        if ($this->client instanceof ClientInterface) {
            return $this->client;
        }

        $builder = ClientBuilder::create()
            ->withDriver('default', $this->uri, $this->authentication())
            ->withDefaultDriver('default');

        if ($this->database !== null && $this->database !== '') {
            $builder = $builder->withDefaultSessionConfiguration(SessionConfiguration::default()->withDatabase($this->database));
        }

        return $this->client = $builder->build();
    }

    private function authentication(): AuthenticateInterface
    {
        if ($this->username === null || $this->username === '') {
            return Authenticate::disabled();
        }

        return Authenticate::basic($this->username, (string) $this->password);
    }
}
