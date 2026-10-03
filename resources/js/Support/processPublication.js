/**
 * How a process's publication reads in the UI.
 *
 * The state itself is decided server-side from the approved revisions
 * (QualityProcessBlueprintService::publicationStates()); this only turns it into a label and a
 * badge tone, once, so the list, the item header and the Flyt tab cannot describe the same process
 * three different ways.
 */

const FALLBACKS = {
    unpublished: 'Ikke publisert',
    current: 'Gjeldende, revisjon :number',
    current_with_changes: 'Gjeldende, med upubliserte endringer',
    retired: 'Utgått',
};

const TONES = {
    unpublished: 'slate',
    current: 'green',
    current_with_changes: 'amber',
    retired: 'slate',
};

export function publicationLabel(publication, labels = {}) {
    if (! publication) {
        return null;
    }

    const template = labels?.[publication.state] ?? FALLBACKS[publication.state] ?? publication.state;

    return template.replace(':number', String(publication.revision_number ?? '—'));
}

export function publicationTone(publication) {
    return TONES[publication?.state] ?? 'slate';
}
