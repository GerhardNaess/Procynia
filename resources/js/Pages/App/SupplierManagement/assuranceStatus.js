/**
 * Pure helpers for Kravstatus on a supplier, and for the earlier kontrollbeslutninger kept as
 * history. The server computes the state; these only name codes and arrange what the server sent.
 * They never compute a state and never show a share, a percentage or a score. Registrer beslutning
 * was retired on 2026-10-12: nothing here builds or checks a decision form any more.
 */
import { formatLongDate } from '../Improvements/improvementStatus.js';
import { levelLabel } from './controlRequirements.js';
import { displayStatusLabel } from './requirementEvaluations.js';

export const DECISION_TONES = {
    approved: 'emerald',
    approved_with_follow_up: 'blue',
    not_approved: 'rose',
};

export const STATE_TONES = {
    mandatory_open: 'rose',
    follow_up_required: 'amber',
    in_order: 'emerald',
};

const DECISION_FALLBACKS = {
    approved: 'Godkjent',
    approved_with_follow_up: 'Godkjent med oppfølging',
    not_approved: 'Ikke godkjent for nye kjøp',
};

const STATE_FALLBACKS = {
    mandatory_open: 'Obligatorisk krav åpent',
    follow_up_required: 'Krever oppfølging',
    in_order: 'I orden',
};

/**
 * The summary groups, in the plan's order. Each counts visningsstatuses; «forfalt» is Må fornyes and
 * Aksept utløpt together. Mutually exclusive, so they add up to the requirements that apply.
 */
const SUMMARY_GROUPS = [
    ['documented', ['documented'], ':count dokumentert'],
    ['partially_documented', ['partially_documented'], ':count delvis dokumentert'],
    ['missing', ['missing'], ':count mangler'],
    ['not_evaluated', ['not_evaluated'], ':count ikke vurdert'],
    ['temporarily_accepted', ['temporarily_accepted'], ':count midlertidig akseptert'],
    ['overdue', ['renewal_due', 'acceptance_expired'], ':count forfalt'],
];

/** «Godkjent», «Godkjent med oppfølging», «Ikke godkjent for nye kjøp». */
export function decisionLabel(decision, tr) {
    return tr?.assurance?.decisions?.[decision] ?? DECISION_FALLBACKS[decision] ?? decision;
}

/** «Obligatorisk krav åpent», «Krever oppfølging», «I orden». */
export function stateLabel(key, tr) {
    return tr?.assurance?.states?.[key] ?? STATE_FALLBACKS[key] ?? key;
}

/**
 * Kravstatus as one label with its tone, from the state code the server computed (a state object or
 * just its code). Null when nothing applies.
 */
export function stateNow(state, tr) {
    const key = typeof state === 'string' ? state : state?.state;

    if (! key) {
        return null;
    }

    return { key, label: stateLabel(key, tr), tone: STATE_TONES[key] ?? 'slate' };
}

/** «18 krav gjelder». */
export function applicableText(count, tr) {
    const a = tr?.assurance ?? {};

    return count === 1
        ? (a.applicable_one ?? '1 krav gjelder')
        : (a.applicable ?? ':count krav gjelder').replace(':count', String(count));
}

/**
 * «14 dokumentert · 2 mangler · 1 forfalt …»: the groups with a count, in the plan's order; groups
 * with 0 are left out.
 *
 * @returns {{ key: string, count: number, text: string }[]}
 */
export function summaryGroups(counts, tr) {
    const texts = tr?.assurance?.summary ?? {};

    return SUMMARY_GROUPS
        .map(([key, statuses, fallback]) => {
            const count = statuses.reduce((sum, status) => sum + Number(counts?.[status] ?? 0), 0);

            return { key, count, text: (texts[key] ?? fallback).replace(':count', String(count)) };
        })
        .filter((group) => group.count > 0);
}

/**
 * The mandatory requirements that are not accepted: a heading with how many, and each one by title
 * with its visningsstatus — so «Aksept utløpt» says so. Null when none.
 */
export function openMandatory(rows, tr) {
    const list = Array.isArray(rows) ? rows : [];

    if (list.length === 0) {
        return null;
    }

    const a = tr?.assurance ?? {};

    return {
        heading: list.length === 1
            ? (a.open_mandatory_one ?? '1 obligatorisk krav er ikke dokumentert eller akseptert:')
            : (a.open_mandatory ?? ':count obligatoriske krav er ikke dokumentert eller akseptert:').replace(':count', String(list.length)),
        items: list.map((row) => ({ id: row.id, text: `${row.title} – ${displayStatusLabel(row.display_status, tr)}` })),
    };
}

/** «Besluttet 8. oktober 2026 av Kari Hansen». */
export function decisionByline(entry, tr, locale = 'no') {
    const a = tr?.assurance ?? {};
    const date = formatLongDate(entry?.decided_on, locale);

    return entry?.decided_by_name
        ? (a.decided_by ?? 'Besluttet :date av :name').replace(':date', date).replace(':name', entry.decided_by_name)
        : (a.decided ?? 'Besluttet :date').replace(':date', date);
}

/** «3 beslutninger». */
export function historyCount(count, tr) {
    const a = tr?.assurance ?? {};

    return count === 1 ? (a.history_one ?? '1 beslutning') : (a.history_many ?? ':count beslutninger').replace(':count', String(count));
}

/**
 * A decision's snapshot as it reads in the history: the state then, how many applied, the groups,
 * and the mandatory and important requirements that were not documented — never today's state.
 */
export function snapshotSummary(snapshot, tr) {
    const a = tr?.assurance ?? {};

    return {
        state: snapshot?.state ? stateLabel(snapshot.state, tr) : null,
        applicable: applicableText(Number(snapshot?.applicable_count ?? 0), tr),
        groups: summaryGroups(snapshot?.counts ?? {}, tr),
        unmet: (snapshot?.unmet ?? []).map((row) => ({ id: row.id, text: `${row.title} – ${levelLabel(row.level, tr)} – ${displayStatusLabel(row.display_status, tr)}` })),
    };
}
