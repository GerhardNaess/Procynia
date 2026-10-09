/**
 * Importer leverandører: what the page decides on its own, kept here so it can be tested. The server
 * says what each row is (its status) and whether an existing supplier could be updated; the person's
 * one choice — «Oppdater eksisterende» — decides what happens to those rows. Everything else is the
 * server's, and it decides again when the import is confirmed.
 */

/** The row statuses, in the order the filter and the summary show them. */
export const IMPORT_STATUSES = ['new', 'existing', 'error', 'file_duplicate', 'possible_duplicate'];

/** Badge tone per status; «new» and «error» must never look alike. */
export const IMPORT_STATUS_TONES = {
    new: 'emerald',
    existing: 'sky',
    error: 'rose',
    file_duplicate: 'amber',
    possible_duplicate: 'amber',
};

const STATUS_FALLBACKS = {
    new: 'Ny leverandør',
    existing: 'Eksisterende leverandør',
    error: 'Feil i raden',
    file_duplicate: 'Duplikat i filen',
    possible_duplicate: 'Mulig duplikat',
};

const ACTION_FALLBACKS = {
    create: 'Opprettes',
    update: 'Oppdateres',
    skip: 'Hoppes over',
};

/** The import step the page is on: 1 Last opp, 2 Kontroller, 3 Bekreft — or the result. */
export function importStep(importData, confirming = false) {
    if (! importData) {
        return 'upload';
    }

    if (importData.status === 'completed') {
        return 'result';
    }

    return confirming ? 'confirm' : 'review';
}

/** What happens to the row: create, update or skip. */
export function rowAction(row, updateExisting) {
    if (row.status === 'new') {
        return 'create';
    }

    if (row.status === 'existing' && row.can_update && updateExisting) {
        return 'update';
    }

    return 'skip';
}

export function statusLabel(status, ti = {}) {
    return ti.statuses?.[status] ?? STATUS_FALLBACKS[status] ?? status;
}

export function actionLabel(action, ti = {}) {
    return ti.actions?.[action] ?? ACTION_FALLBACKS[action] ?? action;
}

/**
 * The summary before Bekreft. Skipped is every row that is neither created nor updated — errors,
 * duplicates and existing suppliers left alone.
 */
export function confirmSummary(summary = {}, updateExisting = false) {
    const total = summary.total ?? 0;
    const created = summary.new ?? 0;
    const updated = updateExisting ? (summary.updatable ?? 0) : 0;

    return {
        created,
        existing: summary.existing ?? 0,
        updated,
        updatable: summary.updatable ?? 0,
        errors: summary.error ?? 0,
        skipped: Math.max(0, total - created - updated),
        importable: created + updated,
    };
}

/** The rows the filter shows: all, or one status. */
export function filterRows(rows = [], status = '') {
    return status ? rows.filter((row) => row.status === status) : rows;
}

/** Each status with its count, only those present, in IMPORT_STATUSES order. */
export function statusCounts(rows = []) {
    return IMPORT_STATUSES
        .map((status) => ({ status, count: rows.filter((row) => row.status === status).length }))
        .filter((entry) => entry.count > 0);
}

/**
 * One change to an existing supplier, as a line: «Leverandørnavn: Drift AS → Drift Norge AS». The
 * category is shown by its label; an empty value as «(tom)».
 */
export function changeLine(change, ti = {}, categoryLabel = (value) => value) {
    const field = change.field === 'owner_user_id' ? 'owner' : change.field;
    const label = ti.columns?.[field] ?? field;
    const empty = ti.review?.empty_value ?? '(tom)';
    const show = (value) => {
        if (value === null || value === undefined || value === '') {
            return empty;
        }

        return change.field === 'category' ? categoryLabel(value) : String(value);
    };
    const template = ti.review?.change_line ?? ':field: :from → :to';

    return template.replace(':field', label).replace(':from', show(change.from)).replace(':to', show(change.to));
}

/** A translation with :placeholders filled in. */
export function fill(template = '', values = {}) {
    return Object.entries(values).reduce((text, [key, value]) => text.split(`:${key}`).join(String(value)), template);
}
