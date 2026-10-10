/**
 * Which permission sections are open in Kundemiljø → Tilganger.
 *
 * The page lists one collapsible section per module, and the open set is plain page state: it
 * starts empty so an administrator first sees the modules, not every matrix at once. It is never
 * persisted and never sent anywhere — opening a section is reading, not editing.
 *
 * The set is kept as a sorted array of keys so React can compare it cheaply and so a section key
 * that no longer exists (a module removed between visits) simply has no effect.
 */

/** The fixed bid-role matrix's key, alongside the catalog's domain keys. */
export const BASE_SECTION_KEY = 'base';

/**
 * @param {Array<{key: string}>} domains
 * @param {boolean} includeBase
 * @returns {string[]}
 */
export function sectionKeys(domains, includeBase = true) {
    return [...(includeBase ? [BASE_SECTION_KEY] : []), ...(domains ?? []).map((domain) => domain.key)];
}

/**
 * @param {string[]} open
 * @param {string} key
 * @returns {string[]}
 */
export function toggleSection(open, key) {
    return open.includes(key) ? open.filter((entry) => entry !== key) : [...open, key].sort();
}

/**
 * @param {string[]} open
 * @param {string[]} keys
 */
export function allOpen(open, keys) {
    return keys.length > 0 && keys.every((key) => open.includes(key));
}

/**
 * «3 rettigheter», «1 rolle» — the counts shown on a closed section's header.
 *
 * @param {number} count
 * @param {{one?: string, other?: string}} forms
 */
export function countLabel(count, forms) {
    const template = count === 1 ? forms.one : forms.other;

    return (template ?? ':count').replace(':count', String(count));
}
