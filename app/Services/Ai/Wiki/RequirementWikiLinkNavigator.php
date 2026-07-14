<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Services\EnterpriseWiki\EnterpriseWikiPageTraversalService;

/**
 * Discovers next-candidate pages reachable from already-read pages via real Enterprise Wiki
 * connections — reusing the existing EnterpriseWikiPageTraversalService (LINK_TYPE_WIKILINK only,
 * single-hop outgoing()/incoming()) rather than a new graph model or a fresh markdown parse.
 *
 * A discovered page is a CANDIDATE for further reading, never an automatic inclusion: the research
 * loop still evaluates whether it actually adds knowledge before spending a page-read on it (see
 * RequirementWikiResearchService). This class only answers "what is reachable from here", filtered
 * to pages that could legally ever be read (same customer, approved, not already visited).
 */
class RequirementWikiLinkNavigator
{
    public function __construct(
        private readonly EnterpriseWikiPageTraversalService $traversalService,
    ) {}

    /**
     * Purpose: Find approved, same-customer, not-yet-visited neighbors of the given just-read
     * pages, via both outgoing wikilinks and backlinks.
     * Inputs: The pages just read this round, the customer id, and page ids to exclude (every
     *         page already read or already offered as a candidate in this research run).
     * Returns: One entry per distinct newly-discovered page (first connection encountered wins;
     *          a page reachable from more than one already-read page is not duplicated), each
     *          tagged with where it was discovered from and in which direction.
     * Side effects: None (read-only).
     *
     * @param  list<EnterpriseWikiPage>  $readPages
     * @param  list<int>  $excludePageIds
     * @return list<array{page_id: int, title: string, page_type: string, slug: string, discovered_from_page_id: int, discovered_from_title: string, link_direction: string}>
     */
    public function discoverNeighbors(array $readPages, int $customerId, array $excludePageIds): array
    {
        $discovered = [];
        $seenPageIds = [];

        foreach ($readPages as $fromPage) {
            foreach ($this->traversalService->outgoing($fromPage) as $neighbor) {
                $this->considerCandidate($discovered, $seenPageIds, $neighbor, $fromPage, 'outgoing', $customerId, $excludePageIds);
            }

            foreach ($this->traversalService->incoming($fromPage) as $neighbor) {
                $this->considerCandidate($discovered, $seenPageIds, $neighbor, $fromPage, 'incoming', $customerId, $excludePageIds);
            }
        }

        // Deterministic regardless of underlying query/traversal order — never depend on
        // database row order for which candidates a research round sees.
        ksort($discovered);

        return array_values($discovered);
    }

    /**
     * @param  array<int, array<string, mixed>>  $discovered
     * @param  array<int, bool>  $seenPageIds
     * @param  list<int>  $excludePageIds
     */
    private function considerCandidate(
        array &$discovered,
        array &$seenPageIds,
        ?EnterpriseWikiPage $neighbor,
        EnterpriseWikiPage $fromPage,
        string $direction,
        int $customerId,
        array $excludePageIds,
    ): void {
        if ($neighbor === null) {
            return;
        }

        if ((int) $neighbor->customer_id !== $customerId) {
            return;
        }

        if ($neighbor->status !== EnterpriseWikiPage::STATUS_APPROVED) {
            return;
        }

        if (in_array($neighbor->id, $excludePageIds, true) || isset($seenPageIds[$neighbor->id])) {
            return;
        }

        $seenPageIds[$neighbor->id] = true;
        $discovered[$neighbor->id] = [
            'page_id' => $neighbor->id,
            'title' => $neighbor->title,
            'page_type' => $neighbor->page_type,
            'slug' => $neighbor->slug,
            'discovered_from_page_id' => $fromPage->id,
            'discovered_from_title' => $fromPage->title,
            'link_direction' => $direction,
        ];
    }
}
