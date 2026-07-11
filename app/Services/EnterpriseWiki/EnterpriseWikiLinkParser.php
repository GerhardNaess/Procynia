<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministic parser for the canonical Enterprise Wiki inline link syntax:
 *
 *   [[target-slug]]
 *   [[target-slug|visible anchor text]]
 *
 * This is the only parser for wiki links in the codebase — it is pure text
 * processing with no database access and no LLM involvement. It does not resolve
 * slugs against any page catalog (see EnterpriseWikiLinkResolver for that) and it
 * never rewrites or "repairs" the input markdown.
 *
 * `[[slug#section]]` is intentionally not special-cased in this phase — the
 * `#section` fragment is treated as a literal (and currently unresolvable) part
 * of the slug.
 */
class EnterpriseWikiLinkParser
{
    /**
     * Matches well-formed `[[...]]` spans whose inner content contains no further
     * bracket characters. Malformed markup (unclosed `[[`, nested brackets, a lone
     * `[slug]`) simply produces no match here rather than being "fixed" — that is
     * the deterministic classification the parser contract requires.
     */
    private const PATTERN = '/\[\[([^\[\]]*)\]\]/u';

    /**
     * Parse all valid inline wikilinks out of markdown, in the order they occur.
     *
     * A span is excluded from the result (not returned as an "invalid" entry — it
     * simply is not a link) when:
     *   - the slug is empty after trimming, or
     *   - a `|` is present but the anchor text is empty after trimming.
     *
     * @return list<array{target_slug: string, anchor_text: string, original_markup: string, occurrence_order: int}>
     */
    public function parse(string $markdown): array
    {
        if (! preg_match_all(self::PATTERN, $markdown, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $results = [];
        $occurrenceOrder = 0;

        foreach ($matches as $match) {
            $originalMarkup = $match[0];
            $inner = $match[1];

            $pipePosition = strpos($inner, '|');

            if ($pipePosition === false) {
                $slug = trim($inner);
                $anchor = $slug;
            } else {
                $slug = trim(substr($inner, 0, $pipePosition));
                $anchor = trim(substr($inner, $pipePosition + 1));

                if ($anchor === '') {
                    // [[slug|]] — empty anchor is explicitly invalid.
                    continue;
                }
            }

            if ($slug === '') {
                // [[]] or [[|anchor]] — empty slug is always invalid.
                continue;
            }

            $results[] = [
                'target_slug' => $slug,
                'anchor_text' => $anchor,
                'original_markup' => $originalMarkup,
                'occurrence_order' => $occurrenceOrder,
            ];

            $occurrenceOrder++;
        }

        return $results;
    }

    /**
     * Counts every bracket-matched `[[...]]` span in the markdown, including ones parse()
     * drops for having an empty slug or empty anchor. The gap between this count and
     * count(parse($markdown)) is exactly the number of malformed-but-clearly-attempted
     * wikilinks — useful for callers (e.g. AI-output validation) that need to reject
     * near-miss syntax rather than silently ignore it, without the parser itself
     * attempting to repair anything.
     */
    public function countRawOccurrences(string $markdown): int
    {
        return (int) preg_match_all(self::PATTERN, $markdown);
    }
}
