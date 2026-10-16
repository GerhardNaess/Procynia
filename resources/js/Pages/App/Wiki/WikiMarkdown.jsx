import ReactMarkdown from 'react-markdown';
import { markdownHeadingTag } from './wikiMarkdownHeadings';

/**
 * A Wiki article's Markdown, rendered under the page's own heading (WCAG 1.3.1, 2.4.6; A11Y-046).
 *
 * The page title is the page's h1. Most stored articles also start with `# {tittel}` — the ingest
 * writes it (EnterpriseWikiIngestService::assembleContentMarkdown) — so rendered as written, every
 * article had a second h1 under the first and its sections competed with the page's own h2s. The
 * headings are therefore shifted one level down at render time: `#` is an h2, `##` an h3, and so on
 * (h6 is the floor). The stored Markdown is not touched, and the parser is the same react-markdown
 * without plugins as before; only the element each heading becomes changes.
 *
 * `data-md-level` keeps the Markdown level on the element, so the article's typography
 * (`.wiki-article` in resources/css/app.css) looks exactly as it did — a `#` heading still has the
 * top-level size, whichever element it now is.
 *
 * @param {string}   children The Markdown.
 * @param {object}   [components] Further element overrides (the article passes its internal link).
 */
function shiftedHeading(level) {
    const Tag = markdownHeadingTag(level);

    // react-markdown passes `node` (its syntax tree) along with the element's props; it is not a DOM
    // attribute, so it is taken out before the rest is spread onto the heading.
    return function WikiHeading({ node, children, ...props }) {
        return (
            <Tag {...props} data-md-level={level}>
                {children}
            </Tag>
        );
    };
}

const HEADINGS = {
    h1: shiftedHeading(1),
    h2: shiftedHeading(2),
    h3: shiftedHeading(3),
    h4: shiftedHeading(4),
    h5: shiftedHeading(5),
    h6: shiftedHeading(6),
};

export default function WikiMarkdown({ children, components = {} }) {
    return (
        <ReactMarkdown components={{ ...HEADINGS, ...components }}>
            {children}
        </ReactMarkdown>
    );
}
