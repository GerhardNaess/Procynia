/**
 * How the Abonnement page presents «Moduler og pakker».
 *
 * The verdict — which package is active, which steps are included in it, which action each row
 * offers and what that action would take away — is ModuleEntitlementService::overviewFor()'s. This
 * only turns that verdict into words, so the page never ranks packages itself.
 */

const fill = (template, values) => Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`:${key}`, value),
    template ?? '',
);

/** «A», «A og B», «A, B og C». */
export function formatList(items, andWord = 'og') {
    if (items.length <= 1) {
        return items.join('');
    }

    return `${items.slice(0, -1).join(', ')} ${andWord} ${items[items.length - 1]}`;
}

/** The status badge of one row: Aktiv, Inkludert i X, Ikke aktiv, ... */
export function packageStatus(entry, text = {}, packageName = (key) => key) {
    switch (entry.status) {
        case 'active':
            return { tone: 'green', label: text.status_active ?? 'Aktiv' };
        case 'included':
            return {
                tone: 'blue',
                label: fill(text.status_included ?? 'Inkludert i :package', { package: packageName(entry.included_in) }),
            };
        case 'requested':
            return { tone: 'amber', label: text.status_requested ?? 'Bestilt' };
        case 'declined':
            return { tone: 'slate', label: text.status_declined ?? 'Ikke innvilget' };
        default:
            return { tone: 'slate', label: text.status_available ?? 'Ikke aktiv' };
    }
}

/** The label of the one button a row offers, or null when it offers none (an included step). */
export function packageActionLabel(entry, text = {}) {
    switch (entry.action) {
        case 'change':
            return text.change ?? 'Endre pakke';
        case 'upgrade':
            return text.upgrade ?? 'Oppgrader';
        case 'cancel':
            return text.cancel ?? 'Avbestill';
        case 'order':
            return entry.status === 'declined' ? (text.order_again ?? 'Bestill på nytt') : (text.order ?? 'Bestill');
        default:
            return null;
    }
}

/** The steps a customer can move to from Endre pakke: every main package but the active one. */
export function changeTargets(packages) {
    return packages.filter((entry) => entry.kind === 'main' && entry.status !== 'active' && entry.orderable);
}

/**
 * What a confirmation says before a package change or a cancellation: what becomes unavailable and
 * that nothing is deleted, or — moving up — what becomes available and that access still follows
 * roles.
 */
export function consequenceLines(entry, text = {}, moduleName = (key) => key) {
    const list = (keys) => formatList(keys.map(moduleName), text.list_and ?? 'og');
    const lines = [];

    if (entry.modules_lost?.length) {
        lines.push(fill(text.consequence_lost ?? ':modules blir ikke lenger tilgjengelig.', { modules: list(entry.modules_lost) }));
        lines.push(text.consequence_kept ?? 'Registrerte data og historikk slettes ikke, og er der igjen hvis pakken aktiveres på nytt.');
    }

    if (entry.modules_gained?.length) {
        lines.push(fill(text.consequence_gained ?? ':modules blir tilgjengelig.', { modules: list(entry.modules_gained) }));
        lines.push(text.consequence_access ?? 'Tilganger endres ikke automatisk: brukere får bare tilgang gjennom rollene sine.');
    }

    return lines;
}

export { fill as fillTemplate };
