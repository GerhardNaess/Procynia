<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;

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

    public function __construct(
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovals,
        private readonly RequirementWikiFigureCatalog $figureCatalog = new RequirementWikiFigureCatalog,
    ) {}

    /**
     * Statuses that can ever represent CURRENT customer knowledge. Archived/superseded/rejected are
     * excluded by construction, so no caller can widen $statuses into stale content.
     */
    public const CURRENT_KNOWLEDGE_STATUSES = [
        EnterpriseWikiPage::STATUS_APPROVED,
        EnterpriseWikiPage::STATUS_DRAFT,
        EnterpriseWikiPage::STATUS_PENDING_REVIEW,
    ];

    /**
     * Purpose: Build the full customer-scoped Wiki catalog.
     * Inputs: The customer id, and optionally which page statuses are eligible.
     * Returns: One entry per eligible page:
     *          {page_id, title, page_type, scope, slug, content_markdown, figures, headings,
     *           excerpt, outgoing_link_count, backlink_count}.
     * Side effects: None (read-only).
     *
     * $statuses defaults to approved-only, which is the behaviour every existing caller relies on.
     * "Spør Wiki" (EnterpriseWikiQuestionAnswerService) passes the asking user's own visible
     * statuses instead, so a reviewer who may legitimately read a draft page can also get answers
     * grounded in it — the same read model the Wiki pages themselves use. Archived, superseded and
     * rejected pages are never eligible for either caller: they are not current knowledge.
     *
     * $requireCurrentVersionApproval adds the sign-off gate on top: only pages whose CURRENT
     * version is fully approved by its document owners are eligible. That is the gate the ingest
     * flow itself ends on — a run stops at 'awaiting_document_owner_approval', and nothing in the
     * current architecture ever advances enterprise_wiki_pages.status past 'draft'. Page status
     * therefore says nothing about whether anyone has signed off on today's content; it is the
     * legacy single-page review lifecycle (WikiController::submit()/approve(),
     * FinalizeEnterpriseWikiIngest), still meaningful for archived/superseded/rejected but not a
     * statement of approval. Off by default so read-oriented callers keep their exploratory
     * semantics; requirement answers turn it on, because a bid answer presents Wiki content as
     * documented customer fact.
     *
     * @param  list<string>|null  $statuses
     * @return list<array{page_id: int, title: string, page_type: string, scope: string, slug: string, content_markdown: string, figures: list<array<string, mixed>>, headings: list<string>, excerpt: string, outgoing_link_count: int, backlink_count: int}>
     */
    public function build(int $customerId, ?array $statuses = null, bool $requireCurrentVersionApproval = false): array
    {
        $eligibleStatuses = array_values(array_intersect(
            $statuses ?? [EnterpriseWikiPage::STATUS_APPROVED],
            self::CURRENT_KNOWLEDGE_STATUSES,
        ));

        if ($eligibleStatuses === []) {
            return [];
        }

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $eligibleStatuses)
            ->with('currentVersion')
            ->get()
            ->filter(fn (EnterpriseWikiPage $page): bool => trim((string) $page->currentVersion?->content_markdown) !== '')
            ->values();

        if ($requireCurrentVersionApproval) {
            $approvedPageIds = array_flip($this->documentOwnerApprovals->approvedCurrentVersionPageIds(
                $customerId,
                $pages->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            ));

            $pages = $pages
                ->filter(fn (EnterpriseWikiPage $page): bool => isset($approvedPageIds[(int) $page->id]))
                ->values();
        }

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
                    'scope' => (string) $page->scope,
                    'slug' => $page->slug,
                    'content_markdown' => $contentMarkdown,
                    // Figures live only in content_blocks_json — content_markdown carries their
                    // caption and citation but never the image itself. Carried alongside the text
                    // so a page that is actually read can offer its own figures to the answer.
                    'figures' => $this->figureCatalog->fromContentBlocks(
                        (array) ($page->currentVersion->content_blocks_json ?? []),
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
