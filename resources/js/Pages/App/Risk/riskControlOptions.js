/** Above this many controls the picker gets a search field; below it a plain list reads fine. */
export const CONTROL_FILTER_THRESHOLD = 6;

/** «K-01 · Tittel», or just the title when the control has no code. */
export function controlLabel(control) {
    return control.code ? `${control.code} · ${control.title}` : control.title;
}

/** The option text: the control, then where it sits in Kvalitet's flows, so look-alikes differ. */
export function controlOptionLabel(control) {
    const placements = control.placements ?? [];

    return placements.length > 0 ? `${controlLabel(control)} — ${placements.join('; ')}` : controlLabel(control);
}

/**
 * The controls whose code, title or placement contains every word of the query, ignoring case.
 * The control already chosen is kept even when it no longer matches, so typing never silently
 * drops a selection.
 */
export function filterControls(options, query, selectedId = '') {
    const words = String(query ?? '').toLocaleLowerCase('nb-NO').split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return options;
    }

    return options.filter((control) => {
        if (selectedId !== '' && String(control.id) === String(selectedId)) {
            return true;
        }

        const haystack = [control.code ?? '', control.title ?? '', ...(control.placements ?? [])].join(' ').toLocaleLowerCase('nb-NO');

        return words.every((word) => haystack.includes(word));
    });
}
