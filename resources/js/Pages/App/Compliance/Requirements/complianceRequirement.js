export const REQUIREMENT_STATUS_TONES = {
    active: 'emerald',
    retired: 'slate',
};

const REVIEW_FALLBACKS = {
    none: 'Ingen fast intervall',
    1: 'Månedlig',
    3: 'Kvartalsvis',
    6: 'Halvårlig',
    12: 'Årlig',
};

/**
 * The review interval as the page names it: «Kvartalsvis», or «Ingen fast intervall» for none.
 *
 * @param {number|null} months  1, 3, 6, 12 or null
 * @param {object} tr  translations.compliance
 */
export function reviewIntervalLabel(months, tr = {}) {
    const key = months === null || months === undefined || months === '' ? 'none' : String(months);

    return tr.review_intervals?.[key] ?? REVIEW_FALLBACKS[key] ?? key;
}

const KIND_FALLBACKS = {
    standard: 'Standard',
    law: 'Lov/forskrift',
    contract: 'Kontrakt',
    internal: 'Internt krav',
    other: 'Annet',
};

/** A source's type as the page names it: «Lov/forskrift», «Internt krav» … */
export function sourceKindLabel(kind, tr = {}) {
    return tr.kinds?.[kind] ?? KIND_FALLBACKS[kind] ?? kind;
}

/**
 * One status history entry as a sentence: «Satt som utgått av Kari», «Gjenåpnet av Ola».
 *
 * @param {{to_status: string, changed_by_name: string|null}} entry
 * @param {object} tr  translations.compliance
 */
export function describeHistoryEntry(entry, tr = {}) {
    const fallbacks = {
        retired: 'Satt som utgått av :name',
        active: 'Gjenåpnet av :name',
    };
    const template = tr.history?.[entry.to_status] ?? fallbacks[entry.to_status] ?? ':name';

    return template.replace(':name', entry.changed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/**
 * «12 krav» / «1 krav», for the register count and a source's use.
 *
 * @param {number} count
 * @param {string} one   the singular text
 * @param {string} many  the plural template with :count
 */
export function countLabel(count, one, many) {
    return count === 1 ? one : many.replace(':count', String(count));
}
