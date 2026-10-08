/**
 * How the Abonnement page presents «Moduler og pakker»: Basis, then the options.
 *
 * The verdict — which packages are active and which action each one offers — is
 * ModuleEntitlementService::overviewFor()'s. This only turns that verdict into words.
 */

export const fillTemplate = (template, values) => Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`:${key}`, value ?? ''),
    template ?? '',
);

/** Basis on its own, and the options in catalog order. */
export function splitPackages(packages) {
    return {
        base: packages.find((entry) => entry.kind === 'base') ?? null,
        options: packages.filter((entry) => entry.kind === 'option'),
    };
}

/** The status badge: Aktiv or Ikke aktiv (Bestilt / Ikke innvilget for an order in admin hands). */
export function packageStatus(entry, text = {}) {
    switch (entry.status) {
        case 'active':
            return { tone: 'green', label: text.status_active ?? 'Aktiv' };
        case 'requested':
            return { tone: 'amber', label: text.status_requested ?? 'Bestilt' };
        case 'declined':
            return { tone: 'slate', label: text.status_declined ?? 'Ikke innvilget' };
        default:
            return { tone: 'slate', label: text.status_available ?? 'Ikke aktiv' };
    }
}

/** The label of the one button an entry offers, or null — Basis, while active, offers none. */
export function packageActionLabel(entry, text = {}) {
    switch (entry.action) {
        case 'cancel':
            return text.cancel ?? 'Avbestill';
        case 'order':
            return entry.status === 'declined' ? (text.order_again ?? 'Bestill på nytt') : (text.order ?? 'Bestill');
        default:
            return null;
    }
}

/** What the confirmation says before an option is ordered or cancelled. */
export function packageConfirmation(entry, text = {}, packageName = (key) => key) {
    const name = packageName(entry.key);

    if (entry.action === 'cancel') {
        return {
            title: fillTemplate(text.cancel_confirm_title ?? 'Avbestill :package?', { package: name }),
            message: text.cancel_confirm_messages?.[entry.key]
                ?? fillTemplate(text.cancel_confirm_message ?? ':package blir ikke lenger tilgjengelig. Registrerte data og historikk slettes ikke.', { package: name }),
            confirmLabel: text.cancel ?? 'Avbestill',
            warning: true,
        };
    }

    return {
        title: fillTemplate(text.order_confirm_title ?? 'Bestill :package?', { package: name }),
        message: fillTemplate(text.order_confirm_message ?? ':package aktiveres med en gang. Brukere får tilgang gjennom rollene sine; tilganger endres ikke automatisk.', { package: name }),
        confirmLabel: text.order ?? 'Bestill',
        warning: false,
    };
}
