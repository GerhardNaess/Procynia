import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    branchProblems,
    branchTargets,
    canInsertStepOn,
    canMoveStep,
    decisionBranches,
    freshStepKey,
    insertAnchor,
    isEditableStep,
    moveTargets,
    stepBefore,
    stepEditIsValid,
    withStepEdited,
    withDecisionBranches,
    withStepInserted,
    withStepMoved,
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

describe('editing the branches of a decision', () => {
    const flow = [
        { key: 'start', lane: 'buyer', type: 'start', label: 'Behov meldt' },
        { key: 'critical', lane: 'buyer', type: 'decision', label: 'Kritisk?' },
        { key: 'check', lane: 'buyer', type: 'step', label: 'Kontroller' },
        { key: 'end', lane: 'finance', type: 'end', label: 'Godkjent' },
    ];
    const edges = [
        { from: 'start', to: 'critical', label: null },
        { from: 'critical', to: 'check', label: 'Ja' },
        { from: 'check', to: 'end', label: null },
        { from: 'critical', to: 'end', label: 'Nei' },
    ];

    test('reads the arrows out of the decision as branches', () => {
        assert.deepEqual(decisionBranches(edges, 'critical'), [
            { label: 'Ja', to: 'check' },
            { label: 'Nei', to: 'end' },
        ]);
        assert.deepEqual(decisionBranches(edges, 'check'), [{ label: '', to: 'end' }]);
    });

    test('a branch may go to any existing step except the decision itself and the start', () => {
        assert.deepEqual(branchTargets(flow, 'critical').map((node) => node.key), ['check', 'end']);
    });

    test('accepts two named, different branches to existing steps', () => {
        assert.deepEqual(branchProblems(decisionBranches(edges, 'critical'), flow, 'critical'), []);
    });

    test('refuses what the flow validator would refuse, under the same keys', () => {
        assert.deepEqual(branchProblems([{ label: 'Ja', to: 'check' }], flow, 'critical'), ['decision_needs_two_outcomes']);
        assert.deepEqual(branchProblems([{ label: 'Ja', to: 'check' }, { label: '  ', to: 'end' }], flow, 'critical'), ['decision_outcome_unnamed']);
        assert.deepEqual(branchProblems([{ label: 'Ja', to: 'check' }, { label: ' ja', to: 'end' }], flow, 'critical'), ['decision_outcomes_repeat']);
        assert.deepEqual(branchProblems([{ label: 'Ja', to: 'check' }, { label: 'Nei', to: 'critical' }], flow, 'critical'), ['branch_without_target']);
        assert.deepEqual(branchProblems([{ label: 'Ja', to: 'check' }, { label: 'Nei', to: 'start' }], flow, 'critical'), ['branch_without_target']);
        assert.deepEqual(branchProblems([], flow, 'critical'), ['decision_needs_two_outcomes']);
    });

    test('replaces the decision\'s arrows where they stood, and leaves the rest alone', () => {
        const result = withDecisionBranches(edges, 'critical', [
            { label: ' Ja ', to: 'end' },
            { label: 'Nei', to: 'check' },
            { label: 'Vet ikke', to: 'check' },
        ]);

        assert.deepEqual(result, [
            edges[0],
            { from: 'critical', to: 'end', label: 'Ja' },
            { from: 'critical', to: 'check', label: 'Nei' },
            { from: 'critical', to: 'check', label: 'Vet ikke' },
            edges[2],
        ]);
        assert.equal(edges.length, 4);
    });

    test('removing a branch removes only its arrow', () => {
        const result = withDecisionBranches(edges, 'critical', [{ label: 'Nei', to: 'end' }, { label: 'Ja', to: 'check' }]);

        assert.deepEqual(result, [
            edges[0],
            { from: 'critical', to: 'end', label: 'Nei' },
            { from: 'critical', to: 'check', label: 'Ja' },
            edges[2],
        ]);
    });

    test('a decision with no arrows yet gets its branches at the end', () => {
        const result = withDecisionBranches([edges[0]], 'critical', [{ label: 'Ja', to: 'check' }, { label: 'Nei', to: 'end' }]);

        assert.deepEqual(result.map((edge) => edge.to), ['critical', 'check', 'end']);
    });
});

describe('moving an activity', () => {
    const line = {
        nodes: [
            { key: 'start', lane: 'buyer', type: 'start', label: 'Start' },
            { key: 'a', lane: 'buyer', type: 'step', label: 'A' },
            { key: 'b', lane: 'buyer', type: 'step', label: 'B' },
            { key: 'c', lane: 'finance', type: 'step', label: 'C' },
            { key: 'd', lane: 'finance', type: 'step', label: 'D' },
            { key: 'end', lane: 'finance', type: 'end', label: 'Slutt' },
        ],
        edges: [
            { from: 'start', to: 'a', label: null },
            { from: 'a', to: 'b', label: null },
            { from: 'b', to: 'c', label: null },
            { from: 'c', to: 'd', label: null },
            { from: 'd', to: 'end', label: null },
        ],
    };

    const order = (flow) => flow.nodes.map((node) => node.key);
    // Walks the arrows from the start, which is the order the flow actually runs in.
    const run = (flow) => {
        const keys = ['start'];

        while (keys.length <= flow.nodes.length) {
            const next = flow.edges.filter((edge) => edge.from === keys[keys.length - 1]);

            if (next.length !== 1) {
                break;
            }

            keys.push(next[0].to);
        }

        return keys;
    };
    const assertSound = (flow) => {
        const keys = new Set(flow.nodes.map((node) => node.key));
        const pairs = flow.edges.map((edge) => `${edge.from}>${edge.to}`);

        assert.equal(new Set(pairs).size, pairs.length, 'no duplicate arrows');
        assert.ok(flow.edges.every((edge) => keys.has(edge.from) && keys.has(edge.to)), 'no dangling arrows');
        assert.equal(new Set(flow.nodes.map((node) => node.key)).size, flow.nodes.length, 'no duplicate nodes');
    };

    test('a step on the main line moves forward', () => {
        const moved = withStepMoved(line, 'b', 'd');

        assert.deepEqual(run(moved), ['start', 'a', 'c', 'd', 'b', 'end']);
        assert.deepEqual(order(moved), ['start', 'a', 'c', 'd', 'b', 'end']);
        assert.equal(moved.edges.length, line.edges.length);
        assertSound(moved);
    });

    test('a step moves backward, and right after the start', () => {
        assert.deepEqual(run(withStepMoved(line, 'c', 'a')), ['start', 'a', 'c', 'b', 'd', 'end']);
        assert.deepEqual(run(withStepMoved(line, 'd', 'start')), ['start', 'd', 'a', 'b', 'c', 'end']);
        assertSound(withStepMoved(line, 'd', 'start'));
    });

    test('a step moves one place on, past the step after it', () => {
        const moved = withStepMoved(line, 'b', 'c');

        assert.deepEqual(run(moved), ['start', 'a', 'c', 'b', 'd', 'end']);
        assertSound(moved);
    });

    test('keeps the node itself — text, role and everything else — and does not change the input', () => {
        const moved = withStepMoved(line, 'b', 'd');

        assert.deepEqual(moved.nodes.find((node) => node.key === 'b'), line.nodes[2]);
        assert.deepEqual(run(line), ['start', 'a', 'b', 'c', 'd', 'end']);
    });

    test('a step cannot be put after itself or where it already is', () => {
        const targets = moveTargets(line, 'b').map((node) => node.key);

        assert.deepEqual(targets, ['start', 'c', 'd']);
        assert.equal(stepBefore(line, 'b'), 'a');
        assert.equal(withStepMoved(line, 'b', 'a'), null);
        assert.equal(withStepMoved(line, 'b', 'b'), null);
        assert.equal(withStepMoved(line, 'b', 'end'), null);
    });

    test('start and end are not moved', () => {
        assert.equal(canMoveStep(line, 'start'), false);
        assert.equal(canMoveStep(line, 'end'), false);
    });

    const branching = {
        nodes: [
            { key: 'start', lane: 'buyer', type: 'start', label: 'Start' },
            { key: 'a', lane: 'buyer', type: 'step', label: 'A' },
            { key: 'q', lane: 'buyer', type: 'decision', label: 'Kritisk?' },
            { key: 'yes1', lane: 'buyer', type: 'step', label: 'Ja 1' },
            { key: 'yes2', lane: 'buyer', type: 'step', label: 'Ja 2' },
            { key: 'no1', lane: 'finance', type: 'step', label: 'Nei 1' },
            { key: 'merge', lane: 'finance', type: 'step', label: 'Samle' },
            { key: 'z', lane: 'finance', type: 'step', label: 'Z' },
            { key: 'end', lane: 'finance', type: 'end', label: 'Slutt' },
        ],
        edges: [
            { from: 'start', to: 'a', label: null },
            { from: 'a', to: 'q', label: null },
            { from: 'q', to: 'yes1', label: 'Ja' },
            { from: 'q', to: 'no1', label: 'Nei' },
            { from: 'yes1', to: 'yes2', label: null },
            { from: 'yes2', to: 'merge', label: null },
            { from: 'no1', to: 'merge', label: null },
            { from: 'merge', to: 'z', label: null },
            { from: 'z', to: 'end', label: null },
        ],
    };

    test('decisions and steps inside a branch are not moved', () => {
        assert.deepEqual(
            branching.nodes.map((node) => canMoveStep(branching, node.key)),
            [false, true, false, false, false, false, false, true, false],
        );
    });

    test('a step is never put after a decision or into a branch', () => {
        // merge has two arrows in, so it is not movable itself, but it is on the main line.
        assert.deepEqual(moveTargets(branching, 'a').map((node) => node.key), ['merge', 'z']);
        assert.deepEqual(moveTargets(branching, 'z').map((node) => node.key), ['start', 'a']);
    });

    test('moving across a decision leaves its branches as they were', () => {
        const moved = withStepMoved(branching, 'a', 'merge');

        assertSound(moved);
        assert.deepEqual(moved.edges.filter((edge) => edge.from === 'q'), branching.edges.filter((edge) => edge.from === 'q'));
        assert.deepEqual(moved.edges.filter((edge) => edge.to === 'q'), [{ from: 'start', to: 'q', label: null }]);
        assert.deepEqual(moved.edges.filter((edge) => ['merge', 'a'].includes(edge.from)), [
            { from: 'merge', to: 'a', label: null },
            { from: 'a', to: 'z', label: null },
        ]);
        assert.equal(moved.edges.length, branching.edges.length);
    });

    test('a step a loop comes back into is not moved', () => {
        const looping = {
            nodes: [...line.nodes.slice(0, 3), { key: 'q', lane: 'buyer', type: 'decision', label: 'OK?' }, line.nodes[5]],
            edges: [
                { from: 'start', to: 'a', label: null },
                { from: 'a', to: 'b', label: null },
                { from: 'b', to: 'q', label: null },
                { from: 'q', to: 'end', label: 'Ja' },
                { from: 'q', to: 'a', label: 'Nei' },
            ],
        };

        assert.equal(canMoveStep(looping, 'a'), false);
        assert.equal(canMoveStep(looping, 'b'), true);
        assert.deepEqual(moveTargets(looping, 'b').map((node) => node.key), ['start']);
        assert.deepEqual(run(withStepMoved(looping, 'b', 'start')), ['start', 'b', 'a', 'q']);
    });
});
