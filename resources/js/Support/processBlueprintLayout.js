/**
 * Turns a process blueprint into swimlane geometry.
 *
 * This is the whole reason the diagram is not stored. Same blueprint in, same coordinates out,
 * every time and on every machine — so the picture can never disagree with the structure it claims
 * to show. Nothing here reads state, time, randomness or the DOM, and nothing downstream is allowed
 * to nudge a coordinate afterwards: if a diagram looks wrong, the blueprint is wrong, and that is
 * the only place to fix it.
 *
 * It assumes a closed graph — every node in a lane that exists, every edge between nodes that
 * exist. QualityProcessBlueprintService::normalise() guarantees that on the way into the database,
 * which is why there is no error handling here. Rows that still fail the assumption are dropped
 * rather than drawn, so a hand-made payload degrades to a smaller diagram instead of a crash.
 *
 * The co-ordinate system is SVG's: x grows right, y grows DOWN. Said out loud because the Wiki
 * graph uses Sigma, where y grows up, and a layout written for one renders upside down in the other.
 */

/** Geometry. One place, so a change to the look is a change to one object. */
export const METRICS = {
    /** The band on the left that names the role. Part of the canvas, not a separate column. */
    laneLabelWidth: 168,
    nodeWidth: 190,
    /** Uniform, so a lane's rows stack evenly. Labels wrap to `maxLabelLines` and then truncate. */
    nodeHeight: 64,
    /** Horizontal air between two columns. The arrow between them lives here. */
    columnGap: 60,
    /** Vertical air between two nodes stacked in the same lane at the same column. */
    rowGap: 20,
    /** Air inside a lane, above the first row and below the last. */
    lanePaddingY: 20,
    paddingX: 28,
    paddingY: 16,
    maxLabelLines: 3,
    /** Characters per line before wrapping. A proxy for width — see wrapLabel(). */
    maxLabelChars: 24,
    lineHeight: 15,
    /** How far below the nodes a backwards arrow travels to get home. */
    backEdgeDrop: 26,
};

/**
 * Break a label into the lines the node box can hold.
 *
 * Character counting rather than text measurement, deliberately: measuring needs a DOM, which would
 * make the layout depend on where it runs and stop it being testable. The node box is sized with
 * enough slack for the widest realistic line at this character count.
 *
 * @param {string} label
 * @param {number} [maxChars]
 * @param {number} [maxLines]
 * @returns {string[]}
 */
export function wrapLabel(label, maxChars = METRICS.maxLabelChars, maxLines = METRICS.maxLabelLines) {
    const words = String(label ?? '').trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return [''];
    }

    const lines = [];
    let current = '';

    for (const word of words) {
        const candidate = current === '' ? word : `${current} ${word}`;

        if (candidate.length <= maxChars) {
            current = candidate;
            continue;
        }

        if (current !== '') {
            lines.push(current);
        }

        // A single word longer than the line is cut rather than allowed to overflow the box.
        current = word.length > maxChars ? `${word.slice(0, maxChars - 1)}…` : word;
    }

    if (current !== '') {
        lines.push(current);
    }

    if (lines.length <= maxLines) {
        return lines;
    }

    const kept = lines.slice(0, maxLines);
    kept[maxLines - 1] = `${kept[maxLines - 1].slice(0, maxChars - 1)}…`;

    return kept;
}

/**
 * The edges that close a loop, found before anything is ranked.
 *
 * A rework loop — "ikke godkjent, tilbake til steg 2" — is a normal shape for a process, and it is
 * the one shape that breaks a longest-path layering: the cycle keeps relaxing, every node in it
 * drifts right, and the arrow that was supposed to return ends up pointing forwards. So the
 * returning edge is identified first and left out of the ranking entirely. It is still drawn — see
 * routeEdge() — just not allowed to decide where anything sits.
 *
 * A depth-first walk, iterative because a long process would otherwise be deep enough to matter:
 * an edge into a node that is still open on the current path is the edge that closes the loop.
 * Roots are taken in payload order, entry points first, which is what makes the answer stable for
 * a blueprint with more than one cycle to choose from.
 *
 * @param {Array<{key: string}>} nodes
 * @param {Array<{from: string, to: string}>} edges
 * @returns {Set<number>} indices into `edges`
 */
function findBackEdges(nodes, edges) {
    const outgoing = new Map(nodes.map((node) => [node.key, []]));

    edges.forEach((edge, index) => outgoing.get(edge.from).push(index));

    const OPEN = 1;
    const CLOSED = 2;
    const state = new Map();
    const backEdges = new Set();

    const hasIncoming = new Set(edges.map((edge) => edge.to));
    // Entry points first, then everything else — a flow drawn as nothing but a ring has no entry
    // point, and must still be laid out rather than skipped.
    const roots = [...nodes.filter((node) => ! hasIncoming.has(node.key)), ...nodes];

    for (const root of roots) {
        if (state.has(root.key)) {
            continue;
        }

        state.set(root.key, OPEN);
        const stack = [{ key: root.key, next: 0 }];

        while (stack.length > 0) {
            const frame = stack[stack.length - 1];
            const candidates = outgoing.get(frame.key);

            if (frame.next >= candidates.length) {
                state.set(frame.key, CLOSED);
                stack.pop();
                continue;
            }

            const index = candidates[frame.next];
            frame.next += 1;

            const target = edges[index].to;

            if (state.get(target) === OPEN) {
                backEdges.add(index);
            } else if (! state.has(target)) {
                state.set(target, OPEN);
                stack.push({ key: target, next: 0 });
            }
        }
    }

    return backEdges;
}

/**
 * Which column each node sits in.
 *
 * Longest path from the entry points, relaxed edge by edge: a node sits one column to the right of
 * the latest thing that can lead to it. That is what puts a join — two branches meeting again —
 * after both of its branches rather than overlapping one of them.
 *
 * Loop-closing edges are excluded, so the graph being ranked is always acyclic and the relaxation
 * always settles. The pass cap is belt and braces on top of that.
 *
 * @param {Array<{key: string}>} nodes
 * @param {Array<{from: string, to: string}>} edges
 * @returns {Map<string, number>}
 */
function assignColumns(nodes, edges) {
    const backEdges = findBackEdges(nodes, edges);
    const ranks = new Map(nodes.map((node) => [node.key, 0]));

    for (let pass = 0; pass < nodes.length; pass += 1) {
        let moved = false;

        edges.forEach((edge, index) => {
            if (backEdges.has(index)) {
                return;
            }

            const from = ranks.get(edge.from);
            const to = ranks.get(edge.to);

            if (from === undefined || to === undefined) {
                return;
            }

            if (to < from + 1) {
                ranks.set(edge.to, from + 1);
                moved = true;
            }
        });

        if (! moved) {
            break;
        }
    }

    return ranks;
}

/**
 * The full geometry of one blueprint.
 *
 * @param {{lanes: Array<{key: string, label: string}>, nodes: Array<object>, edges: Array<object>}} blueprint
 */
export function layoutBlueprint(blueprint) {
    const lanes = Array.isArray(blueprint?.lanes) ? blueprint.lanes : [];
    const laneKeys = new Set(lanes.map((lane) => lane.key));

    const nodes = (Array.isArray(blueprint?.nodes) ? blueprint.nodes : [])
        .filter((node) => node && typeof node.key === 'string' && laneKeys.has(node.lane));

    const nodeKeys = new Set(nodes.map((node) => node.key));

    const edges = (Array.isArray(blueprint?.edges) ? blueprint.edges : [])
        .filter((edge) => edge && nodeKeys.has(edge.from) && nodeKeys.has(edge.to));

    if (lanes.length === 0 || nodes.length === 0) {
        return { width: 0, height: 0, lanes: [], nodes: [], edges: [], isEmpty: true };
    }

    const ranks = assignColumns(nodes, edges);

    // Row within the lane. Two nodes of the same lane in the same column have to stack, and the
    // slot is taken in the order the nodes are written — the one thing in the layout that depends
    // on payload order, and it is the order the editor shows, so it is the order the user controls.
    const taken = new Map();
    const slotOf = new Map();
    const laneDepth = new Map(lanes.map((lane) => [lane.key, 1]));

    for (const node of nodes) {
        const cell = `${node.lane}@${ranks.get(node.key)}`;
        const used = taken.get(cell) ?? 0;

        taken.set(cell, used + 1);
        slotOf.set(node.key, used);
        laneDepth.set(node.lane, Math.max(laneDepth.get(node.lane), used + 1));
    }

    const rowPitch = METRICS.nodeHeight + METRICS.rowGap;
    const columnPitch = METRICS.nodeWidth + METRICS.columnGap;

    let cursorY = METRICS.paddingY;
    const laneBoxes = lanes.map((lane, index) => {
        const depth = laneDepth.get(lane.key);
        const height = (depth * METRICS.nodeHeight) + ((depth - 1) * METRICS.rowGap) + (METRICS.lanePaddingY * 2);
        const box = { key: lane.key, label: lane.label, index, y: cursorY, height };

        cursorY += height;

        return box;
    });

    const laneByKey = new Map(laneBoxes.map((lane) => [lane.key, lane]));
    const maxRank = nodes.reduce((max, node) => Math.max(max, ranks.get(node.key)), 0);

    const placedNodes = nodes.map((node) => {
        const lane = laneByKey.get(node.lane);
        const rank = ranks.get(node.key);
        const slot = slotOf.get(node.key);

        return {
            key: node.key,
            label: node.label ?? '',
            lines: wrapLabel(node.label ?? ''),
            description: node.description ?? null,
            type: node.type ?? 'step',
            lane: node.lane,
            rank,
            x: METRICS.laneLabelWidth + METRICS.paddingX + (rank * columnPitch),
            y: lane.y + METRICS.lanePaddingY + (slot * rowPitch),
            width: METRICS.nodeWidth,
            height: METRICS.nodeHeight,
        };
    });

    const placedByKey = new Map(placedNodes.map((node) => [node.key, node]));

    const width = METRICS.laneLabelWidth + (METRICS.paddingX * 2) + (maxRank * columnPitch) + METRICS.nodeWidth;
    const height = cursorY + METRICS.paddingY;

    return {
        width,
        height,
        lanes: laneBoxes,
        nodes: placedNodes,
        edges: edges.map((edge) => routeEdge(edge, placedByKey.get(edge.from), placedByKey.get(edge.to), height)),
        isEmpty: false,
    };
}

/**
 * The path one arrow takes.
 *
 * Orthogonal throughout — a flow diagram is read by following corners, and a diagonal across three
 * lanes is unreadable at the width a process actually has. Three cases, in order of how common they
 * are:
 *
 *   forward, same row   a straight horizontal line
 *   forward, other row  out right, across at the midpoint between the columns, in from the left
 *   backward            down out of the bottom, along a channel under the diagram, up into the top
 *
 * The backward case is what a rework loop looks like — "ikke godkjent, tilbake til steg 2" — and it
 * is routed below everything rather than through it so it cannot be mistaken for a forward step.
 */
function routeEdge(edge, from, to, canvasHeight) {
    const label = edge.label ?? null;

    if (! from || ! to) {
        return { from: edge.from, to: edge.to, label, points: [], labelX: 0, labelY: 0, isBackward: false };
    }

    const isBackward = to.x <= from.x;

    if (! isBackward) {
        const startX = from.x + from.width;
        const startY = from.y + (from.height / 2);
        const endX = to.x;
        const endY = to.y + (to.height / 2);

        if (startY === endY) {
            return {
                from: edge.from,
                to: edge.to,
                label,
                points: [{ x: startX, y: startY }, { x: endX, y: endY }],
                labelX: (startX + endX) / 2,
                labelY: startY - 8,
                isBackward: false,
            };
        }

        const midX = (startX + endX) / 2;

        return {
            from: edge.from,
            to: edge.to,
            label,
            points: [
                { x: startX, y: startY },
                { x: midX, y: startY },
                { x: midX, y: endY },
                { x: endX, y: endY },
            ],
            // On the vertical leg, where there is room — a label on the horizontal leg of a lane
            // change lands on top of whatever else runs between those two columns.
            labelX: midX + 8,
            labelY: (startY + endY) / 2,
            isBackward: false,
        };
    }

    const channelY = Math.min(
        Math.max(from.y + from.height, to.y + to.height) + METRICS.backEdgeDrop,
        canvasHeight - 6,
    );

    const startX = from.x + (from.width / 2);
    const endX = to.x + (to.width / 2);

    return {
        from: edge.from,
        to: edge.to,
        label,
        points: [
            { x: startX, y: from.y + from.height },
            { x: startX, y: channelY },
            { x: endX, y: channelY },
            { x: endX, y: to.y + to.height },
        ],
        labelX: (startX + endX) / 2,
        labelY: channelY - 6,
        isBackward: true,
    };
}

/**
 * The outline of one node, as SVG.
 *
 * Shape carries meaning here and is not decoration: a reader must be able to tell a decision from a
 * step without reading either. Pill = the flow starts or stops, hexagon = it branches, rounded
 * rectangle = work is done.
 *
 * Returned as a shape description rather than markup so the renderer stays the only thing that
 * knows about SVG, and so this is assertable from a test with no DOM.
 */
export function nodeShape(node) {
    const { x, y, width: w, height: h } = node;

    if (node.type === 'start' || node.type === 'end') {
        return { kind: 'rect', x, y, width: w, height: h, rx: h / 2 };
    }

    if (node.type === 'decision') {
        const notch = 22;

        return {
            kind: 'polygon',
            points: [
                { x: x + notch, y },
                { x: x + w - notch, y },
                { x: x + w, y: y + (h / 2) },
                { x: x + w - notch, y: y + h },
                { x: x + notch, y: y + h },
                { x, y: y + (h / 2) },
            ],
        };
    }

    return { kind: 'rect', x, y, width: w, height: h, rx: 12 };
}

/**
 * An edge's points as an SVG path with softened corners.
 *
 * The arrowhead is a marker on the path, so the last point must stay exactly where the geometry put
 * it — the rounding only ever shortens the segments either side of a corner, never the endpoints.
 */
export function edgePath(points, radius = 10) {
    if (! Array.isArray(points) || points.length < 2) {
        return '';
    }

    if (points.length === 2) {
        return `M ${points[0].x} ${points[0].y} L ${points[1].x} ${points[1].y}`;
    }

    let d = `M ${points[0].x} ${points[0].y}`;

    for (let i = 1; i < points.length - 1; i += 1) {
        const previous = points[i - 1];
        const corner = points[i];
        const next = points[i + 1];

        const inLength = Math.hypot(corner.x - previous.x, corner.y - previous.y);
        const outLength = Math.hypot(next.x - corner.x, next.y - corner.y);
        const r = Math.min(radius, inLength / 2, outLength / 2);

        const enterX = corner.x - (Math.sign(corner.x - previous.x) * r);
        const enterY = corner.y - (Math.sign(corner.y - previous.y) * r);
        const exitX = corner.x + (Math.sign(next.x - corner.x) * r);
        const exitY = corner.y + (Math.sign(next.y - corner.y) * r);

        d += ` L ${enterX} ${enterY} Q ${corner.x} ${corner.y} ${exitX} ${exitY}`;
    }

    const last = points[points.length - 1];
    d += ` L ${last.x} ${last.y}`;

    return d;
}

/**
 * The flow as a numbered list, in the order the diagram reads left to right.
 *
 * WHY IT GOES THROUGH THE LAYOUT.
 *
 * The list and the diagram are two views of one model, and the whole point of showing both is that
 * a reader can check one against the other. Ordering the list by anything other than the geometry —
 * payload order, say — would let step 3 in the text sit to the left of step 2 in the picture, and
 * the reader would have to work out which of the two was lying. So the rank the layout already
 * computed is the order, ties broken by lane (top band first) and then by the order the editor
 * shows, which is the order the user controls.
 *
 * Outcomes travel with the step that branches, because "Er leverandøren kritisk?" is only readable
 * as a list item once you can see that its answers are Ja and Nei.
 */
export function flowReadingOrder(blueprint) {
    const { nodes, lanes, isEmpty } = layoutBlueprint(blueprint);

    if (isEmpty) {
        return [];
    }

    const laneOrder = new Map(lanes.map((lane, index) => [lane.key, index]));
    const laneLabel = new Map(lanes.map((lane) => [lane.key, lane.label]));
    const payloadOrder = new Map((blueprint?.nodes ?? []).map((node, index) => [node.key, index]));
    const edges = Array.isArray(blueprint?.edges) ? blueprint.edges : [];

    return [...nodes]
        .sort((a, b) => (
            a.rank - b.rank
            || (laneOrder.get(a.lane) ?? 0) - (laneOrder.get(b.lane) ?? 0)
            || (payloadOrder.get(a.key) ?? 0) - (payloadOrder.get(b.key) ?? 0)
        ))
        .map((node) => ({
            key: node.key,
            type: node.type,
            label: node.label,
            description: node.description,
            role: laneLabel.get(node.lane) ?? '',
            outcomes: edges
                .filter((edge) => edge.from === node.key && (edge.label ?? '') !== '')
                .map((edge) => edge.label),
        }));
}
