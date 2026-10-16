import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { kpiTrendSummary, panelCloseFocusCandidates, trendDirection } from '../Pages/App/Objectives/objectivesA11y.js';

const here = dirname(fileURLToPath(import.meta.url));
const page = (name) => readFileSync(join(here, '..', 'Pages', 'App', 'Objectives', name), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md §8): Mål og KPI. The rules are pinned here;
 * behaviour in a real browser is in tests/e2e/a11y-grc-objectives.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

const POINTS = [
    { id: 1, label: 'januar 2026', value: '1100000', value_display: 'NOK 1 100 000' },
    { id: 2, label: 'februar 2026', value: '1250000', value_display: 'NOK 1 250 000' },
    { id: 3, label: 'mars 2026', value: '1350000', value_display: 'NOK 1 350 000' },
];
const KPI = { target_display: '≥ NOK 1 300 000', status_explanation: 'Siste verdi NOK 1 350 000 oppfyller målet ≥ NOK 1 300 000.' };

describe('the KPI chart is a picture with a summary in words (A11Y-052)', () => {
    test('the direction is first period to last, and needs two points', () => {
        assert.equal(trendDirection(POINTS), 'rising');
        assert.equal(trendDirection([...POINTS].reverse()), 'falling');
        assert.equal(trendDirection([POINTS[0], { ...POINTS[2], value: '1100000' }]), 'unchanged');
        assert.equal(trendDirection([POINTS[0]]), null);
        assert.equal(trendDirection([]), null);
    });

    test('the summary says the periods, the latest value, the målverdi, the status and where every value is', () => {
        const summary = kpiTrendSummary({ points: POINTS, kpi: KPI, heading: 'Utvikling over tid', resultLabel: 'På mål' });

        assert.equal(summary, [
            'Utvikling over tid.',
            '3 målinger fra januar 2026 til mars 2026. Siste verdi er NOK 1 350 000 for mars 2026.',
            'Målverdi: ≥ NOK 1 300 000.',
            'Dagens status: På mål.',
            'Siste verdi NOK 1 350 000 oppfyller målet ≥ NOK 1 300 000.',
            'Verdien har økt fra første til siste periode.',
            'Alle verdiene står i tabellen Målehistorikk.',
        ].join(' '));
    });

    test('one measurement has no direction; the English strings come from translations.a11y', () => {
        const ta = {
            objectives_trend_one: 'One measurement: :value for :period.',
            objectives_trend_target: 'Target: :target.',
            objectives_trend_status: 'Current status: :status.',
            objectives_trend_table: 'Every value is in the Measurement history table.',
        };
        const summary = kpiTrendSummary({ points: [POINTS[0]], kpi: { target_display: '≤ 5 days' }, heading: 'Development over time', resultLabel: 'Off target', ta });

        assert.equal(summary, 'Development over time. One measurement: NOK 1 100 000 for januar 2026. Target: ≤ 5 days. Current status: Off target. Every value is in the Measurement history table.');
        assert.equal(kpiTrendSummary({ points: [] }), '');
    });

    test('the chart has no tab stop per point, no live tooltip, and strokes of at least slate-500', () => {
        const source = code(page('KpiTrend.jsx'));

        assert.match(source, /role="img"\s+aria-label=\{summary\}/);
        assert.doesNotMatch(source, /tabIndex/);
        assert.doesNotMatch(source, /focus:outline-none/);
        assert.doesNotMatch(source, /role="status"/);
        assert.doesNotMatch(source, /stroke-slate-(200|300|400)\b/);
        assert.match(source, /event\.key === 'Escape'/);
        assert.match(source, /href="#kpi-measurements-heading"/);
    });
});

describe('tables name their rows and columns (1.3.1)', () => {
    for (const [file, label] of [['Index.jsx', 'objectives'], ['ObjectiveKpis.jsx', 'KPI list'], ['KpiShow.jsx', 'Målehistorikk']]) {
        test(`${file}: caption, scope="col", a row header, in a ScrollRegion (${label})`, () => {
            const source = code(page(file));

            assert.match(source, /<ScrollRegion label=/);
            assert.match(source, /<caption className="sr-only">/);
            assert.doesNotMatch(source, /<th className=/, 'every column header has a scope');
            assert.match(source, /<th scope="row"/);
        });
    }

    test('Målehistorikk row actions are described by their period', () => {
        const source = code(page('KpiShow.jsx'));

        assert.match(source, /id=\{`kpi-correct-\$\{row\.id\}`\}[\s\S]*?aria-describedby=\{periodId\}/);
        assert.match(source, /id=\{`kpi-withdraw-\$\{row\.id\}`\}[\s\S]*?aria-describedby=\{periodId\}/);
    });
});

describe('forms: wired fields, focus on the first error, the «*» explained (A11Y-013, -121)', () => {
    const forms = ['ObjectiveForm.jsx', 'KpiForm.jsx', 'KpiMeasurementForms.jsx', 'KpiStatusForms.jsx', 'ObjectiveStatusForms.jsx', 'KpiContextPanel.jsx'];

    for (const file of forms) {
        test(`${file} uses FormField and focuses the first error`, () => {
            const source = code(page(file));

            assert.match(source, /<FormField\b/);
            assert.match(source, /useFocusFirstError\(formRef\)/);
            assert.match(source, /onError: focusFirstError|onSubmit\(event, focusFirstError\)/);
            assert.doesNotMatch(source, /text-rose-600/, 'errors are rose-700 (ERROR)');
            assert.doesNotMatch(source, /RequiredMark/, 'the star comes from FormField (rose-700)');
            assert.doesNotMatch(source, /border-slate-200 bg-white px-3/, 'fields use the field tone');
        });
    }

    test('the forms with a «*» say what it means once', () => {
        for (const file of ['ObjectiveForm.jsx', 'KpiForm.jsx', 'KpiMeasurementForms.jsx', 'ObjectiveStatusForms.jsx']) {
            assert.match(code(page(file)), /<RequiredFieldsNote\b/, file);
        }
    });

    test('the pages pass the focus request to the visit', () => {
        assert.match(code(page('Index.jsx')), /submitCreate = \(event, focusFirstError\)[\s\S]*?onError: focusFirstError/);
        assert.match(code(page('Show.jsx')), /submit = \(event, focusFirstError\)[\s\S]*?onError: focusFirstError/);
        assert.match(code(page('KpiShow.jsx')), /submit = \(event, focusFirstError\)[\s\S]*?onError: focusFirstError/);
        assert.match(code(page('ObjectiveKpis.jsx')), /submit = \(event, focusFirstError\)[\s\S]*?onError: focusFirstError/);
    });

    test('a client-side number problem also moves focus to the field', () => {
        assert.match(code(page('KpiForm.jsx')), /checkBeforeSubmit\(visibleNumberFields\)\)[\s\S]*?focusFirstError\(\);/);
        assert.match(code(page('KpiMeasurementForms.jsx')), /checkBeforeSubmit\(\['value'\]\)\)[\s\S]*?focusFirstError\(\);/);
    });

    test('the correction notice is announced on change, not as a live region that mounts with its text', () => {
        const source = code(page('KpiMeasurementForms.jsx'));

        assert.match(source, /useTransitionAnnouncement\(/);
        assert.doesNotMatch(source, /role="status"/);
        assert.match(source, /describedBy=\{correction \? \['measurement-correction-notice'\] : \[\]\}/);
    });
});

describe('UnitInput keeps its focus swap and gets only a visible edge (A11Y-019)', () => {
    test('the pointer/keyboard swap is untouched: no timer, pointerup and click settle the cursor', () => {
        const source = code(page('UnitInput.jsx'));

        assert.doesNotMatch(source, /setTimeout|requestAnimationFrame/);
        assert.match(source, /onPointerDown=\{pointerDown\}/);
        assert.match(source, /onPointerUp=\{settlePointer\}/);
        assert.match(source, /onClick=\{settlePointer\}/);
        assert.match(source, /'border-rose-600' : 'border-field-border'/);
    });
});

describe('inline panels give focus back (A11Y-014)', () => {
    test('closing a panel goes to its button, then the other actions, then the heading', () => {
        assert.deepEqual(
            panelCloseFocusCandidates('kpi-action-retire', ['kpi-action-measure', 'kpi-action-edit', 'kpi-action-retire'], 'kpi-heading'),
            ['kpi-action-retire', 'kpi-action-measure', 'kpi-action-edit', 'kpi-heading'],
        );
        assert.deepEqual(panelCloseFocusCandidates(null, [], 'objective-heading'), ['objective-heading']);
    });

    test('the KPI and objective pages request focus into the panel and refocus on close', () => {
        for (const file of ['KpiShow.jsx', 'Show.jsx']) {
            const source = code(page(file));

            assert.match(source, /setPanelFocusRequest\(\(count\) => count \+ 1\)/, file);
            assert.match(source, /refocusIfLost\(panelCloseFocusCandidates\(/, file);
            assert.doesNotMatch(source, /onClick=\{\(\) => setPanel\('/, `${file}: every panel opens through openPanel`);
        }
    });

    test('Ny KPI, Nytt mål and Endre kobling use usePanelFocus with their button', () => {
        assert.match(code(page('ObjectiveKpis.jsx')), /usePanelFocus\(creating, \{ panelRef, returnFocusRef: createButtonRef \}\)/);
        assert.match(code(page('Index.jsx')), /usePanelFocus\(creating, \{ panelRef: createPanelRef, returnFocusRef: createButtonRef \}\)/);
        assert.match(code(page('KpiContextPanel.jsx')), /usePanelFocus\(editing, \{ panelRef: formRef, returnFocusRef: editButtonRef \}\)/);
    });

    test('a refused delete is said beside the button (trackRowAction)', () => {
        assert.match(code(page('KpiShow.jsx')), /trackRowAction\(`kpi-delete-\$\{kpi\.id\}`/);
        assert.match(code(page('Show.jsx')), /trackRowAction\(`objective-delete-\$\{objective\.id\}`/);
    });

    test('the objective count after a search is announced, not on first load', () => {
        const source = code(page('Index.jsx'));

        assert.match(source, /announceCountRef\.current = true;\s*router\.get/);
        assert.match(source, /if \(announceCountRef\.current\) \{\s*announceCountRef\.current = false;\s*announce\(countText\)/);
    });
});
