import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    CPV_CHIP_COLLAPSE_THRESHOLD,
    cpvSelectionSummary,
    filterPanelSummary,
    shouldOfferCpvToggle,
    shouldShowCpvChips,
} from './filterPanelLogic.js';

const here = dirname(fileURLToPath(import.meta.url));
const indexSource = readFileSync(join(here, 'Index.jsx'), 'utf8');
const selectorSource = readFileSync(join(here, 'CpvSelector.jsx'), 'utf8');

/**
 * Returns the `<button …>…</button>` that carries `attribute`. Arrow functions in the JSX contain
 * ">", so the element cannot be matched with a naive regex — slice from the opening tag instead.
 */
function buttonAround(source, attribute) {
    const at = source.indexOf(attribute);

    if (at === -1) {
        return null;
    }

    const open = source.lastIndexOf('<button', at);
    const close = source.indexOf('</button>', at);

    return open === -1 || close === -1 ? null : source.slice(open, close + '</button>'.length);
}


/**
 * A watch list can carry dozens of CPV codes, which used to make the live-search filter panel the
 * tallest thing on the page. Two independent collapse levels fix that: the CPV chips fold on their
 * own, and the whole filter panel folds separately.
 *
 * The load-bearing property is that BOTH are presentation only. Neither may drop a selected code
 * or reset a filter field — a collapsed panel must search exactly like an open one.
 */
describe('CPV chip collapse', () => {
    test('a short selection is shown without asking', () => {
        assert.equal(shouldShowCpvChips(0, false), true);
        assert.equal(shouldShowCpvChips(1, false), true);
        assert.equal(shouldShowCpvChips(CPV_CHIP_COLLAPSE_THRESHOLD, false), true);
    });

    test('a long selection starts collapsed', () => {
        assert.equal(shouldShowCpvChips(CPV_CHIP_COLLAPSE_THRESHOLD + 1, false), false);
        assert.equal(shouldShowCpvChips(28, false), false);
    });

    test('expanding a long selection shows every chip', () => {
        assert.equal(shouldShowCpvChips(28, true), true);
    });

    test('the toggle is only offered when it would do something', () => {
        assert.equal(shouldOfferCpvToggle(0), false);
        assert.equal(shouldOfferCpvToggle(CPV_CHIP_COLLAPSE_THRESHOLD), false);
        assert.equal(shouldOfferCpvToggle(CPV_CHIP_COLLAPSE_THRESHOLD + 1), true);
        assert.equal(shouldOfferCpvToggle(28), true);
    });

    test('a watch list prefill that swaps one long selection for another stays collapsed', () => {
        // The user has not expanded anything; switching watch list must not suddenly unfold 40 chips.
        assert.equal(shouldShowCpvChips(28, false), false);
        assert.equal(shouldShowCpvChips(40, false), false);
    });
});

describe('the collapsed CPV summary keeps the filter scope visible', () => {
    test('it counts the selected codes', () => {
        assert.equal(cpvSelectionSummary(28), '28 valgte koder');
        assert.equal(cpvSelectionSummary(1), '1 valgt kode');
    });

    test('it uses the supplied translations', () => {
        assert.equal(
            cpvSelectionSummary(28, { many: ':count selected codes' }),
            '28 selected codes',
        );
        assert.equal(
            cpvSelectionSummary(1, { one: ':count selected code' }),
            '1 selected code',
        );
    });
});

describe('the collapsed filter panel still names the active watch list', () => {
    test('it shows the watch list label', () => {
        assert.equal(filterPanelSummary({ value: '4', label: 'IT drift - samlet' }), 'IT drift - samlet');
    });

    test('it says so plainly when no watch list is active', () => {
        assert.equal(filterPanelSummary(null), 'Ingen bevakningsliste valgt');
        assert.equal(filterPanelSummary(null, { none: 'No watch list selected' }), 'No watch list selected');
    });
});

/**
 * Source-level guards — the project has no JSX test renderer, so these protect the properties a
 * render test would otherwise cover.
 */
describe('collapsing is presentation only', () => {
    test('no collapse helper resets a filter value', () => {
        const logic = readFileSync(join(here, 'filterPanelLogic.js'), 'utf8');

        for (const setter of ['setSelectedCpvItems', 'setKeywords', 'setOrganizationName', 'setSelectedWatchListId']) {
            assert.ok(
                !logic.includes(setter),
                `filterPanelLogic must not touch ${setter} — collapsing is presentation only`,
            );
        }
    });

    test('the panel toggle only flips its own visibility state', () => {
        const toggle = buttonAround(indexSource, 'aria-expanded={isFilterPanelOpen}');

        assert.ok(toggle, 'the filter panel toggle must exist');
        assert.ok(
            toggle.includes('onClick={() => setIsFilterPanelOpen((current) => !current)}'),
            'the toggle must only flip its own visibility state',
        );
    });

    test('the panel body is hidden, not unmounted, so field state survives a collapse', () => {
        assert.ok(
            indexSource.includes('id="live-filter-panel-body" hidden={!isFilterPanelOpen}'),
            'the filter body must stay mounted and be hidden, so React keeps the field state',
        );
    });

    test('the chip toggle only flips chip visibility', () => {
        const toggle = buttonAround(selectorSource, 'aria-expanded={showChips}');

        assert.ok(toggle, 'the CPV chip toggle must exist');
        assert.ok(
            toggle.includes('onClick={() => setAreChipsExpanded((current) => !current)}'),
            'the toggle must only flip chip visibility',
        );
        assert.ok(
            !selectorSource.includes('onSelectedItemsChange([])'),
            'collapsing chips must never clear the selection',
        );
    });
});

describe('both collapse controls are accessible', () => {
    test('the filter panel control is a real button with aria-expanded and a label', () => {
        const button = buttonAround(indexSource, 'aria-expanded={isFilterPanelOpen}');

        assert.ok(button, 'the filter panel toggle must expose aria-expanded');
        assert.match(button, /type="button"/);
        assert.match(button, /aria-controls="live-filter-panel-body"/);
        assert.match(button, /filterPanelCollapse|filterPanelExpand/);
        // The +/- glyph is decoration; the accessible name comes from the text label.
        assert.match(button, /aria-hidden="true"/);
    });

    test('the CPV control is a real button with aria-expanded and a label', () => {
        const button = buttonAround(selectorSource, 'aria-expanded={showChips}');

        assert.ok(button, 'the CPV chip toggle must expose aria-expanded');
        assert.match(button, /type="button"/);
        assert.match(button, /aria-controls=\{chipsId\}/);
        assert.match(button, /cpv_hide_codes|cpv_show_codes/);
    });

    test('the two levels are independent state', () => {
        assert.ok(
            indexSource.includes('const [isFilterPanelOpen, setIsFilterPanelOpen]'),
            'the panel collapse lives in Index.jsx',
        );
        assert.ok(
            selectorSource.includes('const [areChipsExpanded, setAreChipsExpanded]'),
            'the chip collapse lives in CpvSelector.jsx',
        );
        assert.ok(
            !selectorSource.includes('isFilterPanelOpen'),
            'the CPV chips must not know or care whether the panel is open',
        );
    });
});
