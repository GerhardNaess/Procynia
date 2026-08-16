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
