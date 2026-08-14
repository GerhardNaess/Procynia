<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Services\Ai\Wiki\WikiPageContentAiClient;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles what the generation call SAID about each planned topic against what it actually WROTE.
 *
 * The review is a claim about the page ("I compared this topic against established practice and
 * found nothing to add" / "…and here is what is missing"). Like every other claim in this system it
 * is checked deterministically rather than trusted, and the check is a pure function of data that
 * already exists: the returned blocks in order, their content_origin, and the heading each one sits
 * under. No new mapping, no scoring, no text similarity.
 *
 * Two things this deliberately does NOT do:
 *
 *  - It never fails the run. A hard failure on "claimed a gap, wrote no clause" would make
 *    gap_found=false the safe answer, and then the contract measures nothing. The inconsistency is
 *    recorded and the claim is read back as false.
 *  - It never deletes or rewrites a best_practice block. A block carries its own reason and is
 *    routed to human approval; review metadata is the weaker signal of the two, so when they
 *    disagree the CONTENT wins and the metadata is annotated. Dropping generated substance on the
 *    strength of a boolean is exactly the class of silent loss the block-provenance invariant
 *    exists to prevent.
 *
 * Neither inconsistency is user-facing. They are technical signals about the generation call, in the
 * same sense EnterpriseWikiClaimFindingExplainer::isUserFacingAddition() already separates
 * mechanism-level noise from a real case for a human.
 */
class EnterpriseWikiBestPracticeReviewReconciler
{
    /** A gap was reported but no best-practice clause was written for that topic. */
    public const INCONSISTENCY_CLAIMED_GAP_WITHOUT_CLAUSE = 'claimed_gap_without_clause';

    /** A best-practice clause exists for a topic the review reported as having no gap. */
    public const INCONSISTENCY_CLAUSE_WITHOUT_CLAIMED_GAP = 'clause_without_claimed_gap';

    /**
     * @param  list<array{planned_topic: string, gap_found: bool, assessment: string}>  $review
     * @param  list<array<string, mixed>>  $blocks  The generated blocks, as persisted.
     * @return list<array{planned_topic: string, gap_found: bool, assessment: string, best_practice_blocks: int, inconsistency: string|null}>
     */
    public function reconcile(array $review, array $blocks, string $pageType): array
    {
        if ($review === []) {
            return [];
        }

        $counts = $this->bestPracticeBlockCounts($blocks);
        $reconciled = [];

        foreach ($review as $entry) {
            $topic = trim((string) ($entry['planned_topic'] ?? ''));

            if ($topic === '') {
                continue;
            }

            $claimedGap = (bool) ($entry['gap_found'] ?? false);

            // A whole-page review answers for everything on the page, so every best-practice block
            // supports it — there are no sections to attribute one to. A per-topic review counts the
            // blocks under its own heading, plus any written before the first heading (those belong
            // to no section and would otherwise be invisible to every entry).
            $clauseCount = $topic === WikiPageContentAiClient::REVIEW_TOPIC_WHOLE_PAGE
                ? $counts['total']
                : $this->clausesForTopic($topic, $counts);

            $inconsistency = match (true) {
                $claimedGap && $clauseCount === 0 => self::INCONSISTENCY_CLAIMED_GAP_WITHOUT_CLAUSE,
                ! $claimedGap && $clauseCount > 0 => self::INCONSISTENCY_CLAUSE_WITHOUT_CLAIMED_GAP,
                default => null,
            };

            $reconciled[] = [
                'planned_topic' => $topic,
                // Read back from what was written, not from what was claimed: a gap with no clause
                // never reached the page, so as a record of this run it is a "no gap" outcome.
                'gap_found' => $clauseCount > 0,
                'assessment' => trim((string) ($entry['assessment'] ?? '')),
                'best_practice_blocks' => $clauseCount,
                'inconsistency' => $inconsistency,
            ];
        }

        $inconsistencies = array_filter(array_column($reconciled, 'inconsistency'));

        if ($inconsistencies !== []) {
            Log::info('[WIKI_BEST_PRACTICE_REVIEW] Review reconciled against generated blocks.', [
                'page_type' => $pageType,
                'reviewed' => count($reconciled),
                'inconsistencies' => array_count_values(array_values($inconsistencies)),
            ]);
        }

        return $reconciled;
    }

    /**
     * The clauses that support one topic's assessment: everything under a heading written for that
     * topic, plus anything written before the first heading — a clause with no section belongs to no
     * single topic, and leaving it uncounted everywhere would report a page that visibly carries a
     * recommendation as having produced none.
     *
     * @param  array{by_heading: array<string, int>, unattributed: int, total: int}  $counts
     */
    private function clausesForTopic(string $topic, array $counts): int
    {
        $found = $counts['unattributed'];

        foreach ($counts['by_heading'] as $heading => $count) {
            if ($this->matches($topic, $heading)) {
                $found += $count;
            }
        }

        return $found;
    }

    /**
     * How many best-practice blocks sit under each `## ` heading, plus how many sit under none.
     *
     * Attribution is block order and each block's own leading heading line — the same two facts
     * EnterpriseWikiBestPracticeSectionService reads, and nothing else. It is not reused directly
     * because that service answers a different question (which consecutive best-practice blocks
     * form ONE QA case) and treats a structural heading block as a boundary rather than a title.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{by_heading: array<string, int>, unattributed: int, total: int}
     */
    private function bestPracticeBlockCounts(array $blocks): array
    {
        $byHeading = [];
        $unattributed = 0;
        $total = 0;
        $currentHeading = null;

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $heading = $this->leadingHeading((string) ($block['markdown'] ?? ''));

            if ($heading !== null) {
                $currentHeading = $heading;
            }

            if (($block['content_origin'] ?? null) !== EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
                continue;
            }

            $total++;

            if ($currentHeading === null) {
                $unattributed++;

                continue;
            }

            $key = $this->normalize($currentHeading);
            $byHeading[$key] = ($byHeading[$key] ?? 0) + 1;
        }

        return ['by_heading' => $byHeading, 'unattributed' => $unattributed, 'total' => $total];
    }

    /** The heading text a block opens with, if any. */
    private function leadingHeading(string $markdown): ?string
    {
        $firstLine = trim(explode("\n", trim($markdown), 2)[0] ?? '');

        return preg_match('/^#{1,6}\s+(.+)$/', $firstLine, $matches) === 1 ? trim($matches[1]) : null;
    }

    /**
     * Whether a heading is the one written for a planned topic. Containment either way, exactly as
     * EnterpriseWikiPlannedSectionCoverageValidator matches them — the model routinely shortens a
     * long planned topic into a heading.
     */
    private function matches(string $topic, string $heading): bool
    {
        $topic = $this->normalize($topic);

        return $topic !== '' && $heading !== ''
            && (str_contains($heading, $topic) || str_contains($topic, $heading));
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/\([^)]*\)/u', ' ', mb_strtolower($value)) ?? mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
