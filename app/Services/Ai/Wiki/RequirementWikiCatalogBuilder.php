<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;

/**
 * Builds a compact, customer-scoped catalog of the pages a Wiki-research run is allowed to
 * consider — the "table of contents" step of the Karpathy query flow (find relevant pages before
 * reading them). Built live from existing Wiki tables every time; no new index table.
 *
 * A page is in the catalog only when it:
 * - belongs to the given customer_id,
 * - has a version this caller may ground in (see GROUNDING_* below),
 * - that version exists and actually belongs to this page, and
 * - its content_markdown is non-empty.
 *
 * TWO GROUNDING MODES, because two surfaces want different things and the difference is a product
 * rule rather than a tuning knob:
 *
 * - GROUNDING_PUBLISHED_ONLY (the default) is for drafting a tender answer. What goes into a bid
 *   must be knowledge someone approved; an unreviewed working version has not earned that.
 * - GROUNDING_CURRENT_KNOWLEDGE is for "Spør Wiki", where a person is asking their own Wiki what it
 *   says. Answering "there is no information" about a page they can open and read is not caution,
 *   it is a wrong answer — and on a customer whose 35 pages are all still drafts it made the
 *   feature answer nothing at all.
 *
 * Deliberately NOT gated on page.status beyond the caller's own visible statuses. Status describes
 * what is happening to the WORKING version: a page can be pending_review or rejected while a
 * previously approved version is still published, and that approved knowledge must keep answering
 * questions. Only archived and superseded pages — which are retired outright, not merely being
 * revised — are excluded.
 *
 * Nor is it gated on document-owner approvals. That gate is what allows a version to be published;
 * once publication has happened the decision stands, and pending sign-offs on a NEW working version
 * must not retroactively withdraw knowledge that was approved.
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
    /** Only versions a reviewer approved and published may ground the answer. */
    public const GROUNDING_PUBLISHED_ONLY = 'published_only';

    /** The newest version the page actually has — the knowledge the user can open and read today. */
    public const GROUNDING_CURRENT_KNOWLEDGE = 'current_knowledge';

    /** Excerpt length is deliberately short — just enough for a human/AI to judge topical relevance. */
    private const EXCERPT_MAX_CHARS = 220;

    public function __construct(
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovals,
        private readonly RequirementWikiFigureCatalog $figureCatalog = new RequirementWikiFigureCatalog,
    ) {}

    /**
     * Purpose: Build the full customer-scoped Wiki catalog.
     * Inputs: The customer id, and optionally which page statuses are eligible.
     * Returns: One entry per eligible page:
     *          {page_id, title, page_type, scope, slug, content_markdown, figures, headings,
     *           excerpt, outgoing_link_count, backlink_count}.
     * Side effects: None (read-only).
     *
     * The customer id is the isolation boundary and is never taken from a request; $visibleStatuses
     * is the asking user's own readable set, so widening which VERSIONS may be read can never
     * widen which PAGES may be read.
     *
     * @param  list<string>|null  $visibleStatuses  Null means every non-retired status.
     */
    public function build(
        int $customerId,
        string $grounding = self::GROUNDING_PUBLISHED_ONLY,
        ?array $visibleStatuses = null,
    ): array {
        $query = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereNotIn('status', [EnterpriseWikiPage::STATUS_ARCHIVED, EnterpriseWikiPage::STATUS_SUPERSEDED])
            ->with(['publishedVersion', 'currentVersion']);

        if ($visibleStatuses !== null) {
            // An empty set is "this user may read nothing", which must stay empty rather than
            // degrade into "no filter".
            $query->whereIn('status', $visibleStatuses);
        }

        if ($grounding === self::GROUNDING_PUBLISHED_ONLY) {
            $query->whereNotNull('published_version_id');
        }

        $versionsByPageId = [];

        $pages = $query->get()
            ->filter(function (EnterpriseWikiPage $page) use ($grounding, &$versionsByPageId): bool {
                $version = $this->answerableVersion($page, $grounding);

                if ($version === null) {
                    return false;
                }

                $versionsByPageId[$page->id] = $version;

                return true;
            })
            ->values();

        if ($pages->isEmpty()) {
            return [];
        }

        $pageIds = $pages->pluck('id')->all();
        $outgoingCounts = $this->linkCountsByPageId($customerId, 'from_page_id', $pageIds);
        $backlinkCounts = $this->linkCountsByPageId($customerId, 'to_page_id', $pageIds);

        return $pages
            ->map(function (EnterpriseWikiPage $page) use ($outgoingCounts, $backlinkCounts, $versionsByPageId): array {
                $version = $versionsByPageId[$page->id];
                $contentMarkdown = (string) $version->content_markdown;

                return [
                    'page_id' => $page->id,
                    'title' => $page->title,
                    'page_type' => $page->page_type,
                    'scope' => (string) $page->scope,
                    'slug' => $page->slug,
                    'content_markdown' => $contentMarkdown,
                    // Figures live only in content_blocks_json — content_markdown carries their
                    // caption and citation but never the image itself. Carried alongside the text
                    // so a page that is actually read can offer its own figures to the answer.
                    'figures' => $this->figureCatalog->fromContentBlocks(
                        (array) ($version->content_blocks_json ?? []),
                    ),
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
     * The version this page may be answered from, or null when it has none.
     *
     * NEVER AN OLD PUBLISHED VERSION OVER A NEWER CURRENT ONE. Under GROUNDING_CURRENT_KNOWLEDGE
     * the two candidates are compared by version_number rather than assuming the current one wins:
     * "current" and "newest" are the same thing everywhere the writer maintains them, and comparing
     * says so explicitly instead of relying on it.
     *
     * Fail closed either way. A pointer to a missing version, or to a version belonging to another
     * page, is corruption, and a version with no text cannot answer anything — such a page is
     * dropped from the catalog rather than silently answered from something else.
     */
    private function answerableVersion(EnterpriseWikiPage $page, string $grounding): ?EnterpriseWikiPageVersion
    {
        $candidates = [$page->publishedVersion];

        if ($grounding === self::GROUNDING_CURRENT_KNOWLEDGE) {
            $candidates[] = $page->currentVersion;
        }

        $usable = array_filter(
            $candidates,
            static fn (?EnterpriseWikiPageVersion $version): bool => $version !== null
                && (int) $version->enterprise_wiki_page_id === (int) $page->id
                && trim((string) $version->content_markdown) !== '',
        );

        if ($usable === []) {
            return null;
        }

        usort(
            $usable,
            static fn (EnterpriseWikiPageVersion $left, EnterpriseWikiPageVersion $right): int => (int) $right->version_number <=> (int) $left->version_number,
        );

        return $usable[0];
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
