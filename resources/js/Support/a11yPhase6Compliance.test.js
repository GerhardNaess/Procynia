import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative } from 'node:path';
import {
    countRequirementNodes,
    fieldErrorId,
    fieldStateProps,
    filterSignature,
    groupStateProps,
    inPageTargetId,
    libraryHitAnnouncement,
    nextHeadingLevel,
    optionInvalid,
    panelTransition,
    radioGroupProps,
    rejectedSubmitStep,
    removalFocusCandidates,
} from '../Pages/App/Compliance/complianceA11y.js';

const here = dirname(fileURLToPath(import.meta.url));
const moduleDir = join(here, '..', 'Pages', 'App', 'Compliance');
const read = (file) => readFileSync(join(moduleDir, file), 'utf8');
const lang = (code) => readFileSync(join(here, '..', '..', '..', 'lang', code, 'procynia.php'), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md §8): Etterlevelse og revisjon. The rules are
 * pinned here; behaviour in a real browser is in tests/e2e/a11y-grc-compliance.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

function jsxFiles(dir = moduleDir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return jsxFiles(path);
        }

        return name.endsWith('.jsx') ? [relative(moduleDir, path)] : [];
    });
}

describe('fields say what is wrong with them (A11Y-013)', () => {
    test('the error id derives from the field id', () => {
        assert.equal(fieldErrorId('compliance-audit-title'), 'compliance-audit-title-error');
    });

    test('a valid field is described by its hint only, and is not marked invalid', () => {
        assert.deepEqual(fieldStateProps('f', null, ['f-hint']), { 'aria-describedby': 'f-hint', 'aria-invalid': undefined });
        assert.deepEqual(fieldStateProps('f'), { 'aria-describedby': undefined, 'aria-invalid': undefined });
    });

    test('an invalid field is described by its hint and then its error, and is marked invalid', () => {
        assert.deepEqual(fieldStateProps('f', 'Mangler', ['f-hint', null]), { 'aria-describedby': 'f-hint f-error', 'aria-invalid': true });
    });

    test('a group is described by its hint and error; checkboxes carry aria-invalid themselves', () => {
        assert.deepEqual(groupStateProps('g', 'Velg', ['g-hint']), { 'aria-describedby': 'g-hint g-error' });
        assert.equal(optionInvalid('Velg'), true);
        assert.equal(optionInvalid(null), undefined);
    });

    test('a radio group is a radiogroup that carries required and invalid (a radio cannot)', () => {
        assert.deepEqual(radioGroupProps('r', 'Velg', { required: true }), {
            role: 'radiogroup',
            'aria-describedby': 'r-error',
            'aria-invalid': true,
            'aria-required': true,
        });
        assert.deepEqual(radioGroupProps('r'), { role: 'radiogroup', 'aria-describedby': undefined, 'aria-invalid': undefined, 'aria-required': undefined });
    });

    test('focus moves only when a submit comes back with errors — never on typing or a re-render', () => {
        assert.deepEqual(rejectedSubmitStep(false, true, {}), { pending: true, focus: false });
        assert.deepEqual(rejectedSubmitStep(true, false, { title: 'Mangler' }), { pending: false, focus: true });
        assert.deepEqual(rejectedSubmitStep(true, false, {}), { pending: true, focus: false });
        // Errors present without a submit (typing next to an old error, a re-render) do nothing.
        assert.deepEqual(rejectedSubmitStep(false, false, { title: 'Mangler' }), { pending: false, focus: false });
        assert.deepEqual(rejectedSubmitStep(false, false, { title: null }), { pending: false, focus: false });
    });
});

describe('focus is never lost when a panel closes or a row goes (A11Y-014, A11Y-015)', () => {
    test('a panel opening, closing or staying put', () => {
        assert.deepEqual(panelTransition(null, 'edit'), { open: 'edit' });
        assert.deepEqual(panelTransition('edit-3', null), { close: 'edit-3' });
        assert.deepEqual(panelTransition('create', 'create'), null);
        assert.deepEqual(panelTransition(null, null), null);
    });

    test('after a removal: the next row, the previous row, then the fallbacks', () => {
        const edit = (id) => `edit-${id}`;
        const remove = (id) => `remove-${id}`;

        assert.deepEqual(removalFocusCandidates([1, 2, 3], 2, [edit, remove], ['heading']), ['edit-3', 'remove-3', 'edit-1', 'remove-1', 'heading']);
        assert.deepEqual(removalFocusCandidates([1, 2, 3], 3, [remove], ['add']), ['remove-2', 'add']);
        assert.deepEqual(removalFocusCandidates([7], 7, [remove], ['add', 'heading']), ['add', 'heading']);
        assert.deepEqual(removalFocusCandidates(['1', '2'], 1, [remove]), ['remove-2']);
    });

    test('only a finding or a check is an in-page target', () => {
        assert.equal(inPageTargetId('#finding-12'), 'finding-12');
        assert.equal(inPageTargetId('#check-3'), 'check-3');
        assert.equal(inPageTargetId('#compliance-audit-status'), null);
        assert.equal(inPageTargetId(''), null);
        assert.equal(inPageTargetId(undefined), null);
    });
});

describe('hit counts are spoken once (A11Y-111)', () => {
    const tree = [
        { kind: 'section', children: [{ kind: 'requirement', children: [] }, { kind: 'section', children: [{ kind: 'requirement' }] }] },
        { kind: 'requirement' },
    ];

    test('the library counts the requirements in the visible tree', () => {
        assert.equal(countRequirementNodes(tree), 3);
        assert.equal(countRequirementNodes([]), 0);
    });

    test('the library says nothing while nothing narrows the tree, and the count when something does', () => {
        assert.equal(libraryHitAnnouncement(false, 12), null);
        assert.equal(libraryHitAnnouncement(true, 0), 'Ingen krav passer søket.');
        assert.equal(libraryHitAnnouncement(true, 1), '1 krav passer søket');
        assert.equal(libraryHitAnnouncement(true, 4, { many: ':count requirements match' }), '4 requirements match');
    });

    test('the registers key the announcement on the search and filters only', () => {
        const filters = { search: 'tilgang', status: '', open: ['a'], view: 'list' };

        assert.equal(filterSignature(filters, ['search', 'status']), 'search=tilgang&status=');
        assert.equal(filterSignature({ ...filters, open: ['a', 'b'], view: 'grouped' }, ['search', 'status']), filterSignature(filters, ['search', 'status']));
        assert.notEqual(filterSignature({ search: 'x' }, ['search']), filterSignature({ search: 'y' }, ['search']));
    });

    test('each register and the library announce through the shared helpers, never a region of their own', () => {
        for (const file of ['Requirements/Index.jsx', 'Assessments/Index.jsx', 'Audits/Index.jsx']) {
            assert.match(code(read(file)), /useTransitionAnnouncement\(filterSignature\(filters, \[/, file);
        }
        assert.match(code(read('Library/Show.jsx')), /useSettledAnnouncement\(/);
        for (const file of jsxFiles()) {
            assert.doesNotMatch(code(read(file)), /aria-live=/, file);
        }
    });

    test('the library hit texts exist in both languages', () => {
        for (const locale of ['no', 'en']) {
            assert.match(lang(locale), /'compliance_library_hits_one' => '/, locale);
            assert.match(lang(locale), /'compliance_library_hits' => '/, locale);
        }
    });
});

describe('the module\'s forms (A11Y-013, -019, -120, -121)', () => {
    test('every field uses the shared look with the 3:1 field border, not a copy with slate-200', () => {
        for (const file of jsxFiles()) {
            assert.doesNotMatch(code(read(file)), /const INPUT = /, file);
        }
    });

    test('no error is a loose paragraph: it is a FieldError tied to its field, or the form\'s error summary', () => {
        for (const file of jsxFiles()) {
            const source = code(read(file));

            assert.doesNotMatch(source, /\.errors\.\w+ && <p className=\{ERROR\}>/, file);
            assert.doesNotMatch(source, /errors\.\w+ && <p className="[^"]*text-rose-700">\{\w+\.errors/, file);
            for (const match of source.matchAll(/errors\.(\w+) && <p className=\{ERROR\}([^>]*)>/g)) {
                assert.match(match[2], /data-form-error-summary/, `${file}: ${match[1]}`);
            }
        }
    });

    test('the «*» is readable (rose-700, 4.5:1) and hidden from screen readers, who hear aria-required', () => {
        assert.match(code(read('RequiredMark.jsx')), /<span aria-hidden="true" className="ml-0\.5 text-rose-700">\*<\/span>/);
        for (const file of jsxFiles()) {
            assert.doesNotMatch(read(file), /Risk\/RequiredMark/, file);
        }
    });

    test('a form with «*» explains it once', () => {
        for (const file of jsxFiles()) {
            const source = code(read(file));

            if (source.includes('<RequiredMark')) {
                assert.match(source, /<RequiredFieldsNote|required_note/, file);
            }
        }
    });

    test('radio choices are radiogroups; a rejected form focuses its first invalid field', () => {
        for (const file of jsxFiles()) {
            const source = code(read(file));

            if (source.includes('type="radio"')) {
                assert.match(source, /<fieldset \{\.\.\.radioGroupProps\(/, file);
            }
        }

        // Every form the module posts with useForm — the page registers hand theirs to these.
        for (const file of ['Requirements/RequirementForm.jsx', 'Requirements/RequirementApplicability.jsx', 'Requirements/RequirementCompliance.jsx', 'Requirements/RequirementStatusForms.jsx', 'Requirements/ComplianceSources.jsx', 'Requirements/RequirementQualityContext.jsx', 'Audits/AuditForm.jsx', 'Audits/AuditLifecycleForms.jsx', 'Audits/AuditScope.jsx', 'Audits/AuditChecks.jsx', 'Audits/AuditFindings.jsx']) {
            assert.match(code(read(file)), /useFocusOnRejectedSubmit\(/, file);
        }
    });
});

describe('panels, rows and in-page links keep focus (A11Y-014, A11Y-015)', () => {
    test('the audit page opens its panels with focus inside them and closes them back to their button', () => {
        const show = code(read('Audits/Show.jsx'));

        assert.match(show, /usePanelSwitchFocus\(panel, \{ containerRef: pageRef, returnTargets: panelReturnTargets \}\)/);
        for (const key of ['start', 'cancel', 'reopen', 'complete', 'edit']) {
            assert.match(show, new RegExp(`data-inline-panel="${key}"`), key);
            assert.match(show, new RegExp(`id="compliance-audit-action-${key}"`), key);
        }
    });

    test('a link to a finding or check moves focus to it', () => {
        const show = code(read('Audits/Show.jsx'));

        assert.match(show, /focusElement\(target, \{ preventScroll: true \}\)/);
        assert.match(code(read('Audits/AuditChecks.jsx')), /onClick=\{\(\) => focusInPageTarget\(`#finding-/);
        assert.match(code(read('Audits/AuditFindings.jsx')), /onClick=\{\(\) => focusInPageTarget\(`#check-/);
    });

    test('every removal that stays on the page hands focus on', () => {
        for (const file of ['Audits/AuditScope.jsx', 'Audits/AuditChecks.jsx', 'Audits/AuditFindings.jsx', 'Requirements/ComplianceSources.jsx', 'Requirements/RequirementQualityContext.jsx']) {
            const source = code(read(file));
            const deletes = source.match(/router\.delete\(/g) ?? [];

            assert.ok(deletes.length > 0, file);
            assert.equal((source.match(/refocusIfLost\(candidates\)/g) ?? []).length, deletes.length, file);
        }
    });

    test('a refused source deletion is shown at its button', () => {
        const sources = code(read('Requirements/ComplianceSources.jsx'));

        assert.match(sources, /trackRowAction\(`compliance-source-delete-\$\{source\.id\}`/);
        assert.match(sources, /useRowActionError\(/);
    });

    test('the activation dialog says what happens, and has somewhere to put focus when its trigger is gone', () => {
        const index = code(read('Library/Index.jsx'));

        assert.match(index, /descriptionId="compliance-library-activate-description"/);
        assert.match(index, /id="compliance-library-activate-description"/);
        assert.match(index, /fallbackFocusRef=\{afterActivationRef\}/);
    });
});

describe('structure: headings, tables and focus indicators (A11Y-119, A11Y-110, A11Y-010)', () => {
    test('the register has an h2 above its h3/h4 groups', () => {
        const index = code(read('Requirements/Index.jsx'));
        const groups = code(read('Requirements/RegisterGroups.jsx'));

        assert.ok(index.indexOf('id="compliance-register-heading"') < index.indexOf('<RegisterGroups'));
        assert.match(index, /<h2 id="compliance-register-heading" className="sr-only">/);
        assert.match(groups, /<h3 className="m-0">/);
        assert.match(groups, /<h4 className="m-0">/);
        assert.doesNotMatch(groups, /ring-violet-300|focus-visible:outline-none/);
    });

    test('the library\'s headings follow the depth of the tree, and the guidance sits one level below', () => {
        assert.equal(nextHeadingLevel(2), 3);
        assert.equal(nextHeadingLevel(6), 6);
        const show = code(read('Library/Show.jsx'));

        assert.match(show, /const Heading = `h\$\{level\}`;/);
        assert.match(show, /headingLevel=\{nextHeadingLevel\(level\)\}/);
        assert.match(code(read('Requirements/Show.jsx')), /headingLevel=\{3\}/);
        assert.doesNotMatch(code(read('Library/RequirementGuidance.jsx')), /<h4/);
    });

    test('every table has a caption, column headers with scope, a row header, and scrolls in its own region', () => {
        for (const file of jsxFiles()) {
            const source = code(read(file));
            const tables = source.split('<table').slice(1);

            for (const table of tables) {
                const body = table.slice(0, table.indexOf('</table>'));

                assert.match(body, /<caption className="sr-only">/, file);
                assert.doesNotMatch(body, /<th(?![^>]*scope=)[ >]/, file);
                assert.match(body, /<th scope="row"/, file);
            }
            if (tables.length > 0) {
                assert.match(source, /<ScrollRegion /, file);
            }
        }
    });

    test('the stat tiles keep dt and dd in the list, with the link inside the dt', () => {
        const page = code(read('Assessments/Index.jsx'));

        assert.match(page, /<dt className="text-base font-semibold text-slate-600">\s*<Link href=\{href\(filter\)\}/);
        assert.match(page, /data-focus-indicator="own"/);
        assert.match(page, /has-\[a:focus-visible\]:outline-violet-600/);
    });

    test('the selected phase tab is marked by more than its colour', () => {
        assert.match(code(read('Audits/Show.jsx')), /border-violet-600 bg-violet-50 text-violet-900 ring-1 ring-inset ring-violet-600/);
    });
});
