import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { isEditableStep, stepEditIsValid, withStepEdited } from './processStepEdit.js';

const lanes = [
    { key: 'buyer', label: 'Innkjøper' },
    { key: 'finance', label: 'Økonomi' },
];

const nodes = [
    { key: 'start', lane: 'buyer', type: 'start', label: 'Behov meldt' },
    { key: 'register', lane: 'buyer', type: 'step', label: 'Registrer leverandør', description: 'I ERP', subprocess_quality_item_id: 7 },
    { key: 'critical', lane: 'buyer', type: 'decision', label: 'Kritisk?' },
    { key: 'end', lane: 'finance', type: 'end', label: 'Godkjent' },
];

describe('which nodes open for editing from the diagram', () => {
    test('steps and decisions do, start and end do not', () => {
        assert.deepEqual(nodes.map(isEditableStep), [false, true, true, false]);
    });

    test('a node without a type is a step, as everywhere else', () => {
        assert.equal(isEditableStep({ key: 'x' }), true);
        assert.equal(isEditableStep(null), false);
    });
});

describe('editing one step', () => {
    test('changes text and role of that step and nothing else', () => {
        const edited = withStepEdited(nodes, 'register', { label: '  Opprett leverandør ', lane: 'finance' });

        assert.deepEqual(edited[1], {
            key: 'register',
            lane: 'finance',
            type: 'step',
            label: 'Opprett leverandør',
            description: 'I ERP',
            subprocess_quality_item_id: 7,
        });
        assert.deepEqual([edited[0], edited[2], edited[3]], [nodes[0], nodes[2], nodes[3]]);
    });

    test('does not change the input', () => {
        withStepEdited(nodes, 'register', { label: 'Ny', lane: 'finance' });

        assert.equal(nodes[1].label, 'Registrer leverandør');
    });

    test('an empty text or an unknown role cannot be saved', () => {
        assert.equal(stepEditIsValid({ label: 'Ny', lane: 'finance' }, lanes), true);
        assert.equal(stepEditIsValid({ label: '   ', lane: 'finance' }, lanes), false);
        assert.equal(stepEditIsValid({ label: 'Ny', lane: 'legal' }, lanes), false);
    });
});
