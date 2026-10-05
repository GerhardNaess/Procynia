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
