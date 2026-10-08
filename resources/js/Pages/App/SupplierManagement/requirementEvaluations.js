/**
 * Pure helpers for Kontroller krav on a supplier (docs/supplier-assurance-v2-plan.md §8, §10.2.1).
 * The server decides the visningsstatus and whether a control may be written; these only name
 * codes, prepare the form and say what the form still needs. Never a score, never a conclusion
 * drawn from a document.
 */
import { formatLongDate } from '../Improvements/improvementStatus.js';
import { documentStatusLabel, documentTypeLabel } from './supplierManagement.js';

export const DISPLAY_STATUS_TONES = {
    documented: 'emerald',
    partially_documented: 'amber',
    missing: 'rose',
    temporarily_accepted: 'blue',
    not_evaluated: 'slate',
    renewal_due: 'amber',
    acceptance_expired: 'rose',
};

const DISPLAY_STATUS_FALLBACKS = {
    documented: 'Dokumentert',
    partially_documented: 'Delvis dokumentert',
    missing: 'Mangler',
    temporarily_accepted: 'Midlertidig akseptert',
    not_evaluated: 'Ikke vurdert',
    renewal_due: 'Må fornyes',
    acceptance_expired: 'Aksept utløpt',
};

/** «Dokumentert», «Må fornyes», «Ikke vurdert» … — for a stored or a computed status. */
export function displayStatusLabel(status, tr) {
    return tr?.control?.display_statuses?.[status] ?? DISPLAY_STATUS_FALLBACKS[status] ?? status;
}

/**
 * «Sist kontrollert 8. oktober 2026 av Kari Hansen» for the control in force; null when the
 * requirement has never been controlled — the row then says «Ikke vurdert» and nothing else.
 */
export function lastControlText(current, tr, locale = 'no') {
    if (! current?.evaluated_on) {
        return null;
    }

    const c = tr?.control ?? {};
    const date = formatLongDate(current.evaluated_on, locale);

    return current.evaluated_by_name
        ? (c.last_controlled_by ?? 'Sist kontrollert :date av :name').replace(':date', date).replace(':name', current.evaluated_by_name)
        : (c.last_controlled ?? 'Sist kontrollert :date').replace(':date', date);
}

/** An empty control of one requirement, dated today. */
export function evaluationFormData(requirementId, today) {
    return {
        requirement_id: String(requirementId ?? ''),
        status: '',
        rationale: '',
        evaluated_on: today ?? '',
        accepted_until: '',
        document_ids: [],
    };
}

/**
 * What the form still needs before it can be sent, in the order the person meets it: a result, at
 * least one document for Dokumentert, a date for Midlertidig akseptert, and a begrunnelse. The
 * server checks the same and more; this only keeps the button honest.
 *
 * @returns {string[]} codes: 'status' | 'document' | 'accepted_until' | 'rationale' | 'evaluated_on'
 */
export function evaluationMissing(data) {
    const missing = [];

    if (! data?.status) {
        missing.push('status');
    }

    if (data?.status === 'documented' && (data.document_ids ?? []).length === 0) {
        missing.push('document');
    }

    if (data?.status === 'temporarily_accepted' && ! data.accepted_until) {
        missing.push('accepted_until');
    }

    if (! data?.evaluated_on) {
        missing.push('evaluated_on');
    }

    if (! String(data?.rationale ?? '').trim()) {
        missing.push('rationale');
    }

    return missing;
}

/** Adds or removes one document id. */
export function toggleDocument(ids, id) {
    const current = Array.isArray(ids) ? ids : [];

    return current.includes(id) ? current.filter((item) => item !== id) : [...current, id];
}

/**
 * One documentation row as a choice: type and name, the standard when there is one, and — only when
 * it no longer holds — its status now, so an expired or replaced row is never chosen unawares.
 */
export function documentOption(document, tr) {
    const c = tr?.control?.evaluation ?? {};
    const status = ['expired', 'replaced'].includes(document?.status)
        ? (c.document_status ?? 'nå: :status').replace(':status', documentStatusLabel(document.status, tr))
        : null;

    return {
        type: documentTypeLabel(document?.document_type, tr),
        title: [document?.title, document?.standard ? `(${document.standard})` : null].filter(Boolean).join(' '),
        status,
    };
}

/**
 * A document in a control's history: the snapshot — what the person saw — and, apart from it, what
 * is different now. `now` is null when nothing about the row has changed and it still holds.
 */
export function snapshotDocument(document, tr) {
    const now = document?.now ?? null;
    const noLongerHolds = ['expired', 'replaced'].includes(now?.status);

    return {
        type: documentTypeLabel(document?.document_type, tr),
        title: document?.title ?? '',
        standard: document?.standard ?? null,
        location: document?.location ?? null,
        validFrom: document?.valid_from ?? null,
        validUntil: document?.valid_until ?? null,
        changed: Boolean(document?.changed_since),
        now: now && (document?.changed_since || noLongerHolds)
            ? { title: now.title, status: documentStatusLabel(now.status, tr), validUntil: now.valid_until ?? null }
            : null,
    };
}
