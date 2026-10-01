<?php

namespace App\Services\EnterpriseWiki\GraphQuery;

/**
 * The graph store when Neo4j is disabled.
 *
 * Returns nothing rather than throwing: a caller that forgets to check isAvailable() gets an
 * empty neighbourhood, which is wrong but harmless, instead of a 500 on a page that has a
 * perfectly good SQL-backed graph next to it.
 */
class NullGraphQueryService implements GraphQueryService
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function focusSubgraph(GraphFocusQuery $query): GraphSubgraph
    {
        return GraphSubgraph::empty();
    }
}
