import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    displayStatusLabel,
    documentOption,
    evaluationFormData,
    evaluationMissing,
    lastControlText,
    snapshotDocument,
    toggleDocument,
} from './requirementEvaluations.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const tr = {
    control: {
        display_statuses: { renewal_due: 'Må fornyes', not_evaluated: 'Ikke vurdert' },
        last_controlled_by: 'Sist kontrollert :date av :name',
        evaluation: { document_status: 'nå: :status' },
    },
    documents: {
        types: { certificate: 'Sertifikat' },
        statuses: { expired: 'Utløpt', replaced: 'Erstattet', valid: 'Gyldig' },
    },
};

describe('Kontroller krav (supplier-assurance-v2-plan §8, §10.2.1)', () => {
    test('the latest result and when it was controlled; never controlled says Ikke vurdert and nothing else', () => {
        assert.equal(displayStatusLabel('renewal_due', tr), 'Må fornyes');
        assert.equal(displayStatusLabel('acceptance_expired', {}), 'Aksept utløpt');
        assert.equal(lastControlText({ status: 'documented', evaluated_on: '2026-10-08', evaluated_by_name: 'Kari Hansen' }, tr, 'no'), 'Sist kontrollert 8. oktober 2026 av Kari Hansen');
        assert.equal(lastControlText(null, tr), null);
        assert.match(source('./SupplierControlRequirements.jsx'), /displayStatusLabel\(row\.display_status, tr\)/);
    });

    test('the form starts dated today and asks for what the result needs', () => {
        const data = evaluationFormData(7, '2026-10-08');
        assert.deepEqual(data, { requirement_id: '7', status: '', rationale: '', evaluated_on: '2026-10-08', accepted_until: '', document_ids: [] });
        assert.deepEqual(evaluationMissing(data), ['status', 'rationale']);
        // Dokumentert needs a document; the others do not.
        assert.deepEqual(evaluationMissing({ ...data, status: 'documented', rationale: 'Dekker kravet.' }), ['document']);
        assert.deepEqual(evaluationMissing({ ...data, status: 'missing', rationale: 'Ikke mottatt.' }), []);
        // Midlertidig akseptert needs a date.
        assert.deepEqual(evaluationMissing({ ...data, status: 'temporarily_accepted', rationale: 'Under signering.' }), ['accepted_until']);
        // A begrunnelse of spaces is no begrunnelse.
        assert.deepEqual(evaluationMissing({ ...data, status: 'missing', rationale: '   ' }), ['rationale']);
    });

    test('documents are chosen and unchosen, and a row that no longer holds says so', () => {
        assert.deepEqual(toggleDocument([1], 2), [1, 2]);
        assert.deepEqual(toggleDocument([1, 2], 1), [2]);
        assert.deepEqual(documentOption({ document_type: 'certificate', title: 'ISO 27001 2026', standard: 'ISO 27001', status: 'valid' }, tr), { type: 'Sertifikat', title: 'ISO 27001 2026 (ISO 27001)', status: null });
        assert.equal(documentOption({ document_type: 'certificate', title: 'ISO 2024', status: 'expired' }, tr).status, 'nå: Utløpt');
        // The form never edits the documentation itself; without any it sends the person to Dokumentasjon.
        const form = source('./SupplierRequirementEvaluation.jsx');
        assert.match(form, /evaluation-no-documents/);
        assert.doesNotMatch(form, /\/documents\//);
    });

    test('the history shows the snapshot, and what differs now only apart from it', () => {
        const unchanged = snapshotDocument({ document_type: 'certificate', title: 'ISO 27001 2026', valid_until: '2027-01-01', changed_since: false, now: { title: 'ISO 27001 2026', status: 'valid' } }, tr);
        assert.equal(unchanged.now, null);

        const changed = snapshotDocument({
            document_type: 'certificate', title: 'ISO 27001 2026', standard: 'ISO 27001', valid_until: '2027-01-01',
            changed_since: true, now: { title: 'ISO 27001-sertifikat', status: 'expired', valid_until: '2026-10-07' },
        }, tr);
        assert.deepEqual([changed.title, changed.validUntil, changed.changed], ['ISO 27001 2026', '2027-01-01', true]);
        assert.deepEqual(changed.now, { title: 'ISO 27001-sertifikat', status: 'Utløpt', validUntil: '2026-10-07' });

        // Replaced since, with no correction to the row: still told, never folded into the snapshot.
        const replaced = snapshotDocument({ document_type: 'certificate', title: 'ISO 2025', changed_since: false, now: { title: 'ISO 2025', status: 'replaced' } }, tr);
        assert.deepEqual([replaced.changed, replaced.now.status], [false, 'Erstattet']);
        assert.match(source('./SupplierRequirementEvaluation.jsx'), /data-testid="snapshot-now"/);
    });
});
