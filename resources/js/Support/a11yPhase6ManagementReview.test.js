import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { paneHeadingId, readinessStateText, removalFocusCandidates } from '../Pages/App/ManagementReview/reviewA11y.js';

const here = dirname(fileURLToPath(import.meta.url));
const page = (name) => readFileSync(join(here, '..', 'Pages', 'App', 'ManagementReview', name), 'utf8');
const blade = readFileSync(join(here, '..', '..', 'views', 'management-review', 'report.blade.php'), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md §8): Ledelsens gjennomgang. The rules are
 * pinned here; behaviour in a real browser is in tests/e2e/a11y-grc-management-review.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

describe('section navigation puts focus on the pane it opened (A11Y-014)', () => {
    test('each pane has a heading to land on', () => {
        assert.equal(paneHeadingId('overview', true), 'mr-readiness-heading');
        assert.equal(paneHeadingId('overview', false), 'mr-attention-heading');
        assert.equal(paneHeadingId('decisions'), 'mr-decisions-heading');
        assert.equal(paneHeadingId('history'), 'mr-history-heading');
        assert.equal(paneHeadingId('risks'), 'mr-section-risks');
    });

    test('Neste/Forrige and the Oversikt links ask for the heading; the navigation does not', () => {
        const source = code(page('Show.jsx'));

        assert.match(source, /const openWithFocus = \(key\) => open\(key, \{ focusHeading: true \}\)/);
        assert.match(source, /<SectionNav [^>]*onOpen=\{open\}/);
        assert.equal((source.match(/onOpen=\{openWithFocus\}/g) ?? []).length, 2, 'Overview and SectionPane');
        // The request is computed when asked, so a reload of the page's props never moves focus.
        assert.match(source, /\}, \[headingFocus\]\);/);
        for (const id of ['mr-readiness-heading', 'mr-attention-heading', 'mr-decisions-heading', 'mr-history-heading']) {
            assert.match(source, new RegExp(`id="${id}" tabIndex=\\{-1\\}`), id);
        }
        assert.match(source, /id=\{`mr-section-\$\{section\.key\}`\} tabIndex=\{-1\}/);
    });
});

describe('status is said in words (A11Y-024)', () => {
    test('readiness items read «fullført» or «gjenstår», with the Saksforløp keys', () => {
        assert.equal(readinessStateText(true), 'fullført');
        assert.equal(readinessStateText(false), 'gjenstår');
        assert.equal(readinessStateText(true, { step_completed: 'completed' }), 'completed');
        assert.match(code(page('Show.jsx')), /<span className="sr-only">\{` \(\$\{readinessStateText\(item\.done, ta\)\}\)`\}<\/span>/);
    });

    test('section markers are at least 3:1 and a flagged number says so', () => {
        const source = code(page('Show.jsx'));

        const markers = source.match(/const MARKER_STYLE = \{[\s\S]*?\};/)[0];

        assert.doesNotMatch(markers, /border-(slate|amber|emerald)-(200|300|400|500)\b(?<!slate-500)/);
        assert.match(markers, /open: 'border-slate-500 bg-slate-50'/);
        assert.match(markers, /attention: 'border-amber-600/);
        assert.match(code(page('SectionBasis.jsx')), /flagged && <span className="sr-only">/);
    });
});

describe('the report: row headers and the accessible alternative to the PDF (A11Y-113, A11Y-126)', () => {
    test('key figures are a table with a caption and row headers', () => {
        const source = code(page('Report.jsx'));

        assert.match(source, /<caption className="sr-only">/);
        assert.match(source, /<th scope="row" className="py-1 pr-4 text-left font-normal">\{metric\.label\}<\/th>/);
    });

    test('the PDF link is described by a note that links to this accessible version', () => {
        const source = code(page('Report.jsx'));

        assert.match(source, /aria-describedby="mr-pdf-note" data-testid="mr-download-pdf"/);
        assert.match(source, /<p id="mr-pdf-note"/);
        assert.match(source, /<a href="#mr-report"/);
        assert.match(source, /<article id="mr-report" tabIndex=\{-1\}/);
    });

    test('the PDF template gives its header cells a scope', () => {
        assert.doesNotMatch(blade, /<th>/);
        assert.match(blade, /<th scope="col">\{\{ \$labels\['item'\] \}\}<\/th>/);
    });

    test('the register table has a caption, column scopes and a row header', () => {
        const source = code(page('Index.jsx'));

        assert.match(source, /<ScrollRegion label=/);
        assert.match(source, /<caption className="sr-only">/);
        assert.doesNotMatch(source, /<th className=/);
        assert.match(source, /<th scope="row"/);
    });
});

describe('forms: grouped choices, wired fields, focus on the first error (A11Y-013, -120, -121)', () => {
    test('ReviewForm groups Avgrensning and the fagområder, ties every hint, and says what «*» means', () => {
        const source = code(page('ReviewForm.jsx'));

        assert.match(source, /<FieldsetField\s+id="mr-scope"/);
        assert.match(source, /role="group" aria-label=\{tf\.areas \?\? 'Fagområder'\}/);
        assert.match(source, /aria-describedby=\{describedBy\(tf\.purpose_hint && 'mr-purpose-hint'/);
        assert.match(source, /<RequiredFieldsNote\b/);
        assert.match(source, /onSubmit=\{\(event\) => onSubmit\(event, focusFirstError\)\}/);
        assert.doesNotMatch(source, /RequiredMark/);
    });

    test('decision forms: Type and the hand-off choice are named groups with their hints', () => {
        const source = code(page('ReviewDecisions.jsx'));

        assert.match(source, /<fieldset aria-describedby=\{kindHintId\}>/);
        assert.match(source, /<fieldset aria-describedby=\{hintId\}>\s*<legend className="sr-only">/);
        assert.equal((source.match(/useFocusFirstError\(formRef\)/g) ?? []).length, 4);
        assert.doesNotMatch(source, /RequiredMark/);
    });

    test('Show: assessment, participants, conclusion, next review and amendments go through FormField', () => {
        const source = code(page('Show.jsx'));

        for (const id of ['mr-participant-user', 'mr-conclusion-text', 'mr-next-date', 'mr-next-owner', 'mr-amendment-text', 'mr-amendment-reason']) {
            assert.match(source, new RegExp(`<FormField id="${id}"`), id);
        }
        assert.match(source, /<FieldsetField id=\{`mr-judgement-group-\$\{section\.key\}`\}/);
        assert.equal((source.match(/onError: focusFirstError/g) ?? []).length, 6);
    });
});

describe('removing a row never drops focus (A11Y-014)', () => {
    test('next row, then previous, then the fallbacks', () => {
        const id = (row) => `remove-${row}`;

        assert.deepEqual(removalFocusCandidates([1, 2, 3], 2, id, ['add', 'heading']), ['remove-3', 'remove-1', 'add', 'heading']);
        assert.deepEqual(removalFocusCandidates([1, 2, 3], 3, id, ['add']), ['remove-2', 'remove-1', 'add']);
        assert.deepEqual(removalFocusCandidates([7], 7, id, ['heading']), ['heading']);
    });

    test('participants and decisions use it, and row buttons carry the row', () => {
        const show = code(page('Show.jsx'));
        const decisions = code(page('ReviewDecisions.jsx'));

        assert.match(show, /refocusIfLost\(removalFocusCandidates\(participants\.map/);
        assert.match(show, /aria-describedby=\{`mr-participant-name-\$\{participant\.id\}`\}/);
        assert.doesNotMatch(show, /row_action/, 'the picker chip is «Fjern <name>»; the row button must not share that name');
        assert.match(decisions, /onSuccess: \(\) => refocusIfLost\(focusAfterRemoval\(decision\.id\)\)/);
        assert.match(decisions, /id=\{buttonId\('delete'\)\} onClick=\{destroy\} aria-describedby=\{textId\}/);
        assert.match(decisions, /usePanelFocus\(adding, \{ panelRef: addPanelRef, returnFocusRef: addButtonRef \}\)/);
    });
});
