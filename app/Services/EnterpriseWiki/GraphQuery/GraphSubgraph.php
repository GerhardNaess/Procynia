<?php

namespace App\Services\EnterpriseWiki\GraphQuery;

/**
 * What a graph store returns for a focus query: plain PHP arrays only.
 *
 * No driver type ever leaves a GraphQueryService. A CypherMap behaves enough like an array to
 * pass casual use and then fails at the first json_encode, cache write or array function, so the
 * boundary is enforced here rather than left to the caller to remember.
 */
class GraphSubgraph
{
    /**
     * @param  list<array<string, mixed>>  $nodes  Node properties as projected, keyed by property name.
     * @param  list<array<string, mixed>>  $edges  Relationship properties, with `metadata` decoded back to an array.
     */
    public function __construct(
        public readonly array $nodes = [],
        public readonly array $edges = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }
}
