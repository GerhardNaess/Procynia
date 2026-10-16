import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { inertOutside, isTopModal, pushModal } from './modalInert.js';
import { resetRowActionError, rowErrorFor, trackRowAction } from './rowActionError.js';
import { focusFirstInvalidField, hasErrors } from './focusManagement.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, '..', ...parts), 'utf8');

/**
 * Fase 3 of the WCAG plan (docs/wcag-accessibility-plan.md): forms, dialogs, tables and status
 * messages. The rules are pinned here; behaviour in a real browser is in tests/e2e/a11y-forms.spec.js.
 */

/** Just enough DOM for the inert walk: parents, children, attributes and two selectors. */
function element(tag, { id = null, live = false } = {}) {
    const attributes = new Map();

    if (id) attributes.set('id', id);
    if (live) attributes.set('aria-live', 'polite');

    return {
        tag,
        children: [],
        parentElement: null,
        ownerDocument: null,
        hasAttribute: (name) => attributes.has(name),
        setAttribute: (name, value) => attributes.set(name, value),
        removeAttribute: (name) => attributes.delete(name),
        matches(selector) {
            assert.equal(selector, '[aria-live], #procynia-toast');

            return attributes.has('aria-live') || attributes.get('id') === 'procynia-toast';
        },
    };
}

function tree() {
    const body = element('body');
    const doc = { body };
    const append = (parent, child) => {
        child.parentElement = parent;
        child.ownerDocument = doc;
        parent.children.push(child);

        return child;
    };
    body.ownerDocument = doc;

    const app = append(body, element('div', { id: 'app' }));
    const header = append(app, element('header'));
    const main = append(app, element('main'));
    const before = append(main, element('section'));
    const dialog = append(main, element('div'));
    const after = append(main, element('section'));
    const polite = append(body, element('div', { live: true }));
    const toast = append(body, element('div', { id: 'procynia-toast' }));
    const other = append(body, element('div'));

    return { body, app, header, main, before, dialog, after, polite, toast, other, append };
}

describe('the page behind a modal dialog is inert, the dialog is not (A11Y-020)', () => {
    test('every sibling on the way up to <body> becomes inert; the dialog and its ancestors stay live', () => {
        const nodes = tree();
        inertOutside(nodes.dialog);

        for (const name of ['header', 'before', 'after', 'other']) {
            assert.equal(nodes[name].hasAttribute('inert'), true, `${name} is inert`);
        }

        for (const name of ['dialog', 'main', 'app']) {
            assert.equal(nodes[name].hasAttribute('inert'), false, `${name} stays live — the dialog is inside it`);
        }
    });

    test('the live regions and the toast keep speaking while a dialog is open', () => {
        const nodes = tree();
        inertOutside(nodes.dialog);

        assert.equal(nodes.polite.hasAttribute('inert'), false);
        assert.equal(nodes.toast.hasAttribute('inert'), false);
    });

    test('closing undoes exactly what opening did, and leaves what was inert before alone', () => {
        const nodes = tree();
        nodes.other.setAttribute('inert', '');
        const restore = inertOutside(nodes.dialog);
        restore();

        for (const name of ['header', 'before', 'after']) {
            assert.equal(nodes[name].hasAttribute('inert'), false, `${name} is live again`);
        }

        assert.equal(nodes.other.hasAttribute('inert'), true, 'inert before the dialog, inert after it');
    });

    test('a dialog opened inside an inert part of the page releases its ancestor, and gives it back on close', () => {
        const nodes = tree();
        // As ActionDialog does it: on the stack first, then the background.
        const popOuter = pushModal({});
        const restoreOuter = inertOutside(nodes.dialog);
        // A second dialog rendered in the section the first one made inert.
        const nested = nodes.append(nodes.after, element('div'));
        const popNested = pushModal({});
        const restoreNested = inertOutside(nested);

        assert.equal(nodes.after.hasAttribute('inert'), false, 'the nested dialog is reachable');
        assert.equal(nodes.dialog.hasAttribute('inert'), true, 'the outer dialog waits behind it');

        restoreNested();
        popNested();
        assert.equal(nodes.after.hasAttribute('inert'), true, 'back to the outer dialog’s state');
        assert.equal(nodes.dialog.hasAttribute('inert'), false);

        restoreOuter();
        popOuter();
        assert.equal(nodes.after.hasAttribute('inert'), false);
    });

    test('nothing stays inert once the last dialog is gone, even if dialogs close out of order', () => {
        const nodes = tree();
        const popOuter = pushModal({});
        const restoreOuter = inertOutside(nodes.dialog);
        const nested = nodes.append(nodes.after, element('div'));
        const popNested = pushModal({});
        const restoreNested = inertOutside(nested);

        // Outer first, then inner — the wrong order.
        restoreOuter();
        popOuter();
        restoreNested();
        popNested();

        for (const name of ['header', 'before', 'after', 'other', 'dialog']) {
            assert.equal(nodes[name].hasAttribute('inert'), false, `${name} is live`);
        }
    });

    test('an undo that runs twice does nothing the second time', () => {
        const nodes = tree();
        const pop = pushModal({});
        const restore = inertOutside(nodes.dialog);
        restore();
        nodes.header.setAttribute('inert', '');
        restore();

        assert.equal(nodes.header.hasAttribute('inert'), true, 'a mark set by someone else is left alone');
        nodes.header.removeAttribute('inert');
        pop();
    });

    test('ActionDialog cannot leave the page inert when opening fails half way', () => {
        const dialog = read('Components', 'App', 'ActionDialog.jsx');

        assert.match(dialog, /try \{\n\s+focusInitialElement\(\);\n\s+\} catch \(error\) \{\n\s+restoreBackground\(\);\n\s+popModal\(\);\n\s+throw error;/);
    });

    test('a detached element is a no-op', () => {
        assert.doesNotThrow(() => inertOutside(null)());
    });

    test('only the dialog opened last answers the keyboard', () => {
        const outer = {};
        const inner = {};
        const popOuter = pushModal(outer);
        const popInner = pushModal(inner);

        assert.equal(isTopModal(inner), true);
        assert.equal(isTopModal(outer), false, 'one Escape must not close both');

        popInner();
        assert.equal(isTopModal(outer), true);
        popOuter();
        assert.equal(isTopModal(outer), false);
    });

    test('ActionDialog makes the background inert, and live again before focus goes back', () => {
        const dialog = read('Components', 'App', 'ActionDialog.jsx');

        assert.match(dialog, /inertOutside\(backdropRef\.current\)/);
        assert.ok(
            dialog.indexOf('restoreBackground();') < dialog.indexOf('returnFocusTarget.focus()'),
            'an inert trigger cannot take focus',
        );
        assert.match(dialog, /if \(!isTopModal\(token\)\) \{\n\s+return;/);
    });
});

describe('a rejected submit puts focus on what has to be fixed (A11Y-013, A11Y-133)', () => {
    test('an error bag with a message has errors; empty values do not count', () => {
        assert.equal(hasErrors({ name: 'Navn må fylles ut.' }), true);
        assert.equal(hasErrors({}), false);
        assert.equal(hasErrors({ name: '' }), false);
        assert.equal(hasErrors(null), false);
    });

    test('the first invalid field in the form gets focus', () => {
        const focused = [];
        const field = {
            matches: () => true,
            hasAttribute: () => true,
            focus: () => focused.push('name'),
        };
        const container = { querySelector: (selector) => (selector === '[aria-invalid="true"]' ? field : null) };

        assert.equal(focusFirstInvalidField(container), true);
        assert.deepEqual(focused, ['name']);
    });

    test('focus moves only when asked — never on an ordinary re-render with errors', () => {
        const source = read('Support', 'focusManagement.js');
        const hook = source.slice(source.indexOf('export function useFocusFirstError'));

        assert.match(hook, /useState\(\(\) => \(hasErrors\(errorsOnMount\) \? 1 : 0\)\)/);
        assert.match(hook, /if \(request === 0\) \{\n\s+return;/);
        assert.match(hook, /\[data-form-error-summary\]/, 'a form-level error takes focus when no field does');
    });

    test('the migrated forms ask for it after a rejected submit', () => {
        const forms = {
            'Pages/App/WatchProfiles/Create.jsx': /onError: focusFirstError/,
            'Pages/App/WatchProfiles/Edit.jsx': /onError: focusFirstError/,
            'Pages/App/Departments/Create.jsx': /onError: focusFirstError/,
            'Pages/App/Departments/Edit.jsx': /onError: focusFirstError/,
            'Pages/App/GoNoGo/Templates/Index.jsx': /onError: focusFirstError/,
            'Pages/App/GoNoGo/Templates/Edit.jsx': /onError: focusFirstError/,
            'Pages/App/CustomerEnvironment/Index.jsx': /onError: focusFirstDepartmentError/,
            'Pages/App/CustomerEnvironment/CustomerRolesPanel.jsx': /onError: focusFirstRoleError/,
            'Pages/App/CustomerEnvironment/BusinessAreasPanel.jsx': /onError: focusFirstError/,
            // These post natively: the page that comes back carries the errors.
            'Pages/App/Users/Create.jsx': /useFocusFirstError\(formRef, \{ errorsOnMount: errors \}\)/,
            'Pages/App/Users/Edit.jsx': /useFocusFirstError\(formRef, \{ errorsOnMount: errors \}\)/,
        };

        for (const [file, pattern] of Object.entries(forms)) {
            assert.match(read(...file.split('/')), pattern, file);
        }
    });
});

/** Every <label>…</label> element in a source file — comments, which talk about labels, left out. */
function labelBlocks(source) {
    const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

    return code.match(/<label\b[\s\S]*?<\/label>/g) ?? [];
}

describe('no explanation button inside a label (A11Y-001)', () => {
    for (const file of [
        'Pages/App/Users/Create.jsx',
        'Pages/App/Users/Edit.jsx',
        'Pages/App/WatchProfiles/WatchProfileForm.jsx',
    ]) {
        test(file, () => {
            const source = read(...file.split('/'));

            for (const block of labelBlocks(source)) {
                assert.ok(! block.includes('<InfoHint'), `InfoHint inside a label:\n${block.slice(0, 200)}`);
            }
        });
    }

    test('the hint panel is phrasing content, so it is valid inside a label, heading or paragraph', () => {
        const tooltip = read('Components', 'App', 'hintTooltip.jsx');
        const panel = tooltip.slice(tooltip.indexOf('export function HintTooltipPanel'));

        assert.match(panel, /<span\n\s+ref=\{panelRef\}/);
        assert.ok(! panel.includes('<div'), 'no <div> in the panel');
        assert.match(panel, /before:-top-2\.5/, 'a pointer bridge over the gap to the trigger (A11Y-025)');
    });

    test('a tap that opened the hint does not close it again in the same gesture', () => {
        const tooltip = read('Components', 'App', 'hintTooltip.jsx');

        assert.match(tooltip, /export const OPENED_BY_SAME_PRESS_MS = 400/);
        assert.match(tooltip, /current && Date\.now\(\) - openedAtRef\.current < OPENED_BY_SAME_PRESS_MS \? true : ! current/);
    });

    test('the «i» relies on the app-wide focus outline, not a violet-300 ring beside it', () => {
        assert.ok(! read('Components', 'App', 'InfoHint.jsx').includes('focus-visible:ring-violet-300'));
    });
});

describe('Fase 3 pages use the one dialog (A11Y-002)', () => {
    for (const file of [
        'Pages/App/GoNoGo/Templates/Index.jsx',
        'Pages/App/GoNoGo/Templates/Edit.jsx',
        'Pages/App/CustomerEnvironment/Index.jsx',
        'Pages/App/Billing/Index.jsx',
        'Components/App/AiCapacityLevelDialog.jsx',
    ]) {
        test(file, () => {
            const source = read(...file.split('/'));

            assert.ok(! source.includes('fixed inset-0'), 'no hand-made overlay');
            assert.match(source, /<ActionDialog\b/);
            assert.match(source, /titleId=/, 'the dialog is named by its heading');
        });
    }

    test('a close button says what it does, not «×»', () => {
        const dialog = read('Components', 'App', 'ActionDialog.jsx');
        const bar = dialog.slice(dialog.indexOf('export function DialogTitleBar'));

        assert.match(bar, /aria-label=\{closeLabel\}/);
        assert.match(bar, /<span aria-hidden="true">×<\/span>/);
    });
});

describe('groups, tabs and the access matrix', () => {
    test('a checkbox group is a fieldset named by its question (A11Y-120)', () => {
        const group = read('Components', 'App', 'DepartmentCheckboxGroup.jsx');
        const field = read('Components', 'App', 'FormField.jsx');

        assert.match(group, /<FieldsetField/);
        assert.match(field, /<fieldset id=\{groupId\} aria-labelledby=\{legendId\}/);
        assert.match(field, /const optionProps = error \? \{ 'aria-invalid': true \} : \{\}/);
    });

    test('the role dialog groups its permissions per module', () => {
        const panel = read('Pages', 'App', 'CustomerEnvironment', 'CustomerRolesPanel.jsx');

        assert.match(panel, /<fieldset key=\{domain\.key\}/);
        assert.match(panel, /<legend className="px-1 text-base font-semibold text-slate-800">\{domain\.label\}<\/legend>/);
    });

    test('the current view of Kundemiljø is marked, not only coloured (A11Y-027)', () => {
        const header = read('Pages', 'App', 'CustomerEnvironment', 'CustomerEnvironmentHeader.jsx');

        assert.match(header, /<nav aria-label=\{label\}/);
        assert.match(header, /aria-current=\{isActive \? 'page' : undefined\}/);
    });

    test('a matrix checkbox stays focusable while it saves, and the save is spoken (A11Y-029)', () => {
        const index = read('Pages', 'App', 'CustomerEnvironment', 'Index.jsx');
        const panel = read('Pages', 'App', 'CustomerEnvironment', 'CustomerRolesPanel.jsx');

        for (const source of [index, panel]) {
            assert.match(source, /aria-disabled=\{/);
            assert.match(source, /announce\(a11y\.saved \?\? 'Lagret'\)/);
        }

        assert.ok(! index.includes('disabled={locked || permissionSaving === row.key}'));
        assert.ok(! panel.includes('disabled={savingRoleId === role.id}\n                                                                    onChange={() => togglePermission'));
    });
});

describe('tables (A11Y-043, -108, -109, -110)', () => {
    test('a scroll box keeps room for the focus ring and is a named, focusable region only while it scrolls', () => {
        const region = read('Components', 'App', 'ScrollRegion.jsx');
        const css = read('..', 'css', 'app.css');

        assert.match(region, /edgeRoom \? '-mx-1 px-1' : ''/);
        assert.match(region, /\.\.\.\(scrollable \? \{ role: 'region', 'aria-label': label, tabIndex: 0 \} : \{\}\)/);
        assert.match(css, /\[data-scroll-region\]:focus-visible \{\n\s+outline-offset: -2px;/);
    });

    test('the Quality tables where the clipped ring was measured use it', () => {
        const quality = read('Pages', 'App', 'Quality', 'Index.jsx');

        assert.equal((quality.match(/<ScrollRegion label=/g) ?? []).length, 3);
        assert.ok(! quality.includes('relative mt-4 overflow-x-auto'));
    });

    test('a sortable column says how it is sorted', () => {
        const suppliers = read('Pages', 'App', 'Suppliers', 'Index.jsx');

        assert.match(suppliers, /aria-sort=\{ariaSort\(field\)\}/);
        assert.match(suppliers, /sortDirection === 'asc' \? 'ascending' : 'descending'/);
        assert.match(suppliers, /<span aria-hidden="true">\{sortIndicator\(field\)\}<\/span>/);
        assert.ok(! suppliers.includes('const SortHeader = ('), 'a component made per render would drop focus');
    });

    test('row actions name their row, and an action column has a header', () => {
        assert.match(read('Pages', 'App', 'Billing', 'Index.jsx'), /aria-label=\{\(a11y\.invoice_pdf/);
        assert.match(read('Pages', 'App', 'Suppliers', 'Index.jsx'), /aria-label=\{`\$\{ts\.view_button \?\? 'Vis'\} \$\{supplier\.supplier_name\}`\}/);
        assert.match(read('Pages', 'App', 'CustomerEnvironment', 'BusinessAreasPanel.jsx'), /<span className="sr-only">\{t\.col_actions \?\? 'Handlinger'\}<\/span>/);
    });
});

describe('a refused row action is answered beside the row (A11Y-137)', () => {
    test('the message belongs to the row that asked, and only while the page still shows it', () => {
        const record = { key: 'user-toggle-7', message: 'Kunden må ha minst én aktiv systemeier.' };

        assert.equal(rowErrorFor(record, 'user-toggle-7', record.message), record.message);
        assert.equal(rowErrorFor(record, 'user-toggle-8', record.message), null, 'another row says nothing');
        assert.equal(rowErrorFor(record, 'user-toggle-7', null), null, 'the next visit cleared the flash');
        assert.equal(rowErrorFor(record, 'user-toggle-7', 'En annen feil.'), null);
        assert.equal(rowErrorFor(null, 'user-toggle-7', record.message), null);
    });

    test('the visit options keep the caller’s own and record the refusal on success', () => {
        resetRowActionError();
        const calls = [];
        const options = trackRowAction('area-delete-3', { preserveScroll: true, onSuccess: () => calls.push('own') });

        assert.equal(options.preserveScroll, true);
        options.onSuccess({ props: { flash: { error: 'Fagområdet er i bruk.' } } });
        assert.deepEqual(calls, ['own']);
        resetRowActionError();
    });

    test('the local message is described by the button and is not a second live region', () => {
        for (const file of [
            'Pages/App/CustomerEnvironment/Index.jsx',
            'Pages/App/CustomerEnvironment/BusinessAreasPanel.jsx',
            'Pages/App/Users/Index.jsx',
        ]) {
            const source = read(...file.split('/'));

            assert.match(source, /trackRowAction\(`/, file);
            assert.match(source, /aria-describedby=\{rowError\(`/, file);
            assert.ok(! /<p id=\{rowErrorId[^>]*role="alert"/.test(source), `${file}: the banner is the one alert`);
        }
    });
});

describe('a flash message is shown once (A11Y-138)', () => {
    for (const file of ['Pages/App/GoNoGo/Templates/Index.jsx', 'Pages/App/GoNoGo/Templates/Edit.jsx', 'Pages/App/Billing/Index.jsx']) {
        test(`${file} leaves flash to the layout`, () => {
            assert.ok(! /flash\??\.(success|error)/.test(read(...file.split('/'))));
        });
    }

    test('the layout renders it, the toast in the live region and errors as an alert', () => {
        const flash = read('Components', 'App', 'FlashMessages.jsx');

        assert.match(flash, /role="alert"/);
        assert.match(flash, /liveRegion\('toast'\)/);
    });
});

describe('field borders are visible (A11Y-019)', () => {
    test('one field tone of at least 3:1, used by the shared styles and the Fase 3 forms', () => {
        const css = read('..', 'css', 'app.css');

        assert.match(css, /--color-field-border: oklch\(63% 0\.042 257\);/);
        assert.match(read('Support', 'formStyles.js'), /border border-field-border/);

        for (const file of ['Pages/App/Users/Create.jsx', 'Pages/App/WatchProfiles/WatchProfileForm.jsx', 'Pages/App/CustomerEnvironment/Index.jsx', 'Pages/App/GoNoGo/Templates/Edit.jsx', 'Components/App/SearchableMultiSelect.jsx']) {
            const source = read(...file.split('/'));
            assert.match(source, /border-field-border/, file);
            assert.ok(! /border-slate-200[^"'`]*focus:border-violet/.test(source), `${file}: no slate-200 field left`);
        }
    });

    test('an invalid field turns rose-600; the focus outline is not touched', () => {
        const css = read('..', 'css', 'app.css');
        const rule = css.slice(css.indexOf("select, textarea)[aria-invalid='true']"));

        assert.match(rule, /^select, textarea\)\[aria-invalid='true'\] \{\n\s+border-color: var\(--color-rose-600/);
        assert.ok(! rule.slice(0, rule.indexOf('}')).includes('outline'));
    });
});
