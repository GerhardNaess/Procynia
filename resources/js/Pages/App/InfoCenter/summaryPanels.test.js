import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, 'Index.jsx'), 'utf8');

const region = (name) => {
    const start = source.indexOf(name);
    assert.ok(start > -1, `${name} must exist`);

    return source.slice(start, source.indexOf('\n}', start));
};

/**
 * The Info Center had two ways to change the same list.
 *
 * Four panels across the top said what was outstanding, and a separate row of buttons underneath
 * decided what the list showed. The thing the eye landed on first was not the thing you could act
 * on, and two of the four buttons repeated two of the four panels by name. The panels are the
 * navigation now, and the button row is gone.
 *
 * The mapping is by key, against the views the controller already filters by — no filtering rule
 * was added, and no count changed. Source-level guards, the idiom used elsewhere in this suite;
 * the behaviour itself was driven in a browser at 1680, 1280 and 420 px.
 */
describe('the panels are the navigation', () => {
    test('a panel whose key is a view becomes that view\'s control', () => {
        assert.match(source, /const viewByKey = new Map\(viewOptions\.map\(\(option\) => \[option\.value, option\]\)\);/);
        assert.match(source, /href=\{viewByKey\.get\(item\.key\)\?\.href \?\? null\}/);
        assert.match(source, /isActive=\{item\.key === activeView\}/);
    });

    test('it navigates to the same url the old button did', () => {
        // option.href is route('app.info-center.index', ['view' => ...]), untouched on the backend.
        const panel = region('function SummaryPanel(');

        assert.match(panel, /<Link\s*\n\s*href=\{href\}/);
    });

    test('the separate button row is gone, not merely hidden', () => {
        assert.ok(! source.includes('InfoCenterViewTab'), 'the tab component is removed');
        assert.ok(! source.includes('INFO_CENTER_TAB_HELP_TEXTS'), 'and so is its help map');
        assert.ok(! /viewOptions\.map\(\(option\) => \(\s*\n\s*<InfoCenter/.test(source));
    });
});

describe('a panel that cannot open a list does not pretend it can', () => {
    test('only a panel with a matching view is a link', () => {
        const panel = region('function SummaryPanel(');

        assert.match(panel, /\{href \? \(/);
        assert.match(panel, /\) : \(\s*\n\s*<div className=\{frame\}>\{body\}<\/div>\s*\n\s*\)\}/);
    });

    test('hover, focus and the pointer belong to the link alone', () => {
        const panel = region('function SummaryPanel(');
        const frame = panel.match(/const frame = classNames\(([\s\S]*?)\);/)?.[1] ?? '';

        assert.ok(! /hover:/.test(frame), 'the shared frame carries no hover');
        assert.ok(! /focus/.test(frame), 'nor a focus ring');
        assert.match(panel, /'hover:border-slate-300 focus-visible:outline-2/, 'both sit on the link');
    });
});

describe('the selected panel is stated quietly', () => {
    test('a subtle border and tint, in the violet this app already uses for selection', () => {
        const panel = region('function SummaryPanel(');

        assert.match(panel, /\? 'border-violet-300 bg-violet-50\/70 ring-1 ring-violet-200'/);
        assert.match(panel, /: 'border-slate-200 bg-white'/);
    });

    test('the panel tint is free to mean "selected" because tone moved to the number', () => {
        const tone = region('function summaryToneClassName(');

        for (const cls of ['text-rose-700', 'text-indigo-700', 'text-amber-800', 'text-violet-700']) {
            assert.ok(tone.includes(`'${cls}'`), cls);
        }

        assert.ok(! /bg-(rose|indigo|amber|violet)-50/.test(tone), 'no panel backgrounds left in the tone map');
    });

    test('the state is in the markup, not only in the paint', () => {
        const panel = region('function SummaryPanel(');

        assert.match(panel, /aria-current=\{isActive \? 'true' : undefined\}/);
    });
});

describe('the info button explains the panel without selecting it', () => {
    test('it is a sibling of the link, never nested inside it', () => {
        const panel = region('function SummaryPanel(');
        const linkBlock = panel.slice(panel.indexOf('<Link'), panel.indexOf('</Link>'));

        assert.ok(! linkBlock.includes('InfoHint'), 'an interactive element inside a link is invalid markup');
        assert.match(panel, /<span className="absolute right-3 top-3 z-10">\s*\n\s*<InfoHint/);
    });

    test('it keeps the label the help text already had', () => {
        const panel = region('function SummaryPanel(');

        assert.match(panel, /label=\{`Vis forklaring for \$\{item\.label\}`\}/);
    });

    test('the explanations are the ones the tab row used, not new wording', () => {
        for (const [key, text] of [
            ['my_tasks', 'Åpne aksjoner og oppfølginger som er tildelt deg.'],
            ['awaiting_response', 'Aksjoner du har sendt ut og fortsatt venter svar på fra andre.'],
            ['outbound', 'Aksjoner og oppfølginger du har opprettet, også tidligere og lukkede.'],
            ['inbound', 'Informasjon og oppfølginger som har kommet inn til deg eller saken.'],
        ]) {
            assert.ok(source.includes(`${key}: '${text}'`), key);
        }

        // due_soon has no tab to inherit from; it reuses the sentence the page help gives it.
        assert.ok(source.includes("due_soon: 'Viser åpne punkter med nær frist.'"));
        assert.ok(source.includes("page_help_item_deadline_text ?? 'Viser åpne punkter med nær frist.'"));
    });

    test('every panel the page can show has one', () => {
        // The backend emits five distinct panel keys across the two personas. A panel without an
        // explanation, next to three that have one, reads as an oversight rather than a decision.
        const map = source.match(/const INFO_CENTER_HELP_TEXTS = \{([\s\S]*?)\n\};/)?.[1] ?? '';

        for (const key of ['my_tasks', 'awaiting_response', 'decision', 'clarification', 'due_soon']) {
            assert.match(map, new RegExp(`\\n *${key}: '`), `${key} has no help text`);
        }
    });

    test('the two counters that span other people\'s work say so', () => {
        // Their panel body describes what a decision or clarification is; what it cannot say is
        // that these two are counted across every visible case while their neighbours are not.
        for (const key of ['decision', 'clarification']) {
            const text = source.match(new RegExp(`${key}: '([^']*)'`))?.[1] ?? '';

            assert.match(text, /alle saker du har tilgang til/, `${key} must state its scope`);
            assert.match(text, /uansett hvem de er tildelt/, `${key} must say it is not only yours`);
            assert.match(text, /[Ll]ukkede/, `${key} must say closed items are excluded`);
        }
    });
});

describe('a view with no counter keeps its place beside the list', () => {
    test('it is whatever the panels do not already cover', () => {
        assert.match(source, /const secondaryViews = viewOptions\.filter\(\s*\n\s*\(option\) => ! summaryItems\.some\(\(item\) => item\.key === option\.value\),\s*\n\s*\);/);
    });

    test('it sits in the list header, not in a row of its own', () => {
        const header = source.slice(source.indexOf('data-testid="info-center-secondary-views"') - 400, source.indexOf('data-testid="info-center-secondary-views"') + 600);

        assert.match(header, /Vis også/);
        assert.match(header, /<SecondaryViewLink/);
        assert.ok(! /rounded-\[24px\][^"]*p-5/.test(header), 'no section card of its own');
    });

    test('it carries the same help text and the same selected treatment', () => {
        const link = region('function SecondaryViewLink(');

        assert.match(link, /INFO_CENTER_HELP_TEXTS\[option\.value\]/);
        assert.match(link, /label=\{`Vis forklaring for \$\{option\.label\}`\}/);
        assert.match(link, /aria-current=\{isActive \? 'true' : undefined\}/);
        assert.match(link, /\? 'bg-violet-50 font-semibold text-violet-700'/);
    });
});

describe('the panels stay compact and readable', () => {
    test('they share one row by growing, so three or four both fill it', () => {
        assert.match(source, /className="mt-5 flex flex-wrap gap-3"/);
        assert.match(source, /className="relative min-w-60 flex-1"/);
        assert.ok(! source.includes('md:grid-cols-3'), 'no fixed column count to leave a gap');
    });

    test('title 16px, number clearly larger, explanation 16px', () => {
        const panel = region('function SummaryPanel(');

        assert.match(panel, /<div className="text-base font-semibold leading-6 text-slate-900">/);
        assert.match(panel, /text-\[30px\] font-semibold leading-none tabular-nums/);
        assert.match(panel, /<p className="mt-2 text-base leading-6 text-slate-600">/);
        assert.ok(! panel.includes('text-xs'), 'nothing on a panel is set at 12px');
    });

    test('the number leaves room for the info button beside the title', () => {
        const panel = region('function SummaryPanel(');

        assert.match(panel, /px-4 py-4 pr-11/);
    });
});

describe('nothing about the data behind the page changed', () => {
    test('the same payload fields, read the same way', () => {
        for (const line of [
            "const activeView = infoCenter?.active_view ?? 'my_tasks';",
            'const viewOptions = infoCenter?.view_options ?? [];',
            'const summaryItems = infoCenter?.summary?.items ?? [];',
            'const items = infoCenter?.items ?? [];',
            'const wikiTasks = infoCenter?.wiki_tasks ?? [];',
            'const pagination = infoCenter?.pagination ?? {};',
        ]) {
            assert.ok(source.includes(line), line);
        }
    });

    test('pagination still moves through the same urls', () => {
        assert.match(source, /goToPage\(pagination\.prev_page_url\)/);
        assert.match(source, /goToPage\(pagination\.next_page_url\)/);
    });

    test('no count is computed in the client', () => {
        assert.ok(! /summaryItems\.(reduce|filter)\(/.test(source), 'counts arrive from the backend');
        assert.match(source, /\{item\.count\}/);
    });
});
