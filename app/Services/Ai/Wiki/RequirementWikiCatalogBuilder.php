<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;

/**
 * Builds a compact, customer-scoped catalog of the pages a Wiki-research run is allowed to
 * consider — the "table of contents" step of the Karpathy query flow (find relevant pages before
 * reading them). Built live from existing Wiki tables every time; no new index table.
 *
 * A page is in the catalog only when it:
 * - belongs to the given customer_id,
 * - has EnterpriseWikiPage::STATUS_APPROVED,
 * - has a current page version, and
 * - that version's content_markdown is non-empty.
 *
 * Each entry carries the page's own content_markdown (needed by RequirementWikiPageRanker for
 * content-hit scoring and by RequirementWikiPageReader once a page is actually selected) plus
 * derived, compact fields (headings, excerpt, outgoing/backlink counts). The AI-facing candidate
 * payload sent to RequirementWikiResearchAiClient is a smaller projection of this — built by
 * RequirementWikiResearchService, not here — that deliberately drops content_markdown so the
 * page-selector prompt never grows into "send the whole Wiki."
 */
class RequirementWikiCatalogBuilder
{
    /** Excerpt length is deliberately short — just enough for a human/AI to judge topical relevance. */
    private const EXCERPT_MAX_CHARS = 220;

    /**
     * Purpose: Build the full customer-scoped Wiki catalog.
     * Inputs: The customer id.
     * Returns: One entry per eligible page:
     *          {page_id, title, page_type, slug, content_markdown, headings, excerpt,
     *           outgoing_link_count, backlink_count}.
     * Side effects: None (read-only).
     *
     * @return list<array{page_id: int, title: string, page_type: string, slug: string, content_markdown: string, headings: list<string>, excerpt: string, outgoing_link_count: int, backlink_count: int}>
     */
    public function build(int $customerId): array
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('status', EnterpriseWikiPage::STATUS_APPROVED)
            ->with('currentVersion')
            ->get()
            ->filter(fn (EnterpriseWikiPage $page): bool => trim((string) $page->currentVersion?->content_markdown) !== '')
            ->values();

        if ($pages->isEmpty()) {
            return [];
        }

        $pageIds = $pages->pluck('id')->all();
        $outgoingCounts = $this->linkCountsByPageId($customerId, 'from_page_id', $pageIds);
        $backlinkCounts = $this->linkCountsByPageId($customerId, 'to_page_id', $pageIds);

        return $pages
            ->map(function (EnterpriseWikiPage $page) use ($outgoingCounts, $backlinkCounts): array {
                $contentMarkdown = (string) $page->currentVersion->content_markdown;

                return [
                    'page_id' => $page->id,
                    'title' => $page->title,
                    'page_type' => $page->page_type,
                    'slug' => $page->slug,
                    'content_markdown' => $contentMarkdown,
                    'headings' => $this->extractHeadings($contentMarkdown),
                    'excerpt' => $this->extractExcerpt($contentMarkdown),
                    'outgoing_link_count' => $outgoingCounts[$page->id] ?? 0,
                    'backlink_count' => $backlinkCounts[$page->id] ?? 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Purpose: Extract Markdown ATX heading text (any level 1-6) in document order.
     * Inputs: Raw content_markdown.
     * Returns: Heading titles, without the leading '#' markers.
     * Side effects: None.
     *
     * @return list<string>
     */
    private function extractHeadings(string $contentMarkdown): array
    {
        $headings = [];

        foreach (explode("\n", $contentMarkdown) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*$/u', $line, $matches) === 1) {
                $headings[] = trim($matches[1]);
            }
        }

        return $headings;
    }

    /**
     * Purpose: Build a short, deterministic excerpt from the page's first non-heading paragraph.
     * Inputs: Raw content_markdown.
     * Returns: The first paragraph, truncated to EXCERPT_MAX_CHARS.
     * Side effects: None.
     */
    private function extractExcerpt(string $contentMarkdown): string
    {
        foreach (preg_split('/\n{2,}/u', $contentMarkdown) ?: [] as $block) {
            $block = trim($block);

            if ($block === '' || str_starts_with($block, '#')) {
                continue;
            }

            return mb_strlen($block, 'UTF-8') > self::EXCERPT_MAX_CHARS
                ? mb_substr($block, 0, self::EXCERPT_MAX_CHARS, 'UTF-8').'…'
                : $block;
        }

        return '';
    }

    /**
     * Purpose: Count wikilink rows per page in one bulk query (never per-page, to avoid N+1).
     * Inputs: Customer id, the column to group by ('from_page_id' or 'to_page_id'), and the set
     *         of page ids to count for.
     * Returns: page_id => count map.
     * Side effects: None.
     *
     * @param  list<int>  $pageIds
     * @return array<int, int>
     */
    private function linkCountsByPageId(int $customerId, string $column, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        return EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->whereIn($column, $pageIds)
            ->selectRaw("{$column} as page_id, count(*) as link_count")
            ->groupBy($column)
            ->pluck('link_count', 'page_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}
