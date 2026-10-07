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

/**
 * The badge tone of a compliance status. not_assessed is derived — no assessment exists — and is
 * never a stored result.
 */
export const COMPLIANCE_STATUS_TONES = {
    compliant: 'emerald',
    partially_compliant: 'amber',
    non_compliant: 'rose',
    not_applicable: 'slate',
    not_assessed: 'sky',
};

const RESULT_FALLBACKS = {
    compliant: 'Oppfylt',
    partially_compliant: 'Delvis oppfylt',
    non_compliant: 'Ikke oppfylt',
    not_applicable: 'Ikke relevant',
    not_assessed: 'Ikke vurdert',
};

/** «Oppfylt», «Delvis oppfylt» … «Ikke vurdert». */
export function complianceStatusLabel(status, tr = {}) {
    return tr.assessment?.results?.[status] ?? RESULT_FALLBACKS[status] ?? status;
}

/**
 * What the register's Etterlevelse column says for one requirement. An active requirement shows
 * its current result, and «Revurdering forfalt» beside it — never instead of it. A retired one
 * shows its last result only as history («Siste vurdering: Oppfylt»), so it never reads as active,
 * and nothing at all when it was never assessed.
 *
 * @param {{status: string, is_overdue: boolean}} compliance  from ComplianceStatusResolver
 * @param {string} requirementStatus  'active' | 'retired'
 * @param {object} tr  translations.compliance
 * @returns {{kind: 'current'|'historic'|'none', label: string, tone: string, overdue: boolean}}
 */
export function registerCompliance(compliance, requirementStatus, tr = {}) {
    const status = compliance?.status ?? 'not_assessed';
    const label = complianceStatusLabel(status, tr);

    if (requirementStatus !== 'active') {
        if (status === 'not_assessed') {
            return { kind: 'none', label: '', tone: 'slate', overdue: false };
        }

        const template = tr.assessment?.historic ?? 'Siste vurdering: :result';

        return { kind: 'historic', label: template.replace(':result', label), tone: 'slate', overdue: false };
    }

    return { kind: 'current', label, tone: COMPLIANCE_STATUS_TONES[status] ?? 'slate', overdue: Boolean(compliance?.is_overdue) };
}

/**
 * A moment with date and time in the person's language: «6. oktober 2026 kl. 09:30» /
 * «6 October 2026, 09:30».
 */
export function formatDateTime(iso, locale = 'no') {
    if (! iso) {
        return '';
    }

    const tag = String(locale).toLowerCase().startsWith('en') ? 'en-GB' : 'nb-NO';

    return new Date(iso).toLocaleString(tag, { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * The five reasons a requirement needs attention, in the server's display order
 * (ComplianceAttentionService::REASONS). The server decides which apply; this only names them.
 */
export const ATTENTION_REASONS = ['not_assessed', 'non_compliant', 'partially_compliant', 'review_overdue', 'missing_owner'];

const ATTENTION_REASON_FALLBACKS = {
    not_assessed: 'Ikke vurdert',
    non_compliant: 'Ikke oppfylt',
    partially_compliant: 'Delvis oppfylt',
    review_overdue: 'Revurdering forfalt',
    missing_owner: 'Mangler ansvarlig',
};

/** «Ikke vurdert», «Mangler ansvarlig» … */
export function attentionReasonLabel(reason, tr = {}) {
    return tr.attention?.reasons?.[reason] ?? ATTENTION_REASON_FALLBACKS[reason] ?? reason;
}

/** «1 krav trenger oppmerksomhet» / «3 krav trenger oppmerksomhet». */
export function attentionTotalLabel(total, tr = {}) {
    return countLabel(
        total,
        tr.attention?.total_one ?? '1 krav trenger oppmerksomhet',
        tr.attention?.total_many ?? ':count krav trenger oppmerksomhet',
    );
}

/** How many entries the panel lists before «Vis alle». */
export const ATTENTION_PREVIEW = 5;

/**
 * What the «Trenger oppmerksomhet» panel draws: nothing when no requirement needs attention, else
 * the first ATTENTION_PREVIEW entries — or all of them once expanded — and whether there are more.
 *
 * @param {{total: number, requirements?: Array, audits?: Array}|null} attention  from the server
 * @param {boolean} expanded
 * @returns {{visible: boolean, total: number, items: Array, hasMore: boolean}}
 */
export function attentionPanel(attention, expanded = false) {
    const total = attention?.total ?? 0;
    // Krav sends `requirements`, Revisjoner `audits`: the same worklist over a different register.
    const all = attention?.requirements ?? attention?.audits ?? [];

    if (total === 0 || all.length === 0) {
        return { visible: false, total: 0, items: [], hasMore: false, count: 0 };
    }

    return {
        visible: true,
        total,
        items: expanded ? all : all.slice(0, ATTENTION_PREVIEW),
        hasMore: all.length > ATTENTION_PREVIEW,
        count: all.length,
    };
}
