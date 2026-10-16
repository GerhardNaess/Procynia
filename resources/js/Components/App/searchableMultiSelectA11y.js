/**
 * The pure rules behind SearchableMultiSelect's announcements and focus moves (WCAG 4.1.3, 2.4.3;
 * docs/wcag-accessibility-plan.md A11Y-111, Fase 6). Kept out of the component so they are unit-tested
 * (resources/js/Support/a11yPhase6Remaining.test.js).
 */

/**
 * What a screen reader hears once the user has stopped typing in the search field: the number of
 * options left, or that none match. Null for an empty search — the full list is what opened, and
 * nothing was filtered.
 *
 * @param {string} query
 * @param {number} count
 * @param {{ results_count?: string, results_none?: string }} a11y translations.a11y
 */
export function pickerResultAnnouncement(query, count, a11y = {}) {
    if (String(query ?? '').trim() === '') {
        return null;
    }

    return count === 0
        ? (a11y.results_none ?? 'Ingen treff')
        : (a11y.results_count ?? ':count treff').replace(':count', String(count));
}

/**
 * Where focus goes after a chosen item's remove button took itself away: the chip that now sits in
 * its place (the next one), else the one before it, else null — the caller then focuses the search
 * field. Never <body>.
 *
 * @param {Array<string|number>} values The chosen values before the removal, in chip order.
 * @param {string|number} removed
 * @returns {string|number|null} The value whose chip should take focus.
 */
export function chipFocusAfterRemoval(values, removed) {
    const index = values.indexOf(removed);

    if (index === -1) {
        return null;
    }

    const rest = values.filter((value) => value !== removed);

    if (rest.length === 0) {
        return null;
    }

    return rest[Math.min(index, rest.length - 1)];
}
