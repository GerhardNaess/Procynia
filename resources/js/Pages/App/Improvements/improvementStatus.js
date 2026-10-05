export const IMPROVEMENT_STATUS_TONES = {
    open: 'blue',
    in_progress: 'amber',
    closed: 'emerald',
    cancelled: 'slate',
};

export const IMPROVEMENT_TYPE_TONES = {
    deviation: 'rose',
    improvement: 'violet',
};

/**
 * A day as the page shows it: «31.12.2026», or the fallback when there is none.
 *
 * @param {string|null} value  Y-m-d
 * @param {string} fallback
 */
export function formatDay(value, fallback = '—') {
    if (! value) {
        return fallback;
    }

    const [year, month, day] = value.split('-');

    return `${day}.${month}.${year}`;
}

/**
 * A moment as a long date in the person's language: «5. oktober 2026» / «5 October 2026».
 *
 * @param {string|null} iso
 * @param {string} locale  The app locale shared by the backend ('no', 'en').
 */
export function formatLongDate(iso, locale = 'no') {
    if (! iso) {
        return '';
    }

    const tag = String(locale).toLowerCase().startsWith('en') ? 'en-GB' : 'nb-NO';

    return new Date(iso).toLocaleDateString(tag, { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * The text that tells the person what to write in Beskrivelse, for the chosen type. An avvik asks
 * what happened and what was expected; a forbedring what can be better and why.
 *
 * @param {string} type  'deviation' | 'improvement' | ''
 * @param {object} tr    translations.improvements
 */
export function descriptionHint(type, tr = {}) {
    return type === 'improvement'
        ? (tr.field_description_hint_improvement ?? 'Beskriv hva som kan forbedres, og hvorfor det vil være nyttig.')
        : (tr.field_description_hint_deviation ?? 'Beskriv hva som skjedde, og hva som var forventet.');
}

/**
 * One history entry as a sentence: «Behandling startet av Kari», «Lukket av Ola» …
 *
 * @param {{to_status: string, changed_by_name: string|null}} entry
 * @param {object} tr  translations.improvements
 */
export function describeHistoryEntry(entry, tr = {}) {
    const fallbacks = {
        in_progress: 'Behandling startet av :name',
        closed: 'Lukket av :name',
        cancelled: 'Avbrutt av :name',
        open: 'Gjenåpnet av :name',
    };
    const template = tr.history?.[entry.to_status] ?? fallbacks[entry.to_status] ?? ':name';

    return template.replace(':name', entry.changed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

export const VERIFICATION_RESULT_TONES = {
    effective: 'emerald',
    not_effective: 'rose',
};

export const ACTION_STATUS_TONES = {
    planned: 'blue',
    in_progress: 'amber',
    completed: 'emerald',
    cancelled: 'slate',
};

/**
 * One tiltak history entry as a sentence: «Startet av Kari», «Fullført av Ola», «Gjenåpnet av …».
 *
 * @param {{to_status: string, changed_by_name: string|null}} entry
 * @param {object} tr  translations.improvements
 */
export function describeActionHistoryEntry(entry, tr = {}) {
    const fallbacks = {
        in_progress: 'Startet av :name',
        completed: 'Fullført av :name',
        cancelled: 'Avbrutt av :name',
        planned: 'Gjenåpnet av :name',
    };
    const template = tr.actions?.history?.[entry.to_status] ?? fallbacks[entry.to_status] ?? ':name';

    return template.replace(':name', entry.changed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/**
 * Why a cancelled tiltak was cancelled: the note of its latest change, which is the cancellation
 * itself. Not stored on the tiltak — the history is the only record of it.
 *
 * @param {{status: string, history: Array<{to_status: string, note: string|null}>}} action  history newest first
 */
export function cancellationReason(action) {
    if (action?.status !== 'cancelled') {
        return null;
    }

    const latest = (action.history ?? [])[0];

    return latest?.to_status === 'cancelled' ? latest.note : null;
}

const fill = (text, values) => Object.entries(values)
    .reduce((result, [name, value]) => result.replace(`:${name}`, String(value ?? '')), text ?? '');

/**
 * The headline of «Trenger oppmerksomhet»: «1 sak og 3 tiltak trenger oppmerksomhet». Cases and
 * tiltak are counted apart and never added up into one number.
 *
 * @param {number} caseTotal
 * @param {number} actionTotal
 * @param {object} ta  translations.improvements.attention
 */
export function attentionSummary(caseTotal, actionTotal, ta = {}) {
    const cases = caseTotal === 1
        ? (ta.cases_one ?? '1 sak')
        : fill(ta.cases_many ?? ':count saker', { count: caseTotal });
    const actions = actionTotal === 1
        ? (ta.actions_one ?? '1 tiltak')
        : fill(ta.actions_many ?? ':count tiltak', { count: actionTotal });

    if (caseTotal > 0 && actionTotal > 0) {
        return fill(ta.summary ?? ':cases og :actions trenger oppmerksomhet', { cases, actions });
    }

    return caseTotal > 0
        ? fill(ta.summary_cases ?? ':cases trenger oppmerksomhet', { cases })
        : fill(ta.summary_actions ?? ':actions trenger oppmerksomhet', { actions });
}

/**
 * The register's light tiltak indicator: «3 tiltak · 1 åpent», or just «3 tiltak» when none is open.
 * Open is planned or under arbeid; the server counts it. Null for a case without tiltak.
 *
 * @param {{total: number, open: number}|null} summary
 * @param {object} ti  translations.improvements.action_indicator
 */
export function actionIndicator(summary, ti = {}) {
    if (! summary || summary.total < 1) {
        return null;
    }

    const total = summary.total === 1
        ? (ti.total_one ?? '1 tiltak')
        : fill(ti.total_many ?? ':count tiltak', { count: summary.total });

    if (summary.open < 1) {
        return total;
    }

    const open = summary.open === 1
        ? (ti.open_one ?? '1 åpent')
        : fill(ti.open_many ?? ':count åpne', { count: summary.open });

    return `${total} · ${open}`;
}
