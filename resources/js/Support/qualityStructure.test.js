import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import {
    candidatesForRelationEnd,
    candidatesForRelationStart,
    itemLabel,
    relationTypeIsUsable,
} from './qualityStructure.js';

/**
 * These helpers exist so the relation form stops offering work the backend would refuse. What is
 * worth holding is the thing two independent type lists got wrong: the ends of a relation are not
 * independent, and `uses` is the proof.
 */

const RELATION_TYPES = [
    {
        key: 'governs',
        pairs: [{ from: 'policy', to: 'process' }],
    },
    {
        key: 'uses',
        pairs: [
            { from: 'procedure', to: 'work_instruction' },
            { from: 'process', to: 'checklist' },
            { from: 'procedure', to: 'checklist' },
        ],
    },
    {
        key: 'verifies',
        pairs: [{ from: 'control', to: 'process' }],
    },
];

const ITEMS = [
    { id: 1, title: 'Innkjøpspolicy', code: 'POL-01', quality_type: 'policy' },
    { id: 2, title: 'Anskaffelsesprosess', code: null, quality_type: 'process' },
    { id: 3, title: 'Leverandørvurdering', code: 'RUT-02', quality_type: 'procedure' },
    { id: 4, title: 'Sjekkliste tilbud', code: null, quality_type: 'checklist' },
    { id: 5, title: 'Signering i ERP', code: null, quality_type: 'work_instruction' },
    { id: 6, title: 'Stikkprøve innkjøp', code: 'K-01', quality_type: 'control' },
];

describe('candidatesForRelationStart', () => {
    test('offers only the types that may begin the relation', () => {
        assert.deepEqual(candidatesForRelationStart(ITEMS, RELATION_TYPES, 'governs').map((i) => i.id), [1]);
        assert.deepEqual(candidatesForRelationStart(ITEMS, RELATION_TYPES, 'verifies').map((i) => i.id), [6]);
    });

    test('returns nothing for a relation type the backend did not ship', () => {
        assert.deepEqual(candidatesForRelationStart(ITEMS, RELATION_TYPES, 'invented'), []);
    });
});

describe('candidatesForRelationEnd', () => {
    test('narrows the end to what the chosen start actually allows', () => {
        // The case independent from/to lists got wrong: a process reaches a work instruction
        // through its procedure, never directly.
        const fromProcess = candidatesForRelationEnd(ITEMS, RELATION_TYPES, 'uses', 'process');
        const fromProcedure = candidatesForRelationEnd(ITEMS, RELATION_TYPES, 'uses', 'procedure');

        assert.deepEqual(fromProcess.map((i) => i.quality_type), ['checklist']);
        assert.deepEqual(fromProcedure.map((i) => i.quality_type), ['checklist', 'work_instruction']);
    });

    test('offers every legal end before a start is chosen', () => {
        const types = candidatesForRelationEnd(ITEMS, RELATION_TYPES, 'uses')
            .map((i) => i.quality_type)
            .sort();

        assert.deepEqual(types, ['checklist', 'work_instruction']);
    });
});

describe('relationTypeIsUsable', () => {
    test('is false when no single legal pair can be filled', () => {
        const onlyPolicies = [ITEMS[0]];

        assert.strictEqual(relationTypeIsUsable(onlyPolicies, RELATION_TYPES, 'governs'), false);
        assert.strictEqual(relationTypeIsUsable(ITEMS, RELATION_TYPES, 'governs'), true);
    });

    test('is false when both ends exist but never in the same pair', () => {
        // A process and a work instruction are both present, and both appear in `uses` — but not
        // together. Checking type membership end by end would wrongly call this usable.
        const processAndInstruction = [ITEMS[1], ITEMS[4]];

        assert.strictEqual(relationTypeIsUsable(processAndInstruction, RELATION_TYPES, 'uses'), false);
    });
});

describe('itemLabel', () => {
    test('leads with the document number when there is one', () => {
        assert.strictEqual(itemLabel(ITEMS[0]), 'POL-01 — Innkjøpspolicy');
        assert.strictEqual(itemLabel(ITEMS[1]), 'Anskaffelsesprosess');
        assert.strictEqual(itemLabel(null), '');
    });
});
