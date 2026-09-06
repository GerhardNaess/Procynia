import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import {
    PRIMARY_ACTION,
    SECONDARY_ACTION,
    WARNING_ACTION,
    DESTRUCTIVE_ACTION,
    DESTRUCTIVE_CONFIRM,
    DISCLOSURE_ACTION,
    DISCLOSURE_INLINE,
} from './actionStyles.js';

const pages = join(dirname(fileURLToPath(import.meta.url)), '..', 'Pages', 'App');
const read = (relative) => readFileSync(join(pages, relative), 'utf8');

/**
 * The className of the actionable element that renders `label`, for every place it is rendered.
 * Anchoring on the nearest className above the label keeps the assertion on the button itself
 * rather than on any nested icon or wrapper.
 */
function classNamesRendering(source, label) {
    const lines = source.split('\n');
    const found = [];

    lines.forEach((line, index) => {
        if (!line.includes(label)) {
            return;
        }

        for (let i = index; i >= 0 && i > index - 14; i -= 1) {
            // Either a literal className="…" or an expression className={…}, since the re-roled
            // buttons now reference the shared constants rather than spelling the classes out.
            const match = lines[i].match(/className="([^"]+)"|className=\{([^\n]+)/);

            if (match) {
                found.push(match[1] ?? match[2]);
                return;
            }
        }
    });

    assert.notEqual(found.length, 0, `"${label}" is no longer rendered`);

    return found;
}

/**
 * Colour is the only signal separating "this commits my work" from "this takes me elsewhere". These
 * guard the roles that were actually corrected — the project has no JSX renderer, so the assertions
 * read the source, the same idiom the rest of the suite uses.
 */
describe('the roles are visually distinct from each other', () => {
    test('each role owns a different fill', () => {
        const fills = [PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION, DESTRUCTIVE_ACTION, DESTRUCTIVE_CONFIRM]
            .map((style) => style.match(/bg-[a-z]+-\d+|bg-white/)[0]);

        assert.equal(new Set(fills).size, fills.length, 'two roles share a fill and stop meaning different things');
    });

    test('only the committing roles are filled; the rest carry a border', () => {
        for (const style of [PRIMARY_ACTION, DESTRUCTIVE_CONFIRM]) {
            assert.ok(!style.includes('border '), 'a committing action is solid, not outlined');
        }
        for (const style of [SECONDARY_ACTION, WARNING_ACTION, DESTRUCTIVE_ACTION]) {
            assert.match(style, /border border-/);
        }
    });

    test('every role shares one geometry, so only colour varies', () => {
        for (const style of [PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION, DESTRUCTIVE_ACTION, DESTRUCTIVE_CONFIRM]) {
            assert.match(style, /min-h-11/);
            assert.match(style, /rounded-xl px-4 py-2\.5 text-base font-semibold/);
        }
    });
});

describe('navigation no longer wears the primary colour', () => {
    // "Åpne i Doffin" used to outrank the Go/No-Go decision beside it on the same page.
    test('opening a notice in Doffin is secondary on the work list', () => {
        const source = read('Notices/Index.jsx');

        for (const label of ['noticesText.alertsOpenDoffin', 'noticesText.openInDoffinLabel']) {
            for (const className of classNamesRendering(source, label)) {
                assert.ok(!className.includes('bg-violet-50'), `${label} must not use the violet tint`);
                assert.match(className, /bg-white/);
            }
        }
    });
});

describe('saving is primary', () => {
    test('every save on the case page commits in filled violet', () => {
        const source = read('Notices/SavedShow.jsx');

        for (const form of ['phaseCommentForm.processing', 'infoItemForm.processing', 'bidManagerForm.processing', 'opportunityOwnerForm.processing']) {
            for (const className of classNamesRendering(source, `{${form} ?`)) {
                assert.match(className, /bg-violet-600/, `${form} should submit as a primary action`);
                assert.ok(!className.includes('bg-violet-50'), `${form} still uses the tinted style`);
            }
        }
    });
});

describe('rose means data disappears', () => {
    test('retrying a failed page is not styled as destructive', () => {
        const source = read('Wiki/Index.jsx');
        const classNames = classNamesRendering(source, 'runs_pages_retry');

        assert.equal(classNames.length, 2, 'both retry buttons are still present');

        for (const className of classNames) {
            assert.ok(!className.includes('rose'), 'retry recovers work, it does not delete any');
        }
    });
});

describe('a disclosure commits nothing, so it must not look like it does', () => {
    test('it is neutral — never violet, amber or rose', () => {
        for (const style of [DISCLOSURE_ACTION, DISCLOSURE_INLINE]) {
            for (const colour of ['violet', 'amber', 'rose']) {
                assert.ok(!style.includes(colour), `a disclosure must not borrow ${colour}`);
            }
        }
    });

    test('it stays keyboard-visible', () => {
        for (const style of [DISCLOSURE_ACTION, DISCLOSURE_INLINE]) {
            assert.match(style, /hover:border-slate-300/);
            assert.match(style, /focus-visible:outline-slate-500/);
        }
    });

    test('the two variants differ only in geometry', () => {
        const colours = (style) => style.match(/(border|bg|text|hover:[a-z-]+|focus-visible:[a-z-]+)-[a-z]+-?\d*/g).join(' ');

        assert.equal(colours(DISCLOSURE_ACTION), colours(DISCLOSURE_INLINE));
    });

    test('the toggles that only show and hide use it', () => {
        for (const [file, label] of [
            ['Notices/Index.jsx', 'noticesText.filterPanelCollapse'],
            ['Notices/CpvSelector.jsx', 'texts.cpv_hide_codes'],
            ['Notices/SavedShow.jsx', 'tsn.new_action'],
            ['AI/Show.jsx', 'tai.hide_requirement_form'],
        ]) {
            for (const className of classNamesRendering(read(file), label)) {
                assert.match(className, /DISCLOSURE_/, `${file} (${label}) should use the disclosure style`);
            }
        }
    });

    test('aria-expanded survives on the toggles that had it', () => {
        for (const [file, state] of [
            ['Notices/Index.jsx', 'isFilterPanelOpen'],
            ['Notices/CpvSelector.jsx', 'showChips'],
            ['AI/Show.jsx', 'showManualRequirementForm'],
        ]) {
            assert.ok(read(file).includes(`aria-expanded={${state}}`), `${file} lost aria-expanded`);
        }
    });
});

describe('the Go/No-Go decision reads as terminal', () => {
    const savedShow = read('Notices/SavedShow.jsx');

    test('setting No-Go warns, in the dialog as well as on the trigger', () => {
        // The trigger is coloured by actionButtonClassName, which already special-cases no_go.
        assert.match(savedShow, /status === 'no_go'\)? \{\s*\n\s*return 'border-amber-200/);

        for (const className of classNamesRendering(savedShow, '{isStatusActionProcessing ? tsn.updating : tsn.setNoGo}')) {
            assert.match(className, /WARNING_ACTION/, 'the final confirmation must warn too, not read as an ordinary submit');
        }
    });

    test('reopening a case is the primary way back into the decision flow', () => {
        for (const className of classNamesRendering(savedShow, 'tsn.reopen_no_go_action')) {
            assert.match(className, /PRIMARY_ACTION/);
        }
    });

    test('cancelling out of either dialog stays secondary', () => {
        for (const className of classNamesRendering(savedShow, '{common.cancel}')) {
            assert.match(className, /bg-white/);
            assert.ok(!className.includes('violet-600'), 'cancel must never compete with the submit');
        }
    });
});
