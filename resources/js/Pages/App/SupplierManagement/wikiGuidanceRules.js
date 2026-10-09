/**
 * Veiledning fra Enterprise Wiki on a control requirement (supplier-assurance-v2-plan §28): what the
 * section shows, decided from what the server sent. The server sends `wiki_guidance: null` when the
 * person cannot read the Wiki — then nothing is shown at all, not even an empty section.
 */

/**
 * Whether the section is shown: the person can read the Wiki, and there is guidance — or they can
 * add some. An empty section is never shown to someone who can only read it.
 *
 * @param {Array|null|undefined} guidance
 * @param {boolean} canManage
 */
export function wikiGuidanceVisible(guidance, canManage) {
    if (! Array.isArray(guidance)) {
        return false;
    }

    return guidance.length > 0 || Boolean(canManage);
}

/** «Arkivert i Wiki» / «Ikke publisert ennå» — only when it matters; null otherwise. */
export function wikiNoteLabel(note, tr) {
    const notes = tr?.wiki_guidance?.notes ?? {};

    return {
        archived: notes.archived ?? 'Arkivert i Wiki',
        not_published: notes.not_published ?? 'Ikke publisert ennå',
    }[note] ?? null;
}

/**
 * Search results as the person reads them: every match, the ones already linked to the requirement
 * marked so instead of silently missing (a search that only finds linked pages would otherwise
 * read as «no match»).
 *
 * @returns {Array<{page: object, linked: boolean}>}
 */
export function searchRows(results, guidance) {
    const linked = new Set((guidance ?? []).map((page) => page.id));

    return (results ?? []).map((page) => ({ page, linked: linked.has(page.id) }));
}

/** The search URL for a term. */
export function wikiSearchUrl(term) {
    const value = (term ?? '').trim();

    return `/app/supplier-management/control-requirements/wiki-pages${value === '' ? '' : `?search=${encodeURIComponent(value)}`}`;
}
