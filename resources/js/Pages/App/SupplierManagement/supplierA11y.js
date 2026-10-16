/**
 * Accessibility rules for Leverandøroppfølging (WCAG Fase 6, docs/wcag-accessibility-plan.md),
 * kept pure so they are unit-tested (resources/js/Support/a11yPhase6Suppliers.test.js). The React
 * side — where focus goes when a panel opens or closes — is in supplierFocus.js.
 */

import { countLabel } from './supplierManagement.js';

/** The import's steps in order; «result» comes after the last one shown. */
export const IMPORT_STEP_ORDER = { upload: 0, review: 1, confirm: 2, result: 3 };

/**
 * Where step `index` stands while the import is at `step` (A11Y-024): «done», «current» or «todo».
 * The ✓ and the colour show it; this is what the hidden text says.
 */
export function importStepState(step, index) {
    const at = IMPORT_STEP_ORDER[step] ?? 0;

    if (index < at) {
        return 'done';
    }

    return index === at ? 'current' : 'todo';
}

/** The hidden words for a step's state: «fullført», «nåværende steg», «gjenstår». */
export function importStepStateText(state, s = {}, ta = {}) {
    if (state === 'done') {
        return ta.suppliers_step_done ?? 'fullført';
    }

    if (state === 'current') {
        return s.current ?? 'nåværende steg';
    }

    return ta.suppliers_step_todo ?? 'gjenstår';
}

/**
 * The element an in-page link should put focus on (A11Y-015). A section under «Detaljer» is anchored
 * on its heading, which holds the section's open/close button: focus goes to that button, so the
 * user lands on the control that names the section rather than on a heading made focusable. Any
 * other target (a requirement row, a section heading) is focused itself.
 */
export function inPageFocusTarget(element) {
    if (! element) {
        return null;
    }

    const isHeading = /^H[1-6]$/.test(String(element.tagName ?? '').toUpperCase());
    const control = isHeading ? element.querySelector?.('button, a[href]') : null;

    return control ?? element;
}

/** What is said once after a search or filter in the register: «12 leverandører». */
export function registerResultAnnouncement(count, tr = {}) {
    return countLabel(count, tr);
}

/** What is said once after the import's row filter changes: «3 rader vises». */
export function importFilterAnnouncement(count, ta = {}) {
    if (count === 0) {
        return ta.suppliers_import_rows_none ?? 'Ingen rader vises';
    }

    return count === 1
        ? (ta.suppliers_import_rows_one ?? '1 rad vises')
        : (ta.suppliers_import_rows_many ?? ':count rader vises').replace(':count', String(count));
}

/** What is said once when a Wiki search finishes (WikiGuidance): «4 artikler funnet». */
export function wikiSearchAnnouncement(status, count, hasMore = false, ta = {}) {
    // Loading says nothing; a failed search is shown as text the user reads where they are.
    if (status !== 'ready') {
        return null;
    }

    if (count === 0) {
        return ta.suppliers_wiki_results_none ?? 'Ingen artikler passer søket';
    }

    const text = count === 1
        ? (ta.suppliers_wiki_results_one ?? '1 artikkel funnet')
        : (ta.suppliers_wiki_results_many ?? ':count artikler funnet').replace(':count', String(count));

    return hasMore ? `${text}. ${ta.suppliers_wiki_results_more ?? 'Skriv mer for å snevre inn søket.'}` : text;
}

/** The id of the button that opened a panel, from the click that opened it. */
export const PANEL_TRIGGER_ATTRIBUTE = 'data-panel-trigger';

export function panelTriggerId(target) {
    return target?.closest?.(`[${PANEL_TRIGGER_ATTRIBUTE}]`)?.id || null;
}

/**
 * Where focus goes when an inline panel closes (A11Y-014): the button that opened it, if it is back;
 * otherwise the fallbacks, in order. Duplicates and empty values are dropped.
 *
 * @returns {string[]} Element ids, for refocusIfLost.
 */
export function panelCloseCandidates(triggerId, fallbackIds = []) {
    return [...new Set([triggerId, ...fallbackIds].filter(Boolean))];
}

/**
 * Where focus goes after a row in a list was removed (deleted document, unlinked risk): the next
 * row's own control, else the previous row's, else the list's heading (A11Y-014).
 *
 * @param {Array<number|string>} ids  The rows, in page order, as they were when the action started.
 * @param {number|string} removedId
 * @param {(id: string) => string} controlId Maps a row id to the element id to focus.
 * @param {string[]} fallbackIds
 */
export function removalFocusCandidates(ids, removedId, controlId, fallbackIds = []) {
    const list = ids.map(String);
    const index = list.indexOf(String(removedId));
    const next = index === -1 ? [] : list.slice(index + 1);
    const previous = index === -1 ? [] : list.slice(0, index).reverse();

    return [...next, ...previous].map(controlId).concat(fallbackIds);
}
