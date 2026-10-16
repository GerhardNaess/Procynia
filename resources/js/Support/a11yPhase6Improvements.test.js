import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    ACTION_BUTTON_KINDS,
    actionButtonId,
    actionFocusCandidates,
    casePanelFocusCandidates,
} from '../Pages/App/Improvements/improvementA11y.js';
import { removalFocusCandidates, resultCountMessage } from '../Pages/App/Risk/riskA11y.js';

const here = dirname(fileURLToPath(import.meta.url));
const dir = join(here, '..', 'Pages', 'App', 'Improvements');
const read = (file) => readFileSync(join(dir, file), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md §8): Avvik og forbedringer. The rules are
 * pinned here; behaviour in a real browser is in tests/e2e/a11y-grc-improvements.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

const jsxFiles = readdirSync(dir).filter((name) => name.endsWith('.jsx'));

describe('focus after a tiltak panel closes or the tiltak changes (A11Y-014)', () => {
    test('the pressed button first, then the card\'s buttons in order, then its title and the heading', () => {
        const candidates = actionFocusCandidates(4, 'complete');

        assert.equal(candidates[0], 'improvement-action-4-complete');
        assert.deepEqual(candidates.slice(1, 7), ACTION_BUTTON_KINDS.filter((kind) => kind !== 'complete').map((kind) => actionButtonId(4, kind)));
        assert.deepEqual(candidates.slice(-2), ['improvement-action-4-title', 'improvement-actions-heading']);
    });

    test('without a pressed button, every button is a candidate once', () => {
        const candidates = actionFocusCandidates(4);

        assert.equal(new Set(candidates).size, candidates.length);
        assert.equal(candidates.length, ACTION_BUTTON_KINDS.length + 2);
    });

    test('the card renders every id the candidates name', () => {
        const actions = code(read('ImprovementActions.jsx'));

        assert.match(actions, /buttonProps = \(kind\) => \(\{ id: actionButtonId\(action\.id, kind\), 'aria-describedby': titleId \}\)/);
        for (const kind of ACTION_BUTTON_KINDS) {
            assert.match(actions, new RegExp(`buttonProps\\('${kind}'\\)`), kind);
        }
        assert.match(actions, /id="improvement-actions-heading"/);
    });

    test('a deleted tiltak hands focus to the next card, then the previous one, then «Nytt tiltak»', () => {
        const titles = ['improvement-action-1-title', 'improvement-action-2-title'];

        assert.deepEqual(
            removalFocusCandidates(titles, 'improvement-action-1-title', ['improvement-action-create', 'improvement-actions-heading']),
            ['improvement-action-2-title', 'improvement-action-create', 'improvement-actions-heading'],
        );
    });
});

describe('focus after a case panel closes (A11Y-014)', () => {
    test('Rediger returns to Rediger, then the heading', () => {
        assert.deepEqual(casePanelFocusCandidates('edit'), ['improvement-edit-button', 'improvement-heading']);
    });

    test('a lifecycle form returns to its button, else what the case offers now, else Behandling', () => {
        const candidates = casePanelFocusCandidates('close');

        assert.equal(candidates[0], 'improvement-close-button');
        assert.ok(candidates.includes('improvement-reopen-button'));
        assert.equal(candidates.at(-1), 'improvement-handling-heading');
    });

    test('the page renders the ids the candidates name', () => {
        const show = code(read('Show.jsx'));

        for (const id of ['improvement-edit-button', 'improvement-heading', 'improvement-start-button', 'improvement-close-button', 'improvement-cancel-button', 'improvement-reopen-button']) {
            assert.match(show, new RegExp(`id="${id}"`), id);
        }
    });
});

describe('forms in Avvik og forbedringer (A11Y-013, -019, -121, -143)', () => {
    test('no file keeps its own light field border', () => {
        for (const file of jsxFiles) {
            assert.doesNotMatch(code(read(file)), /const INPUT = /, file);
        }
    });

    test('every form that shows the star also explains it, at 16 px', () => {
        for (const file of jsxFiles.filter((name) => /<RequiredMark/.test(code(read(name))))) {
            assert.match(code(read(file)), /<RequiredFieldsNote[^>]*className="text-base text-slate-600"/, file);
        }
    });

    test('errors are tied to their fields, and a rejected save moves focus to the first one', () => {
        for (const file of ['ImprovementForm.jsx', 'ImprovementActionForm.jsx', 'ImprovementStatusForms.jsx', 'ImprovementCause.jsx', 'ImprovementActions.jsx']) {
            const source = code(read(file));

            assert.match(source, /fieldA11y\(/, file);
            assert.doesNotMatch(source, /errors\.\w+ && <p className=/, file);
            assert.doesNotMatch(source, /errors\[field\] && <p className=/, file);
        }
        for (const file of ['Index.jsx', 'Show.jsx', 'ImprovementStatusForms.jsx', 'ImprovementCause.jsx', 'ImprovementActions.jsx']) {
            assert.match(code(read(file)), /onError: \(\) => focusFirst\w*Error\(\)/, file);
        }
    });

    test('save and start buttons stay focusable while working', () => {
        for (const file of jsxFiles) {
            const source = code(read(file));

            assert.doesNotMatch(source, /(?<!aria-)disabled=\{form\.processing\}/, file);
            assert.doesNotMatch(source, /(?<!aria-)disabled=\{starting\}/, file);
        }
    });

    test('the radio groups are described by their error', () => {
        assert.match(code(read('ImprovementForm.jsx')), /<fieldset aria-describedby=\{errors\.type \? typeErrorId : undefined\}>/);
        assert.match(code(read('ImprovementActions.jsx')), /<fieldset aria-describedby=\{form\.errors\.result \? resultErrorId : undefined\}>/);
    });
});

describe('register, refusals and status (A11Y-108, -110, -111, -137, 1.4.1)', () => {
    const index = code(read('Index.jsx'));

    test('the table has a caption, column and row headers, and scrolls in a named region', () => {
        assert.match(index, /<ScrollRegion label=/);
        assert.match(index, /<caption className="sr-only">/);
        assert.equal((index.match(/<th scope="col"/g) ?? []).length, 6);
        assert.match(index, /<th scope="row"/);
    });

    test('the hit count is announced from the search visit, in the register\'s words', () => {
        assert.match(index, /onSuccess: announceCount\(/);
        assert.equal(resultCountMessage(1, { many: ':count saker', one: '1 sak' }), '1 sak');
    });

    test('a refused delete is shown at the button (trackRowAction), with window.confirm kept', () => {
        for (const file of ['Show.jsx', 'ImprovementActions.jsx']) {
            const source = code(read(file));

            assert.match(source, /window\.confirm\(/, file);
            assert.match(source, /trackRowAction\(/, file);
            assert.match(source, /useRowActionError\(flash\?\.error\)/, file);
        }
    });

    test('a refused start of the case is said and shown at the button', () => {
        const show = code(read('Show.jsx'));

        assert.match(show, /pageErrors\.status/);
        assert.match(show, /id="improvement-start-error" role="alert"/);
    });

    test('overdue and a missing owner are words, not colour', () => {
        assert.match(index, /\{tr\.overdue \?\? 'Frist passert'\}/);
        assert.match(index, /\{tr\.no_owner \?\? 'Mangler ansvarlig'\}/);
        assert.match(code(read('ImprovementActions.jsx')), /\{t\.overdue \?\? 'Frist passert'\}/);
    });
});
