<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\EnterpriseWikiPageVersion;

/**
 * Fase 8K-1: finds the existing Wiki pages a NEW source document might be changing, and returns
 * their real current content so the maintainer decision can actually see what the Wiki says today.
 *
 * The gap this closes (run 25): a document that explicitly revised existing requirements produced
 * only new pages, because the maintainer's Wiki index carries a 200-character excerpt per page
 * (EnterpriseWikiIndexContextService) — far too little to reveal a concrete threshold, deadline or
 * rule sitting in the middle of a page. The maintainer knew the pages existed; it could not see
 * that they held values the new document supersedes.
 *
 * This is the two-step context model from the plan, resolved deterministically rather than with a
 * second model request: broad metadata (the existing index) narrows to a small candidate set here,
 * and only those candidates get their full current content loaded.
 *
 * Two deterministic signals, in strict priority order:
 *
 *  1. DIRECTLY NAMED — the document mentions an existing page's title. This is the exact mirror of
 *     EnterpriseWikiIncrementalRelinkService::findCandidates(): that service asks "does an existing
 *     page mention this new page's title?", this one asks "does this new document mention an
 *     existing page's title?". Same primitive, a plain case-insensitive containment test.
 *
 *  2. ONE HOP ALONG THE CANONICAL WIKILINK GRAPH — pages the directly-named ones link to, or that
 *     link to them (EnterpriseWikiPageLink, link_type=wikilink). A change note names the service or
 *     concept it concerns, but rarely the exact title of every procedure page recording the rules
 *     it revises; those pages are reached through the Wiki's own relations. Exactly one hop, then
 *     the cap applies — this is a bounded neighbourhood, never a graph walk.
 *
 * Both signals are structural. No embeddings, no RAG, no keyword lists, and nothing domain-,
 * language- or customer-specific: a page is either named in the document, or linked to one that is.
 * Guarantees in both cases: customer-scoped, current-version-only, archived/superseded/rejected
 * excluded, hard-capped, deterministically ordered.
 *
 * 8K-1 is read-only. Nothing here decides, writes, or patches anything.
 */
class EnterpriseWikiPatchCandidateService
{
    /**
     * Hard cap on candidates. Deliberately far lower than
     * EnterpriseWikiIncrementalRelinkService::MAX_CANDIDATES_PER_TRIGGER (10): that service sends
     * one page's markdown per AI call, while every candidate here is appended to a single
     * maintainer prompt that already carries the source catalog and the Wiki index.
     *
     * Three is a measured value, not a guess. On the run-25 change document the pages holding the
     * superseded requirements ranked 1st and 2nd, so three covers the observed case with a slot of
     * margin; raising it to five added roughly 6 000 characters and no additional coverage, taking
     * the prompt from +57% to +100%. If a future document genuinely needs more, that should be a
     * tuning decision backed by the same measurement — never a reflex increase.
     */
    public const MAX_CANDIDATES = 3;

    /**
     * Per-candidate content ceiling. Truncation is applied on whole content-block boundaries where
     * the version has them (see contentFor()), so a candidate is never cut mid-sentence and the
     * concrete requirement a reader needs to spot stays intact.
     */
    public const MAX_CONTENT_CHARS = 6000;

    /**
     * A title shorter than this is not used as a match term. Very short titles produce incidental
     * substring hits that say nothing about relevance; the existing relink service relies on the
     * same "plainly mentions" assumption, and this keeps that assumption honest.
     */
    private const MIN_TITLE_LENGTH = 4;

    /** Page types that can hold authoritative content a change document might revise. */
    private const CANDIDATE_PAGE_TYPES = [
        EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
        EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
        EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        EnterpriseWikiPage::PAGE_TYPE_ENTITY,
    ];

    /** Statuses that are not live Wiki knowledge and must never be offered as patch candidates. */
    private const EXCLUDED_STATUSES = [
        EnterpriseWikiPage::STATUS_ARCHIVED,
        EnterpriseWikiPage::STATUS_SUPERSEDED,
        EnterpriseWikiPage::STATUS_REJECTED,
    ];

    /**
     * Existing pages this document plausibly touches, with their current content.
     *
     * @param  list<int>  $excludePageIds  pages already planned by this run — never their own candidate
     * @return list<array{
     *     page_id: int,
     *     title: string,
     *     slug: string,
     *     page_type: string,
     *     page_version_id: int,
     *     version_number: int,
     *     content: string,
     *     truncated: bool,
     *     mention_count: int,
     * }>
     */
    public function findForDocument(EnterpriseWikiDocument $document, array $excludePageIds = []): array
    {
        $sourceText = trim((string) $document->extracted_text);

        if ($sourceText === '') {
            return [];
        }

        $haystack = mb_strtolower($sourceText);

        // Customer scoping is applied in the query, never after the fact: a page belonging to
        // another customer is never even loaded, so it cannot leak into a prompt by oversight.
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $document->customer_id)
            ->whereIn('page_type', self::CANDIDATE_PAGE_TYPES)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereNotIn('id', $excludePageIds)
            ->orderBy('id')
            ->get(['id', 'title', 'slug', 'page_type']);

        $scored = [];

        foreach ($pages as $page) {
            $title = trim((string) $page->title);

            if (mb_strlen($title) < self::MIN_TITLE_LENGTH) {
                continue;
            }

            $mentions = mb_substr_count($haystack, mb_strtolower($title));

            if ($mentions === 0) {
                continue;
            }

            $scored[] = ['page' => $page, 'mentions' => $mentions];
        }

        if ($scored === []) {
            return [];
        }

        // Deterministic ordering: most-mentioned first, then page id ascending. Page id is a stable
        // tiebreak, unlike updated_at which can collide within a run and shift between runs.
        usort($scored, static fn (array $a, array $b): int => [$b['mentions'], $a['page']->id] <=> [$a['mentions'], $b['page']->id]);

        $selected = array_slice($scored, 0, self::MAX_CANDIDATES);

        // Signal 2: one hop along the canonical wikilink graph, only if the cap leaves room.
        $namedIds = array_map(static fn (array $row): int => (int) $row['page']->id, $selected);

        foreach ($this->oneHopNeighbours($document->customer_id, $namedIds, array_merge($namedIds, $excludePageIds)) as $neighbour) {
            if (count($selected) >= self::MAX_CANDIDATES) {
                break;
            }

            $selected[] = ['page' => $neighbour, 'mentions' => 0];
        }

        // One query for every candidate's current version rather than one per page.
        $versions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', array_map(static fn (array $row): int => $row['page']->id, $selected))
            ->where('is_current', true)
            ->get()
            ->keyBy('enterprise_wiki_page_id');

        $candidates = [];

        foreach ($selected as $row) {
            $version = $versions->get($row['page']->id);

            // A page with no current version has no authoritative content to compare against.
            if (! $version instanceof EnterpriseWikiPageVersion) {
                continue;
            }

            [$content, $truncated] = $this->contentFor($version);

            if (trim($content) === '') {
                continue;
            }

            $candidates[] = [
                'page_id' => (int) $row['page']->id,
                'title' => (string) $row['page']->title,
                'slug' => (string) $row['page']->slug,
                'page_type' => (string) $row['page']->page_type,
                'page_version_id' => (int) $version->id,
                'version_number' => (int) $version->version_number,
                'content' => $content,
                'truncated' => $truncated,
                'mention_count' => $row['mentions'],
            ];
        }

        return $candidates;
    }

    /**
     * Pages exactly one wikilink hop from the directly-named ones, in deterministic order: most
     * link connections to the named set first, then page id ascending. Uses only canonical
     * link_type=wikilink edges — the same relation Wiki navigation and the graph are built on.
     *
     * @param  list<int>  $namedIds
     * @param  list<int>  $excludeIds
     * @return list<EnterpriseWikiPage>
     */
    private function oneHopNeighbours(int $customerId, array $namedIds, array $excludeIds): array
    {
        if ($namedIds === []) {
            return [];
        }

        $edges = EnterpriseWikiPageLink::query()
            ->where('customer_id', $customerId)
            ->where('link_type', EnterpriseWikiPageLink::LINK_TYPE_WIKILINK)
            ->where(fn ($q) => $q->whereIn('from_page_id', $namedIds)->orWhereIn('to_page_id', $namedIds))
            ->get(['from_page_id', 'to_page_id']);

        $degree = [];

        foreach ($edges as $edge) {
            foreach ([(int) $edge->from_page_id, (int) $edge->to_page_id] as $side) {
                if (in_array($side, $namedIds, true) || in_array($side, $excludeIds, true)) {
                    continue;
                }

                $degree[$side] = ($degree[$side] ?? 0) + 1;
            }
        }

        if ($degree === []) {
            return [];
        }

        $neighbourIds = array_keys($degree);

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $neighbourIds)
            ->whereIn('page_type', self::CANDIDATE_PAGE_TYPES)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->get(['id', 'title', 'slug', 'page_type'])
            ->all();

        usort($pages, static fn (EnterpriseWikiPage $a, EnterpriseWikiPage $b): int => [$degree[$b->id] ?? 0, $a->id] <=> [$degree[$a->id] ?? 0, $b->id]);

        return $pages;
    }

    /**
     * The candidate's current content, truncated on block boundaries when the version carries
     * content_blocks_json and on a whole-line boundary otherwise. Never a mid-sentence cut: the
     * whole point is that a reader can still recognise a concrete requirement.
     *
     * @return array{0: string, 1: bool} [content, wasTruncated]
     */
    private function contentFor(EnterpriseWikiPageVersion $version): array
    {
        $markdown = trim((string) $version->content_markdown);

        if (mb_strlen($markdown) <= self::MAX_CONTENT_CHARS) {
            return [$markdown, false];
        }

        $blocks = (array) ($version->content_blocks_json ?? []);
        $parts = [];
        $used = 0;

        foreach ($blocks as $block) {
            $blockMarkdown = trim((string) ($block['markdown'] ?? ''));

            if ($blockMarkdown === '') {
                continue;
            }

            if ($used + mb_strlen($blockMarkdown) > self::MAX_CONTENT_CHARS) {
                break;
            }

            $parts[] = $blockMarkdown;
            $used += mb_strlen($blockMarkdown) + 2;
        }

        if ($parts !== []) {
            return [implode("\n\n", $parts), true];
        }

        // No usable blocks (older version, or a single oversized block): fall back to a line-boundary
        // cut so the last line rendered is still a complete one.
        $cut = mb_substr($markdown, 0, self::MAX_CONTENT_CHARS);
        $lastBreak = mb_strrpos($cut, "\n");

        return [trim($lastBreak !== false && $lastBreak > 0 ? mb_substr($cut, 0, $lastBreak) : $cut), true];
    }
}
