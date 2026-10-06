import { formatDay } from '../../Improvements/improvementStatus.js';

/** Kvalitet's own status tones, the same as on Risiko's control list. */
export const QUALITY_STATUS_TONES = { draft: 'slate', active: 'emerald', under_review: 'amber', retired: 'slate' };

/** Above this many candidates the picker gets a search field; below it a plain list reads fine. */
export const LINK_FILTER_THRESHOLD = 6;

/** «K-01 · Tittel», or just the title when the item has no code. */
export function qualityItemLabel(item) {
    return item.code ? `${item.code} · ${item.title}` : item.title;
}

/** The option text: the item, then — for a control — where it sits in Kvalitet's flows. */
export function qualityOptionLabel(item) {
    const placements = item.placements ?? [];

    return placements.length > 0 ? `${qualityItemLabel(item)} — ${placements.join('; ')}` : qualityItemLabel(item);
}

/**
 * The candidates whose code, title or placement contains every word of the query, ignoring case.
 * The one already chosen is kept even when it no longer matches, so typing never drops a selection.
 */
export function filterQualityOptions(options, query, selectedId = '') {
    const words = String(query ?? '').toLocaleLowerCase('nb-NO').split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return options;
    }

    return options.filter((item) => {
        if (selectedId !== '' && String(item.id) === String(selectedId)) {
            return true;
        }

        const haystack = [item.code ?? '', item.title ?? '', ...(item.placements ?? [])].join(' ').toLocaleLowerCase('nb-NO');

        return words.every((word) => haystack.includes(word));
    });
}

/**
 * The fields of a control worth showing, in reading order, leaving out what Kvalitet has not
 * filled in. Frequency reads as Kvalitet names it.
 *
 * @param {object} control  one of quality_context.controls
 * @param {object} tq       translations.compliance.quality
 * @param {object} frequencies  translations.quality.frequencies
 * @returns {{key: string, label: string, value: string}[]}
 */
export function controlFacts(control, tq = {}, frequencies = {}) {
    const facts = [
        ['criterion', tq.criterion ?? 'Hva kontrolleres', control.criterion],
        ['method', tq.method ?? 'Metode', control.method],
        ['frequency', tq.frequency ?? 'Frekvens', control.frequency ? (frequencies[control.frequency] ?? control.frequency) : null],
        ['responsibility', tq.responsibility ?? 'Ansvar', control.responsibility],
    ];

    return facts
        .filter(([, , value]) => typeof value === 'string' && value.trim() !== '')
        .map(([key, label, value]) => ({ key, label, value }));
}

/** «Lagt til 05.10.2026 av Kari», or without a name when the person is gone. */
export function evidenceAddedLabel(evidence, tq = {}) {
    const date = formatDay(evidence.added_at, '');

    if (! date) {
        return '';
    }

    return evidence.added_by
        ? (tq.evidence_added ?? 'Lagt til :date av :name').replace(':date', date).replace(':name', evidence.added_by)
        : (tq.evidence_added_date ?? 'Lagt til :date').replace(':date', date);
}
