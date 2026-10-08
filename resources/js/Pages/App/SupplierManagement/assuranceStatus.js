/**
 * Pure helpers for Kontrollstatus on a supplier (docs/supplier-assurance-v2-plan.md §9.5). The
 * server computes the state now, «Krever beslutning» and which decisions may be registered; these
 * only name codes and arrange what the server sent. They never compute a state, never merge the
 * decision into the state, and never show a share, a percentage or a score.
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
    decision_required: 'rose',
    mandatory_open: 'amber',
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

/**
 * Tilstand nå as one label: «Krever beslutning» when the server's signal is on, otherwise the state
 * itself — «Obligatorisk krav åpent» is only seen when the decision in force is «Ikke godkjent for
 * nye kjøp». Null when nothing applies.
 */
export function stateNow(state, tr) {
    if (! state) {
        return null;
    }

    const a = tr?.assurance ?? {};
    const key = state.decision_required ? 'decision_required' : state.state;
    const label = state.decision_required
        ? (a.decision_required ?? 'Krever beslutning')
        : (a.states?.[state.state] ?? STATE_FALLBACKS[state.state] ?? state.state);

    return { key, label, tone: STATE_TONES[key] ?? 'slate' };
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

/** An empty decision dated today. */
export function decisionFormData(today) {
    return { decision: '', rationale: '', follow_up_note: '', decided_on: today ?? '' };
}

/**
 * What the form still needs: a decision the server allows, what is followed up for «Godkjent med
 * oppfølging», a date and a begrunnelse. The server checks the same against the state now.
 *
 * @returns {string[]} codes: 'decision' | 'follow_up_note' | 'decided_on' | 'rationale'
 */
export function decisionMissing(data, allowed = []) {
    const missing = [];

    if (! data?.decision || ! allowed.includes(data.decision)) {
        missing.push('decision');
    }

    if (data?.decision === 'approved_with_follow_up' && ! String(data.follow_up_note ?? '').trim()) {
        missing.push('follow_up_note');
    }

    if (! data?.decided_on) {
        missing.push('decided_on');
    }

    if (! String(data?.rationale ?? '').trim()) {
        missing.push('rationale');
    }

    return missing;
}

/**
 * The choices in the form, each with its hint and — when the state does not allow it now — why.
 *
 * @returns {{ value: string, label: string, hint: string|undefined, allowed: boolean, reason: string|null }[]}
 */
export function decisionOptions(decisions = [], allowed = [], tr) {
    const f = tr?.assurance?.form ?? {};

    return decisions.map((value) => ({
        value,
        label: decisionLabel(value, tr),
        hint: f.hints?.[value],
        allowed: allowed.includes(value),
        reason: allowed.includes(value) ? null : (f.not_allowed?.[value] ?? null),
    }));
}

/**
 * A decision's snapshot as it reads in the history: the state then, how many applied, the groups,
 * and the mandatory and important requirements that were not documented — never today's state.
 */
export function snapshotSummary(snapshot, tr) {
    const a = tr?.assurance ?? {};

    return {
        state: snapshot?.state ? (a.states?.[snapshot.state] ?? STATE_FALLBACKS[snapshot.state] ?? snapshot.state) : null,
        applicable: applicableText(Number(snapshot?.applicable_count ?? 0), tr),
        groups: summaryGroups(snapshot?.counts ?? {}, tr),
        unmet: (snapshot?.unmet ?? []).map((row) => ({ id: row.id, text: `${row.title} – ${levelLabel(row.level, tr)} – ${displayStatusLabel(row.display_status, tr)}` })),
    };
}
