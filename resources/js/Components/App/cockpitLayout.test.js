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

    test('`bare` drops the frame for regions that only needed grouping', () => {
        const card = region('function Card(');

        assert.match(card, /bare \? '' : classNames\('rounded-2xl border border-slate-200 bg-white'/);

        // Pipeline, Styring and Resultater group content; only the calendar is a work surface.
        for (const title of ['pipeline_title', 'management_title', 'results_title']) {
            const call = source.slice(source.lastIndexOf('<Card', source.indexOf(`redesignText.${title}`)), source.indexOf(`redesignText.${title}`));

            assert.match(call, /\bbare\b/, `${title} must not be a card`);
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
        assert.match(signal, /'text-base font-semibold leading-6',/);
        assert.ok(! signal.includes('uppercase'));
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
    test('the phases share a surface and are separated by a divider', () => {
        assert.match(source, /className="flex flex-wrap items-stretch divide-x divide-slate-200 rounded-2xl border border-slate-200 bg-white"/);
        assert.ok(! source.includes('xl:grid-cols-6'), 'no six-card grid');
    });

    test('a phase is a cell: a label, a number, and its share of the busiest phase', () => {
        const stage = region('function PipelineStage(');

        assert.match(stage, /<div className="flex min-w-30 flex-1 flex-col gap-2 px-4 py-4">/);
        assert.match(stage, /text-base font-semibold leading-6 text-slate-700/);
        assert.match(stage, /text-\[26px\] font-semibold leading-none tabular-nums/);
    });

    test('the violet bar is the only colour in the strip, and the maths behind it is untouched', () => {
        const stage = region('function PipelineStage(');

        assert.match(stage, /const width = stageMax > 0 \? Math\.round\(\(count \/ stageMax\) \* 100\) : 0;/);
        assert.match(stage, /style=\{\{ width: `\$\{Math\.max\(count > 0 \? 6 : 0, width\)\}%` \}\}/);
    });

    test('six phases still fit on one line on a laptop', () => {
        // 6 x 7.5rem = 720px, and the column measured 803px at a 1280px viewport.
        const stage = region('function PipelineStage(');

        assert.match(stage, /min-w-30\b/);
    });
});

describe('styring is a quiet list, not a panel', () => {
    test('rows are separated by lines rather than framed', () => {
        assert.match(source, /<dl className="divide-y divide-slate-200 border-y border-slate-200">/);
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
        assert.match(source, /const CALENDAR_CONTROL_CLS = 'h-11 rounded-lg/);
        assert.match(source, /const CALENDAR_ARROW_CLS = 'inline-flex h-11 w-11/);
        assert.match(source, /inline-flex h-11 shrink-0 items-center justify-center rounded-lg/);
        assert.match(source, /<div className="flex flex-wrap items-end gap-3">/);
    });

    test('its labels are words, not 12px uppercase technical keys', () => {
        assert.match(source, /const CALENDAR_LABEL_CLS = 'text-base font-medium text-slate-600';/);
    });

    test('a day is legible, and the deadline count beside it too', () => {
        assert.match(source, /<div className="text-base font-semibold leading-6 tabular-nums">\s*\n\s*\{day\.dayOfMonth\}/);
        assert.match(source, /h-6 min-w-6 items-center justify-center rounded-full bg-violet-100 px-1 text-sm font-semibold/);
        assert.match(source, /min-h-12 rounded-lg border/);
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

    test('the empty state is a sentence, not a dashed box', () => {
        assert.match(source, /<p className="text-base leading-6 text-slate-600">\s*\n\s*\{redesignText\.results_none\}/);
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

    test('nothing on the page is set in uppercase any more', () => {
        // 24 strings were, including the six pipeline phases and the calendar's weekday row.
        // The comments still say the word, so read the code with the prose stripped out.
        const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');

        assert.ok(! /\buppercase\b/.test(code));
        assert.ok(! /tracking-\[0\.1\d+em\]/.test(source), 'and the wide tracking went with it');
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
