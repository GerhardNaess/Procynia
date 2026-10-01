<?php

namespace Tests\Support;

use App\Services\EnterpriseWiki\GraphQuery\GraphFocusQuery;
use App\Services\EnterpriseWiki\GraphQuery\GraphQueryService;
use App\Services\EnterpriseWiki\GraphQuery\GraphSubgraph;

/**
 * A graph store with whatever contents a test says it has.
 *
 * Lets the orchestration tests state the interesting case directly — a projection that still
 * contains a page SQL has since hidden, or an edge to another customer — without needing a Neo4j
 * instance to be persuaded into that state.
 */
class FakeGraphQueryService implements GraphQueryService
{
    /** @var list<GraphFocusQuery> */
    public array $queries = [];

    public function __construct(
        public GraphSubgraph $subgraph = new GraphSubgraph,
        public bool $available = true,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function focusSubgraph(GraphFocusQuery $query): GraphSubgraph
    {
        $this->queries[] = $query;

        return $this->subgraph;
    }
}
