<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministically identifies text whose entire content is a bare pointer to another Wiki page
 * (run 575's finding #5587: "Begreper og rammeverk er omtalt på ITIL Incident Management." was
 * classified as a best-practice suggestion even though it asserts nothing of its own — it only
 * tells the reader where related content lives). No AI, no free-form keyword list: the signal is
 * structural. Text is split into clauses on sentence/clause boundaries (. ! ? ,), and EVERY
 * clause must both contain a genuine [[wikilink]] and, once that link's own markup is removed,
 * leave only a short residual with no room for an independent assertion. A clause with no
 * wikilink at all, or whose residual is long enough to carry its own claim, disqualifies the
 * WHOLE text — a professional statement is never hidden just because a wikilink also appears
 * somewhere in the same sentence.
 */
class EnterpriseWikiNavigationReferenceDetector
{
    /**
     * A clause's residual word count (after removing its own wikilink markup) must not exceed
     * this to count as a bare pointer. Calibrated against the longest legitimate navigation
     * clause ("Begreper og rammeverk er omtalt på ITIL Incident Management." — 6 residual words)
     * with a small margin, while staying well below a clause that also carries its own assertion
     * ("Incident Management skal alltid ha én tydelig sakseier, se ITIL Incident Management." —
     * 9 residual words in its own comma-joined clause).
     */
    private const MAX_RESIDUAL_WORDS = 7;

    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
    ) {}

    /**
     * True only when every non-blank clause in $text is a bare wikilink pointer. A single clause
     * that lacks a wikilink, or whose own wording extends beyond the link itself, makes the whole
     * text ineligible — this never exempts a text merely because a wikilink appears somewhere
     * inside it.
     */
    public function isPureNavigationReference(string $text): bool
    {
        $clauses = $this->splitIntoClauses($text);

        if ($clauses === []) {
            return false;
        }

        $hasSubstantiveClause = false;

        foreach ($clauses as $clause) {
            if (trim($clause) === '') {
                continue;
            }

            if (! $this->isBareLinkClause($clause)) {
                return false;
            }

            $hasSubstantiveClause = true;
        }

        if (! $hasSubstantiveClause) {
            return false;
        }

        return true;
    }

    private function isBareLinkClause(string $clause): bool
    {
        $links = $this->linkParser->parse($clause);

        if ($links === []) {
            return false;
        }

        $residual = $clause;

        foreach ($links as $link) {
            $residual = str_replace($link['original_markup'], ' ', $residual);
        }

        $words = preg_split('/\s+/u', trim($residual), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) && count($words) <= self::MAX_RESIDUAL_WORDS;
    }

    /**
     * @return list<string>
     */
    private function splitIntoClauses(string $text): array
    {
        $parts = preg_split('/[.!?,]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
