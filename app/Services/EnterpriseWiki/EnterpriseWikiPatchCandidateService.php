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
 * Three deterministic signals, combined into one ranking (see tierFor()):
 *
 *  1. DIRECTLY NAMED — the document mentions an existing page's title. This is the exact mirror of
 *     EnterpriseWikiIncrementalRelinkService::findCandidates(): that service asks "does an existing
 *     page mention this new page's title?", this one asks "does this new document mention an
 *     existing page's title?". Same primitive, a plain case-insensitive containment test.
 *
 *  2. ONE HOP ALONG THE CANONICAL WIKILINK GRAPH — pages the directly-named ones link to, or that
 *     link to them (EnterpriseWikiPageLink, link_type=wikilink). A change note names the service or
 *     concept it concerns, but rarely the exact title of every procedure page recording the rules
 *     it revises; those pages are reached through the Wiki's own relations. Exactly one hop — a
 *     bounded neighbourhood, never a graph walk.
 *
 *  3. SUBSTANCE MATCH — the page's current content states something this document puts a number on
 *     (see substanceScores()). Added after run 27: signals 1 and 2 answer "is this page related?",
 *     and that let two pages still holding the superseded values rank behind a graph neighbour with
 *     no affected substance at all, so they never became candidates and never got patch targets.
 *     This signal answers the different question "does this page state the thing being changed?".
 *
 * Ranking puts actual involvement ahead of mere proximity: a page whose content matches outranks a
 * graph neighbour whose content does not. That is the property the cap depends on — with only three
 * slots, they must be spent on pages that genuinely hold affected substance.
 *
 * All three signals are structural. No embeddings, no RAG, no keyword lists, and nothing domain-,
 * language- or customer-specific: a page is named in the document, linked to one that is, or states
 * a number-bearing phrase the document also states. Guarantees in every case: customer-scoped,
 * current-version-only, archived/superseded/rejected excluded, hard-capped, deterministically
 * ordered — identical input yields a byte-identical block.
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
     *
     * Re-measured on the run-27 document after the substance signal was added, same Wiki state:
     *
     *   before   1. p55 (stale)  2. p49 (stale)  3. p53 (NO affected substance)  4. p50 (stale)  5. p54 (stale)
     *   after    1. p55 (stale)  2. p49 (stale)  3. p50 (stale)                  4. p54 (stale)  ... 7. p53
     *
     * The cap stays at three because the fix was never "more slots", it was "stop spending a slot on
     * a page with nothing affected": p53 fell from 3rd to 7th and all three slots now go to pages
     * that genuinely hold superseded substance.
     *
     * Known and deliberate residual: that document has FOUR affected pages, so three slots cannot
     * cover them all — p54 sits 4th. That is arithmetic, not a ranking defect, and raising the cap to
     * paper over it would be exactly the reflex this docblock warns against. Full per-value coverage
     * is a separate design question (an owner set derived per changed value rather than a fixed
     * top-N), and it belongs to whoever takes it on with its own measurement.
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

    /**
     * Punctuation trimmed from a token before it is compared. Only surrounding punctuation is
     * removed — never anything inside the token, so a decimal separator, a hyphenated identifier or
     * a date stays one token.
     */
    private const TOKEN_TRIM_CHARACTERS = ".,;:()[]{}\"'«»–—…!?/\\|*_`#";

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
     *     substance_match_count: int,
     *     neighbour_degree: int,
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

        if ($pages->isEmpty()) {
            return [];
        }

        // Signal 3 first: which of these pages actually state substance this document changes.
        $substanceScores = $this->substanceScores($sourceText, $pages->pluck('id')->all());

        // Signal 1: pages this document names by title.
        $mentionsById = [];

        foreach ($pages as $page) {
            $title = trim((string) $page->title);

            if (mb_strlen($title) < self::MIN_TITLE_LENGTH) {
                continue;
            }

            $mentions = mb_substr_count($haystack, mb_strtolower($title));

            if ($mentions > 0) {
                $mentionsById[(int) $page->id] = $mentions;
            }
        }

        // Signal 2: one hop along the canonical wikilink graph from the directly-named pages. Still
        // anchored on the named set specifically — a change note names the service it concerns, and
        // the graph is how the procedure pages recording its rules are reached.
        $namedIds = array_keys($mentionsById);
        $degreesById = $this->oneHopNeighbourDegrees($document->customer_id, $namedIds, $excludePageIds);

        $scored = [];

        foreach ($pages as $page) {
            $pageId = (int) $page->id;
            $mentions = $mentionsById[$pageId] ?? 0;
            $substance = $substanceScores[$pageId] ?? 0;
            $degree = $degreesById[$pageId] ?? 0;

            if ($mentions === 0 && $substance === 0 && $degree === 0) {
                continue;
            }

            $scored[] = [
                'page' => $page,
                'mentions' => $mentions,
                'substance' => $substance,
                'degree' => $degree,
                'tier' => $this->tierFor($mentions, $substance),
            ];
        }

        if ($scored === []) {
            return [];
        }

        // Deterministic ordering, strongest evidence of actual involvement first:
        //   tier (named+substance > substance > named > neighbour only)
        //   then substance score, then mention count, then graph degree,
        //   then page id ascending as a stable tiebreak — unlike updated_at, which can collide
        //   within a run and shift between runs.
        usort($scored, static fn (array $a, array $b): int => [
            $b['tier'], $b['substance'], $b['mentions'], $b['degree'], $a['page']->id,
        ] <=> [
            $a['tier'], $a['substance'], $a['mentions'], $a['degree'], $b['page']->id,
        ]);

        $selected = array_slice($scored, 0, self::MAX_CANDIDATES);

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
                'substance_match_count' => $row['substance'],
                'neighbour_degree' => $row['degree'],
            ];
        }

        return $candidates;
    }

    /**
     * Signal 3 — SUBSTANCE MATCH. How strongly each existing page states substance this document
     * puts a number on.
     *
     * The gap this closes (run 27): the maintainer produced correct, structured patch targets for
     * every candidate it was given, but two existing pages that still carried the superseded values
     * were never candidates — they ranked 4th and 5th behind a graph neighbour holding no affected
     * substance at all. Graph proximity says "this page is related"; it cannot say "this page states
     * the thing being changed". This signal says the latter.
     *
     * The mechanism is a NUMERIC-ANCHORED BIGRAM overlap. From both the source document and a page's
     * current content, take every token containing a digit, and pair it with its adjacent
     * non-numeric word ("120 units", "within 30 minutes", "99,5 prosent"). The score is the number of
     * DISTINCT such pairs the two texts share.
     *
     * Why this shape, measured rather than assumed (run-27 data, 18 existing pages):
     *  - bare numeric-token overlap ranked a page holding NO affected substance first, because
     *    incidental numbers ("2026", "1.0", an identifier) are shared everywhere
     *  - rarity/inverse-page-frequency weighting was worse still, for the same reason
     *  - bigram overlap put all four genuinely affected pages in the top four and dropped the
     *    unaffected graph neighbour to zero
     * A shared *number* is incidental. A shared number *together with the word next to it* is a
     * shared statement fragment — and restating the old value is exactly what an authoritative change
     * document does when it says what it supersedes.
     *
     * Deliberately generic: nothing here knows about percentages, minutes, currencies, dates, a
     * language, or a domain. "Contains a digit" is the only lexical assumption, and it holds in every
     * language and script this Wiki can hold. No keyword lists, no embeddings, no AI, no RAG — see
     * the class docblock: this stays deterministic pre-selection.
     *
     * Scanned against FULL current content, not the truncated prompt excerpt: a superseded value
     * sitting past MAX_CONTENT_CHARS must still make its page a candidate.
     *
     * @param  list<int>  $pageIds  already customer-scoped, type-filtered and status-filtered
     * @return array<int, int> page id => count of distinct shared numeric-anchored bigrams
     */
    private function substanceScores(string $sourceText, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $sourceBigrams = $this->numericBigrams($sourceText);

        if ($sourceBigrams === []) {
            return [];
        }

        $versions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pageIds)
            ->where('is_current', true)
            ->orderBy('enterprise_wiki_page_id')
            ->get(['enterprise_wiki_page_id', 'content_markdown']);

        $scores = [];

        foreach ($versions as $version) {
            $shared = array_intersect_key(
                $this->numericBigrams((string) $version->content_markdown),
                $sourceBigrams,
            );

            if ($shared !== []) {
                $scores[(int) $version->enterprise_wiki_page_id] = count($shared);
            }
        }

        return $scores;
    }

    /**
     * Every distinct bigram anchored on a token containing a digit, as a set keyed by the bigram.
     * Both orders are produced (numeric+following word, preceding word+numeric) so a value is matched
     * whether the unit follows it ("30 minutes") or precedes it ("within 30").
     *
     * @return array<string, true>
     */
    private function numericBigrams(string $text): array
    {
        $words = [];

        foreach (preg_split('/\s+/u', mb_strtolower($text)) ?: [] as $raw) {
            $word = trim((string) $raw, self::TOKEN_TRIM_CHARACTERS);

            if ($word !== '') {
                $words[] = $word;
            }
        }

        $bigrams = [];
        $count = count($words);

        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/\d/u', $words[$i]) !== 1) {
                continue;
            }

            // Pair only with a NON-numeric neighbour: two adjacent numbers ("99,5 99,7") carry no
            // statement fragment, and pairing them would reintroduce the noise this signal avoids.
            if (isset($words[$i + 1]) && preg_match('/\d/u', $words[$i + 1]) !== 1) {
                $bigrams[$words[$i].' '.$words[$i + 1]] = true;
            }

            if ($i > 0 && preg_match('/\d/u', $words[$i - 1]) !== 1) {
                $bigrams[$words[$i - 1].' '.$words[$i]] = true;
            }
        }

        return $bigrams;
    }

    /**
     * The tier a page lands in, which dominates ranking before any raw score:
     *
     *   3  named in the document AND states affected substance  — strongest possible evidence
     *   2  states affected substance                            — the run-27 gap: p50/p54 sat here
     *   1  named in the document                                — relevant, substance not shown
     *   0  reached only through the wikilink graph              — related, involvement unknown
     *
     * The load-bearing property is 2 > 0: a page that actually states the changed substance must not
     * lose a candidate slot to a graph neighbour that merely sits next to a named page.
     */
    private function tierFor(int $mentions, int $substance): int
    {
        return match (true) {
            $mentions > 0 && $substance > 0 => 3,
            $substance > 0 => 2,
            $mentions > 0 => 1,
            default => 0,
        };
    }

    /**
     * Signal 2 — how many wikilink connections each page has to the directly-named set. Uses only
     * canonical link_type=wikilink edges — the same relation Wiki navigation and the graph are built
     * on — and stays anchored on the NAMED pages specifically: a change note names the service it
     * concerns, and the graph is how the procedure pages recording its rules are reached.
     *
     * Returns degrees rather than pages now, so ranking can weigh graph proximity against the other
     * two signals instead of using it only to fill leftover slots. Page filtering (customer, type,
     * status, exclusions) is already done by the caller's page query, so a neighbour that is not a
     * legal candidate simply never appears in the scored set.
     *
     * @param  list<int>  $namedIds
     * @param  list<int>  $excludeIds
     * @return array<int, int> page id => number of edges to the named set
     */
    private function oneHopNeighbourDegrees(int $customerId, array $namedIds, array $excludeIds): array
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

        return $degree;
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
