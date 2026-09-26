import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const index = readFileSync(join(here, 'Index.jsx'), 'utf8');
const pagesTab = index.slice(index.indexOf('function PagesTab'), index.indexOf('\nfunction ', index.indexOf('function PagesTab') + 10));

/**
 * The Wiki page list, as the first surface to get Procynia's list layout right.
 *
 * It was four stacked white boxes — a card around the page title, a card around the counts, loose
 * controls, and the table — each with its own border and shadow, so nothing was obviously the main
 * thing. The content is unchanged; what changed is how much furniture surrounds it.
 *
 * Source-level guards, the idiom used elsewhere in this suite. The measurements behind them were
 * taken in a browser at three widths.
 */
describe('the page introduces itself without a card', () => {
    test('the header is a header, not a panel', () => {
        assert.match(index, /<header className="flex flex-col gap-4 overflow-visible/);
        assert.ok(! index.includes('rounded-3xl border border-slate-200 bg-white\/80 p-6 shadow-sm'));
    });

    test('the description is ordinary text, at 16px', () => {
        assert.match(index, /<p className="max-w-3xl text-base leading-6 text-slate-600">/);
        assert.ok(! index.includes('text-[15px] leading-7 text-slate-500'), 'no 15px half-step');
    });
});

describe('the status summary is a strip, not a dashboard', () => {
    test('one bordered row with dividers between the counts', () => {
        assert.match(index, /divide-x divide-slate-200 rounded-2xl border border-slate-200 bg-white"/);
        assert.ok(! index.includes('grid-cols-4'), 'not four cards');
    });

    test('the number leads at 22px, the label explains it at 16px', () => {
        assert.match(index, /text-\[22px\] font-semibold leading-none/);
        assert.match(index, /<span className="text-base leading-6 text-slate-500">\{label\}<\/span>/);
    });

    test('colour is carried by the number alone, and kept muted', () => {
        for (const tone of ['text-emerald-700', 'text-amber-700', 'text-slate-500']) {
            assert.ok(index.includes(`'${tone}'`), tone);
        }
        // No filled backgrounds: a summary must not outweigh what it summarises.
        const strip = index.slice(index.indexOf('data-testid="wiki-publication-summary"') - 300, index.indexOf('data-testid="wiki-publication-summary"') + 700);
        assert.ok(! /bg-(emerald|amber|rose|sky|violet)-\d/.test(strip));
    });
});

describe('search and filters are one toolbar', () => {
    test('they share a surface', () => {
        assert.match(pagesTab, /className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-3"/);
    });

    test('every control is the same height, and readable', () => {
        assert.match(index, /const PAGES_CONTROL_CLS = 'h-11 rounded-lg[^']*text-base/);
        assert.ok(! pagesTab.includes('className={SELECT_CLS}'), 'the toolbar has its own control class');
        // Shared with the other tabs, so it must survive untouched.
        assert.match(index, /const SELECT_CLS = 'h-9 rounded-lg/);
    });

    test('search leads and can breathe; the count stays out of the way', () => {
        assert.match(pagesTab, /PAGES_CONTROL_CLS \+ ' w-full min-w-0 sm:w-72'/);
        assert.match(pagesTab, /className="ml-auto whitespace-nowrap pr-1 text-base text-slate-500"/);
    });
});

describe('the table is the main surface', () => {
    test('its frame is lighter, and it is still the only card', () => {
        assert.match(pagesTab, /className="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white md:block"/);
        assert.ok(! pagesTab.includes('shadow-[0_8px_24px_rgba(15,23,42,0.04)]'));
    });

    test('column heads are 14px, never 12px', () => {
        assert.match(pagesTab, /text-left text-sm font-semibold uppercase tracking-\[0\.08em\] text-slate-500/);
    });

    test('the page title is the row, at 16px semibold', () => {
        assert.match(pagesTab, /<td className="px-6 py-5 font-semibold text-slate-950">\{page\.title\}<\/td>/);
        assert.match(pagesTab, /<tr key=\{page\.id\} className="text-base text-slate-700 transition hover:bg-slate-50\/70">/);
    });

    test('rows have room, and hover is a hint rather than a highlight', () => {
        assert.ok((pagesTab.match(/px-6 py-5/g) ?? []).length >= 6, 'the whole row grew, not one cell');
        assert.match(pagesTab, /hover:bg-slate-50\/70/);
    });

    test('nothing in the list is set below 14px', () => {
        assert.ok(! pagesTab.includes('text-xs'));
    });

    test('"Åpne" is still secondary', () => {
        assert.match(pagesTab, /border border-slate-200 bg-white px-3\.5 py-2 text-base font-semibold text-slate-700/);
        assert.ok(! pagesTab.includes('bg-violet-600'), 'never promoted to a primary action');
    });
});

describe('nothing about the data or the controls moved', () => {
    test('the same filters, in the same order, doing the same thing', () => {
        for (const key of ['page_type', 'status', 'document_owner', 'lint', 'sort']) {
            assert.match(pagesTab, new RegExp(`navigate\\(\\{ ${key}: e\\.target\\.value, page: 1 \\}\\)`));
        }
    });

    test('the same columns', () => {
        for (const col of ['Tittel', 'Type', 'Status', 'document_owner_column', 'claims_approved_column', 'tw.updated']) {
            assert.ok(pagesTab.includes(col), col);
        }
    });

    test('the narrow layout still swaps the table for cards', () => {
        assert.match(pagesTab, /md:hidden/);
        assert.match(pagesTab, /md:block/);
    });
});
