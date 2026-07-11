<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use Illuminate\Support\Collection;

/**
 * Read-only traversal service for the canonical Enterprise Wiki link graph.
 *
 * nodes = EnterpriseWikiPage
 * edges = EnterpriseWikiPageLink where link_type = wikilink
 *
 * Every method here is restricted to link_type = EnterpriseWikiPageLink::LINK_TYPE_WIKILINK
 * (8I-3/8I-4 correction): the only relations derived from actual inline [[wikilinks]] in a
 * page's current content_markdown. Historical combinatoric rows (article_to_summary,
 * article_to_concept, etc. — built by EnterpriseWikiBuildPageLinksService::build()) still
 * exist in the table but are never surfaced by this service, mirroring
 * EnterpriseWikiGraphDataService's existing wikilink-only filter.
 *
 * Both directions are stored as explicit rows, so every method is a simple
 * forward query on from_page_id or to_page_id — no reverse joins needed.
 * All queries are scoped to customer_id.
 */
class EnterpriseWikiPageTraversalService
{
    /**
     * All pages this page links to (outgoing edges).
     */
    public function outgoing(EnterpriseWikiPage $page): Collection
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $page->customer_id)
            ->where('from_page_id', $page->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->with('toPage')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link) => $link->toPage)
            ->filter()
            ->values();
    }

    /**
     * All pages that link to this page (incoming edges / backlinks).
     */
    public function incoming(EnterpriseWikiPage $page): Collection
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $page->customer_id)
            ->where('to_page_id', $page->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->with('fromPage')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link) => $link->fromPage)
            ->filter()
            ->values();
    }

    /**
     * Article pages this page (a concept or entity page) links to.
     */
    public function relatedArticles(EnterpriseWikiPage $page): Collection
    {
        return $this->outgoingByTargetType($page, EnterpriseWikiPage::PAGE_TYPE_ARTICLE);
    }

    /**
     * Concept pages this page (an article or summary page) links to.
     */
    public function relatedConcepts(EnterpriseWikiPage $page): Collection
    {
        return $this->outgoingByTargetType($page, EnterpriseWikiPage::PAGE_TYPE_CONCEPT);
    }

    /**
     * Entity pages this page (an article or summary page) links to.
     */
    public function relatedEntities(EnterpriseWikiPage $page): Collection
    {
        return $this->outgoingByTargetType($page, EnterpriseWikiPage::PAGE_TYPE_ENTITY);
    }

    /**
     * Outgoing wikilink targets of a given page_type. The old combinatoric implementation
     * filtered by link_type pairs (e.g. article_to_concept OR summary_to_concept) because
     * the relation itself encoded both endpoints' types. A canonical wikilink row only
     * encodes link_type=wikilink, so the target page_type is filtered on the joined page
     * instead.
     */
    private function outgoingByTargetType(EnterpriseWikiPage $page, string $targetPageType): Collection
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $page->customer_id)
            ->where('from_page_id', $page->id)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->with('toPage')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link) => $link->toPage)
            ->filter(fn (?EnterpriseWikiPage $target) => $target?->page_type === $targetPageType)
            ->values();
    }
}
