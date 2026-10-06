import { formatDay } from '../../Improvements/improvementStatus.js';

/** The four statuses, in the order an audit passes through them, each with its badge tone. */
export const AUDIT_STATUS_TONES = {
    planned: 'blue',
    in_progress: 'amber',
    completed: 'emerald',
    cancelled: 'slate',
};

const STATUS_FALLBACKS = {
    planned: 'Planlagt',
    in_progress: 'Under arbeid',
    completed: 'Fullført',
    cancelled: 'Avbrutt',
};

const TYPE_FALLBACKS = {
    internal: 'Intern',
    external: 'Ekstern',
};

/**
 * @param {string} status
 * @param {object} ta  translations.compliance.audits
 */
export function auditStatusLabel(status, ta = {}) {
    return ta.statuses?.[status] ?? STATUS_FALLBACKS[status] ?? status;
}

/**
 * @param {string} type  'internal' | 'external'
 * @param {object} ta    translations.compliance.audits
 */
export function auditTypeLabel(type, ta = {}) {
    return ta.types?.[type] ?? TYPE_FALLBACKS[type] ?? type;
}

/**
 * The planned period as one line: «01.11.2026–15.11.2026», or «Til 15.11.2026» without a start.
 *
 * @param {{planned_start_date: ?string, planned_end_date: ?string}} audit
 * @param {object} ta  translations.compliance.audits
 */
export function plannedPeriodLabel(audit, ta = {}) {
    const end = formatDay(audit?.planned_end_date ?? null, '');

    if (! audit?.planned_start_date) {
        return end === '' ? '—' : (ta.period_until ?? 'Til :date').replace(':date', end);
    }

    return `${formatDay(audit.planned_start_date)}–${end}`;
}

const HISTORY_FALLBACKS = {
    planned_in_progress: 'Startet av :name',
    in_progress_completed: 'Fullført av :name',
    planned_cancelled: 'Avbrutt av :name',
    in_progress_cancelled: 'Avbrutt av :name',
    completed_in_progress: 'Gjenåpnet av :name',
};

/**
 * One status history entry as a sentence. Gjenåpnet is told apart from Startet by where it came
 * from, which is why the key is the pair, not the new status alone.
 *
 * @param {{from_status: string, to_status: string, changed_by_name: ?string}} entry
 * @param {object} ta  translations.compliance.audits
 */
export function describeAuditHistoryEntry(entry, ta = {}) {
    const key = `${entry.from_status}_${entry.to_status}`;
    const template = ta.history?.[key] ?? HISTORY_FALLBACKS[key] ?? key;

    return template.replace(':name', entry.changed_by_name ?? ta.unknown_user ?? 'en tidligere bruker');
}

/**
 * What the page says about editing, by status: nothing while it can be changed; that it is locked
 * when completed (and how to change it), or read-only when cancelled.
 *
 * @param {string} status
 * @param {object} ta  translations.compliance.audits
 */
export function lockedNotice(status, ta = {}) {
    if (status === 'completed') {
        return ta.locked_completed ?? 'Revisjonen er fullført og låst. Gjenåpne den for å gjøre endringer.';
    }

    if (status === 'cancelled') {
        return ta.locked_cancelled ?? 'Revisjonen er avbrutt og kan ikke endres.';
    }

    return null;
}

/**
 * The requirements to choose from, narrowed by every word of the query in reference, title or
 * source. The ones already chosen are always kept, so a search never drops a selection out of sight.
 *
 * @param {Array<{id: number, reference: ?string, title: string, source_label: ?string}>} options
 * @param {string} query
 * @param {Array<number>} selected
 */
export function filterRequirementOptions(options, query, selected = []) {
    const words = String(query ?? '').toLowerCase().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return options;
    }

    return options.filter((option) => {
        if (selected.includes(option.id)) {
            return true;
        }

        const haystack = [option.reference, option.title, option.source_label].filter(Boolean).join(' ').toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
}

/** A requirement as one line: «A.5.15 Tilgangsstyring», or just the title without a reference. */
export function requirementLabel(requirement) {
    return requirement.reference ? `${requirement.reference} ${requirement.title}` : requirement.title;
}
