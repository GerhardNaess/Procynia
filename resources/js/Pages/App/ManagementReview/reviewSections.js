/**
 * Ledelsens gjennomgåelse, the part that is pure: status tones, what each section marker means, which
 * numbers count as needing attention, and where «Se nåsituasjonen» leads. The server decides what
 * the person may see; this only turns it into words and order.
 */

export const REVIEW_STATUS_TONES = {
    draft: 'amber',
    finalized: 'emerald',
};

export const JUDGEMENT_TONES = {
    satisfactory: 'emerald',
    needs_improvement: 'amber',
    not_satisfactory: 'rose',
};

const STATUS_FALLBACKS = { draft: 'Utkast', finalized: 'Ferdigstilt' };

const JUDGEMENT_FALLBACKS = {
    satisfactory: 'Tilfredsstillende',
    needs_improvement: 'Bør forbedres',
    not_satisfactory: 'Ikke tilfredsstillende',
};

/** The pseudo-panes around the sections. */
export const OVERVIEW = 'overview';
export const DECISIONS = 'decisions';
export const HISTORY = 'history';

/**
 * The numbers in each section that say something needs the management's attention. A section with
 * any of them above zero gets the «!» marker and is listed under «Hva krever oppmerksomhet».
 */
export const ATTENTION_METRICS = {
    previous_decisions: ['actions_overdue'],
    objectives: ['kpis_off_target', 'target_date_passed', 'measurement_missing'],
    risks: ['level_very_high', 'level_high', 'review_overdue', 'acceptance_expired', 'actions_overdue'],
    improvements: ['case_overdue', 'action_overdue', 'not_effective'],
    compliance: ['non_compliant', 'review_overdue', 'audits_overdue', 'nonconformity_without_follow_up'],
    quality: ['controls_without_evidence', 'controls_without_activity', 'processes_without_governing_policy', 'processes_overdue_for_review'],
    suppliers: ['suppliers_with_findings'],
};

/** Where «Se nåsituasjonen i modulen» leads, per section. */
export const MODULE_LINKS = {
    objectives: '/app/objectives',
    risks: '/app/risk',
    improvements: '/app/improvements',
    compliance: '/app/compliance/requirements',
    quality: '/app/quality',
    suppliers: '/app/supplier-management',
    context_changes: '/app/compliance/requirements',
};

export function statusLabel(status, t = {}) {
    return t.statuses?.[status] ?? STATUS_FALLBACKS[status] ?? status;
}

export function judgementLabel(judgement, t = {}) {
    return t.judgements?.[judgement] ?? JUDGEMENT_FALLBACKS[judgement] ?? judgement;
}

export function sectionTitle(key, t = {}) {
    return t.sections?.[key]?.title ?? key;
}

/**
 * The attention numbers of one section, as {key, label, value} with value > 0. Read from the basis the
 * server already narrowed to the reader.
 */
export function attentionItems(section) {
    const keys = ATTENTION_METRICS[section?.key] ?? [];
    const groups = Array.isArray(section?.basis?.groups) ? section.basis.groups : [];
    const items = [];

    for (const group of groups) {
        for (const metric of group.metrics ?? []) {
            if (keys.includes(metric.key) && Number(metric.value) > 0 && ! items.some((item) => item.key === metric.key)) {
                items.push({ key: metric.key, label: metric.label, value: Number(metric.value) });
            }
        }
    }

    return items;
}

const MARKER_FALLBACKS = {
    judged: 'Vurdering gjennomført',
    open: 'Ikke vurdert',
    attention: 'Forhold krever oppmerksomhet',
    unavailable: 'Ikke tilgjengelig',
    optional: 'Ikke påkrevd for ferdigstilling',
};

const MARKER_SHORT_FALLBACKS = {
    judged: 'vurdert',
    open: 'ikke vurdert',
    attention: 'krever oppmerksomhet',
    unavailable: 'ikke tilgjengelig',
};

/**
 * Where a section stands, as two separate things — never one marker standing in for the other:
 *
 *  - progress: 'judged' once a judgement is saved (the same test «Klar for ferdigstilling» uses),
 *    'open' until then and again once it is removed, 'unavailable' when the reader cannot see the
 *    section or there is no basis to see. Opening a section changes nothing.
 *  - attention: the flagged numbers of the basis, the same list «Hva krever oppmerksomhet» shows.
 *    It stays while the numbers say so, judged or not; it never makes a judged section unfinished.
 *  - optional: an open section «Klar for ferdigstilling» does not ask for (Tidligere beslutninger
 *    before there is an earlier review). Read from the checklist itself, so the two never disagree.
 */
export function sectionStatus(section, readiness = null) {
    if (section?.state && section.state !== 'available') {
        return { progress: 'unavailable', attention: [], optional: false };
    }

    const progress = section?.judgement ? 'judged' : 'open';
    const missing = readiness?.items?.find((item) => item.key === 'judgements')?.sections;
    const optional = progress === 'open' && Array.isArray(missing) && ! missing.includes(section?.key);

    return { progress, attention: attentionItems(section), optional };
}

export function markerLabel(marker, t = {}) {
    return t.section?.markers?.[marker] ?? MARKER_FALLBACKS[marker] ?? marker;
}

/** The status in a sentence: «Vurdering gjennomført. Forhold krever oppmerksomhet: Høy risiko 1.» */
export function statusDescription(status, t = {}) {
    const parts = [markerLabel(status.progress, t)];

    if (status.optional) {
        parts.push(markerLabel('optional', t));
    }

    if (status.attention.length > 0) {
        parts.push(`${markerLabel('attention', t)}: ${status.attention.map((item) => `${item.label} ${item.value}`).join(', ')}`);
    }

    return `${parts.join('. ')}.`;
}

/** A phone's section select carries no icons, so its options say the same in words. */
export function sectionOptionLabel(section, t = {}, readiness = null) {
    const status = sectionStatus(section, readiness);
    const short = (marker) => t.section?.markers_short?.[marker] ?? MARKER_SHORT_FALLBACKS[marker];
    const words = [short(status.progress), ...(status.attention.length > 0 ? [short('attention')] : [])];

    return `${sectionTitle(section.key, t)} (${words.join(' · ')})`;
}

/** The panes in order: Oversikt, the sections, Beslutninger og tiltak, Historikk. */
export function paneKeys(sections = []) {
    return [OVERVIEW, ...sections.map((section) => section.key), DECISIONS, HISTORY];
}

/** The pane before and after the given one, for «Forrige / Neste seksjon». */
export function neighbours(sections, current) {
    const keys = sections.map((section) => section.key);
    const index = keys.indexOf(current);

    return {
        previous: index > 0 ? keys[index - 1] : null,
        next: index >= 0 && index < keys.length - 1 ? keys[index + 1] : null,
    };
}

/** The pane to open from the URL (?section=), falling back to Oversikt for anything unknown. */
export function paneFromSearch(search, sections = []) {
    const value = new URLSearchParams(search ?? '').get('section');

    return value && paneKeys(sections).includes(value) ? value : OVERVIEW;
}

/** Owners offered for a case in a fagområde: those who can read cases there. */
export function ownersForArea(options = [], areaId) {
    const id = Number(areaId);

    return id ? options.filter((person) => (person.area_ids ?? []).includes(id)) : [];
}

export function formatDay(value, fallback = '—') {
    if (! value) {
        return fallback;
    }

    const [year, month, day] = String(value).slice(0, 10).split('-');

    return `${day}.${month}.${year}`;
}

export function formatMoment(iso, fallback = '—') {
    if (! iso) {
        return fallback;
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return fallback;
    }

    const pad = (number) => String(number).padStart(2, '0');

    return `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function fill(template, replacements = {}) {
    return Object.entries(replacements).reduce(
        (text, [token, value]) => text.replaceAll(`:${token}`, value ?? ''),
        String(template ?? ''),
    );
}

/** The PageHelpButton props for one page (index or review), from translations.management_review.help. */
export function reviewHelp(t, page) {
    const help = t?.help ?? {};
    const content = help[page] ?? {};

    return {
        buttonLabel: help.button ?? 'Hjelp',
        title: content.title ?? 'Om ledelsens gjennomgåelse',
        intro: content.intro,
        sections: Array.isArray(content.sections) ? content.sections : [],
    };
}
