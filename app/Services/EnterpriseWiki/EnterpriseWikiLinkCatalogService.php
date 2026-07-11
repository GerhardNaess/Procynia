<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Facades\Log;

/**
 * Builds the compact, deterministic catalog of pages a wiki page generator is allowed to
 * link to via inline [[wikilinks]] (8I-4). The model must never invent a slug — every
 * target it uses has to come from this list, which EnterpriseWikiGenerateAppliedPagesService
 * validates the generated markdown against before persisting a page version.
 *
 * Composition, customer-scoped:
 * - every other applied page belonging to the current run (never truncated), plus
 * - up to self::MAX_OTHER_PAGES additional existing customer pages, most recently
 *   updated first, to bound prompt size for customers with a large wiki.
 *
 * Deliberately excludes: the page being generated, content_markdown/excerpts of any page,
 * and anything from Knowledge Base/RAG. This is a distinct, minimal catalog shape from
 * EnterpriseWikiIndexContextService (which includes excerpts/lint counts for the
 * maintainer-decision prompt) — the wikilink catalog only ever needs slug/title/page_type.
 */
class EnterpriseWikiLinkCatalogService
{
    /**
     * Maximum number of additional existing customer pages (outside the current run)
     * included in the catalog. Bounds prompt token cost for customers with a large wiki.
     * Run pages are never subject to this cap — see class docblock.
     */
    public const MAX_OTHER_PAGES = 50;

    /**
     * @return array{
     *     catalog: list<array{slug: string, title: string, page_type: string}>,
     *     run_page_count: int,
     * }
     */
    public function buildForPage(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $excludePage): array
    {
        $runPages = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->with('page')
            ->get()
            ->pluck('page')
            ->filter()
            ->reject(fn (EnterpriseWikiPage $page) => $page->id === $excludePage->id)
            ->values();

        $excludedIds = $runPages->pluck('id')->push($excludePage->id)->all();

        $totalAvailable = EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->where('id', '!=', $excludePage->id)
            ->count();

        $otherPages = EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->whereNotIn('id', $excludedIds)
            ->orderByDesc('updated_at')
            ->limit(self::MAX_OTHER_PAGES)
            ->get();

        $catalog = $runPages->concat($otherPages)
            ->map(fn (EnterpriseWikiPage $page) => [
                'slug' => $page->slug,
                'title' => $page->title,
                'page_type' => $page->page_type,
            ])
            ->values()
            ->all();

        Log::info('[WIKI_LINK_CATALOG] Catalog built.', [
            'run_id' => $run->id,
            'page_id' => $excludePage->id,
            'available_pages' => $totalAvailable,
            'included_pages' => count($catalog),
            'omitted_pages' => max(0, $totalAvailable - count($catalog)),
        ]);

        return [
            'catalog' => $catalog,
            'run_page_count' => $runPages->count(),
        ];
    }
}
