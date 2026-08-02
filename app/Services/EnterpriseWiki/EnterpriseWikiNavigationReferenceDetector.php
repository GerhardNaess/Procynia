<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministically identifies text whose entire content is a bare pointer to another Wiki page
 * (finding #5646: "For detaljert flyt og rollebeskrivelser, se Illustrasjon av Incident
 * Management." was classified as a best-practice suggestion even though it asserts nothing of its
 * own — it only tells the reader where related content lives). No AI, no free-form keyword list:
 * the signal is structural. Text is split into SENTENCES on `.`/`!`/`?` only (never on a comma —
 * "For detaljert flyt og rollebeskrivelser, se [[link]]." is one sentence with an introductory
 * clause, not two independent statements each needing their own link). Every sentence must both
 * contain a genuine [[wikilink]] and, once that link's own markup is removed, leave only a short
 * residual with no room for an independent assertion. A sentence with no wikilink at all, or whose
 * residual is long enough to carry its own claim, disqualifies the WHOLE text — a professional
 * statement is never hidden just because a wikilink also appears somewhere in the same text
 * (conservative-by-construction: requirement is per-sentence, not merely "a link exists
 * somewhere").
 */
class EnterpriseWikiNavigationReferenceDetector
{
    /**
     * A sentence's residual word count (after removing its own wikilink markup) must not exceed
     * this to count as a bare pointer. Calibrated against the longest legitimate navigation
     * sentences ("For detaljert flyt og rollebeskrivelser, se [[link]]." and "Begreper og
     * rammeverk er omtalt på [[link]]." — both 6 residual words) with a small margin, while
     * staying well below a sentence that also carries its own assertion ("Incident Management
     * skal alltid ha én tydelig sakseier, se [[link]]." — 9 residual words in the same sentence,
     * comma-joined rather than link-only).
     */
    private const MAX_RESIDUAL_WORDS = 7;

    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
    ) {}

    /**
     * True only when every non-blank sentence in $text is a bare wikilink pointer. A single
     * sentence that lacks a wikilink, or whose own wording extends beyond the link itself, makes
     * the whole text ineligible — this never exempts a text merely because a wikilink appears
     * somewhere inside it.
     */
    public function isPureNavigationReference(string $text): bool
    {
        $sentences = $this->splitIntoSentences($text);

        if ($sentences === []) {
            return false;
        }

        $hasSubstantiveSentence = false;

        foreach ($sentences as $sentence) {
            if (trim($sentence) === '') {
                continue;
            }

            // A heading line is a hard boundary — never a navigation-only judgment call.
            if (preg_match('/^\s*#{1,6}\s/u', $sentence) === 1) {
                return false;
            }

            if (! $this->isBareLinkSentence($sentence)) {
                return false;
            }

            $hasSubstantiveSentence = true;
        }

        return $hasSubstantiveSentence;
    }

    private function isBareLinkSentence(string $sentence): bool
    {
        $links = $this->linkParser->parse($sentence);

        if ($links === []) {
            return false;
        }

        $residual = $sentence;

        foreach ($links as $link) {
            $residual = str_replace($link['original_markup'], ' ', $residual);
        }

        $words = preg_split('/\s+/u', trim($residual), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) && count($words) <= self::MAX_RESIDUAL_WORDS;
    }

    /**
     * Splits on sentence-ending punctuation only (. ! ?) — deliberately NOT on a comma, so an
     * introductory clause sharing a sentence with the link ("For detaljert flyt og
     * rollebeskrivelser, se [[link]].") is judged as one unit, not as a separate, link-less
     * "sentence" that would wrongly disqualify the whole text.
     *
     * @return list<string>
     */
    private function splitIntoSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
