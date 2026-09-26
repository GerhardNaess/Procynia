import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, 'DashboardCockpit.jsx'), 'utf8');

const region = (name) => {
    const start = source.indexOf(name);
    assert.ok(start > -1, `${name} must exist`);

    return source.slice(start, source.indexOf('\n}', start));
};

/**
 * Bid Status, brought into the same visual family as the Wiki page list.
 *
 * The cockpit had drifted into a report form. Every region was a card with a drop shadow, a 24px
 * radius and a 12px uppercase title — and several of those cards held cards of their own, so the
 * pipeline was six framed boxes inside a seventh. Twenty-four separate strings on the page rendered
 * in uppercase at 12px, including the calendar's day numbers, which is smaller than anything a bid
 * manager should have to read. None of the region titles were headings, so the whole page offered
 * a screen reader exactly one <h2>.
 *
 * Nothing about what the page computes or shows has changed. These are source-level guards (the
 * project has no JSX renderer); the sizes behind them were measured in a browser at 1680, 1280 and
 * 420 px.
 */
describe('the page introduces itself the way the Wiki list does', () => {
    test('the header is a header, with no card around it', () => {
        assert.match(source, /<header className="space-y-2">/);
        assert.match(source, /<h1 className="text-4xl font-semibold tracking-tight text-slate-950">/);
    });

    test('the description is 16px, at the same measure as the Wiki page', () => {
        assert.match(source, /<p className="max-w-3xl text-base leading-6 text-slate-600">/);
        assert.ok(! source.includes('max-w-4xl text-base leading-7'), 'no wider, looser variant');
    });
});

describe('every region names itself as a heading', () => {
    test('a region title is a real heading, not a styled div', () => {
        const card = region('function Card(');

        assert.match(card, /const Heading = sub \? 'h3' : 'h2';/);
        assert.match(card, /sub \? 'text-lg' : 'text-xl',/);
    });

    test('a region nested inside another one is a level down, and a size down', () => {
        // The follow-up drill-down sits inside "Krever oppfølging" and must not outrank it.
        assert.match(source, /<Card\s*\n\s*sub\s*\n\s*title=\{openSignal\.title\}/);
    });

    test('a region is a card at one radius and one padding', () => {
        const card = region('function Card(');

        assert.match(card, /bare \? '' : classNames\('rounded-3xl border border-slate-200 bg-white'/);

        // Pipeline, Styring, Bidkalender and Resultater are all cards; `bare` is kept for the
        // follow-up group, which is a heading over four cards of its own.
        for (const title of ['pipeline_title', 'management_title', 'results_title']) {
            const call = source.slice(source.lastIndexOf('<Card', source.indexOf(`redesignText.${title}`)), source.indexOf(`redesignText.${title}`));

            assert.ok(! /\bbare\b/.test(call), `${title} is a card`);
        }
    });

    test('no region carries a drop shadow any more', () => {
        // The deadline popover is the exception: it floats above the page, so it needs lift.
        const withoutPopover = source.replace(/function DeadlinePopover[\s\S]*?\n}\n/, '');

        assert.ok(! withoutPopover.includes('shadow-['), 'no heavy shadows outside the popover');
        assert.ok(! source.includes('rounded-[24px]'), 'and no oversized radii');
    });
});

describe('the follow-up signals read as signals, not widgets', () => {
    const signal = region('function FollowUpSignal(');

    test('the number leads, sized for a cockpit but below the page title', () => {
        assert.match(signal, /text-\[30px\] font-semibold leading-none tabular-nums/);
        assert.ok(! signal.includes('text-4xl'), 'no longer the same size as the H1');
    });

    test('the signal name is readable text, not a 12px label', () => {
        assert.match(signal, /<span className="mt-2 block text-base font-semibold leading-6 text-slate-900">/);
        assert.ok(! signal.includes('uppercase'));
    });

    test('each signal carries its own mark, and the mark does not report the count', () => {
        // The tile identifies which signal this is; severity is on the card and the number.
        assert.match(source, /'go-no-go-pending': \{ path: ICON_PATHS\.clipboard, tile: 'bg-indigo-50 text-indigo-600' \}/);
        assert.match(source, /'deadline-soon': \{ path: ICON_PATHS\.clock, tile: 'bg-amber-50 text-amber-600' \}/);
        assert.match(signal, /const mark = SIGNAL_MARKS\[signal\.key\] \?\? \{ path: ICON_PATHS\.clipboard, tile: 'bg-slate-100 text-slate-500' \};/);
        assert.match(signal, /flex h-11 w-11 shrink-0 items-center justify-center rounded-xl/);
    });

    test('the number leads the column beside the mark, above the name', () => {
        assert.match(signal, /flex h-full w-full items-start gap-4 rounded-2xl border px-5 py-5 text-left/);
    });

    test('colour is a tint that repeats the words, never the thing carrying them', () => {
        assert.match(signal, /danger: 'border-rose-200 bg-rose-50\/50'/);
        assert.match(signal, /warning: 'border-amber-200 bg-amber-50\/50'/);
    });

    test('a signal at zero stays on the board but recedes', () => {
        assert.match(signal, /isActive \? \(tone\[signal\.severity\] \?\? tone\.neutral\) : 'border-slate-200 bg-white'/);
        assert.match(signal, /isActive \? 'text-slate-950' : 'text-slate-400'/);
    });

    test('it is still a button that reports whether it is open', () => {
        assert.match(signal, /type="button"/);
        assert.match(signal, /aria-expanded=\{isOpen\}/);
        assert.match(signal, /focus-visible:outline-violet-600/);
    });
});

describe('the pipeline is one strip, not six cards', () => {
    test('the phases share a surface and are joined by chevrons', () => {
        assert.match(source, /className="flex flex-col divide-y divide-slate-200 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 sm:flex-row sm:items-stretch sm:divide-y-0"/);
        assert.ok(! source.includes('xl:grid-cols-6'), 'no six-card grid');
        assert.match(source, /\{index > 0 \? <PipelineChevron \/> : null\}/);
    });

    test('the chevron is decoration, so it is hidden from assistive technology', () => {
        const chevron = region('function PipelineChevron(');

        assert.match(chevron, /aria-hidden="true"/);
        assert.match(chevron, /hidden shrink-0 items-center text-slate-300 sm:flex/, 'and it goes away when the strip stacks');
    });

    test('a phase is a segment: a label, a number, and its share of the busiest phase', () => {
        const stage = region('function PipelineStage(');

        assert.match(stage, /<div className={classNames\(\s*\n\s*'flex min-w-0 flex-1 flex-col gap-2 px-4 py-4',/);
        assert.match(stage, /text-\[26px\] font-semibold leading-none tabular-nums/);
    });

    test('a phase holding cases is tinted; an empty one is not', () => {
        const stage = region('function PipelineStage(');

        assert.match(stage, /const hasCases = count > 0;/);
        assert.match(stage, /hasCases \? 'bg-violet-50' : ''/);
        assert.match(stage, /hasCases \? 'text-slate-950' : 'text-slate-400'/);
    });

    test('the maths behind the bar is untouched', () => {
        const stage = region('function PipelineStage(');

        assert.match(stage, /const width = stageMax > 0 \? Math\.round\(\(count \/ stageMax\) \* 100\) : 0;/);
        assert.match(stage, /style=\{\{ width: `\$\{Math\.max\(hasCases \? 6 : 0, width\)\}%` \}\}/);
    });

    test('the strip stacks below sm, where six segments in a row would be unreadable', () => {
        assert.match(source, /flex flex-col divide-y[^"]*sm:flex-row/);
    });
});

describe('styring is a quiet list, not a panel', () => {
    test('rows are separated by lines rather than framed', () => {
        assert.match(source, /<dl className="divide-y divide-slate-200">/);
        assert.match(source, /className="flex items-baseline justify-between gap-4 py-3\.5 first:pt-0 last:pb-0"/);
    });

    test('label and value are both 16px; only weight separates them', () => {
        assert.match(source, /<dt className="min-w-0 text-base leading-6 text-slate-600">\{row\.label\}<\/dt>/);
        assert.match(source, /<dd className="shrink-0 text-base font-semibold tabular-nums text-slate-950">\{row\.value\}<\/dd>/);
    });
});

describe('the calendar is a work surface, so it stays a card', () => {
    test('it is the one region that keeps a frame', () => {
        const call = source.slice(source.lastIndexOf('<Card', source.indexOf('sectionsText.deadlines?.title')), source.indexOf('sectionsText.deadlines?.subtitle_prefix'));

        assert.ok(! /\bbare\b/.test(call), 'the calendar keeps its card');
        assert.ok(! source.includes('border-violet-200 bg-violet-50/60'), 'but not a violet one, and not a card inside it');
    });

    test('month, year and the date jump are one toolbar at one height', () => {
        // Four controls on the row — the month pill, two selects, the date input — plus "Vis",
        // all 44px. The two arrows are 36px because they sit inside the pill, not on the row.
        assert.match(source, /const CALENDAR_CONTROL_CLS = 'h-11 rounded-lg/);
        assert.match(source, /flex h-11 shrink-0 items-center gap-1 rounded-xl/);
        assert.match(source, /inline-flex h-11 shrink-0 items-center justify-center rounded-lg/);
        assert.match(source, /const CALENDAR_ARROW_CLS = 'inline-flex h-9 w-9 shrink-0/);
        assert.match(source, /<div className="flex flex-wrap items-end gap-3">/);
    });

    test('its labels are words, not 12px uppercase technical keys', () => {
        assert.match(source, /const CALENDAR_LABEL_CLS = 'text-sm font-medium text-slate-500';/);
    });

    test('the month and its two steps are one control', () => {
        assert.match(source, /<div className="flex h-11 shrink-0 items-center gap-1 rounded-xl border border-slate-200 bg-white px-1">/);
        assert.match(source, /<span className="min-w-36 px-1 text-center text-base font-semibold capitalize text-slate-900">/);
        assert.match(source, /rotate-180/, 'the back step is the same chevron, turned round');
    });

    test('a day is legible, and the deadline count beside it too', () => {
        // 14px is the floor for metadata; the grid is dense, so the days sit on it rather than above.
        assert.match(source, /<div className="text-sm font-semibold leading-5 tabular-nums">\s*\n\s*\{day\.dayOfMonth\}/);
        assert.match(source, /h-5 min-w-5 items-center justify-center rounded-full bg-violet-100 px-1 text-sm font-semibold/);
        assert.match(source, /min-h-11 rounded-lg border/);
    });

    test('the date jump still moves the calendar and selects the day', () => {
        assert.match(source, /setVisibleMonthStart\(startOfMonth\(nextDate\)\);/);
        assert.match(source, /setSelectedDateKey\(toDateKey\(nextDate\)\);/);
    });

    test('the narrow layout gives the date jump its own row instead of overflowing', () => {
        // At 420px the toolbar used to push the "Vis" button 51px past the viewport.
        assert.match(source, /className="flex w-full min-w-0 flex-col gap-1 sm:w-auto sm:flex-1 sm:basis-56">/);
    });
});

describe('results are a section, not a box inside a box', () => {
    test('the four outcomes share one surface', () => {
        assert.match(source, /<div className="grid grid-cols-2 overflow-hidden rounded-2xl border border-slate-200 bg-white">/);
        assert.match(source, /index % 2 === 1 \? 'border-l border-slate-200' : '',/);
        assert.match(source, /index > 1 \? 'border-t border-slate-200' : '',/);
    });

    test('the empty state has a mark and a sentence, and no dashed border', () => {
        assert.match(source, /flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-10 text-center/);
        assert.match(source, /<p className="text-base leading-6 text-slate-500">\s*\n\s*\{redesignText\.results_none\}/);
        assert.ok(! source.includes('border-dashed'), 'no dashed boxes anywhere on the page');
    });

    test('win rate is still shown only when there is a basis for it', () => {
        assert.match(source, /\{winRate \? \(/);
        assert.match(source, /\{winRate\.numerator\}\/\{winRate\.denominator\}/);
    });
});

describe('the readability floor holds across the page', () => {
    test('nothing is set at 12px or below', () => {
        assert.ok(! source.includes('text-xs'));
        assert.ok(! /text-\[1[0-3]px\]/.test(source));
    });

    test('uppercase is left to the two rows that are genuinely labels', () => {
        // It was on 24 strings, all at 12px. It survives on the pipeline phase names and the
        // calendar's weekday row — both are column labels for the numbers under them — and
        // nowhere else. Both sit at 14px with tracking well below the old 0.14em.
        const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
        const uppercased = [...code.matchAll(/[^'"]*uppercase[^'"]*/g)].map((m) => m[0]);

        assert.equal(uppercased.length, 2, 'exactly two places, no creep');

        for (const cls of uppercased) {
            assert.match(cls, /text-sm/, 'never smaller than 14px');
            assert.match(cls, /tracking-\[0\.06em\]/, 'and never the old wide tracking');
        }

        assert.ok(! /tracking-\[0\.1\d+em\]/.test(source));
    });

    test('hierarchy comes from weight and size, in one ladder', () => {
        // 36 / 20 / 18 / 16, with 14 reserved for metadata.
        assert.match(source, /text-4xl font-semibold/);      // page title
        assert.match(source, /sub \? 'text-lg' : 'text-xl'/); // section, then nested section
        assert.match(source, /text-base font-semibold/);      // labels that carry a number
    });
});

describe('nothing the page computes or shows has changed', () => {
    test('the same six phases, from the same payload', () => {
        assert.match(source, /const pipelineStages = pipeline\.stages \?\? \[\];/);
        assert.match(source, /const stageMax = Math\.max\(\.\.\.pipelineStages\.map\(\(stage\) => Number\(stage\.count \?\? 0\)\), 1\);/);
    });

    test('the same four signals, built by the same helper', () => {
        assert.match(source, /const followUpSignals = buildFollowUpSignals\(/);
        for (const key of ['go-no-go-pending', 'missing-bid-manager', 'deadline-soon', 'inactive-seven-days']) {
            assert.ok(source.includes(`'${key}'`), key);
        }
    });

    test('management, outcomes and win rate still come from dashboardLogic', () => {
        for (const call of ['buildManagementRows(', 'buildVisibleOutcomes(', 'outcomesHaveAnyCount(', 'winRateMetric(']) {
            assert.ok(source.includes(call), call);
        }
    });

    test('the calendar is built the same way, from the same month state', () => {
        assert.match(source, /const calendar = useMemo\(\(\) => buildCalendarDays\(visibleMonthStart, locale\), \[visibleMonthStart, locale\]\);/);
        assert.match(source, /const deadlineGroups = useMemo\(\(\) => groupByDate\(deadlines\.items \?\? \[\]\), \[deadlines\.items\]\);/);
    });
});
