<?php

namespace App\Services\EnterpriseWiki;

/**
 * Single, shared text normalization for comparing a claim's anchor text (page_excerpt or
 * claim_text) against the visible text of a Wiki content block or page — used wherever a claim
 * needs to be located inside already-generated Wiki markdown (claim extraction's block matching,
 * claim verification's anchor check).
 *
 * The AI-generated markdown a claim's anchor is compared against may contain inline
 * [[wikilink]]/[[slug|anchor]] markup, standard Markdown links, emphasis, heading markers, and
 * list prefixes that were not present (or were rendered differently) when the claim's anchor
 * text was captured. A literal substring comparison of raw markdown against a plain-text claim
 * excerpt produces false "anchor not found" results for exactly this reason — not because the
 * claim's text is actually absent from the block, but because the surrounding markup differs.
 * Normalizing both sides through the same rules before comparing removes that false-negative
 * source without weakening genuine anchor/content mismatches.
 *
 * [[slug]] (no explicit anchor) resolves to the slug itself as visible text — the same rule
 * EnterpriseWikiLinkParser::parse() already applies for an unpiped link (anchor_text defaults to
 * target_slug). [[slug|text]] resolves to `text`. This class does not look up target pages, so a
 * link's visible text is always exactly what EnterpriseWikiLinkParser already computes it to be.
 */
class EnterpriseWikiClaimAnchorTextNormalizer
{
    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
    ) {}

    /**
     * Normalize markdown/plain text for anchor comparison: resolve wikilinks and Markdown links
     * to their visible text, strip emphasis/heading/list markup, decode HTML entities, collapse
     * whitespace, and lowercase.
     */
    public function normalize(string $text): string
    {
        $text = $this->resolveWikilinks($text);
        $text = $this->stripMarkdownLinks($text);
        $text = $this->stripEmphasis($text);
        $text = $this->stripHeadingMarkers($text);
        $text = $this->stripListPrefixes($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $this->collapseWhitespace($text);

        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Whether $needle (raw claim anchor text) is found inside $haystack (raw block/page
     * markdown) after both are normalized identically.
     *
     * Run-38 fix: two further, deliberately last-resort fallbacks for claim-extraction
     * boundary artifacts that are not really content mismatches:
     *
     * 1. Comma-for-period at a clause boundary — claim extraction sometimes closes an anchor
     *    with a period where the source itself continues the sentence with a comma + conjunction
     *    ("...redusere risiko for nye avbrudd, mens endringer vurderes...", extracted as
     *    "...redusere risiko for nye avbrudd."). A needle ending in "." also matches a haystack
     *    that has "," at the same position.
     * 2. Whitespace-insensitive fallback — a bare inflectional suffix immediately after a
     *    resolved wikilink can pick up a stray space during extraction that the source itself
     *    never had (source: "[[itil|ITIL-rammeverk]]et" → "ITIL-rammeverket", one word; claim
     *    anchor: "ITIL-rammeverk et", two words). Comparing with all whitespace stripped from
     *    both sides catches this (and similar stray-space artifacts) without weakening a genuine
     *    mismatch — a claim-length string matching only once every space is removed is, in
     *    practice, always the same content with a formatting glitch, never a coincidence.
     */
    public function contains(string $haystack, string $needle): bool
    {
        $normalizedNeedle = $this->normalize($needle);

        if ($normalizedNeedle === '') {
            return false;
        }

        $normalizedHaystack = $this->normalize($haystack);

        if (str_contains($normalizedHaystack, $normalizedNeedle)) {
            return true;
        }

        if (str_ends_with($normalizedNeedle, '.')) {
            $needleWithoutPeriod = rtrim(substr($normalizedNeedle, 0, -1));

            if ($needleWithoutPeriod !== '' && str_contains($normalizedHaystack, $needleWithoutPeriod.',')) {
                return true;
            }
        }

        $needleNoSpace = preg_replace('/\s+/u', '', $normalizedNeedle) ?? $normalizedNeedle;
        $haystackNoSpace = preg_replace('/\s+/u', '', $normalizedHaystack) ?? $normalizedHaystack;

        return $needleNoSpace !== '' && str_contains($haystackNoSpace, $needleNoSpace);
    }

    private function resolveWikilinks(string $text): string
    {
        $links = $this->linkParser->parse($text);

        if ($links === []) {
            return $text;
        }

        $replacements = [];

        foreach ($links as $link) {
            $replacements[$link['original_markup']] = $link['anchor_text'];
        }

        return strtr($text, $replacements);
    }

    private function stripMarkdownLinks(string $text): string
    {
        return preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
    }

    private function stripEmphasis(string $text): string
    {
        $text = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/u', '$1', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/u', '$1', $text) ?? $text;

        return preg_replace('/(?<!\w)_([^_]+)_(?!\w)/u', '$1', $text) ?? $text;
    }

    private function stripHeadingMarkers(string $text): string
    {
        return preg_replace('/^\h{0,3}#{1,6}\h+/mu', '', $text) ?? $text;
    }

    private function stripListPrefixes(string $text): string
    {
        return preg_replace('/^\h*(?:[-*+]|\d+[.)])\h+/mu', '', $text) ?? $text;
    }

    private function collapseWhitespace(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
