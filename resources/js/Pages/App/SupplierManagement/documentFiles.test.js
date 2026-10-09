import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileActions, fileSummary } from './supplierManagement.js';
import { snapshotDocument } from './requirementEvaluations.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

describe('Dokumentasjon: one private file per row (supplier-assurance-v2-plan §27)', () => {
    test('the file reads as its server-decided type and a size a person understands', () => {
        assert.equal(fileSummary({ mime_type: 'application/pdf', size_bytes: 1_258_291 }, 'nb-NO'), 'PDF · 1,2 MB');
        assert.equal(fileSummary({ mime_type: 'image/jpeg', size_bytes: 300 }, 'nb-NO'), 'JPG · 1 kB');
        assert.equal(fileSummary({ mime_type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', size_bytes: 40_960 }, 'nb-NO'), 'Excel · 40 kB');
        assert.equal(fileSummary(null), '');
    });

    test('upload without a file, replace and remove with one — none while a control rests on the row or without the right', () => {
        assert.deepEqual(fileActions({ file: null, file_locked: false }, true), { upload: true, replace: false, remove: false });
        assert.deepEqual(fileActions({ file: { name: 'a.pdf' }, file_locked: false }, true), { upload: false, replace: true, remove: true });
        assert.deepEqual(fileActions({ file: { name: 'a.pdf' }, file_locked: true }, true), { upload: false, replace: false, remove: false });
        assert.deepEqual(fileActions({ file: null, file_locked: true }, true), { upload: false, replace: false, remove: false });
        assert.deepEqual(fileActions({ file: { name: 'a.pdf' }, file_locked: false }, false), { upload: false, replace: false, remove: false });
    });

    test('a control shows the file it rested on, by name and checksum, as it was', () => {
        const doc = snapshotDocument({ document_type: 'certificate', title: 'DBA', file_name: 'DBA signert.pdf', file_sha256: 'a'.repeat(64), now: null }, {});
        assert.deepEqual([doc.fileName, doc.fileSha256], ['DBA signert.pdf', 'a'.repeat(64)]);
        assert.equal(snapshotDocument({ document_type: 'certificate', title: 'Uten fil' }, {}).fileName, null);
    });

    test('the page downloads only through the server-given URL, never a storage path, and explains a locked file', () => {
        const page = source('./SupplierDocuments.jsx');
        assert.match(page, /href=\{file\.download_url\}/);
        assert.match(page, /\/documents\/\$\{document\.id\}\/file`/);
        assert.doesNotMatch(page, /file_path|storage\/|customers\//);
        assert.match(page, /data-testid="document-file-locked"/);
        assert.match(page, /type="file"/);
        // Every text in the new parts is at least 16 px: no text-sm or text-xs.
        assert.doesNotMatch(page, /text-(xs|sm)\b/);
    });
});
