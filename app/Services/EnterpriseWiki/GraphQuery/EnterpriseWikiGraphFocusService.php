<?php

namespace App\Services\EnterpriseWiki\GraphQuery;

use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The focus neighbourhood of a Wiki page, traversed in the graph and authorised in SQL.
 *
 * Division of labour, deliberately asymmetric:
 *
 *   Neo4j decides WHICH pages are connected to the focus page, and how. That is the traversal the
 *   relational model cannot express in one query.
 *
 *   PostgreSQL decides WHETHER this viewer may see each of them, and what each page is called
 *   right now. The projection is rebuildable and may lag; an access decision must never be.
 *
 * So every page id coming out of the graph is re-read from enterprise_wiki_pages under the same
 * customer and status filter EnterpriseWikiGraphDataService already applies, and a page that does
 * not survive that filter takes its edges with it. A stale or over-broad projection can therefore
 * cost the user a missing edge, never another tenant's page.
 */
class EnterpriseWikiGraphFocusService
{
    public function __construct(
        private readonly GraphQueryService $graph,
    ) {}

    public function isAvailable(): bool
    {
        return $this->graph->isAvailable();
    }

    /**
     * @param  list<string>  $visibleStatuses  The same per-viewer status set as
     *                                         User::visibleEnterpriseWikiPageStatuses(): the focus
     *                                         graph must never show a page the ordinary page list
     *                                         would hide from this viewer.
     * @param  list<string>|null  $relationTypes
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException if the focus page is unknown, hidden or another customer's
     */
    public function focus(
        int $customerId,
        int $pageId,
        array $visibleStatuses,
        int $depth = 1,
        GraphDirection $direction = GraphDirection::Both,
        ?array $relationTypes = null,
    ): array {
        $query = new GraphFocusQuery($customerId, $pageId, $depth, $direction, $relationTypes);

        $focusPage = EnterpriseWikiPage::query()
            ->where('id', $pageId)
            ->where('customer_id', $customerId)
            ->whereIn('status', $visibleStatuses)
            ->first();

        if (! $focusPage instanceof EnterpriseWikiPage) {
            throw new InvalidArgumentException("Page [{$pageId}] not found or does not belong to this customer.");
        }

        $subgraph = $this->graph->focusSubgraph($query);

        $pages = $this->visiblePages($customerId, $visibleStatuses, $subgraph, $pageId);
        $edges = $this->visibleEdges($customerId, $subgraph, $pages);
        $depths = $this->depthsFromFocus($pageId, $edges, $query->direction);

        $nodes = $pages
            ->filter(fn (EnterpriseWikiPage $page): bool => isset($depths[(int) $page->id]))
            ->map(fn (EnterpriseWikiPage $page): array => $this->nodePayload($page, $depths[(int) $page->id], $pageId))
            ->sortBy([['depth', 'asc'], ['page_id', 'asc']])
            ->values()
            ->all();

        // An edge whose far end lost its way back to the focus (because an intermediate page is
        // hidden) would otherwise hang off nothing.
        $edges = array_values(array_filter(
            $edges,
            fn (array $edge): bool => isset($depths[$edge['from_page_id']], $depths[$edge['to_page_id']]),
        ));

        return [
            'available' => true,
            'focus' => [
                'page_id' => (int) $focusPage->id,
                'slug' => $focusPage->slug,
                'title' => $focusPage->title,
                // False means the projection has not caught up with SQL for this page — a real and
                // recoverable state (`php artisan wiki:graph-project`), not an empty neighbourhood.
                'projected' => collect($subgraph->nodes)->contains(fn (array $node): bool => (int) ($node['page_id'] ?? 0) === (int) $focusPage->id),
            ],
            'nodes' => $nodes,
            'edges' => array_map(fn (array $edge): array => $this->edgePayload($edge), $edges),
            'scope' => [
                'type' => 'focus',
                'page_id' => (int) $focusPage->id,
                'depth' => $query->depth,
                'direction' => $query->direction->value,
                'relation_types' => $query->relationTypes,
            ],
            'summary' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
            ],
        ];
    }

    /**
     * @param  list<string>  $visibleStatuses
     * @return Collection<int, EnterpriseWikiPage>
     */
    private function visiblePages(int $customerId, array $visibleStatuses, GraphSubgraph $subgraph, int $focusPageId): Collection
    {
        $candidateIds = collect($subgraph->nodes)
            ->pluck('page_id')
            ->merge(collect($subgraph->edges)->pluck('from_page_id'))
            ->merge(collect($subgraph->edges)->pluck('to_page_id'))
            ->push($focusPageId)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $candidateIds)
            ->whereIn('status', $visibleStatuses)
            ->get()
            ->keyBy(fn (EnterpriseWikiPage $page): int => (int) $page->id);
    }

    /**
     * @param  Collection<int, EnterpriseWikiPage>  $pages
     * @return list<array<string, mixed>>
     */
    private function visibleEdges(int $customerId, GraphSubgraph $subgraph, Collection $pages): array
    {
        return array_values(array_filter($subgraph->edges, function (array $edge) use ($customerId, $pages): bool {
            $from = (int) ($edge['from_page_id'] ?? 0);
            $to = (int) ($edge['to_page_id'] ?? 0);
            $edgeCustomerId = $edge['customer_id'] ?? null;

            // The customer check is redundant with the Cypher scope and kept anyway: it is the one
            // assertion that fails closed if the traversal is ever widened by mistake.
            return ($edgeCustomerId === null || (int) $edgeCustomerId === $customerId)
                && $pages->has($from)
                && $pages->has($to);
        }));
    }

    /**
     * Hop distance from the focus page, walked over the edges that SURVIVED the visibility filter
     * rather than over the raw graph. A page reachable only through one this viewer may not see is
     * therefore not reachable at all, which is the same answer the SQL-backed graph gives.
     *
     * @param  list<array<string, mixed>>  $edges
     * @return array<int, int>
     */
    private function depthsFromFocus(int $focusPageId, array $edges, GraphDirection $direction): array
    {
        $adjacency = [];

        foreach ($edges as $edge) {
            $from = (int) ($edge['from_page_id'] ?? 0);
            $to = (int) ($edge['to_page_id'] ?? 0);

            if ($direction !== GraphDirection::Incoming) {
                $adjacency[$from][] = $to;
            }

            if ($direction !== GraphDirection::Outgoing) {
                $adjacency[$to][] = $from;
            }
        }

        $depths = [$focusPageId => 0];
        $frontier = [$focusPageId];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $pageId) {
                foreach ($adjacency[$pageId] ?? [] as $neighbourId) {
                    if (isset($depths[$neighbourId])) {
                        continue;
                    }

                    $depths[$neighbourId] = $depths[$pageId] + 1;
                    $next[] = $neighbourId;
                }
            }

            $frontier = $next;
        }

        return $depths;
    }

    /**
     * @return array<string, mixed>
     */
    private function nodePayload(EnterpriseWikiPage $page, int $depth, int $focusPageId): array
    {
        return [
            'id' => "page-{$page->id}",
            'page_id' => (int) $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type,
            'status' => $page->status,
            'url' => "/app/wiki/{$page->slug}",
            'depth' => $depth,
            'is_focus' => (int) $page->id === $focusPageId,
        ];
    }

    /**
     * Shaped like an EnterpriseWikiGraphDataService edge on purpose: same id scheme, same
     * source/target keys, same anchor_text semantics. A focus subgraph and the SQL-backed graph
     * describe the same relations, and a consumer should not need two renderers to say so.
     *
     * @param  array<string, mixed>  $edge
     * @return array<string, mixed>
     */
    private function edgePayload(array $edge): array
    {
        $metadata = is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [];
        $anchorText = $metadata['anchor_text'] ?? null;
        $anchorText = is_string($anchorText) ? trim($anchorText) : null;

        return [
            'id' => "link-{$edge['link_id']}",
            'link_id' => (int) $edge['link_id'],
            'source' => "page-{$edge['from_page_id']}",
            'target' => "page-{$edge['to_page_id']}",
            'from_page_id' => (int) $edge['from_page_id'],
            'to_page_id' => (int) $edge['to_page_id'],
            'relation_type' => $edge['relation_type'] ?? 'WIKILINK',
            'link_type' => $edge['link_type'] ?? null,
            'origin' => $edge['source'] ?? null,
            'confidence' => $edge['confidence'] ?? null,
            'anchor_text' => ($anchorText === null || $anchorText === '') ? null : $anchorText,
            'metadata' => $metadata,
        ];
    }
}
