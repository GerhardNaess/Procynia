import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    previewAnnouncement,
    previewKey,
    removalFocusCandidates,
    resultCountMessage,
    riskActionFocusCandidates,
} from '../Pages/App/Risk/riskA11y.js';
import { previewLevel } from '../Pages/App/Risk/riskLevel.js';

const here = dirname(fileURLToPath(import.meta.url));
const riskDir = join(here, '..', 'Pages', 'App', 'Risk');
const read = (file) => readFileSync(join(riskDir, file), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md §8): Risiko. The rules are pinned here;
 * behaviour in a real browser is in tests/e2e/a11y-grc-risk.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

const jsxFiles = readdirSync(riskDir).filter((name) => name.endsWith('.jsx'));
const CRITERIA = {
    likelihood: [1, 2, 3, 4, 5],
    consequence: [1, 2, 3, 4, 5],
    bands: [
        { min: 1, max: 4, level: 'low' },
        { min: 5, max: 9, level: 'moderate' },
        { min: 10, max: 16, level: 'high' },
        { min: 17, max: 25, level: 'very_high' },
    ],
};

describe('focus after an item leaves a list (A11Y-014)', () => {
    const ids = ['a', 'b', 'c'];

    test('the next item first, then the previous one, then the fallbacks', () => {
        assert.deepEqual(removalFocusCandidates(ids, 'b', ['add', 'heading']), ['c', 'a', 'add', 'heading']);
    });

    test('the last item falls back to the previous one', () => {
        assert.deepEqual(removalFocusCandidates(ids, 'c', ['add']), ['b', 'add']);
    });

    test('the only item falls back to the section', () => {
        assert.deepEqual(removalFocusCandidates(['a'], 'a', ['add', 'heading']), ['add', 'heading']);
    });

    test('an unknown item gives only the fallbacks', () => {
        assert.deepEqual(removalFocusCandidates(ids, 'x', ['heading']), ['heading']);
    });
});

describe('the number of hits is said in the register\'s own words (A11Y-111)', () => {
    test('the count template is filled', () => {
        assert.equal(resultCountMessage(3, { many: ':count risikoer' }), '3 risikoer');
    });

    test('nothing found after a search says so', () => {
        assert.equal(resultCountMessage(0, { many: ':count risikoer', none: 'Ingen risikoer passer søket.' }), 'Ingen risikoer passer søket.');
    });

    test('without a «none» text, zero is a number like any other', () => {
        assert.equal(resultCountMessage(0, { many: ':count risikoer' }), '0 risikoer');
    });

    test('one has its own wording when there is one', () => {
        assert.equal(resultCountMessage(1, { many: ':count saker', one: '1 sak' }), '1 sak');
    });
});

describe('the calculated level is spoken as words, not colour (1.4.1, 4.1.3)', () => {
    const ta = { level_preview: 'Beregnet nivå', score: 'Score :score', levels: { high: 'Høy' } };

    test('level and score from the server\'s bands, as a sentence', () => {
        assert.equal(previewAnnouncement(previewLevel(CRITERIA, 3, 4), ta), 'Beregnet nivå: Høy. Score 12');
    });

    test('half a pair says nothing', () => {
        assert.equal(previewAnnouncement(previewLevel(CRITERIA, 3, ''), ta), null);
        assert.equal(previewKey(null), null);
    });

    test('the same level and score are the same key, so they are not said twice', () => {
        assert.equal(previewKey(previewLevel(CRITERIA, 3, 4)), previewKey(previewLevel(CRITERIA, 4, 3)));
        assert.notEqual(previewKey(previewLevel(CRITERIA, 3, 4)), previewKey(previewLevel(CRITERIA, 5, 4)));
    });

    test('English texts are used when given', () => {
        assert.equal(
            previewAnnouncement({ level: 'low', score: 2 }, { level_preview: 'Calculated level', score: 'Score :score', levels: { low: 'Low' } }),
            'Calculated level: Low. Score 2',
        );
    });
});

describe('focus after a risk tiltak changes', () => {
    test('the pressed button first, then what the tiltak offers, then its name and the heading', () => {
        assert.deepEqual(riskActionFocusCandidates(7, 'complete'), [
            'risk-action-7-complete',
            'risk-action-7-edit',
            'risk-action-7-reopen',
            'risk-action-7-name',
            'risk-treatment-heading',
        ]);
    });

    test('every candidate id is one the panel renders', () => {
        const panel = code(read('RiskTreatmentPanel.jsx'));

        for (const suffix of ['edit', 'complete', 'reopen', 'name']) {
            assert.match(panel, new RegExp(`risk-action-\\$\\{action\\.id\\}-${suffix}`), suffix);
        }
        assert.match(panel, /id="risk-treatment-heading"/);
    });
});

describe('forms in Risiko (A11Y-013, A11Y-019, A11Y-121, A11Y-143)', () => {
    test('no file keeps its own light field border; fields use formStyles (border-field-border)', () => {
        for (const file of jsxFiles) {
            const source = code(read(file));

            assert.doesNotMatch(source, /const INPUT = /, file);
            assert.doesNotMatch(source, /border border-slate-200 bg-white px-3 py-2/, file);
        }
    });

    test('error text is rose-700, never rose-500/600', () => {
        for (const file of jsxFiles) {
            assert.doesNotMatch(code(read(file)), /text-rose-(500|600)\b/, file);
        }
    });

    test('the star is rose-700 and hidden from screen readers', () => {
        const mark = code(read('RequiredMark.jsx'));

        assert.match(mark, /aria-hidden="true"/);
        assert.match(mark, /text-rose-700/);
    });

    test('every form that shows the star also explains it, at 16 px', () => {
        for (const file of jsxFiles.filter((name) => /<RequiredMark/.test(code(read(name))))) {
            const source = code(read(file));

            assert.match(source, /<RequiredFieldsNote[^>]*className="text-base text-slate-600"/, file);
        }
    });

    test('a rejected save puts focus on the first invalid field', () => {
        for (const file of ['Index.jsx', 'Show.jsx', 'RiskAssessmentPanel.jsx', 'RiskTreatmentPanel.jsx', 'RiskControlsPanel.jsx', 'RiskContextPanel.jsx', 'RiskAcceptancePanel.jsx']) {
            const source = code(read(file));

            assert.match(source, /useFocusFirstError\(/, file);
            assert.match(source, /onError: \(\) => focusFirstError\(\)/, file);
        }
    });

    test('errors are tied to their fields through fieldA11y (aria-describedby + aria-invalid)', () => {
        for (const file of ['RiskForm.jsx', 'RiskAssessmentPanel.jsx', 'RiskTreatmentPanel.jsx', 'RiskControlsPanel.jsx', 'RiskContextPanel.jsx', 'RiskAcceptancePanel.jsx']) {
            const source = code(read(file));

            assert.match(source, /fieldA11y\(/, file);
            assert.doesNotMatch(source, /form\.errors\.\w+ && <p className=/, file);
        }
    });

    test('save buttons stay focusable while saving', () => {
        for (const file of jsxFiles) {
            const source = code(read(file));

            assert.doesNotMatch(source, /(?<!aria-)disabled=\{form\.processing\}/, file);
            assert.doesNotMatch(source, /(?<!aria-)disabled=\{form\.processing \|\|/, file);
        }
    });
});

describe('inline panels and removal (A11Y-014)', () => {
    test('every panel that replaces its button moves focus in and back', () => {
        for (const file of ['Index.jsx', 'RiskAssessmentPanel.jsx', 'RiskTreatmentPanel.jsx', 'RiskControlsPanel.jsx', 'RiskContextPanel.jsx', 'RiskAcceptancePanel.jsx']) {
            assert.match(code(read(file)), /useInlinePanelFocus\(/, file);
        }
    });

    test('the edit form on the risk page returns focus to the button that opened it', () => {
        const show = code(read('Show.jsx'));

        assert.match(show, /refocusIfLost\(\[editReturnRef\.current, 'risk-edit-button', 'risk-heading'\]\)/);
        assert.match(show, /startEditing\('risk-review-interval', 'risk-review-interval-edit'\)/);
        assert.match(show, /startEditing\('risk-treatment-strategy', 'risk-treatment-strategy-edit'\)/);
        assert.match(code(read('RiskReviewSchedule.jsx')), /id="risk-review-interval-edit"/);
        assert.match(code(read('RiskTreatmentStrategy.jsx')), /id="risk-treatment-strategy-edit"/);
    });

    test('removing a link keeps window.confirm and moves focus to the next link', () => {
        for (const file of ['RiskControlsPanel.jsx', 'RiskContextPanel.jsx']) {
            const source = code(read(file));

            assert.match(source, /window\.confirm\(/, file);
            assert.match(source, /removalFocusCandidates\(/, file);
            assert.match(source, /onSuccess: \(\) => refocusIfLost\(candidates\)/, file);
        }
    });

    test('a repeated row button is described by its row', () => {
        assert.match(code(read('RiskControlsPanel.jsx')), /aria-describedby=\{`risk-control-\$\{control\.id\}-name`\}/);
        assert.match(code(read('RiskTreatmentPanel.jsx')), /aria-describedby=\{`risk-action-\$\{action\.id\}-name`\}/);
    });
});

describe('the register (A11Y-108, -110, -111) and the assessment (A11Y-112)', () => {
    const index = code(read('Index.jsx'));

    test('the table has a caption, column and row headers, and scrolls in a named region', () => {
        assert.match(index, /<ScrollRegion label=/);
        assert.match(index, /<caption className="sr-only">/);
        assert.equal((index.match(/<th scope="col"/g) ?? []).length, 5);
        assert.match(index, /<th scope="row"/);
    });

    test('the hit count is announced from the search visit, not on load', () => {
        assert.match(index, /onSuccess: announceCount\(/);
        assert.doesNotMatch(index, /useEffect/);
    });

    test('grey text on the page background is slate-600', () => {
        assert.doesNotMatch(index, /<p className="text-base text-slate-500">\s*\{tr\.scope_note/);
    });

    test('the calculated level is not a live region that mounts with the form', () => {
        const panel = code(read('RiskAssessmentPanel.jsx'));

        assert.doesNotMatch(panel, /aria-live/);
        assert.match(panel, /announce\(message\)/);
    });

    test('the rating explanation is text, not a hover-only title', () => {
        assert.doesNotMatch(code(read('RiskAssessmentPanel.jsx')), /title=\{hint\}/);
    });

    test('status and level are text in the badge, not colour alone', () => {
        assert.match(index, /\{levelLabels\[risk\.residual_level\] \?\? risk\.residual_level\}/);
        assert.match(index, /\{statusLabels\[risk\.status\] \?\? risk\.status\}/);
    });
});

describe('the hit count spoken after a search is the number of rows the search returned (A11Y-111)', () => {
    // `visible_count` is everything the user may see, unaffected by the search; announcing it would
    // say «1 risikoer» for a search that found nothing. Found by tests/e2e/a11y-grc-risk.spec.js.
    const registers = [
        ['Risk/Index.jsx', 'risks'],
        ['Improvements/Index.jsx', 'cases'],
        ['SupplierManagement/Index.jsx', 'suppliers'],
    ];

    for (const [file, list] of registers) {
        test(`${file} announces ${list}.length, not visible_count`, () => {
            const source = code(readFileSync(join(here, '..', 'Pages', 'App', file), 'utf8'));
            const announcing = source.slice(source.indexOf('const announceCount'), source.indexOf('const announceCount') + 400);

            assert.match(announcing, new RegExp(`page\\?\\.props\\?\\.${list}\\?\\.length`));
            assert.doesNotMatch(announcing, /visible_count/);
        });
    }
});
