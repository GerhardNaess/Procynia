/** The badge tone of each supplier status; the three differ. */
export const SUPPLIER_STATUS_TONES = {
    onboarding: 'sky',
    active: 'emerald',
    ended: 'slate',
};

const STATUS_FALLBACKS = {
    onboarding: 'Under vurdering',
    active: 'Aktiv',
    ended: 'Avsluttet',
};

const CATEGORY_FALLBACKS = {
    it_cloud: 'IT og skytjenester',
    consulting: 'Konsulent og rådgivning',
    goods: 'Varer og materiell',
    construction: 'Bygg og anlegg',
    transport_logistics: 'Transport og logistikk',
    operations_facilities: 'Drift og fasilitet',
    other: 'Annet',
};

/** A status as the page names it: «Under vurdering», «Aktiv», «Avsluttet». */
export function statusLabel(status, tr = {}) {
    return tr.statuses?.[status] ?? STATUS_FALLBACKS[status] ?? status;
}

/** A category as the page names it: «IT og skytjenester» … */
export function categoryLabel(category, tr = {}) {
    return tr.categories?.[category] ?? CATEGORY_FALLBACKS[category] ?? category;
}

/**
 * One status history entry as a sentence: «Tatt i bruk av Kari», «Avsluttet av Ola»,
 * «Gjenåpnet av Kari». Ending and reopening both land on a status that also has another route in
 * (ended, active), so the pair decides.
 *
 * @param {{from_status: string, to_status: string, changed_by_name: string|null}} entry
 * @param {object} tr  translations.supplier_management
 */
export function describeHistoryEntry(entry, tr = {}) {
    const kind = entry.to_status === 'ended'
        ? 'ended'
        : (entry.from_status === 'ended' ? 'reopened' : 'activated');
    const fallbacks = {
        activated: 'Tatt i bruk av :name',
        ended: 'Avsluttet av :name',
        reopened: 'Gjenåpnet av :name',
    };
    const template = tr.history?.[kind] ?? fallbacks[kind];

    return template.replace(':name', entry.changed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/** The registration as the history's first line: «Registrert som Under vurdering av Kari». */
export function describeRegistration(registered, tr = {}) {
    const template = tr.history?.registered ?? 'Registrert som :status av :name';

    return template
        .replace(':status', statusLabel(registered.status, tr))
        .replace(':name', registered.by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/** «12 leverandører» / «1 leverandør». */
export function countLabel(count, tr = {}) {
    return count === 1
        ? (tr.count_one ?? '1 leverandør')
        : (tr.count ?? ':count leverandører').replace(':count', String(count));
}
