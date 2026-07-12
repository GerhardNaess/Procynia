<?php

namespace App\Services\EnterpriseWiki;

/**
 * Deterministic, safe canonicalization of inline [[wikilinks]] against an explicit allowed
 * catalog, run after AI generation and before EnterpriseWikiGenerateAppliedPagesService's final
 * wikilink validation.
 *
 * The model is given both a page's canonical `slug` and its `title` in the same catalog entry
 * (see EnterpriseWikiLinkCatalogService/WikiPageContentAiClient) — for a page whose slug is a
 * straightforward lowercasing of its title (e.g. slug "advania", title "Advania"), it is easy
 * for the model to write the bare, differently-cased title as if it were the slug: `[[Advania]]`
 * instead of `[[advania|Advania]]`. Without a canonicalization step this is rejected outright by
 * validateWikilinks() even though the model's intent was completely unambiguous.
 *
 * This class only rewrites near-misses that resolve to EXACTLY one catalog page:
 *   1. Exact canonical slug — already correct, left untouched.
 *   2. Case-insensitive slug match — exactly one catalog page's slug matches case-insensitively.
 *   3. Exact title match, case-insensitive — exactly one catalog page's title matches.
 *
 * Anything else — no match, an ambiguous match (more than one catalog page matches), or
 * malformed syntax — is left completely untouched and continues to be rejected downstream by
 * EnterpriseWikiLinkResolver/validateWikilinks() exactly as before. No fuzzy or partial
 * matching, no page creation, no change to self-link/cross-customer rejection (the catalog
 * passed in already excludes the page being generated and is customer-scoped).
 *
 * Never touches fenced code blocks or inline code spans, ordinary Markdown links, or any text
 * outside [[...]] markup.
 */
class EnterpriseWikiWikilinkCanonicalizer
{
    /**
     * Matches fenced code blocks (```...```) and inline code spans (`...`), mirroring
     * EnterpriseWikiWikilinkRenderer, so canonicalization never rewrites literal "[[...]]"
     * text shown as an example inside code.
     */
    private const CODE_PATTERN = '/(```.*?```|`[^`\n]*`)/s';

    public function __construct(
        private readonly EnterpriseWikiLinkParser $parser,
    ) {}

    /**
     * @param  list<array{slug: string, title: string, page_type: string}>  $catalog
     */
    public function canonicalize(string $markdown, array $catalog): string
    {
        if ($catalog === []) {
            return $markdown;
        }

        [$slugIndex, $titleIndex] = $this->buildIndexes($catalog);

        return $this->transformOutsideCode(
            $markdown,
            fn (string $segment) => $this->canonicalizeSegment($segment, $slugIndex, $titleIndex),
        );
    }

    /**
     * @param  list<array{slug: string, title: string, page_type: string}>  $catalog
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private function buildIndexes(array $catalog): array
    {
        $slugIndex = [];
        $titleIndex = [];

        foreach ($catalog as $entry) {
            $slugIndex[mb_strtolower($entry['slug'])][] = $entry['slug'];
            $titleIndex[mb_strtolower($entry['title'])][] = $entry['slug'];
        }

        return [$slugIndex, $titleIndex];
    }

    private function canonicalizeSegment(string $segment, array $slugIndex, array $titleIndex): string
    {
        $parsed = $this->parser->parse($segment);

        if ($parsed === []) {
            return $segment;
        }

        $replacements = [];

        foreach ($parsed as $link) {
            $markup = $link['original_markup'];

            if (array_key_exists($markup, $replacements)) {
                continue;
            }

            $canonicalSlug = $this->resolveCanonicalSlug($link['target_slug'], $slugIndex, $titleIndex);

            if ($canonicalSlug === null || $canonicalSlug === $link['target_slug']) {
                // Already canonical, or no safe unambiguous match — leave completely
                // untouched. An unresolved target continues to be rejected as broken/self
                // by EnterpriseWikiLinkResolver exactly as before.
                continue;
            }

            $replacements[$markup] = "[[{$canonicalSlug}|{$link['anchor_text']}]]";
        }

        return $replacements === [] ? $segment : strtr($segment, $replacements);
    }

    /**
     * @param  array<string, list<string>>  $slugIndex
     * @param  array<string, list<string>>  $titleIndex
     */
    private function resolveCanonicalSlug(string $targetSlug, array $slugIndex, array $titleIndex): ?string
    {
        $lower = mb_strtolower($targetSlug);

        $slugMatches = array_values(array_unique($slugIndex[$lower] ?? []));

        if (count($slugMatches) === 1) {
            return $slugMatches[0];
        }

        if (count($slugMatches) > 1) {
            // Two catalog pages whose slugs differ only by case — genuinely ambiguous.
            return null;
        }

        $titleMatches = array_values(array_unique($titleIndex[$lower] ?? []));

        return count($titleMatches) === 1 ? $titleMatches[0] : null;
    }

    /**
     * Splits markdown into alternating non-code/code segments and applies $transform
     * only to the non-code segments, leaving fenced code blocks and inline code spans
     * byte-for-byte untouched. Mirrors EnterpriseWikiWikilinkRenderer::transformOutsideCode().
     */
    private function transformOutsideCode(string $markdown, \Closure $transform): string
    {
        $parts = preg_split(self::CODE_PATTERN, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $transform($markdown);
        }

        $result = '';

        foreach ($parts as $index => $part) {
            $result .= $index % 2 === 1 ? $part : $transform($part);
        }

        return $result;
    }
}
