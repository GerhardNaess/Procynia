import { createElement } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { normalizeWikiAnswerText } from './wikiAnswerPresentation.js';

/**
 * The stored answer is, and stays, Markdown in one `answer_text` column — this module only decides
 * how that one string is DISPLAYED. It is written with createElement rather than JSX so the node
 * --test runner (`npm run test:unit`, no JSX transform) can import and render it directly; that is
 * the only reason this is a .js file and not a .jsx one.
 *
 * remark-gfm is load-bearing: react-markdown's core is CommonMark, where pipe tables are not a
 * construct at all. Without the plugin a Wiki answer's table renders as the literal
 * "| Element | Beskrivelse |" text the model wrote, which is exactly the regression this fixes.
 *
 * Raw HTML in the answer is deliberately NOT rendered: react-markdown drops HTML nodes unless
 * rehype-raw is added, and it is not added here. Answer text is model-authored, so it is treated
 * as untrusted markup.
 */
const paragraph = (props) => createElement('p', { className: 'mb-3 last:mb-0 leading-7' }, props.children);

export const WIKI_ANSWER_MARKDOWN_COMPONENTS = {
    p: paragraph,
    h1: (props) => createElement('h3', { className: 'mb-2 mt-4 first:mt-0 text-lg font-semibold text-slate-950' }, props.children),
    h2: (props) => createElement('h3', { className: 'mb-2 mt-4 first:mt-0 text-lg font-semibold text-slate-950' }, props.children),
    h3: (props) => createElement('h4', { className: 'mb-2 mt-4 first:mt-0 text-base font-semibold text-slate-950' }, props.children),
    h4: (props) => createElement('h4', { className: 'mb-2 mt-4 first:mt-0 text-base font-semibold text-slate-950' }, props.children),
    ul: (props) => createElement('ul', { className: 'mb-3 list-disc space-y-1 pl-5' }, props.children),
    ol: (props) => createElement('ol', { className: 'mb-3 list-decimal space-y-1 pl-5' }, props.children),
    li: (props) => createElement('li', { className: 'leading-7' }, props.children),
    blockquote: (props) => createElement('blockquote', { className: 'mb-3 border-l-4 border-violet-200 pl-4 text-slate-700' }, props.children),
    code: (props) => createElement('code', { className: 'rounded bg-slate-100 px-1 py-0.5 text-[0.95em]' }, props.children),
    a: (props) => createElement(
        'a',
        {
            href: props.href,
            target: '_blank',
            rel: 'noreferrer noopener',
            className: 'text-violet-700 underline underline-offset-2',
        },
        props.children,
    ),
    // A wide table must scroll inside its own box rather than widening the answer panel.
    table: (props) => createElement(
        'div',
        { className: 'mb-4 overflow-x-auto rounded-[14px] border border-slate-200' },
        createElement('table', { className: 'w-full min-w-max border-collapse text-base text-slate-800' }, props.children),
    ),
    thead: (props) => createElement('thead', { className: 'bg-slate-50' }, props.children),
    th: (props) => createElement('th', { scope: 'col', className: 'border-b border-slate-200 px-4 py-2.5 text-left font-semibold text-slate-700' }, props.children),
    td: (props) => createElement('td', { className: 'border-b border-slate-100 px-4 py-2.5 align-top' }, props.children),
};

/**
 * Purpose: Render one saved Wiki answer's Markdown as readable output (real tables, headings, lists).
 * Inputs: `text` — the answer's Markdown exactly as stored.
 * Returns: A react-markdown element; an empty/blank answer renders nothing.
 */
export function WikiAnswerMarkdown({ text }) {
    const markdown = normalizeWikiAnswerText(text);

    if (markdown.trim() === '') {
        return null;
    }

    return createElement(
        ReactMarkdown,
        { remarkPlugins: [remarkGfm], components: WIKI_ANSWER_MARKDOWN_COMPONENTS },
        markdown,
    );
}

/**
 * Purpose: Render one Wiki figure the answer carries.
 * Inputs: A resolved figure from the answer payload (image_url, caption, alt_text, page_reference).
 * Returns: A semantic <figure>/<img>/<figcaption>, the same shape the Wiki page itself uses.
 *
 * The citation is not decoration: a figure lifted out of the Wiki into a bid answer must keep
 * saying where it came from, so it never reads as a loose illustration the AI produced.
 */
export function WikiAnswerFigure({ figure }) {
    if (!figure || typeof figure.image_url !== 'string' || figure.image_url === '') {
        return null;
    }

    const caption = typeof figure.caption === 'string' ? figure.caption : '';
    const sourceLine = typeof figure.page_reference === 'string' && figure.page_reference !== ''
        ? figure.page_reference
        : (typeof figure.page_title === 'string' ? figure.page_title : '');

    return createElement(
        'figure',
        { className: 'not-prose my-4 space-y-2', 'data-testid': 'wiki-answer-figure' },
        createElement('img', {
            src: figure.image_url,
            alt: typeof figure.alt_text === 'string' ? figure.alt_text : '',
            className: 'max-w-full rounded-2xl border border-slate-200 bg-white shadow-sm',
        }),
        createElement(
            'figcaption',
            { className: 'text-base text-slate-600' },
            caption !== '' ? createElement('span', { className: 'font-semibold text-slate-700' }, caption) : null,
            caption !== '' && sourceLine !== '' ? ' — ' : null,
            sourceLine !== '' ? sourceLine : null,
        ),
    );
}

/**
 * Purpose: Render a whole answer — text plus the figures the generator placed in it.
 * Inputs: `text` (the stored Markdown), `figures` (resolved figures) and `segments` (the answer
 *         split into its generated sections, or null when that split can no longer be proven).
 * Returns: Section-by-section output with each figure at its own section when `segments` is
 *          available; otherwise the whole answer followed by its figures.
 *
 * The fallback exists because answer_text is hand-editable: once an edit has moved the section
 * boundaries, placing a figure "at section 3" would put it against text it does not illustrate.
 * The figures are still shown, still cited — only their position degrades.
 */
export function WikiAnswerBody({ text, figures = [], segments = null }) {
    const allFigures = Array.isArray(figures) ? figures.filter(Boolean) : [];

    if (Array.isArray(segments) && segments.length > 0) {
        return createElement(
            'div',
            null,
            ...segments.map((segment, index) => createElement(
                'div',
                { key: segment?.section_key ?? `segment-${index}` },
                createElement(WikiAnswerMarkdown, { text: segment?.text }),
                ...(Array.isArray(segment?.figures) ? segment.figures : []).map((figure, figureIndex) =>
                    createElement(WikiAnswerFigure, { key: figure?.figure_ref ?? `figure-${figureIndex}`, figure })),
            )),
        );
    }

    return createElement(
        'div',
        null,
        createElement(WikiAnswerMarkdown, { text }),
        ...allFigures.map((figure, index) =>
            createElement(WikiAnswerFigure, { key: figure?.figure_ref ?? `figure-${index}`, figure })),
    );
}
