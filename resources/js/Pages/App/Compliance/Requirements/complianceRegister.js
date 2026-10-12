/**
 * Kravregister: Gruppert (the default) and Liste over the same filtered register. Pure, so the page
 * and its tests agree on the URL the server reads.
 *
 * The URL carries everything the page shows — search, filters, the chosen view and which sources and
 * chapters are open (`open=12,12:345`) — so «Tilbake til kravregisteret» and the browser's back
 * button both land on the same view.
 */
export const REGISTER_URL = '/app/compliance/requirements';

export const VIEW_GROUPED = 'grouped';

export const VIEW_LIST = 'list';

/**
 * The query string values for one register state; empty values are left out, and the default view
 * (Gruppert) is not written.
 *
 * @param {{search?: string, source?: string|number, status?: string, applicability?: string, view?: string, open?: string[]}} state
 */
export function registerQuery({ search = '', source = '', status = '', applicability = '', view = VIEW_GROUPED, open = [] } = {}) {
    const query = {};

    if (String(search).trim() !== '') {
        query.search = String(search).trim();
    }
    if (source !== '' && source !== null && source !== undefined) {
        query.source = String(source);
    }
    if (status) {
        query.status = status;
    }
    if (applicability) {
        query.applicability = applicability;
    }
    if (view === VIEW_LIST) {
        query.view = VIEW_LIST;
    }
    if (open.length > 0) {
        query.open = open.join(',');
    }

    return query;
}

/**
 * The open keys with one key opened or closed. Closing a source leaves its chapters' keys in place,
 * so reopening it shows them as they were.
 *
 * @param {string[]} open
 * @param {string} key  «12» for a source, «12:345» for a chapter in it
 */
export function toggleOpen(open, key) {
    return open.includes(key) ? open.filter((value) => value !== key) : [...open, key];
}

/**
 * A chapter's heading: «4. Organisasjonens kontekst» for a numbered chapter, «A.5 Organisatoriske
 * kontroller» for a theme — the same as Kravbiblioteket names them.
 *
 * @param {{reference: ?string, title: string}} group
 */
export function groupHeading(group) {
    const reference = String(group?.reference ?? '').trim();

    if (reference === '') {
        return group?.title ?? '';
    }

    return /^\d+$/.test(reference) ? `${reference}. ${group.title}` : `${reference} ${group.title}`;
}

/**
 * Whether the register is narrowed by a search or a filter.
 *
 * @param {object} filters  the server's filters prop
 */
export function isFiltered(filters = {}) {
    return Boolean(filters.search || filters.source || filters.status || filters.applicability);
}
