<?php

namespace App\Services\EnterpriseWiki\GraphQuery;

/**
 * Read access to the graph projection.
 *
 * The mirror image of GraphProjectionService: nothing outside this namespace talks to Neo4j, and
 * nothing inside it reads SQL. A graph store answers "which pages are connected to this one, and
 * how" — whether the asking user is allowed to see those pages is a question for the relational
 * database, which remains the source of truth (see EnterpriseWikiGraphFocusService).
 */
interface GraphQueryService
{
    /**
     * Whether a graph store is configured at all. False is a normal state, not an error: the
     * pilot is opt-in and the SQL-backed graph keeps working without it.
     */
    public function isAvailable(): bool;

    /**
     * The projected neighbourhood around one page, within the query's depth and direction.
     *
     * Returns graph truth only. Pages the projection has not caught up with, or that the viewer
     * may not see, are the caller's problem to reconcile against SQL.
     */
    public function focusSubgraph(GraphFocusQuery $query): GraphSubgraph;
}
