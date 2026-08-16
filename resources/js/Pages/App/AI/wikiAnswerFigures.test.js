import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { WikiAnswerBody, WikiAnswerFigure } from './wikiAnswerMarkdown.js';
import { buildWikiAnswerCopyHtml } from './wikiAnswerPresentation.js';

const FIGURE = {
    figure_ref: 'fig:63:img3',
    page_id: 187,
    page_title: 'Samhandling',
    section_key: 'S2',
    section_index: 1,
    caption: 'Figur 4',
    alt_text: 'Samhandlingsmodellen',
    page_reference: 'Masterdata Samhandling.docx → Figur 4',
    image_url: '/app/wiki/sources/63/images/img3',
};

const render = (element) => renderToStaticMarkup(element);

describe('WikiAnswerFigure', () => {
    test('a figure renders as figure/img/figcaption with its caption and alt text', () => {
        const html = render(createElement(WikiAnswerFigure, { figure: FIGURE }));

        assert.match(html, /<figure/);
        assert.match(html, /<img[^>]+src="\/app\/wiki\/sources\/63\/images\/img3"/);
        assert.match(html, /alt="Samhandlingsmodellen"/);
        assert.match(html, /<figcaption/);
        assert.match(html, /Figur 4/);
    });

    test('the source citation travels with the figure', () => {
        const html = render(createElement(WikiAnswerFigure, { figure: FIGURE }));

        assert.match(html, /Masterdata Samhandling\.docx/);
    });

    test('a figure without a usable image url renders nothing', () => {
        assert.equal(render(createElement(WikiAnswerFigure, { figure: { ...FIGURE, image_url: '' } })), '');
        assert.equal(render(createElement(WikiAnswerFigure, { figure: null })), '');
    });
});

describe('WikiAnswerBody', () => {
    const SEGMENTS = [
        { section_key: 'S1', text: 'Første avsnitt.', figures: [] },
        { section_key: 'S2', text: 'Andre avsnitt.', figures: [FIGURE] },
    ];

    test('a figure is rendered at the section the generator chose, not at the end', () => {
        const html = render(createElement(WikiAnswerBody, {
            text: 'Første avsnitt.\n\nAndre avsnitt.',
            figures: [FIGURE],
            segments: SEGMENTS,
        }));

        assert.ok(html.indexOf('Første avsnitt.') < html.indexOf('Andre avsnitt.'));
        assert.ok(html.indexOf('Andre avsnitt.') < html.indexOf('<figure'));
        assert.equal(html.match(/<figure/g).length, 1);
    });

    test('without a proven section split the figures follow the whole answer', () => {
        const html = render(createElement(WikiAnswerBody, {
            text: 'Et omskrevet svar.',
            figures: [FIGURE],
            segments: null,
        }));

        assert.ok(html.indexOf('Et omskrevet svar.') < html.indexOf('<figure'));
        assert.equal(html.match(/<figure/g).length, 1);
    });

    test('an answer with no figures renders exactly the Markdown and nothing else', () => {
        const withoutFigures = render(createElement(WikiAnswerBody, { text: '| A | B |\n|---|---|\n| 1 | 2 |' }));

        assert.match(withoutFigures, /<table/);
        assert.ok(!withoutFigures.includes('<figure'));
    });

    test('tables still render inside a segmented answer', () => {
        const html = render(createElement(WikiAnswerBody, {
            text: 'Tekst.\n\n| A | B |\n|---|---|\n| 1 | 2 |',
            figures: [FIGURE],
            segments: [
                { section_key: 'S1', text: 'Tekst.', figures: [] },
                { section_key: 'S2', text: '| A | B |\n|---|---|\n| 1 | 2 |', figures: [FIGURE] },
            ],
        }));

        assert.match(html, /<table/);
        assert.match(html, /<th[^>]*>A<\/th>/);
        assert.match(html, /<figure/);
    });
});

describe('buildWikiAnswerCopyHtml', () => {
    /** Minimal stand-in for the rendered answer node; no DOM library is available to this runner. */
    function fakeElement(images) {
        const nodes = images.map((src) => {
            const attributes = { src };

            return {
                getAttribute: (name) => attributes[name] ?? null,
                setAttribute: (name, value) => { attributes[name] = value; },
                remove() { this.removed = true; },
                removed: false,
                attributes,
            };
        });

        return {
            nodes,
            cloneNode: () => ({
                querySelectorAll: () => nodes,
                get innerHTML() {
                    // A removed node is detached from the tree, so it is absent from innerHTML.
                    return nodes
                        .filter((node) => !node.removed)
                        .map((node) => `<img src="${node.attributes.src}">`)
                        .join('');
                },
            }),
        };
    }

    test('a figure is inlined as a data URI so it survives a paste into Word', async () => {
        const element = fakeElement(['/app/wiki/sources/63/images/img3']);
        const html = await buildWikiAnswerCopyHtml(element, async () => 'data:image/png;base64,AAAA');

        assert.match(html, /src="data:image\/png;base64,AAAA"/);
        assert.ok(!html.includes('/app/wiki/sources/'));
    });

    test('a figure whose bytes cannot be fetched is dropped rather than pasted broken', async () => {
        const element = fakeElement(['/app/wiki/sources/63/images/img3']);
        const html = await buildWikiAnswerCopyHtml(element, async () => null);

        assert.ok(element.nodes[0].removed);
        assert.equal(html, '');
    });

    test('nothing rendered means no HTML flavour at all, so plain text carries the copy', async () => {
        assert.equal(await buildWikiAnswerCopyHtml(null, async () => 'data:image/png;base64,AAAA'), '');
    });
});

describe('Figure wiring in the AI answer panel', () => {
    const showSource = readFileSync(fileURLToPath(new URL('./Show.jsx', import.meta.url)), 'utf8');

    test('the rendered answer is fed the resolved figures and segments', () => {
        assert.match(showSource, /<WikiAnswerBody\s+text=\{activeRequirementWikiAnswerText\}\s+figures=\{activeRequirementWikiAnswerFigures\}\s+segments=\{activeRequirementWikiAnswerSegments\}/);
    });

    test('the editor still edits answer_text alone', () => {
        const editorBlock = showSource.slice(
            showSource.indexOf('{isEditingActiveWikiAnswer ? ('),
            showSource.indexOf('data-testid="wiki-answer-rendered"'),
        );

        assert.ok(editorBlock.includes('updateActiveWikiAnswerText'));
        assert.ok(!editorBlock.includes('figure_ref'));
        // The user is told the figures are safe rather than left guessing.
        assert.ok(editorBlock.includes('wiki-answer-figure-edit-notice'));
    });
});
