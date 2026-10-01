/**
 * Focus mode: one page, its neighbours, and nothing else.
 *
 * The full graph answers "what does this wiki look like". Focus answers "what is connected to THIS
 * page" — a different question, served by a different endpoint (/app/wiki/graph-focus, Neo4j-backed)
 * and laid out differently. Everything here is pure so it can be tested without a renderer; the
 * component in WikiGraphFocusView.jsx does nothing but fetch, lay out and draw.
 */

/** Mirrors GraphFocusQuery::MAX_DEPTH — the server rejects anything else, so the UI offers nothing else. */
export const FOCUS_DEPTHS = [1, 2];

/** Mirrors the GraphDirection enum. Order is the order the radio group renders in. */
export const FOCUS_DIRECTIONS = ['both', 'outgoing', 'incoming'];

export const DEFAULT_FOCUS_DEPTH = 1;
export const DEFAULT_FOCUS_DIRECTION = 'both';

/**
 * The request for one focus neighbourhood, or null when there is no page to focus on yet.
 *
 * Depth and direction are normalised here rather than trusted from state: the server validates them
 * again and answers 422, and a 422 caused by our own UI is a bug the user should never have to see.
 */
export function buildFocusUrl({ pageId, depth = DEFAULT_FOCUS_DEPTH, direction = DEFAULT_FOCUS_DIRECTION } = {}) {
    const id = Number.parseInt(pageId, 10);

    if (!Number.isInteger(id) || id < 1) {
        return null;
    }

    const safeDepth = FOCUS_DEPTHS.includes(Number(depth)) ? Number(depth) : DEFAULT_FOCUS_DEPTH;
    const safeDirection = FOCUS_DIRECTIONS.includes(direction) ? direction : DEFAULT_FOCUS_DIRECTION;

    const params = new URLSearchParams({
        page_id: String(id),
        depth: String(safeDepth),
        direction: safeDirection,
    });

    return `/app/wiki/graph-focus?${params.toString()}`;
}

/**
 * Which message the user gets. The three cases are genuinely different situations with different
 * remedies, so they are kept apart rather than collapsed into "noe gikk galt":
 *
 *   503 — the projection is off or unreachable. Nothing the user did; the full graph still works.
 *   422 — the page is unknown, hidden from this viewer, or the parameters are out of range.
 *   403 — no customer context at all.
 */
export function focusErrorFromStatus(status) {
    if (status === 503) {
        return 'unavailable';
    }

    if (status === 422) {
        return 'invalid';
    }

    if (status === 403) {
        return 'forbidden';
    }

    return 'generic';
}

/** The page picker's options, taken from the full graph payload already in memory. */
export function focusPageOptions(nodes = []) {
    return nodes
        .filter((node) => Number.isInteger(Number(node?.page_id)))
        .map((node) => ({
            pageId: Number(node.page_id),
            title: String(node.title ?? '').trim() || `#${node.page_id}`,
        }))
        .sort((a, b) => a.title.localeCompare(b.title, 'no'));
}

/**
 * How many pages a ring can carry before it stops being readable.
 *
 * A page label is drawn horizontally at a fixed pixel size, so what a ring has to give each page is
 * not arc length but HORIZONTAL room — and near the top and bottom of a circle, two neighbouring
 * pages are barely separated horizontally at all. Past roughly ten spokes their labels start running
 * through each other, and no amount of extra radius helps: the camera fits the ring to the viewport,
 * so a bigger ring is simply drawn smaller. Hence a count, not a radius, decides.
 */
export const RING_MAX_SPOKES = 10;

/** The same limit for the second hop, which gets a longer ring and therefore a little more room. */
export const OUTER_RING_MAX_SPOKES = 18;

export const DEFAULT_RING_RADIUS = 160;

/** Vertical distance between two pages in the same column, in layout units. */
export const COLUMN_ROW_PITCH = 48;

/**
 * How much horizontal room each column leaves for the labels of the column inside it, in PIXELS on
 * screen — the unit that matters, since labels do not scale with the camera.
 */
export const COLUMN_LABEL_GAP_PX = 240;

/**
 * The viewport height the column gap is calibrated against. The camera fits the layout's height into
 * the viewport, so a taller layout is drawn at a smaller scale — and a gap expressed in fixed layout
 * units would shrink with it, right when there are the most labels to keep apart. Scaling the gap
 * with the layout's own height instead keeps the ON-SCREEN gap roughly constant as a neighbourhood
 * grows, which is the thing labels actually need.
 */
export const COLUMN_NOMINAL_VIEWPORT_HEIGHT = 640;

/**
 * Which way a page's label is written from its dot.
 *
 * Labels are drawn outward — away from the focus page — so that they lean into the empty space at
 * the edge of the picture rather than across the middle of it, and so that the left-hand side of the
 * layout is not forced to reserve a label's width on BOTH sides of every page.
 */
export function labelSideForX(x) {
    return Number(x) < 0 ? 'left' : 'right';
}

/**
 * Ring or columns. One rule, applied to the two hop counts that can each make a ring unreadable on
 * their own: twelve first-hop pages, or a handful of first-hop pages with forty children between
 * them.
 */
export function focusLayoutStrategy(innerCount, outerCount) {
    return innerCount > RING_MAX_SPOKES || outerCount > OUTER_RING_MAX_SPOKES ? 'columns' : 'ring';
}

/**
 * Where each node goes.
 *
 * A force layout is the right answer for a few hundred nodes with no distinguished member. A focus
 * neighbourhood has neither property: it is small, and one node is the entire point of the view. So
 * the layout states the structure directly, in one of two ways:
 *
 *   RING — focus in the middle, first hop on a ring around it, second hop on an outer ring placed
 *   NEXT TO the first-hop page it hangs off. Reads beautifully while the ring has room.
 *
 *   COLUMNS — focus in the middle, first hop in a column either side of it, second hop in a further
 *   column out, each child in line with its own parent. Chosen once a ring would crowd: a column
 *   spends the viewport's WIDTH on label length and its HEIGHT on the page count, which is exactly
 *   how a horizontal label wants to be laid out, and it keeps working at thirty neighbours where a
 *   ring is already a hairball at fifteen.
 *
 * Both are deterministic by construction, so the same neighbourhood always draws the same way —
 * re-running a physics simulation on every depth change would move pages the user had just located.
 *
 * @returns {Record<string, {x: number, y: number, labelSide: 'left'|'right'}>} keyed by node id
 */
export function focusLayout(nodes = [], edges = [], options = {}) {
    if (nodes.length === 0) {
        return {};
    }

    const focus = nodes.find((node) => node.is_focus) ?? nodes.find((node) => Number(node.depth) === 0) ?? nodes[0];
    const inner = nodes.filter((node) => node !== focus && Number(node.depth) === 1);
    const outer = nodes.filter((node) => node !== focus && Number(node.depth) >= 2);

    const strategy = options.strategy ?? focusLayoutStrategy(inner.length, outer.length);

    return strategy === 'columns'
        ? focusColumnLayout(focus, inner, outer, edges, options)
        : focusRingLayout(focus, inner, outer, edges, options);
}

/**
 * Which first-hop page each second-hop page hangs off. Direction is irrelevant here — the question
 * is only "which part of the picture does this belong near", and an incoming edge answers it just as
 * well as an outgoing one.
 *
 * @param {Set<string>} innerIds
 * @returns {Record<string, string>} child node id → first-hop node id
 */
function parentsByChild(edges = [], innerIds) {
    const parentById = {};

    edges.forEach((edge) => {
        const ends = [edge.source, edge.target];

        ends.forEach((end, index) => {
            const other = ends[1 - index];

            if (parentById[end] === undefined && innerIds.has(other)) {
                parentById[end] = other;
            }
        });
    });

    return parentById;
}

function focusRingLayout(focus, inner, outer, edges, { ringRadius = DEFAULT_RING_RADIUS } = {}) {
    const positions = {};

    positions[focus.id] = { x: 0, y: 0, labelSide: 'right' };

    // Coordinates are mathematical — positive y is UP, which is how Sigma renders them. Starting at
    // +PI/2 therefore puts the first page of the ring directly above the focus page, where a reader
    // looks first; the negative value a screen-coordinate habit suggests would put it at the bottom.
    const START_ANGLE = Math.PI / 2;

    const innerAngles = {};

    inner.forEach((node, index) => {
        const angle = START_ANGLE + (index * 2 * Math.PI) / inner.length;
        const x = Math.cos(angle) * ringRadius;
        innerAngles[node.id] = angle;
        positions[node.id] = { x, y: Math.sin(angle) * ringRadius, labelSide: labelSideForX(x) };
    });

    if (outer.length === 0) {
        return positions;
    }

    const parentById = parentsByChild(edges, new Set(inner.map((node) => node.id)));

    const childrenByParent = {};
    const unattached = [];

    outer.forEach((node) => {
        const parent = parentById[node.id];

        if (parent === undefined) {
            unattached.push(node);

            return;
        }

        (childrenByParent[parent] ??= []).push(node);
    });

    // Each first-hop page owns the slice of the outer ring its own ring position sits in. Slightly
    // narrower than the full slice so two neighbouring families do not run into each other.
    const slice = inner.length > 0 ? ((2 * Math.PI) / inner.length) * 0.85 : 2 * Math.PI;

    Object.entries(childrenByParent).forEach(([parentId, children]) => {
        const centre = innerAngles[parentId];

        children.forEach((node, index) => {
            const offset = children.length === 1
                ? 0
                : -slice / 2 + (index * slice) / (children.length - 1);
            const angle = centre + offset;
            const x = Math.cos(angle) * ringRadius * 2;

            positions[node.id] = { x, y: Math.sin(angle) * ringRadius * 2, labelSide: labelSideForX(x) };
        });
    });

    // Reachable at depth 2 but with no first-hop neighbour in this payload. Should not happen — the
    // server drops nodes whose path back to the focus was filtered away — so this is a safety net,
    // not a layout case: spread them evenly rather than stacking them all at the origin.
    unattached.forEach((node, index) => {
        const angle = START_ANGLE + (index * 2 * Math.PI) / unattached.length;
        const x = Math.cos(angle) * ringRadius * 2.6;

        positions[node.id] = { x, y: Math.sin(angle) * ringRadius * 2.6, labelSide: labelSideForX(x) };
    });

    return positions;
}

/**
 * Focus in the middle, neighbours in columns either side of it.
 *
 * Pages alternate between the two sides in payload order, which keeps the two columns within one row
 * of each other however many neighbours there are — a side chosen by edge direction instead would
 * put all twenty-four of a page's outgoing links in one column twice as tall as the viewport.
 *
 * Each first-hop page is given a block of rows as tall as its own children and sits at the middle of
 * it, so a child is always in line with its parent and an edge between them is a short, nearly
 * horizontal line. That is what replaces the ring's "put the child next to its parent's bearing".
 */
function focusColumnLayout(focus, inner, outer, edges, {
    rowPitch = COLUMN_ROW_PITCH,
    labelGapPx = COLUMN_LABEL_GAP_PX,
    nominalViewportHeight = COLUMN_NOMINAL_VIEWPORT_HEIGHT,
} = {}) {
    const innerIds = new Set(inner.map((node) => node.id));
    const parentById = parentsByChild(edges, innerIds);

    const childrenByParent = {};
    const unattached = [];

    outer.forEach((node) => {
        const parent = parentById[node.id];

        if (parent === undefined) {
            unattached.push(node);

            return;
        }

        (childrenByParent[parent] ??= []).push(node);
    });

    const sides = {
        right: { members: [], parents: [], children: [], rows: 0 },
        left: { members: [], parents: [], children: [], rows: 0 },
    };

    inner.forEach((node, index) => sides[index % 2 === 0 ? 'right' : 'left'].members.push(node));

    Object.values(sides).forEach((side) => {
        side.members.forEach((node) => {
            const children = childrenByParent[node.id] ?? [];
            const span = Math.max(1, children.length);

            children.forEach((child, index) => side.children.push({ node: child, row: side.rows + index }));
            side.parents.push({ node, row: side.rows + (span - 1) / 2 });

            side.rows += span;
        });
    });

    // Reachable at depth 2 but with no first-hop neighbour in this payload. The server should never
    // send one; if it does, it goes below the shorter column rather than on top of the focus page.
    unattached.forEach((node) => {
        const side = sides.right.rows <= sides.left.rows ? sides.right : sides.left;

        side.children.push({ node, row: side.rows });
        side.rows += 1;
    });

    const tallest = Math.max(sides.right.rows, sides.left.rows, 1);
    const contentHeight = Math.max(tallest - 1, 1) * rowPitch;
    const gap = (labelGapPx * Math.max(contentHeight, nominalViewportHeight)) / nominalViewportHeight;

    const positions = { [focus.id]: { x: 0, y: 0, labelSide: 'right' } };

    Object.entries(sides).forEach(([name, side]) => {
        const sign = name === 'right' ? 1 : -1;
        // Positive y is UP in Sigma, so the first row has to be the LARGEST y, not the smallest, or
        // every column reads bottom-to-top against the order its edges were listed in.
        const y = (row) => ((side.rows - 1) / 2 - row) * rowPitch;

        side.parents.forEach(({ node, row }) => {
            positions[node.id] = { x: sign * gap, y: y(row), labelSide: name };
        });

        side.children.forEach(({ node, row }) => {
            positions[node.id] = { x: sign * gap * 2, y: y(row), labelSide: name };
        });
    });

    return positions;
}
