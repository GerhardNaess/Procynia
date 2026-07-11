<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;

/**
 * Render-only transformation of canonical inline [[wikilinks]] into standard Markdown
 * links, for display purposes only.
 *
 * `content_markdown` in the database is never touched by this class — it stays the raw,
 * canonical source of truth (`[[slug]]` / `[[slug|anchor]]`), which
 * EnterpriseWikiLinkParser/Resolver and EnterpriseWikiBuildPageLinksService continue to
 * parse directly for materialization. This renderer produces a derived string
 * (`rendered_markdown`) for the frontend to display — nothing it does is persisted.
 *
 * Rules:
 * - A valid [[slug]]/[[slug|anchor]] pointing at another existing page for the same
 *   customer becomes a standard Markdown link: [anchor](/app/wiki/slug).
 * - Broken (unknown/cross-customer) slugs and self-links are rendered as plain text
 *   (the anchor text, brackets stripped) — never as a clickable or malformed link.
 * - Wikilink syntax inside fenced code blocks or inline code spans is left completely
 *   untouched, so example markdown containing "[[...]]" as literal text isn't rewritten.
 * - Ordinary Markdown links ([text](url)) are untouched — this class only ever matches
 *   the double-bracket [[...]] syntax.
 */
class EnterpriseWikiWikilinkRenderer
{
    /**
     * Matches fenced code blocks (```...```) and inline code spans (`...`) so the
     * transform can skip over them without interpreting a markdown parser.
     */
    private const CODE_PATTERN = '/(```.*?```|`[^`\n]*`)/s';

    public function __construct(
        private readonly EnterpriseWikiLinkParser $parser,
        private readonly EnterpriseWikiLinkResolver $resolver,
    ) {}

    public function render(string $markdown, int $customerId, EnterpriseWikiPage $sourcePage): string
    {
        return $this->transformOutsideCode(
            $markdown,
            fn (string $segment) => $this->transformSegment($segment, $customerId, $sourcePage),
        );
    }

    private function transformSegment(string $segment, int $customerId, EnterpriseWikiPage $sourcePage): string
    {
        $parsed = $this->parser->parse($segment);

        if ($parsed === []) {
            return $segment;
        }

        $occurrences = $this->resolver->resolveOccurrences($customerId, $sourcePage, $parsed);

        $replacements = [];

        foreach ($occurrences as $occurrence) {
            $link = $occurrence['link'];
            $markup = $link['original_markup'];

            if (array_key_exists($markup, $replacements)) {
                continue;
            }

            $replacements[$markup] = $occurrence['status'] === EnterpriseWikiLinkResolver::STATUS_VALID
                ? sprintf('[%s](/app/wiki/%s)', $link['anchor_text'], $occurrence['target_page']->slug)
                : $link['anchor_text'];
        }

        return strtr($segment, $replacements);
    }

    /**
     * Splits markdown into alternating non-code/code segments and applies $transform
     * only to the non-code segments, leaving fenced code blocks and inline code spans
     * byte-for-byte untouched.
     */
    private function transformOutsideCode(string $markdown, \Closure $transform): string
    {
        $parts = preg_split(self::CODE_PATTERN, $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $transform($markdown);
        }

        $result = '';

        foreach ($parts as $index => $part) {
            // preg_split with one capturing group + DELIM_CAPTURE alternates
            // [non-match, match, non-match, match, ...]; odd indices are code.
            $result .= $index % 2 === 1 ? $part : $transform($part);
        }

        return $result;
    }
}
