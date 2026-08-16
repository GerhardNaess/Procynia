<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;

/**
 * Deterministically ranks Wiki catalog entries (see RequirementWikiCatalogBuilder) against a set
 * of query tokens — either a requirement's own text, or explicit search_terms the research AI
 * client asked for during a "search_more" round. Never depends on database row order or on claim
 * ids; the same catalog + same query tokens always produce the same ordered candidate list.
 *
 * Scoring priority (highest weight first), matching the required signal order:
 * 1. Hit in the page title.
 * 2. Hits in the page's own headings.
 * 3. Number of distinct normalized requirement/query terms found anywhere in the page.
 * 4. Share of the query's own terms that were found in the page (rewards focused pages over
 *    long pages that merely contain a few matching words among a lot of unrelated text).
 * 5. Relevant (non-conflicting, current-version) claim hits — a supporting signal, never the
 *    deciding one on its own.
 * 6. Stable secondary sort on page_id — never on database/array order, never on lowest claim id.
 */
class RequirementWikiPageRanker
{
    /**
     * At most this many ranked candidates are ever returned. Chosen after inspecting real data:
     * a real customer's approved Wiki catalog was 31 pages; 15 is roughly half — generous enough
     * that a relevant page is very unlikely to be cut before the research step even sees it, while
     * still being a meaningful bound for a larger customer wiki (never "send the whole catalog").
     */
    public const MAX_CANDIDATES = 15;

    private const SCORE_TITLE_HIT = 100;

    private const SCORE_PER_HEADING_HIT = 15;

    private const SCORE_PER_TERM_OVERLAP = 5;

    private const SCORE_OVERLAP_RATIO_WEIGHT = 20;

    private const SCORE_PER_CLAIM_HIT = 3;

    /**
     * Deliberately lower than SCORE_PER_CLAIM_HIT (v0.9 provenance-gap closure): a page whose only
     * matching claims are best_practice-marked (never documented in the customer's own sources) may
     * still rank — recommendation-style requirements genuinely benefit from surfacing it — but a
     * page backed by a real source_based claim hit is a stronger recall signal for a customer-fact
     * question and should be preferred when both compete for the same score band.
     */
    private const SCORE_PER_BEST_PRACTICE_CLAIM_HIT = 1;

    /**
     * Purpose: Rank catalog entries against a set of already-normalized query tokens.
     * Inputs: The full catalog (see RequirementWikiCatalogBuilder::build()), the query tokens
     *         (RequirementWikiTermNormalizer::tokenize() output — caller's responsibility), the
     *         customer id (for scoping the claim-hit signal), and page ids to exclude (already
     *         read or already rejected this research run).
     * Returns: Candidates sorted by descending score then ascending page_id, each carrying a
     *          transparent score_breakdown, capped to MAX_CANDIDATES. content_markdown is
     *          deliberately NOT included — this is the compact, AI-facing candidate shape.
     * Side effects: None (read-only; one grouped claim-hit query).
     *
     * @param  list<array<string, mixed>>  $catalog
     * @param  list<string>  $queryTokens
     * @param  list<int>  $excludePageIds
     * @return list<array{page_id: int, title: string, page_type: string, scope: string, slug: string, headings: list<string>, excerpt: string, outgoing_link_count: int, backlink_count: int, score: int, score_breakdown: array}>
     */
    public function rank(array $catalog, array $queryTokens, int $customerId, array $excludePageIds = []): array
    {
        $catalog = array_values(array_filter(
            $catalog,
            static fn (array $entry): bool => ! in_array($entry['page_id'], $excludePageIds, true),
        ));

        if ($catalog === [] || $queryTokens === []) {
            return [];
        }

        $claimHitCounts = $this->claimHitCountsByPageId($customerId, $queryTokens);

        $scored = array_map(
            function (array $entry) use ($queryTokens, $claimHitCounts): array {
                $titleTokens = RequirementWikiTermNormalizer::tokenize($entry['title']);
                $headingTokens = RequirementWikiTermNormalizer::tokenize(implode(' ', $entry['headings']));
                $contentTokens = RequirementWikiTermNormalizer::tokenize($entry['content_markdown']);

                $titleHit = array_intersect($queryTokens, $titleTokens) !== [];
                [$headingOverlapCount] = RequirementWikiTermNormalizer::overlap($queryTokens, $headingTokens);
                [$contentOverlapCount, $contentOverlapRatio] = RequirementWikiTermNormalizer::overlap($queryTokens, $contentTokens);
                $hits = $claimHitCounts[$entry['page_id']] ?? ['source_based' => 0, 'best_practice' => 0];

                $score = ($titleHit ? self::SCORE_TITLE_HIT : 0)
                    + ($headingOverlapCount * self::SCORE_PER_HEADING_HIT)
                    + ($contentOverlapCount * self::SCORE_PER_TERM_OVERLAP)
                    + (int) round($contentOverlapRatio * self::SCORE_OVERLAP_RATIO_WEIGHT)
                    + ($hits['source_based'] * self::SCORE_PER_CLAIM_HIT)
                    + ($hits['best_practice'] * self::SCORE_PER_BEST_PRACTICE_CLAIM_HIT);

                return [
                    'page_id' => $entry['page_id'],
                    'title' => $entry['title'],
                    'page_type' => $entry['page_type'],
                    'scope' => $entry['scope'],
                    'slug' => $entry['slug'],
                    'headings' => $entry['headings'],
                    'excerpt' => $entry['excerpt'],
                    'outgoing_link_count' => $entry['outgoing_link_count'],
                    'backlink_count' => $entry['backlink_count'],
                    'score' => $score,
                    'score_breakdown' => [
                        'title_hit' => $titleHit,
                        'heading_overlap_count' => $headingOverlapCount,
                        'content_overlap_count' => $contentOverlapCount,
                        'content_overlap_ratio' => round($contentOverlapRatio, 3),
                        'claim_hit_count' => $hits['source_based'] + $hits['best_practice'],
                        'source_based_claim_hit_count' => $hits['source_based'],
                        'best_practice_claim_hit_count' => $hits['best_practice'],
                    ],
                ];
            },
            $catalog,
        );

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            // Stable, deterministic tie-break — never database/array order, never claim id.
            return $a['page_id'] <=> $b['page_id'];
        });

        return array_slice(
            array_values(array_filter($scored, static fn (array $entry): bool => $entry['score'] > 0)),
            0,
            self::MAX_CANDIDATES,
        );
    }

    /**
     * Purpose: Count, per page, how many approved/non-conflicting current-version claims match
     * the query tokens — a supporting recall signal, never the primary one. Split into a
     * best_practice bucket and an "everything else" bucket so a best_practice-marked claim
     * (recognized professional practice, not documented in the customer's own sources) can be
     * weighted lower than any other claim — see SCORE_PER_CLAIM_HIT vs
     * SCORE_PER_BEST_PRACTICE_CLAIM_HIT. This is a ranking recall signal only, not a citation
     * decision, so unclassified/legacy claims are deliberately NOT excluded here the way the
     * answer-grounding path (RequirementWikiResearchService::supportingClaimsByOrigin()) excludes
     * them — surfacing a candidate page for a human researcher to read carries none of the risk
     * that citing an unclassified claim as customer fact in a generated answer would.
     * Inputs: Customer id and query tokens.
     * Returns: page_id => ['source_based' => count, 'best_practice' => count].
     * Side effects: None (single grouped-in-PHP query; claim text volume per customer is small
     *               enough that scoring in PHP, not SQL, keeps the matching logic identical to
     *               every other relevance computation in this subsystem).
     *
     * @param  list<string>  $queryTokens
     * @return array<int, array{source_based: int, best_practice: int}>
     */
    private function claimHitCountsByPageId(int $customerId, array $queryTokens): array
    {
        $claims = EnterpriseWikiClaim::query()
            ->whereHas('page', function ($query) use ($customerId): void {
                $query->where('customer_id', $customerId)
                    ->where('status', EnterpriseWikiPage::STATUS_APPROVED);
            })
            ->whereHas('version', function ($query): void {
                $query->where('is_current', true);
            })
            ->where('conflict_flag', false)
            ->get(['id', 'enterprise_wiki_page_id', 'claim_text', 'content_origin']);

        $counts = [];

        foreach ($claims as $claim) {
            $claimTokens = RequirementWikiTermNormalizer::tokenize((string) $claim->claim_text);
            [$overlapCount] = RequirementWikiTermNormalizer::overlap($queryTokens, $claimTokens);

            // Deliberately >=1, not the stricter >=2 bar used elsewhere: claims exist here purely
            // to widen recall for a page that title/heading/content search would otherwise miss —
            // a single distinctive shared term (e.g. one specific named process) is enough to
            // surface it as a low-weight candidate, never as the primary signal.
            if ($overlapCount < 1) {
                continue;
            }

            $counts[$claim->enterprise_wiki_page_id] ??= ['source_based' => 0, 'best_practice' => 0];
            $bucket = $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE ? 'best_practice' : 'source_based';
            $counts[$claim->enterprise_wiki_page_id][$bucket]++;
        }

        return $counts;
    }
}
