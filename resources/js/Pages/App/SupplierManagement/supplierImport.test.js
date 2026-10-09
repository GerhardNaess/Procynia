import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    IMPORT_STATUSES,
    IMPORT_STATUS_TONES,
    actionLabel,
    changeLine,
    confirmSummary,
    fill,
    filterRows,
    importStep,
    rowAction,
    statusCounts,
    statusLabel,
} from './supplierImport.js';

const rows = [
    { row: 2, status: 'new', can_update: false },
    { row: 3, status: 'existing', can_update: true },
    { row: 4, status: 'existing', can_update: false },
    { row: 5, status: 'error', can_update: false },
    { row: 6, status: 'file_duplicate', can_update: false },
    { row: 7, status: 'possible_duplicate', can_update: false },
];
const summary = { total: 6, new: 1, existing: 2, updatable: 1, error: 1, file_duplicate: 1, possible_duplicate: 1 };

describe('Importer leverandører: steps', () => {
    test('upload without an import, review then confirm while pending, the result once completed', () => {
        assert.equal(importStep(null), 'upload');
        assert.equal(importStep({ status: 'pending' }), 'review');
        assert.equal(importStep({ status: 'pending' }, true), 'confirm');
        assert.equal(importStep({ status: 'completed' }, true), 'result');
    });
});

describe('Importer leverandører: what happens to a row', () => {
    test('only new rows are created, and existing ones are never touched unless chosen', () => {
        assert.deepEqual(rows.map((row) => rowAction(row, false)), ['create', 'skip', 'skip', 'skip', 'skip', 'skip']);
    });

    test('choosing «Oppdater eksisterende» updates only the existing rows that can be updated', () => {
        assert.deepEqual(rows.map((row) => rowAction(row, true)), ['create', 'update', 'skip', 'skip', 'skip', 'skip']);
    });

    test('an error or a duplicate is never created, whatever is chosen', () => {
        for (const status of ['error', 'file_duplicate', 'possible_duplicate']) {
            assert.equal(rowAction({ status, can_update: true }, true), 'skip', status);
        }
    });
});

describe('Importer leverandører: the summary before Bekreft', () => {
    test('without updating: created, existing, errors, and everything else skipped', () => {
        assert.deepEqual(confirmSummary(summary, false), {
            created: 1, existing: 2, updated: 0, updatable: 1, errors: 1, skipped: 5, importable: 1,
        });
    });

    test('with updating: the updatable rows move from skipped to updated', () => {
        assert.deepEqual(confirmSummary(summary, true), {
            created: 1, existing: 2, updated: 1, updatable: 1, errors: 1, skipped: 4, importable: 2,
        });
    });

    test('nothing to import is said, never a confirm of nothing', () => {
        assert.equal(confirmSummary({ total: 2, error: 2 }, true).importable, 0);
        assert.equal(confirmSummary({}).skipped, 0);
    });
});

describe('Importer leverandører: filter and labels', () => {
    test('the filter shows one status, or every row', () => {
        assert.equal(filterRows(rows).length, 6);
        assert.deepEqual(filterRows(rows, 'existing').map((row) => row.row), [3, 4]);
    });

    test('the status counts follow the fixed order and leave out what is absent', () => {
        assert.deepEqual(statusCounts(rows.slice(0, 4)), [
            { status: 'new', count: 1 },
            { status: 'existing', count: 2 },
            { status: 'error', count: 1 },
        ]);
    });

    test('every status has a tone, and a new row never looks like an error', () => {
        for (const status of IMPORT_STATUSES) {
            assert.ok(IMPORT_STATUS_TONES[status], status);
        }

        assert.notEqual(IMPORT_STATUS_TONES.new, IMPORT_STATUS_TONES.error);
    });

    test('labels come from the translations, with Norwegian fallbacks', () => {
        assert.equal(statusLabel('possible_duplicate'), 'Mulig duplikat');
        assert.equal(statusLabel('new', { statuses: { new: 'New supplier' } }), 'New supplier');
        assert.equal(actionLabel('update'), 'Oppdateres');
        assert.equal(actionLabel('skip', { actions: { skip: 'Skipped' } }), 'Skipped');
    });

    test('a change reads as one line, with the column name, the category label and «(tom)» for nothing', () => {
        const ti = { columns: { name: 'Leverandørnavn', owner: 'Intern ansvarlig (e-post)', category: 'Kategori', note: 'Notat' } };

        assert.equal(changeLine({ field: 'name', from: 'Drift AS', to: 'Drift Norge AS' }, ti), 'Leverandørnavn: Drift AS → Drift Norge AS');
        assert.equal(changeLine({ field: 'owner_user_id', from: 'Kari', to: 'Ola' }, ti), 'Intern ansvarlig (e-post): Kari → Ola');
        assert.equal(changeLine({ field: 'category', from: 'other', to: 'it_cloud' }, ti, (value) => ({ other: 'Annet', it_cloud: 'IT og skytjenester' })[value]), 'Kategori: Annet → IT og skytjenester');
        assert.equal(changeLine({ field: 'note', from: null, to: 'Ny' }, ti), 'Notat: (tom) → Ny');
    });

    test('placeholders are filled in, every occurrence', () => {
        assert.equal(fill('Rad :row av :row', { row: 4 }), 'Rad 4 av 4');
    });
});

describe('Importer leverandører: the page', () => {
    const page = readFileSync(new URL('./Import.jsx', import.meta.url), 'utf8');

    test('nothing is imported without the explicit Bekreft step, which sends the preview it was shown', () => {
        assert.match(page, /preview_hash: importData\.preview_hash/);
        assert.match(page, /update_existing: updateExisting/);
        // The update choice starts off.
        assert.match(page, /useState\(false\);\n\s+const step = importStep/);
    });

    test('the register offers the import only to someone who can register suppliers', () => {
        const index = readFileSync(new URL('./Index.jsx', import.meta.url), 'utf8');

        assert.match(index, /\{canEdit && ! creating && \(\n\s+<Link href=\{`\$\{REGISTER_URL\}\/import`\}/);
    });
});
