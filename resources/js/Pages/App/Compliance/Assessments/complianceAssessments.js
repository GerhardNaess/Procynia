/**
 * Etterlevelsesvurdering: the pure parts of the worklist — the figures, the filter choices and how
 * a requirement's group is named. The server decides every group (ComplianceAssessmentOverview);
 * nothing here derives one.
 */

/** The figures across the top, in order. Each one is also a shortcut to the matching filter. */
export const STAT_TILES = [
    { key: 'assessable', filter: '' },
    { key: 'assessed', filter: null },
    { key: 'compliant', filter: 'compliant' },
    { key: 'partially_compliant', filter: 'partially_compliant' },
    { key: 'non_compliant', filter: 'non_compliant' },
    { key: 'not_assessed', filter: 'not_assessed' },
];

/** The per-standard table's columns, in order. */
export const SOURCE_COLUMNS = ['assessable', 'assessed', 'compliant', 'partially_compliant', 'non_compliant', 'not_assessed'];

const STAT_FALLBACKS = {
    assessable: 'Vurderbare krav',
    assessed: 'Vurdert',
    compliant: 'Oppfylt',
    partially_compliant: 'Delvis oppfylt',
    non_compliant: 'Ikke oppfylt',
    not_assessed: 'Ikke vurdert',
    overdue: 'Revurdering forfalt',
    not_applicable: 'Ikke relevant eller utelatt',
    relevance_open: 'Relevans eller kontrollvalg ikke avklart',
};

export function statLabel(key, ta = {}) {
    return ta.stats?.[key] ?? STAT_FALLBACKS[key] ?? key;
}

const FILTER_FALLBACKS = {
    '': 'Alle vurderbare krav',
    not_assessed: 'Ikke vurdert',
    gaps: 'Mangler (delvis eller ikke oppfylt)',
    compliant: 'Oppfylt',
    partially_compliant: 'Delvis oppfylt',
    non_compliant: 'Ikke oppfylt',
    overdue: 'Revurdering forfalt',
    relevance_open: 'Relevans eller kontrollvalg ikke avklart',
    not_applicable: 'Ikke relevant eller utelatt',
};

/** The «Vis» choices: every vurderbar requirement first, then the server's filters in its order. */
export function filterOptions(serverFilters = [], ta = {}) {
    return ['', ...serverFilters].map((value) => ({ value, label: ta.filters?.[value === '' ? 'all' : value] ?? FILTER_FALLBACKS[value] ?? value }));
}

export const GROUP_TONES = {
    compliant: 'emerald',
    partially_compliant: 'amber',
    non_compliant: 'rose',
    not_assessed: 'sky',
    not_applicable: 'slate',
    relevance_open: 'violet',
};

const GROUP_FALLBACKS = {
    compliant: 'Oppfylt',
    partially_compliant: 'Delvis oppfylt',
    non_compliant: 'Ikke oppfylt',
    not_assessed: 'Ikke vurdert',
    not_applicable: 'Ikke relevant',
    relevance_open: 'Relevans ikke avklart',
};

const CONTROL_GROUP_FALLBACKS = {
    not_applicable: 'Utelatt',
    relevance_open: 'Kontroll ikke valgt',
};

/**
 * How a requirement's group reads in the list. An Annex A control speaks of Kontrollvalg —
 * «Utelatt», «Kontroll ikke valgt» — never of relevance; its results read like any requirement's.
 */
export function groupLabel(group, isControl = false, ta = {}) {
    if (isControl && CONTROL_GROUP_FALLBACKS[group]) {
        return ta.groups_control?.[group] ?? CONTROL_GROUP_FALLBACKS[group];
    }

    return ta.groups?.[group] ?? GROUP_FALLBACKS[group] ?? group;
}

/** «12 av 40 vurdert». */
export function progressText(stats, template = ':assessed av :total vurdert') {
    return template.replace(':assessed', String(stats?.assessed ?? 0)).replace(':total', String(stats?.assessable ?? 0));
}

/** The share of vurderbare requirements that have an assessment, 0–100. */
export function progressPercent(stats) {
    const total = stats?.assessable ?? 0;

    return total > 0 ? Math.round(((stats?.assessed ?? 0) / total) * 100) : 0;
}

/** The query the filter form sends: empty values left out, so the URL stays readable. */
export function worklistQuery({ source = '', status = '', search = '' } = {}) {
    const query = {};

    if (source !== '' && source !== null && source !== undefined) {
        query.source = source;
    }

    if (status) {
        query.status = status;
    }

    if (String(search ?? '').trim() !== '') {
        query.search = String(search).trim();
    }

    return query;
}
