/**
 * Which element a Markdown heading becomes inside a Wiki article (A11Y-046). Pure, so the rule is
 * unit-tested: one level below what the Markdown says, because the page title is already the h1 —
 * `#` → h2, `##` → h3 … `#####` and `######` → h6.
 */
export const ARTICLE_HEADING_SHIFT = 1;

export function markdownHeadingTag(markdownLevel) {
    const level = Number.isInteger(markdownLevel) && markdownLevel >= 1 ? markdownLevel : 1;

    return `h${Math.min(6, level + ARTICLE_HEADING_SHIFT)}`;
}
