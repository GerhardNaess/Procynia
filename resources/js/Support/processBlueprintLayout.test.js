import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import {
    METRICS,
    edgePath,
    flowReadingOrder,
    layoutBlueprint,
    nodeShape,
    wrapLabel,
} from './processBlueprintLayout.js';

/**
 * What these tests defend is the claim the whole feature rests on: the blueprint is the source of
 * truth, and the diagram is a function of it.
 *
 * That claim is only worth anything if the function is total and stable — the same blueprint has to
 * produce the same geometry, a branch has to land after what it branches from, and a payload with a
 * loop in it must not hang. Those are the properties asserted here. How the picture looks is not;
 * that is a judgement, and pinning pixel values would make every visual improvement a test failure.
 */

/**
 * A flow with everything that makes a flow hard: four lanes, a branch with named outcomes, an
 * escalation into another lane, and two paths that join again before the end. Trimmed from the
 * Incident Management example the generator seeds.
 */
const INCIDENT = {
    lanes: [
        { key: 'sluttbruker', label: 'Sluttbruker' },
        { key: 'servicedesk', label: 'Servicedesk (1. linje)' },
        { key: 'fagansvarlig', label: 'Fagansvarlig (2. linje)' },
    ],
    nodes: [
        { key: 'melding', lane: 'sluttbruker', type: 'start', label: 'Hendelse meldes inn' },
        { key: 'registrer', lane: 'servicedesk', type: 'step', label: 'Registrer og kategoriser' },
        { key: 'lost', lane: 'servicedesk', type: 'decision', label: 'Løst i 1. linje?' },
        { key: 'eskaler', lane: 'fagansvarlig', type: 'step', label: 'Eskaler til 2. linje' },
        { key: 'verifiser', lane: 'sluttbruker', type: 'step', label: 'Bekreft at tjenesten virker' },
        { key: 'lukket', lane: 'servicedesk', type: 'end', label: 'Hendelsen er lukket' },
    ],
    edges: [
        { from: 'melding', to: 'registrer', label: null },
        { from: 'registrer', to: 'lost', label: null },
        { from: 'lost', to: 'verifiser', label: 'Ja' },
        { from: 'lost', to: 'eskaler', label: 'Nei' },
        { from: 'eskaler', to: 'verifiser', label: null },
        { from: 'verifiser', to: 'lukket', label: null },
    ],
};

function nodeByKey(layout, key) {
    return layout.nodes.find((node) => node.key === key);
}

describe('layoutBlueprint', () => {
    test('the same blueprint always produces the same geometry', () => {
        assert.deepEqual(layoutBlueprint(INCIDENT), layoutBlueprint(INCIDENT));
    });

    test('it does not mutate the blueprint it was given', () => {
        const before = JSON.stringify(INCIDENT);

        layoutBlueprint(INCIDENT);

        assert.equal(JSON.stringify(INCIDENT), before);
    });

    test('a node sits one column right of whatever leads to it', () => {
        const layout = layoutBlueprint(INCIDENT);

        assert.equal(nodeByKey(layout, 'melding').rank, 0);
        assert.equal(nodeByKey(layout, 'registrer').rank, 1);
        assert.equal(nodeByKey(layout, 'lost').rank, 2);
        assert.equal(nodeByKey(layout, 'eskaler').rank, 3);
    });

    /**
     * The reason columns are a longest path and not a breadth-first level. `verifiser` is reachable
     * from the decision in one hop and from the escalation in two; placing it at the shorter of the
     * two would draw the escalation arrow backwards through the diagram.
     */
    test('a join lands after the longest of the branches that reach it', () => {
        const layout = layoutBlueprint(INCIDENT);

        const escalate = nodeByKey(layout, 'eskaler');
        const join = nodeByKey(layout, 'verifiser');

        assert.equal(join.rank, escalate.rank + 1);
        assert.ok(join.x > escalate.x);
    });

    test('a node is drawn inside its own lane', () => {
        const layout = layoutBlueprint(INCIDENT);

        for (const node of layout.nodes) {
            const lane = layout.lanes.find((candidate) => candidate.key === node.lane);

            assert.ok(node.y >= lane.y, `${node.key} starts above its lane`);
            assert.ok(node.y + node.height <= lane.y + lane.height, `${node.key} overflows its lane`);
        }
    });

    test('lanes are stacked in the order they are declared, with no gap and no overlap', () => {
        const layout = layoutBlueprint(INCIDENT);

        assert.deepEqual(layout.lanes.map((lane) => lane.key), INCIDENT.lanes.map((lane) => lane.key));

        for (let i = 1; i < layout.lanes.length; i += 1) {
            const previous = layout.lanes[i - 1];

            assert.equal(layout.lanes[i].y, previous.y + previous.height);
        }
    });

    test('two nodes in the same lane and column are stacked rather than drawn on top of each other', () => {
        const layout = layoutBlueprint({
            lanes: [{ key: 'a', label: 'A' }],
            nodes: [
                { key: 'start', lane: 'a', type: 'start', label: 'Start' },
                { key: 'one', lane: 'a', type: 'step', label: 'Parallelt A' },
                { key: 'two', lane: 'a', type: 'step', label: 'Parallelt B' },
            ],
            edges: [
                { from: 'start', to: 'one', label: null },
                { from: 'start', to: 'two', label: null },
            ],
        });

        const one = nodeByKey(layout, 'one');
        const two = nodeByKey(layout, 'two');

        assert.equal(one.rank, two.rank);
        assert.notEqual(one.y, two.y);
        assert.ok(Math.abs(one.y - two.y) >= METRICS.nodeHeight);
        assert.ok(layout.lanes[0].height >= METRICS.nodeHeight * 2);
    });

    /**
     * A rework loop — "ikke godkjent, tilbake til steg 2" — is a real and common shape. The layout
     * must terminate on it and must mark the arrow as going backwards, because an arrow that
     * returns is a different statement from one that moves on.
     */
    test('a loop back to an earlier step terminates and is routed as a back edge', () => {
        const layout = layoutBlueprint({
            lanes: [{ key: 'a', label: 'A' }],
            nodes: [
                { key: 'utfor', lane: 'a', type: 'step', label: 'Utfør arbeidet' },
                { key: 'godkjent', lane: 'a', type: 'decision', label: 'Godkjent?' },
                { key: 'ferdig', lane: 'a', type: 'end', label: 'Ferdig' },
            ],
            edges: [
                { from: 'utfor', to: 'godkjent', label: null },
                { from: 'godkjent', to: 'ferdig', label: 'Ja' },
                { from: 'godkjent', to: 'utfor', label: 'Nei' },
            ],
        });

        const back = layout.edges.find((edge) => edge.from === 'godkjent' && edge.to === 'utfor');

        assert.equal(back.isBackward, true);
        assert.ok(back.points.length === 4);
        // It travels below both nodes rather than straight through them.
        assert.ok(back.points[1].y > nodeByKey(layout, 'utfor').y + METRICS.nodeHeight);
    });

    test('every edge is orthogonal — each segment runs either horizontally or vertically', () => {
        const layout = layoutBlueprint(INCIDENT);

        for (const edge of layout.edges) {
            for (let i = 1; i < edge.points.length; i += 1) {
                const a = edge.points[i - 1];
                const b = edge.points[i];

                assert.ok(
                    a.x === b.x || a.y === b.y,
                    `${edge.from}->${edge.to} has a diagonal segment`,
                );
            }
        }
    });

    test('a forward edge starts at its source and ends at its target', () => {
        const layout = layoutBlueprint(INCIDENT);
        const edge = layout.edges.find((candidate) => candidate.from === 'melding');

        const from = nodeByKey(layout, 'melding');
        const to = nodeByKey(layout, 'registrer');

        assert.deepEqual(edge.points[0], { x: from.x + from.width, y: from.y + (from.height / 2) });
        assert.deepEqual(edge.points.at(-1), { x: to.x, y: to.y + (to.height / 2) });
    });

    test('branch outcomes survive onto the drawn edges', () => {
        const layout = layoutBlueprint(INCIDENT);

        const labels = layout.edges
            .filter((edge) => edge.from === 'lost')
            .map((edge) => edge.label)
            .sort();

        assert.deepEqual(labels, ['Ja', 'Nei']);
    });

    /**
     * The renderer has no error handling of its own — see the module docblock. A payload that still
     * dangles has to shrink the diagram, never break it.
     */
    test('a node in a lane that does not exist is dropped rather than drawn nowhere', () => {
        const layout = layoutBlueprint({
            lanes: [{ key: 'a', label: 'A' }],
            nodes: [
                { key: 'ok', lane: 'a', type: 'step', label: 'Tegnes' },
                { key: 'orphan', lane: 'ghost', type: 'step', label: 'Tegnes ikke' },
            ],
            edges: [{ from: 'ok', to: 'orphan', label: null }],
        });

        assert.deepEqual(layout.nodes.map((node) => node.key), ['ok']);
        assert.deepEqual(layout.edges, []);
    });

    test('an empty blueprint reports itself empty instead of producing a zero-size canvas', () => {
        assert.equal(layoutBlueprint({ lanes: [], nodes: [], edges: [] }).isEmpty, true);
        assert.equal(layoutBlueprint(null).isEmpty, true);
    });

    test('the canvas is wide and tall enough for everything it contains', () => {
        const layout = layoutBlueprint(INCIDENT);

        for (const node of layout.nodes) {
            assert.ok(node.x + node.width <= layout.width);
            assert.ok(node.y + node.height <= layout.height);
        }

        assert.ok(layout.nodes.every((node) => node.x >= METRICS.laneLabelWidth));
    });
});

describe('wrapLabel', () => {
    test('a short label stays on one line', () => {
        assert.deepEqual(wrapLabel('Løst i 1. linje?'), ['Løst i 1. linje?']);
    });

    test('a long label wraps on word boundaries', () => {
        const lines = wrapLabel('Sett prioritet ut fra konsekvens og hastegrad');

        assert.ok(lines.length > 1);
        assert.ok(lines.every((line) => line.length <= METRICS.maxLabelChars));
        assert.equal(lines.join(' '), 'Sett prioritet ut fra konsekvens og hastegrad');
    });

    test('a label too long for the box is truncated rather than allowed to overflow', () => {
        const lines = wrapLabel(
            'ett to tre fire fem seks sju atte ni ti elleve tolv tretten fjorten femten seksten sytten atten',
        );

        assert.equal(lines.length, METRICS.maxLabelLines);
        assert.ok(lines.at(-1).endsWith('…'));
    });

    test('a single unbreakable word is cut to the line width', () => {
        const lines = wrapLabel('Avviksbehandlingsrutinedokumentasjonen');

        assert.equal(lines.length, 1);
        assert.ok(lines[0].length <= METRICS.maxLabelChars);
    });
});

describe('nodeShape', () => {
    const base = { x: 10, y: 20, width: 190, height: 64 };

    test('start and end are pills, so the flow has visible ends', () => {
        for (const type of ['start', 'end']) {
            const shape = nodeShape({ ...base, type });

            assert.equal(shape.kind, 'rect');
            assert.equal(shape.rx, base.height / 2);
        }
    });

    test('a decision is a different shape from a step, not just a different colour', () => {
        const decision = nodeShape({ ...base, type: 'decision' });
        const step = nodeShape({ ...base, type: 'step' });

        assert.equal(decision.kind, 'polygon');
        assert.equal(step.kind, 'rect');
    });

    test('a decision polygon stays inside the node box', () => {
        const shape = nodeShape({ ...base, type: 'decision' });

        for (const point of shape.points) {
            assert.ok(point.x >= base.x && point.x <= base.x + base.width);
            assert.ok(point.y >= base.y && point.y <= base.y + base.height);
        }
    });
});

describe('edgePath', () => {
    test('a straight edge is a straight line', () => {
        assert.equal(edgePath([{ x: 0, y: 0 }, { x: 50, y: 0 }]), 'M 0 0 L 50 0');
    });

    /**
     * The arrowhead is a marker on the end of the path, so rounding the corners must never move the
     * last point — the arrow would stop short of the node it points at.
     */
    test('rounding the corners leaves the endpoints exactly where the layout put them', () => {
        const points = [{ x: 0, y: 0 }, { x: 40, y: 0 }, { x: 40, y: 80 }, { x: 120, y: 80 }];
        const d = edgePath(points);

        assert.ok(d.startsWith('M 0 0'));
        assert.ok(d.endsWith('L 120 80'));
        assert.ok(d.includes('Q'));
    });

    test('a degenerate path renders nothing rather than invalid SVG', () => {
        assert.equal(edgePath([]), '');
        assert.equal(edgePath([{ x: 1, y: 1 }]), '');
    });
});

describe('flowReadingOrder', () => {
    const supplierFlow = {
        lanes: [
            { key: 'innkjoper', label: 'Innkjøper' },
            { key: 'sikkerhet', label: 'Sikkerhetsansvarlig' },
            { key: 'okonomi', label: 'Økonomi' },
        ],
        nodes: [
            { key: 'start', lane: 'innkjoper', type: 'start', label: 'Ny leverandør skal opprettes' },
            { key: 'registrer', lane: 'innkjoper', type: 'step', label: 'Registrer leverandøren' },
            { key: 'kritisk', lane: 'innkjoper', type: 'decision', label: 'Er leverandøren kritisk?' },
            { key: 'kontroller', lane: 'sikkerhet', type: 'step', label: 'Kontroller leverandøren' },
            { key: 'godkjenn', lane: 'okonomi', type: 'step', label: 'Godkjenn leverandøren' },
            { key: 'slutt', lane: 'okonomi', type: 'end', label: 'Leverandøren er godkjent' },
        ],
        edges: [
            { from: 'start', to: 'registrer', label: null },
            { from: 'registrer', to: 'kritisk', label: null },
            { from: 'kritisk', to: 'kontroller', label: 'Ja' },
            { from: 'kritisk', to: 'godkjenn', label: 'Nei' },
            { from: 'kontroller', to: 'godkjenn', label: null },
            { from: 'godkjenn', to: 'slutt', label: null },
        ],
    };

    /**
     * The list and the diagram are two views of one model. If they could disagree about the order,
     * a reader checking one against the other would have no way to tell which was wrong — so the
     * list is ordered by the geometry the diagram is drawn from, not by payload order.
     */
    test('the list reads in the order the diagram does', () => {
        assert.deepEqual(
            flowReadingOrder(supplierFlow).map((step) => step.key),
            ['start', 'registrer', 'kritisk', 'kontroller', 'godkjenn', 'slutt'],
        );
    });

    test('a step carries the role whose lane it sits in', () => {
        const byKey = new Map(flowReadingOrder(supplierFlow).map((step) => [step.key, step]));

        assert.equal(byKey.get('kontroller').role, 'Sikkerhetsansvarlig');
        assert.equal(byKey.get('godkjenn').role, 'Økonomi');
    });

    /**
     * "Er leverandøren kritisk?" is unreadable as a list item until its answers are beside it —
     * the branch is the one thing a flat list would otherwise lose.
     */
    test('a decision carries its named outcomes', () => {
        const decision = flowReadingOrder(supplierFlow).find((step) => step.type === 'decision');

        assert.deepEqual(decision.outcomes, ['Ja', 'Nei']);
    });

    test('an ordinary step has no outcomes to show', () => {
        const step = flowReadingOrder(supplierFlow).find((node) => node.key === 'registrer');

        assert.deepEqual(step.outcomes, []);
    });

    test('a payload with nothing drawable in it produces an empty list rather than throwing', () => {
        assert.deepEqual(flowReadingOrder({ lanes: [], nodes: [], edges: [] }), []);
        assert.deepEqual(flowReadingOrder(null), []);
    });

    /** A rework loop is an ordinary process; the reading order must terminate on one. */
    test('a flow that loops back still produces every step exactly once', () => {
        const steps = flowReadingOrder({
            lanes: [{ key: 'l', label: 'Rolle' }],
            nodes: [
                { key: 'start', lane: 'l', type: 'start', label: 'Start' },
                { key: 'utfor', lane: 'l', type: 'step', label: 'Utfør arbeidet' },
                { key: 'kontroll', lane: 'l', type: 'decision', label: 'Godkjent?' },
                { key: 'slutt', lane: 'l', type: 'end', label: 'Ferdig' },
            ],
            edges: [
                { from: 'start', to: 'utfor', label: null },
                { from: 'utfor', to: 'kontroll', label: null },
                { from: 'kontroll', to: 'slutt', label: 'Ja' },
                { from: 'kontroll', to: 'utfor', label: 'Nei' },
            ],
        });

        assert.deepEqual(steps.map((step) => step.key), ['start', 'utfor', 'kontroll', 'slutt']);
    });
});

/**
 * A step may stand for another process. The layout's whole job is to decide where a node sits, and
 * nothing about a subprocess changes that — so what is asserted here is that the reference survives
 * the trip untouched and that it changes no geometry. The diagram draws the indicator from it; the
 * layout only has to hand it over.
 */
describe('a step that stands for another process', () => {
    const withSubprocess = {
        lanes: [{ key: 'l', label: 'Innkjøper' }],
        nodes: [
            { key: 'start', lane: 'l', type: 'start', label: 'Behov meldes' },
            {
                key: 'vurder',
                lane: 'l',
                type: 'step',
                label: 'Vurder leverandøren',
                subprocess_quality_item_id: 42,
                subprocess: { id: 42, title: 'Leverandørkontroll', code: 'P-04', step_count: 6 },
            },
            { key: 'slutt', lane: 'l', type: 'end', label: 'Bestilt' },
        ],
        edges: [
            { from: 'start', to: 'vurder', label: null },
            { from: 'vurder', to: 'slutt', label: null },
        ],
    };

    test('the reference reaches the drawing unchanged', () => {
        const node = layoutBlueprint(withSubprocess).nodes.find((row) => row.key === 'vurder');

        assert.deepEqual(node.subprocess, {
            id: 42,
            title: 'Leverandørkontroll',
            code: 'P-04',
            step_count: 6,
        });
    });

    test('a step that stands for nothing else says so rather than leaving it undefined', () => {
        const node = layoutBlueprint(withSubprocess).nodes.find((row) => row.key === 'start');

        assert.equal(node.subprocess, null);
    });

    /** The indicator is drawn from the node, so it has to be on the step list's rows as well. */
    test('the reading order carries it too', () => {
        const step = flowReadingOrder(withSubprocess).find((row) => row.key === 'vurder');

        assert.equal(step.subprocess.title, 'Leverandørkontroll');
        assert.equal(flowReadingOrder(withSubprocess).find((row) => row.key === 'slutt').subprocess, null);
    });

    /**
     * The reference must not be able to move anything. A reader comparing a flow before and after a
     * subprocess was attached should see the same picture with one more mark on it.
     */
    test('attaching one moves nothing', () => {
        const plain = layoutBlueprint({
            ...withSubprocess,
            nodes: withSubprocess.nodes.map(({ subprocess, subprocess_quality_item_id: _id, ...node }) => node),
        });
        const linked = layoutBlueprint(withSubprocess);

        assert.equal(linked.width, plain.width);
        assert.equal(linked.height, plain.height);
        assert.deepEqual(
            linked.nodes.map((node) => [node.key, node.x, node.y]),
            plain.nodes.map((node) => [node.key, node.x, node.y]),
        );
    });
});

/**
 * An activity may rest on knowledge written down in Wiki. Exactly as with a subprocess, the layout
 * only has to hand the reference over untouched and move nothing: what the indicator says is the
 * diagram's business, and what the page says is Wiki's.
 */
describe('an activity that rests on knowledge in Wiki', () => {
    const withKnowledge = {
        lanes: [{ key: 'l', label: 'Innkjøper' }],
        nodes: [
            { key: 'start', lane: 'l', type: 'start', label: 'Behov meldes' },
            {
                key: 'vurder',
                lane: 'l',
                type: 'step',
                label: 'Vurder leverandøren',
                knowledge_page_ids: [7, 9],
                knowledge: [
                    { page_id: 7, title: 'Anskaffelsesrutine', url: '/app/wiki/anskaffelsesrutine' },
                    { page_id: 9, title: 'Terskelverdier', url: '/app/wiki/terskelverdier' },
                ],
            },
            { key: 'slutt', lane: 'l', type: 'end', label: 'Bestilt' },
        ],
        edges: [
            { from: 'start', to: 'vurder', label: null },
            { from: 'vurder', to: 'slutt', label: null },
        ],
    };

    test('the connections reach the drawing unchanged', () => {
        const node = layoutBlueprint(withKnowledge).nodes.find((row) => row.key === 'vurder');

        assert.deepEqual(node.knowledge.map((page) => page.title), ['Anskaffelsesrutine', 'Terskelverdier']);
    });

    test('an activity that rests on nothing written down carries an empty list, never undefined', () => {
        const node = layoutBlueprint(withKnowledge).nodes.find((row) => row.key === 'start');

        assert.deepEqual(node.knowledge, []);
    });

    /** The indicator is drawn from the node, so the step list's rows need it too. */
    test('the reading order carries them too', () => {
        const steps = flowReadingOrder(withKnowledge);

        assert.equal(steps.find((row) => row.key === 'vurder').knowledge.length, 2);
        assert.deepEqual(steps.find((row) => row.key === 'slutt').knowledge, []);
    });

    test('connecting knowledge moves nothing', () => {
        const plain = layoutBlueprint({
            ...withKnowledge,
            nodes: withKnowledge.nodes.map(({ knowledge, knowledge_page_ids: _ids, ...node }) => node),
        });
        const linked = layoutBlueprint(withKnowledge);

        assert.equal(linked.width, plain.width);
        assert.equal(linked.height, plain.height);
        assert.deepEqual(
            linked.nodes.map((node) => [node.key, node.x, node.y]),
            plain.nodes.map((node) => [node.key, node.x, node.y]),
        );
    });
});
