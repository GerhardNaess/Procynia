import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { WikiAnswerMarkdown } from './wikiAnswerMarkdown.js';
import { buildWikiAnswerCopyText, normalizeWikiAnswerText } from './wikiAnswerPresentation.js';

const render = (text) => renderToStaticMarkup(createElement(WikiAnswerMarkdown, { text }));

// The exact shape the Wiki answer engine produced for "Beskriv Leverandørens samhandlingsmodell."
// — the answer that showed up in the UI as literal pipe characters.
const ANSWER_WITH_TABLE = [
    'Vår samhandlingsmodell bygger på tydelig ansvarsdeling.',
    '',
    '| Element | Beskrivelse |',
    '|----------------------|-------------|',
    '| Organisering | Definerte roller og ansvar for begge parter |',
    '| Kommunikasjon | Faste møteplasser, digitale verktøy, rapportering |',
    '',
    'Modellen tilpasses den enkelte leveransen.',
].join('\n');

describe('WikiAnswerMarkdown', () => {
    test('a Markdown pipe table renders as a real table, not as literal pipe text', () => {
        const html = render(ANSWER_WITH_TABLE);

        assert.match(html, /<table/);
        assert.match(html, /<th[^>]*>Element<\/th>/);
        assert.match(html, /<th[^>]*>Beskrivelse<\/th>/);
        assert.match(html, /<td[^>]*>Organisering<\/td>/);
        assert.match(html, /<td[^>]*>Faste møteplasser, digitale verktøy, rapportering<\/td>/);
        // The regression itself: no row of the table survives as raw Markdown in the output.
        assert.ok(!html.includes('| Element | Beskrivelse |'));
        assert.ok(!html.includes('|----------------------|'));
    });

    test('prose around the table is kept and rendered as paragraphs', () => {
        const html = render(ANSWER_WITH_TABLE);

        assert.match(html, /<p[^>]*>Vår samhandlingsmodell bygger på tydelig ansvarsdeling\.<\/p>/);
        assert.match(html, /<p[^>]*>Modellen tilpasses den enkelte leveransen\.<\/p>/);
    });

    test('headings and lists render as headings and lists', () => {
        const html = render('## Styring\n\n- Roller\n- Ansvar\n\n1. Første\n2. Andre');

        assert.match(html, /<h3[^>]*>Styring<\/h3>/);
        assert.match(html, /<ul[^>]*>/);
        assert.match(html, /<li[^>]*>Roller<\/li>/);
        assert.match(html, /<ol[^>]*>/);
    });

    test('an answer with no Markdown syntax renders as plain paragraphs, unchanged', () => {
        const html = render('Vi svarer i klartekst.\n\nUten noen form for markup.');

        assert.match(html, /<p[^>]*>Vi svarer i klartekst\.<\/p>/);
        assert.match(html, /<p[^>]*>Uten noen form for markup\.<\/p>/);
        assert.ok(!html.includes('<table'));
    });

    test('raw HTML in the answer is never rendered as markup', () => {
        const html = render('Før <script>alert(1)</script> og <b>etter</b>.');

        assert.ok(!html.includes('<script'));
        assert.ok(!html.includes('<b>'));
    });

    test('an empty answer renders nothing rather than an empty document', () => {
        assert.equal(render(''), '');
        assert.equal(render('   \n  '), '');
        assert.equal(render(null), '');
    });
});

describe('Wiki answer round-trip', () => {
    test('the text the editor holds is byte-identical to the stored Markdown', () => {
        // saveActiveWikiAnswerText() sends normalizeWikiAnswerText(text).trim(); rendering must not
        // be in that path at all, so the table syntax survives edit -> save -> reload.
        const saved = normalizeWikiAnswerText(ANSWER_WITH_TABLE).trim();

        assert.equal(saved, ANSWER_WITH_TABLE.trim());
        assert.ok(saved.includes('| Element | Beskrivelse |'));
    });

    test('copying still yields the Markdown source, not the rendered HTML', () => {
        assert.equal(buildWikiAnswerCopyText(ANSWER_WITH_TABLE), ANSWER_WITH_TABLE);
    });
});

describe('WikiAnswerMarkdown usage in the AI answer panel', () => {
    const showSource = readFileSync(fileURLToPath(new URL('./Show.jsx', import.meta.url)), 'utf8');

    test('the answer panel renders through the shared Markdown renderer', () => {
        // Since figure support, the panel mounts WikiAnswerBody, which renders the answer's
        // Markdown through WikiAnswerMarkdown and places the answer's figures around it.
        assert.match(showSource, /import \{ WikiAnswerBody \} from '\.\/wikiAnswerMarkdown'/);
        assert.match(showSource, /<WikiAnswerBody\s+text=\{activeRequirementWikiAnswerText\}/);
    });

    test('the raw textarea only appears while the user is editing', () => {
        // Guards the display/edit split: an unconditional textarea is what made every answer look
        // like raw Markdown in the first place.
        assert.match(showSource, /isEditingActiveWikiAnswer \? \(/);

        const editorBlock = showSource.slice(
            showSource.indexOf('{isEditingActiveWikiAnswer ? ('),
            showSource.indexOf('data-testid="wiki-answer-rendered"'),
        );

        assert.ok(editorBlock.includes('<textarea'));
        assert.ok(editorBlock.includes('saveActiveWikiAnswerText'));
        assert.ok(editorBlock.includes('cancelEditingActiveWikiAnswer'));
    });

    test('a successful save returns the user to the rendered view', () => {
        const saveHandler = showSource.slice(
            showSource.indexOf('const saveActiveWikiAnswerText'),
            showSource.indexOf('const updateRequirementReviewStatus'),
        );

        assert.ok(saveHandler.includes('setWikiAnswerEditingRequirementId(null)'));
    });
});
