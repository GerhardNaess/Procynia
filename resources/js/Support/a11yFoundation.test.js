import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { fieldA11y, domSafeId } from './formFieldA11y.js';
import { CLEAR_AFTER_MS, DUPLICATE_WINDOW_MS, LIVE_REGION_IDS, announce, shouldAnnounce } from './announcer.js';
import { FOCUSABLE_SELECTOR, firstConnected, focusFirstInvalidField } from './focusManagement.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, ...parts), 'utf8');

/**
 * Fase 1 of the WCAG plan (docs/wcag-accessibility-plan.md): the rules the shared accessibility
 * helpers stand for. Behaviour in a real browser — focus visibly moving, a screen-reader region
 * receiving text — is covered by tests/e2e/a11y-foundation.spec.js; these pin the decisions.
 */
describe('a form control is tied to its hint and error (A11Y-013)', () => {
    test('a plain field gets only its id', () => {
        assert.deepEqual(fieldA11y({ id: 'title' }).controlProps, { id: 'title' });
    });

    test('hint and error ids derive from the control id, so they are stable across renders', () => {
        const { hintId, errorId } = fieldA11y({ id: 'title' });
        assert.equal(hintId, 'title-hint');
        assert.equal(errorId, 'title-error');
    });

    test('the hint is read before the error, and an error marks the control invalid', () => {
        const { controlProps } = fieldA11y({ id: 'title', hasHint: true, hasError: true });
        assert.equal(controlProps['aria-describedby'], 'title-hint title-error');
        assert.equal(controlProps['aria-invalid'], true);
    });

    test('no error, no aria-invalid — a field is never announced as wrong before it is', () => {
        const { controlProps } = fieldA11y({ id: 'title', hasHint: true });
        assert.equal(controlProps['aria-invalid'], undefined);
        assert.equal(controlProps['aria-describedby'], 'title-hint');
    });

    test('caller-supplied descriptions come first and empty ones are dropped', () => {
        const { controlProps } = fieldA11y({ id: 'file', hasError: true, describedBy: ['file-name', null, ''] });
        assert.equal(controlProps['aria-describedby'], 'file-name file-error');
    });

    test('required is exposed as aria-required', () => {
        assert.equal(fieldA11y({ id: 'title', required: true }).controlProps['aria-required'], true);
    });

    test('React useId output becomes a selector-safe id', () => {
        assert.equal(domSafeId(':r1a:'), 'r1a');
    });
});

describe('the screen-reader announcer stays quiet unless something new happened (A11Y-012)', () => {
    test('empty or missing messages are never spoken', () => {
        assert.equal(shouldAnnounce(null, undefined, 0), false);
        assert.equal(shouldAnnounce(null, '   ', 0), false);
    });

    test('a new message is spoken', () => {
        assert.equal(shouldAnnounce(null, 'Lagret', 0), true);
        assert.equal(shouldAnnounce({ message: 'Lagret', at: 0 }, 'Slettet', 10), true);
    });

    test('the same message right after itself is dropped — a re-render or a poll must not repeat it', () => {
        assert.equal(shouldAnnounce({ message: 'Lagret', at: 1000 }, 'Lagret', 1000 + DUPLICATE_WINDOW_MS - 1), false);
    });

    test('the same message later is a new event and is spoken again', () => {
        assert.equal(shouldAnnounce({ message: 'Lagret', at: 1000 }, 'Lagret', 1000 + DUPLICATE_WINDOW_MS), true);
    });

    test('the regions are created at boot, outside the per-page React tree', () => {
        assert.match(read('..', 'app.js'), /ensureLiveRegions\(\);\s*\n\s*createRoot\(el\)\.render/);
    });

    test('the success toast is drawn into the persistent live region; a flash error is an alert', () => {
        const layout = read('..', 'Layouts', 'CustomerAppLayout.jsx');
        assert.match(layout, /liveRegion\('toast'\)/);
        assert.match(layout, /flash\?\.success && showSuccess \? portalToLiveRegion\(/);
        assert.match(layout, /<div role="alert" className="mb-6 rounded-2xl border border-rose-200/);
    });

    test('in a rapid sequence only the last message reaches the region, and it is cleared afterwards', () => {
        // A minimal document and a manual clock: announce() touches nothing else.
        const elements = new Map();
        const timers = new Map();
        let nextTimer = 1;
        let clock = 0;
        globalThis.document = {
            body: { appendChild: (element) => elements.set(element.id, element) },
            createElement: () => ({ id: '', className: '', textContent: '', setAttribute() {} }),
            getElementById: (id) => elements.get(id) ?? null,
        };
        globalThis.window = {
            setTimeout: (callback, delay) => { timers.set(nextTimer, { at: clock + delay, callback }); return nextTimer++; },
            clearTimeout: (id) => timers.delete(id),
        };
        const advance = (ms) => {
            clock += ms;
            for (const [id, timer] of [...timers].sort((a, b) => a[1].at - b[1].at)) {
                if (timer.at <= clock && timers.has(id)) {
                    timers.delete(id);
                    timer.callback();
                }
            }
        };

        try {
            const region = () => elements.get(LIVE_REGION_IDS.polite);
            const written = [];

            announce('Lagrer endringene');
            advance(10);
            announce('Endringene er lagret');
            for (let step = 0; step < 10; step += 1) {
                advance(10);
                written.push(region().textContent);
            }

            assert.deepEqual([...new Set(written.filter(Boolean))], ['Endringene er lagret']);
            advance(CLEAR_AFTER_MS);
            assert.equal(region().textContent, '');
        } finally {
            delete globalThis.document;
            delete globalThis.window;
        }
    });

    test('the permanent regions use aria-live without a role, so they never join the page\'s role tree', () => {
        const announcer = read('announcer.js');
        assert.match(announcer, /region\.setAttribute\('aria-live', live\);/);
        assert.doesNotMatch(announcer, /setAttribute\('role'/);
    });
});

describe('focus goes somewhere deliberate (A11Y-014)', () => {
    const element = (connected) => ({ focus() {}, connected });
    const isConnected = (candidate) => candidate.connected;

    test('the first candidate still in the document wins', () => {
        const gone = element(false);
        const fallback = element(true);
        assert.equal(firstConnected([gone, fallback], isConnected), fallback);
    });

    test('missing refs are skipped, and nothing found means nothing is focused', () => {
        assert.equal(firstConnected([null, undefined], isConnected), null);
        assert.equal(firstConnected([element(false)], isConnected), null);
    });

    test('the first invalid field is focused after a rejected submit', () => {
        let focused = false;
        const field = { matches: () => true, hasAttribute: () => true, focus: () => { focused = true; } };
        const form = { querySelector: (selector) => (selector === '[aria-invalid="true"]' ? field : null) };

        assert.equal(focusFirstInvalidField(form), true);
        assert.equal(focused, true);
    });

    test('a form without errors keeps focus where it is', () => {
        assert.equal(focusFirstInvalidField({ querySelector: () => null }), false);
    });

    test('disabled controls and tabindex=-1 are not Tab stops', () => {
        assert.match(FOCUSABLE_SELECTOR, /button:not\(\[disabled\]\)/);
        assert.match(FOCUSABLE_SELECTOR, /\[tabindex\]:not\(\[tabindex="-1"\]\)/);
    });

    test('ActionDialog uses the shared focus helpers and returns focus to a fallback when the trigger is gone', () => {
        const dialog = read('..', 'Components', 'App', 'ActionDialog.jsx');
        assert.match(dialog, /from '..\/..\/Support\/focusManagement'/);
        assert.match(dialog, /firstConnected\(\[\s*returnFocusRef\?\.current,\s*previousFocusRef\.current,\s*fallbackFocusRef\?\.current,\s*\]\)/);
    });

    test('ActionDialog can be described as well as named, so the consequence is heard when focus lands on a button', () => {
        const dialog = read('..', 'Components', 'App', 'ActionDialog.jsx');
        assert.match(dialog, /aria-labelledby=\{titleId\}\s*\n\s*aria-describedby=\{descriptionId\}/);
    });

    test('ActionDialog pulls back focus that the browser moved out, and lets it go before returning focus', () => {
        const dialog = read('..', 'Components', 'App', 'ActionDialog.jsx');
        assert.match(dialog, /document\.addEventListener\('focusin', handleFocusIn\)/);
        assert.ok(
            dialog.indexOf("removeEventListener('focusin', handleFocusIn)") < dialog.indexOf('const returnFocusTarget'),
            'the focusin guard must be removed before focus returns to the trigger outside the dialog',
        );
    });

    test('ActionDialog sits above the sticky header (z-[60])', () => {
        assert.match(read('..', 'Components', 'App', 'ActionDialog.jsx'), /fixed inset-0 z-\[70\]/);
    });
});

describe('keyboard focus is visible everywhere (A11Y-010)', () => {
    const css = read('..', '..', 'css', 'app.css');

    test('the base indicator is a 2 px violet-600 outline', () => {
        assert.match(css, /@layer base \{\s*:focus-visible \{\s*outline: 2px solid var\(--color-violet-600, #7f22fe\);\s*outline-offset: 2px;/);
    });

    test('controls that suppress the outline get it back on keyboard focus, outside any layer', () => {
        const unlayered = css.slice(css.indexOf('Deliberately outside @layer'));
        assert.match(unlayered, /:is\(\.outline-none, \[class~='focus:outline-none'\], \[class~='focus-visible:outline-none'\]\):not\(\[data-focus-indicator='own'\]\):focus-visible \{\s*outline: 2px solid/);
        assert.ok(css.indexOf('Deliberately outside @layer') < css.indexOf('@layer components'), 'the restore rule must not be inside a layer');
    });

    test('text fields draw it inside their edge so a clipping wrapper cannot hide it', () => {
        assert.match(css, /\[type='color'\]\), textarea, select\):is\(\.outline-none[^{]*\{\s*outline-offset: -2px;/);
    });
});

describe('the file picker carries name, focus and error over from its hidden input (A11Y-011)', () => {
    const picker = read('..', 'Components', 'App', 'FilePickerField.jsx');

    test('the visible trigger shows the hidden input\'s keyboard focus', () => {
        assert.match(picker, /peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-violet-600/);
    });

    test('the input is named by caption and button, described by file, hint and error', () => {
        assert.match(picker, /aria-labelledby=\{label \? `\$\{captionId\} \$\{triggerId\}` : undefined\}/);
        assert.match(picker, /describedBy: \[fileNameId\]/);
        assert.match(picker, /role="alert"/);
    });
});

describe('FormField keeps explanation buttons out of the label (A11Y-001)', () => {
    const field = read('..', 'Components', 'App', 'FormField.jsx');

    test('the label element contains only the label text and the required star', () => {
        const label = field.slice(field.indexOf('<label'), field.indexOf('</label>'));
        assert.doesNotMatch(label, /\{info\}/);
        assert.match(field, /<\/label>\s*\{info\}/);
    });

    test('the control receives the wiring through a render function', () => {
        assert.match(field, /children\(controlProps\)/);
    });
});

describe('the help panel moves focus in and back (A11Y-104)', () => {
    const panel = read('..', 'Components', 'App', 'PageHelpPanel.jsx');
    const button = read('..', 'Components', 'App', 'PageHelpButton.jsx');

    test('opening focuses the panel; closing returns to the Hjelp button', () => {
        assert.match(panel, /focusElement\(panelRef\.current, \{ preventScroll: true \}\)/);
        assert.match(panel, /focusElement\(firstConnected\(\[returnFocusRef\?\.current\]\)\)/);
        assert.match(button, /returnFocusRef=\{buttonRef\}/);
    });

    test('the panel\'s own words follow the user\'s language', () => {
        assert.match(panel, /a11y\.help_close \?\? 'Lukk hjelpepanel'/);
        assert.match(panel, /a11y\.help_eyebrow \?\? 'Hjelp'/);
    });
});
