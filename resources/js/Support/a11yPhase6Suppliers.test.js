import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    importFilterAnnouncement,
    importStepState,
    importStepStateText,
    inPageFocusTarget,
    panelCloseCandidates,
    panelTriggerId,
    registerResultAnnouncement,
    removalFocusCandidates,
    wikiSearchAnnouncement,
} from '../Pages/App/SupplierManagement/supplierA11y.js';

const here = dirname(fileURLToPath(import.meta.url));
const MODULE = join(here, '..', 'Pages', 'App', 'SupplierManagement');
const read = (file) => readFileSync(join(MODULE, file), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md): Leverandøroppfølging. The rules are pinned
 * here; behaviour in a real browser is in tests/e2e/a11y-grc-suppliers.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

const PAGES = readdirSync(MODULE).filter((file) => file.endsWith('.jsx'));

describe('import steps say where they stand in words (A11Y-024)', () => {
    test('done, current and still to come follow the step', () => {
        assert.deepEqual([0, 1, 2].map((index) => importStepState('upload', index)), ['current', 'todo', 'todo']);
        assert.deepEqual([0, 1, 2].map((index) => importStepState('review', index)), ['done', 'current', 'todo']);
        assert.deepEqual([0, 1, 2].map((index) => importStepState('confirm', index)), ['done', 'done', 'current']);
        assert.deepEqual([0, 1, 2].map((index) => importStepState('result', index)), ['done', 'done', 'done']);
    });

    test('each state has its own hidden text, translated', () => {
        assert.equal(importStepStateText('done'), 'fullført');
        assert.equal(importStepStateText('current'), 'nåværende steg');
        assert.equal(importStepStateText('todo'), 'gjenstår');
        assert.equal(importStepStateText('done', {}, { suppliers_step_done: 'completed' }), 'completed');
        assert.equal(importStepStateText('current', { current: 'current step' }), 'current step');
    });

    test('every step carries its state, and the current one aria-current="step"', () => {
        const page = code(read('Import.jsx'));

        assert.match(page, /aria-current=\{current \? 'step' : undefined\}/);
        assert.match(page, /<span className="sr-only">\(\{importStepStateText\(state, s, ta\)\}\)<\/span>/);
    });
});

describe('the import upload (A11Y-011)', () => {
    test('FilePickerField is busy while uploading, the error is announced and describes the field, and focus returns to it', () => {
        const page = code(read('Import.jsx'));

        assert.match(page, /<FilePickerField[\s\S]*busy=\{form\.processing\}/);
        assert.doesNotMatch(page, /announceError=\{false\}/);
        assert.doesNotMatch(page, /disabled=\{form\.processing\}/);
        assert.match(page, /onError: \(\) => \{ form\.setData\('file', null\); setInputKey\(\(key\) => key \+ 1\); focusError\(\); \}/);
    });

    test('a changed row filter is said once, with the number of rows shown', () => {
        assert.equal(importFilterAnnouncement(0), 'Ingen rader vises');
        assert.equal(importFilterAnnouncement(1), '1 rad vises');
        assert.equal(importFilterAnnouncement(4), '4 rader vises');
        assert.equal(importFilterAnnouncement(4, { suppliers_import_rows_many: ':count rows shown' }), '4 rows shown');
        assert.match(code(read('Import.jsx')), /announce\(importFilterAnnouncement\(filterRows\(rows, value\)\.length, ta\)\)/);
    });

    test('the cancel confirmation stays native', () => {
        assert.match(code(read('Import.jsx')), /window\.confirm\(r\.cancel_confirm/);
    });
});

describe('in-page links move focus with the view (A11Y-015)', () => {
    const heading = (button) => ({ tagName: 'H3', querySelector: () => button });

    test('a Detaljer section is entered on its open/close button; any other target is focused itself', () => {
        const button = { tagName: 'BUTTON' };
        const row = { tagName: 'LI', querySelector: () => ({ tagName: 'BUTTON' }) };

        assert.equal(inPageFocusTarget(heading(button)), button);
        assert.equal(inPageFocusTarget(row), row);
        assert.equal(inPageFocusTarget(heading(null)).tagName, 'H3');
        assert.equal(inPageFocusTarget(null), null);
    });

    test('Show.jsx focuses the target after scrolling to it', () => {
        assert.match(code(read('Show.jsx')), /scrollIntoView\?\.\(\{ block: 'start' \}\);\s*focusElement\(inPageFocusTarget\(target\), \{ preventScroll: true \}\);/);
    });

    test('the Detaljer toggle has an id the fallbacks can find', () => {
        assert.match(code(read('SupplierDetailsSection.jsx')), /id=\{`\$\{headingId\}-toggle`\}/);
    });
});

describe('inline panels give focus back (A11Y-014)', () => {
    test('the opening button is found from the click, and the candidates are de-duplicated', () => {
        const button = { id: 'supplier-edit-button' };
        const inner = { closest: () => button };

        assert.equal(panelTriggerId(inner), 'supplier-edit-button');
        assert.equal(panelTriggerId({ closest: () => null }), null);
        assert.equal(panelTriggerId(null), null);
        assert.deepEqual(panelCloseCandidates('a', ['a', 'b', null]), ['a', 'b']);
        assert.deepEqual(panelCloseCandidates(null, ['b']), ['b']);
    });

    test('after a removal: the next row, then the previous one, then the fallbacks', () => {
        const id = (row) => `row-${row}`;

        assert.deepEqual(removalFocusCandidates([1, 2, 3], 2, id, ['heading']), ['row-3', 'row-1', 'heading']);
        assert.deepEqual(removalFocusCandidates([1, 2, 3], 3, id, ['heading']), ['row-2', 'row-1', 'heading']);
        assert.deepEqual(removalFocusCandidates([1], 1, id, ['heading']), ['heading']);
        assert.deepEqual(removalFocusCandidates([1, 2], 9, id, ['heading']), ['heading']);
    });

    test('each panel owner wires useInlinePanelFocus, and its triggers say so', () => {
        for (const file of ['Index.jsx', 'Show.jsx', 'SupplierCriticality.jsx', 'SupplierProfile.jsx', 'SupplierAssessment.jsx', 'SupplierDueDiligence.jsx', 'SupplierControlRequirements.jsx', 'SupplierDocuments.jsx', 'SupplierRisks.jsx', 'SupplierImprovementCases.jsx', 'ControlRequirements.jsx', 'WikiGuidance.jsx']) {
            const page = code(read(file));

            assert.match(page, /useInlinePanelFocus\(/, file);
            assert.match(page, /onClickCapture=\{remember\w*Trigger\}/, file);
            assert.match(page, /data-panel-trigger=""/, file);
        }
    });

    test('the handoff forms focus themselves when they mount (they can be opened from another tab)', () => {
        for (const file of ['SupplierRisks.jsx', 'SupplierImprovementCases.jsx']) {
            assert.match(code(read(file)), /useFocusOnMount\(ref\);/, file);
        }
    });

    test('row removals put focus somewhere deliberate, and window.confirm stays', () => {
        for (const file of ['SupplierDocuments.jsx', 'SupplierRisks.jsx', 'SupplierImprovementCases.jsx', 'ControlRequirements.jsx', 'WikiGuidance.jsx']) {
            const page = code(read(file));

            assert.match(page, /window\.confirm\(/, file);
            assert.match(page, /refocusIfLost\(removalFocusCandidates\(/, file);
        }
    });
});

describe('forms (A11Y-013, A11Y-120, A11Y-121)', () => {
    test('no field in the module keeps the old 1.23:1 border or the light «*»', () => {
        for (const file of PAGES) {
            const page = code(read(file));

            assert.doesNotMatch(page, /rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900/, file);
            assert.doesNotMatch(page, /RequiredMark/, file);
        }
    });

    test('every form that posts focuses the first error from onError', () => {
        for (const file of ['Index.jsx', 'Show.jsx', 'SupplierStatusForms.jsx', 'SupplierCriticality.jsx', 'SupplierProfile.jsx', 'SupplierAssessment.jsx', 'SupplierDueDiligence.jsx', 'SupplierRequirementEvaluation.jsx', 'SupplierControlRequirements.jsx', 'ControlRequirementForm.jsx', 'SupplierDocuments.jsx', 'SupplierRisks.jsx', 'SupplierImprovementCases.jsx']) {
            const page = code(read(file));

            assert.match(page, /useFocusFirstError\(/, file);
            assert.match(page, /onError: focus\w*Error/, file);
        }
    });

    test('every form with «*» says what it means once', () => {
        for (const file of ['SupplierForm.jsx', 'SupplierStatusForms.jsx', 'SupplierCriticality.jsx', 'SupplierAssessment.jsx', 'SupplierDueDiligence.jsx', 'SupplierRequirementEvaluation.jsx', 'ControlRequirementForm.jsx', 'SupplierDocuments.jsx', 'SupplierRisks.jsx', 'SupplierImprovementCases.jsx']) {
            assert.match(code(read(file)), /<RequiredNote tr=\{tr\} \/>/, file);
        }
    });

    test('groups are described by their error and mark their options invalid', () => {
        for (const file of ['CriticalityFields.jsx', 'SupplierProfile.jsx', 'SupplierAssessment.jsx', 'SupplierDueDiligence.jsx', 'SupplierRequirementEvaluation.jsx', 'ControlRequirementForm.jsx']) {
            const page = code(read(file));

            assert.match(page, /groupA11y\(/, file);
            assert.match(page, /\{\.\.\.\w+\.optionProps\}/, file);
            assert.match(page, /\{\.\.\.\w+\.groupProps\}/, file);
        }
    });

    test('submit buttons stay focusable while saving: aria-disabled, guarded in the handler (A11Y-143)', () => {
        for (const file of PAGES) {
            const page = code(read(file));

            assert.doesNotMatch(page, /type="submit"[^>]*\s(?<!-)disabled=\{/, file);
        }

        assert.match(code(read('SupplierForm.jsx')), /aria-disabled=\{form\.processing \|\| undefined\}/);
        assert.match(code(read('Show.jsx')), /if \(form\.processing\) \{\s*return;\s*\}/);
    });
});

describe('tables, statuses and messages', () => {
    test('the register table has a caption, column and row headers, and scrolls in its own region', () => {
        const page = code(read('Index.jsx'));

        assert.match(page, /<ScrollRegion label=/);
        assert.match(page, /<caption className="sr-only">/);
        assert.equal((page.match(/<th scope="col"/g) ?? []).length, 8);
        assert.match(page, /<th scope="row"/);
    });

    test('the register says the hit count once, after a search — never on load', () => {
        assert.equal(registerResultAnnouncement(1), '1 leverandør');
        assert.equal(registerResultAnnouncement(12), '12 leverandører');
        const page = code(read('Index.jsx'));
        assert.match(page, /preserveState: true, replace: true, onSuccess: announceCount/);
        assert.match(page, /role="search"/);
    });

    test('a Wiki search says how many it found, and only a search the person typed', () => {
        assert.equal(wikiSearchAnnouncement('loading', 0), null);
        assert.equal(wikiSearchAnnouncement('error', 0), null);
        assert.equal(wikiSearchAnnouncement('ready', 0), 'Ingen artikler passer søket');
        assert.equal(wikiSearchAnnouncement('ready', 1), '1 artikkel funnet');
        assert.equal(wikiSearchAnnouncement('ready', 20, true), '20 artikler funnet. Skriv mer for å snevre inn søket.');
        const page = code(read('WikiGuidance.jsx'));
        assert.doesNotMatch(page, /aria-live/);
        assert.match(page, /typed\.current && state\.status === 'ready'/);
    });

    test('no region in the module mounts together with its text', () => {
        for (const file of PAGES) {
            const page = code(read(file));

            assert.doesNotMatch(page, /role="alert"/, file);
            assert.doesNotMatch(page, /aria-live=/, file);
        }
    });

    test('Ta i bruk likevel is described by the warning it answers', () => {
        assert.match(code(read('Show.jsx')), /aria-describedby="supplier-activate-warning-text"/);
    });

    test('repeated row buttons are described by their row', () => {
        for (const file of ['SupplierControlRequirements.jsx', 'SupplierDocuments.jsx', 'ControlRequirements.jsx', 'SupplierRisks.jsx', 'SupplierImprovementCases.jsx', 'WikiGuidance.jsx', 'RequirementTemplates.jsx', 'SupplierAssessment.jsx']) {
            assert.match(code(read(file)), /aria-describedby=\{/, file);
        }
    });

    test('the Kravmal dialog has a description and a fallback for focus', () => {
        const page = code(read('RequirementTemplates.jsx'));

        assert.match(page, /descriptionId="requirement-template-dialog-description"/);
        assert.match(page, /fallbackFocusRef=\{toggleRef\}/);
        assert.match(page, /aria-disabled=\{processing \|\| undefined\}/);
    });

    test('headings nest: a form under Status is h4, Wiki guidance under a theme is h4', () => {
        assert.match(code(read('SupplierStatusForms.jsx')), /<h4 id=\{`\$\{id\}-heading`\}/);
        assert.match(code(read('WikiGuidance.jsx')), /<h4 id=\{headingId\}/);
    });

    test('no new text below 16 px', () => {
        for (const file of ['supplierA11y.js', 'supplierFocus.js', 'SupplierField.jsx']) {
            assert.doesNotMatch(code(read(file)), /text-(xs|sm)\b/, file);
        }
    });
});
