/**
 * Collapse rules for the live-search filter panel and its CPV field.
 *
 * The two levels are deliberately independent: collapsing the whole filter panel says nothing
 * about whether the CPV chips inside it are expanded, and vice versa. Both are presentation
 * only — no function here touches a filter value, so a collapsed panel filters exactly like an
 * open one.
 */

/** Above this many selected codes the chip list is collapsed until the user asks for it. */
export const CPV_CHIP_COLLAPSE_THRESHOLD = 5;

/**
 * Whether the CPV chips should render.
 *
 * A short list is always shown — collapsing five chips buys nothing and costs a click. A long
 * list starts collapsed and stays that way until the user expands it, including when a watch
 * list prefill swaps one long selection for another.
 *
 * @param {number}  selectedCount
 * @param {boolean} isExpanded      The user's explicit choice, if they made one.
 * @param {number}  [threshold]
 * @returns {boolean}
 */
export function shouldShowCpvChips(selectedCount, isExpanded, threshold = CPV_CHIP_COLLAPSE_THRESHOLD) {
    if (selectedCount <= threshold) {
        return true;
    }

    return isExpanded === true;
}

/**
 * Whether the "Vis koder" / "Skjul koder" control is worth rendering at all.
 *
 * @param {number} selectedCount
 * @param {number} [threshold]
 * @returns {boolean}
 */
export function shouldOfferCpvToggle(selectedCount, threshold = CPV_CHIP_COLLAPSE_THRESHOLD) {
    return selectedCount > threshold;
}

/**
 * "28 valgte koder" — the scope of the filter has to stay readable while the chips are hidden.
 *
 * @param {number} selectedCount
 * @param {{ one?: string, many?: string }} [labels] Templates using :count.
 * @returns {string}
 */
export function cpvSelectionSummary(selectedCount, labels = {}) {
    const template = selectedCount === 1
        ? (labels.one ?? ':count valgt kode')
        : (labels.many ?? ':count valgte koder');

    return template.replace(':count', String(selectedCount));
}

/**
 * The line shown on the collapsed filter header, so nobody has to open the panel just to see
 * which watch list is driving the search.
 *
 * @param {{label?: string}|null} activeWatchList
 * @param {{active?: string, none?: string}} [labels]
 * @returns {string}
 */
export function filterPanelSummary(activeWatchList, labels = {}) {
    if (activeWatchList?.label) {
        return activeWatchList.label;
    }

    return labels.none ?? 'Ingen bevakningsliste valgt';
}
