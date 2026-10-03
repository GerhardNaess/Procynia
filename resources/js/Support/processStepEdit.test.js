import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    canInsertStepOn,
    freshStepKey,
    insertAnchor,
    isEditableStep,
    stepEditIsValid,
    withStepEdited,
    withStepInserted,
} from './processStepEdit.js';

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

describe('adding an activity on an arrow', () => {
    const edges = [
        { from: 'start', to: 'register', label: null },
        { from: 'register', to: 'critical', label: null },
        { from: 'critical', to: 'end', label: 'Ja' },
    ];

    test('only arrows that do not leave a decision take a new step', () => {
        assert.deepEqual(edges.map((edge) => canInsertStepOn(edge, nodes)), [true, true, false]);
        assert.equal(canInsertStepOn({ from: 'register', to: 'gone' }, nodes), false);
    });

    test('a fresh key is free and slug-shaped', () => {
        const key = freshStepKey([...nodes, { key: 'step-5' }]);

        assert.match(key, /^step-\d+$/);
        assert.equal([...nodes, { key: 'step-5' }].some((node) => node.key === key), false);
    });

    test('replaces from → to with from → new → to, in place', () => {
        const result = withStepInserted({ nodes, edges }, edges[1], { key: 'check', label: ' Kontroller ', lane: 'finance' });

        assert.deepEqual(result.nodes.map((node) => node.key), ['start', 'register', 'check', 'critical', 'end']);
        assert.deepEqual(result.nodes[2], { key: 'check', lane: 'finance', type: 'step', label: 'Kontroller', description: null });
        assert.deepEqual(result.edges, [
            edges[0],
            { from: 'register', to: 'check', label: null },
            { from: 'check', to: 'critical', label: null },
            edges[2],
        ]);
    });

    test('keeps a label on the leg out of the step it described, and leaves the input alone', () => {
        const labelled = [{ from: 'start', to: 'register', label: 'Ny' }];
        const result = withStepInserted({ nodes, edges: labelled }, labelled[0], { key: 'x', label: 'X', lane: 'buyer' });

        assert.deepEqual(result.edges, [
            { from: 'start', to: 'x', label: 'Ny' },
            { from: 'x', to: 'register', label: null },
        ]);
        assert.equal(labelled.length, 1);
        assert.equal(nodes.length, 4);
    });

    test('the "+" sits halfway along the arrow as routed', () => {
        assert.deepEqual(insertAnchor([{ x: 0, y: 0 }, { x: 60, y: 0 }]), { x: 30, y: 0 });
        assert.deepEqual(insertAnchor([{ x: 0, y: 0 }, { x: 20, y: 0 }, { x: 20, y: 80 }, { x: 40, y: 80 }]), { x: 20, y: 40 });
        assert.deepEqual(insertAnchor([{ x: 0, y: 0 }, { x: 60, y: 0 }], true), { x: 45, y: 0 });
        assert.equal(insertAnchor([{ x: 0, y: 0 }]), null);
    });
});
