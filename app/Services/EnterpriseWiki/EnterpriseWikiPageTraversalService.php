<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use Illuminate\Support\Collection;

/**
 * Read-only traversal service for the Enterprise Wiki page link graph.
 *
 * nodes = EnterpriseWikiPage
 * edges = EnterpriseWikiPageLink
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
            ->with('fromPage')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link) => $link->fromPage)
            ->filter()
            ->values();
    }

    /**
     * Article pages related to a concept or entity page.
     */
    public function relatedArticles(EnterpriseWikiPage $page): Collection
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $page->customer_id)
            ->where('from_page_id', $page->id)
            ->whereIn('link_type', [
                EnterpriseWikiPageLink::LINK_TYPE_CONCEPT_TO_ARTICLE,
                EnterpriseWikiPageLink::LINK_TYPE_ENTITY_TO_ARTICLE,
            ])
            ->with('toPage')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link) => $link->toPage)
            ->filter()
            ->values();
    }

    /**
     * Concept pages related to an article or summary page.
     */
    public function relatedConcepts(EnterpriseWikiPage $page): Collection
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $page->customer_id)
            ->where('from_page_id', $page->id)
            ->whereIn('link_type', [
                EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_CONCEPT,
                EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_CONCEPT,
            ])
            ->with('toPage')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link) => $link->toPage)
            ->filter()
            ->values();
    }

    /**
     * Entity pages related to an article or summary page.
     */
    public function relatedEntities(EnterpriseWikiPage $page): Collection
    {
        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $page->customer_id)
            ->where('from_page_id', $page->id)
            ->whereIn('link_type', [
                EnterpriseWikiPageLink::LINK_TYPE_ARTICLE_TO_ENTITY,
                EnterpriseWikiPageLink::LINK_TYPE_SUMMARY_TO_ENTITY,
            ])
            ->with('toPage')
            ->get()
            ->map(fn (EnterpriseWikiPageLink $link) => $link->toPage)
            ->filter()
            ->values();
    }
}
