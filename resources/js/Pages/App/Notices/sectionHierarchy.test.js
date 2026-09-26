import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const index = readFileSync(join(here, 'Index.jsx'), 'utf8');

/**
 * "I arbeid" is one page with three sections, and it did not read that way.
 *
 * Each section named itself in a different voice: two in the same weight as the sentence beneath
 * them, and the third — "REGISTRERTE KUNNGJØRINGER" — in uppercase with wide tracking, which is the
 * house style for a technical label rather than for a heading somebody reads. The eye had nothing
 * to step down from, so the page flattened into three unrelated cards.
 *
 * One heading style now, quieter explanations under it, and the count moved below the name it
 * belongs to. The project has no JSX renderer, so these are source-level guards in the idiom used
 * elsewhere in this suite.
 */
describe('the three sections share one heading style', () => {
    const HEADING = 'className="text-lg font-semibold text-slate-900"';

    test('each section names itself the same way', () => {
        for (const title of ['noticesText.privateRequestTitle', 'worklistFilterTitle', 'source?.label']) {
            assert.ok(index.includes(`<h2 ${HEADING}>{${title}}</h2>`), title);
        }
    });

    test('they are headings, not just text that looks like one', () => {
        // Three <h2>s where there were two <div>s and a label — the hierarchy is in the markup too.
        assert.ok((index.match(/<h2 className="text-lg font-semibold text-slate-900">/g) ?? []).length === 3);
    });

    test('the explanation under each keeps its quieter voice, with room above it', () => {
        assert.ok((index.match(/className="mt-1\.5 text-base leading-6 text-slate-600"/g) ?? []).length === 3);
    });
});

describe('the registered notices section reads as a heading', () => {
    test('its name is no longer set as a technical label', () => {
        assert.ok(
            ! index.includes('text-base font-medium uppercase tracking-[0.16em] text-slate-600'),
            'uppercase with wide tracking is how this page marks machine labels',
        );
    });

    test('the hit count sits under the name rather than above it', () => {
        assert.match(index, /<h2 className="text-lg font-semibold text-slate-900">\{source\?\.label\}<\/h2>\s*\n\s*<p className="mt-1\.5 text-base leading-6 text-slate-600">\{liveSearchHeading\}<\/p>/);
    });

    test('the label itself is unchanged; only its presentation moved', () => {
        assert.match(index, /\{source\?\.label\}/);
    });
});

describe('nothing else about the page moved', () => {
    test('the section headings stay below the page title', () => {
        // The page title is the only thing larger than a section name, and it was already right.
        assert.ok(! index.includes('text-xl font-semibold text-slate-900'), 'no section grew past it');
    });

    test('no decoration was added to carry the hierarchy', () => {
        // The sections already had their own card; the fix is type, not chrome.
        const before = 'rounded-[22px] border border-slate-200 bg-white p-5';
        assert.ok(index.includes(before), 'the existing card is untouched');
    });

    test('the actions and controls are where they were', () => {
        assert.match(index, /noticesText\.privateRequestToggleShow/);
        assert.match(index, /worklistFilterLabel/);
        assert.match(index, /id="doffin-results"/);
    });
});

/**
 * A card title belongs under the section that holds it.
 *
 * The notice cards used <h2> at 27px — the largest thing on the page after the page title, and
 * larger than "Registrerte kunngjøringer", the section they sit inside. So the list read as if the
 * section were a footnote to its own contents, and a screen reader heard both at the same level.
 */
describe('notice card titles nest under their section', () => {
    test('the card title is a level below the section', () => {
        assert.match(index, /<h3 className="text-lg font-semibold tracking-tight text-slate-950">\s*\n\s*\{notice\.title\}\s*\n\s*<\/h3>/);
        assert.ok(! index.includes('text-[1.7rem]'), 'and no longer outsizes the section');
    });

    test('it stays a heading people can read at a glance', () => {
        // 18px and bold. Hierarchy comes from weight and level here, not from shrinking text.
        const start = index.indexOf('{notice.title}');
        const heading = index.slice(index.lastIndexOf('<h3', start), start);
        assert.match(heading, /text-lg/);
        assert.match(heading, /font-semibold/);
        assert.match(heading, /text-slate-950/);
    });

    test('nothing on this page is set below 14px', () => {
        // The readability floor for this surface: no 12px anywhere, including badges and metadata.
        assert.ok(! index.includes('text-xs'), 'no 12px text');
        assert.ok(! /text-\[1[0-3]px\]/.test(index), 'and nothing hand-set below 14px either');
    });

    test('the card keeps its layout, badges and actions', () => {
        assert.match(index, /rounded-\[20px\] border border-slate-200 bg-white p-5/);
        assert.match(index, /\{notice\.buyer_name \|\| noticesText\.buyerUnknown\}/);
    });
});
