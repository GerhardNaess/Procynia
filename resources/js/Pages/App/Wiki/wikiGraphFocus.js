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
 * Where each node goes.
 *
 * A force layout is the right answer for a few hundred nodes with no distinguished member. A focus
 * neighbourhood has neither property: it is small, and one node is the entire point of the view. So
 * the layout states the structure directly — focus in the middle, first hop on a ring around it,
 * second hop on an outer ring placed NEXT TO the first-hop page it hangs off. That last part is what
 * makes a 2-hop view readable: without it the outer ring is assigned arbitrarily and most edges cross
 * the middle of the picture.
 *
 * Deterministic by construction, so the same neighbourhood always draws the same way — re-running a
 * physics simulation on every depth change would move pages the user had just located.
 *
 * @returns {Record<string, {x: number, y: number}>} keyed by node id
 */
export function focusRadialLayout(nodes = [], edges = [], { ringRadius = 160 } = {}) {
    const positions = {};

    if (nodes.length === 0) {
        return positions;
    }

    const focus = nodes.find((node) => node.is_focus) ?? nodes.find((node) => node.depth === 0) ?? nodes[0];
    const inner = nodes.filter((node) => node !== focus && Number(node.depth) === 1);
    const outer = nodes.filter((node) => node !== focus && Number(node.depth) >= 2);

    positions[focus.id] = { x: 0, y: 0 };

    // Coordinates are mathematical — positive y is UP, which is how Sigma renders them. Starting at
    // +PI/2 therefore puts the first page of the ring directly above the focus page, where a reader
    // looks first; the negative value a screen-coordinate habit suggests would put it at the bottom.
    const START_ANGLE = Math.PI / 2;

    const innerAngles = {};

    inner.forEach((node, index) => {
        const angle = START_ANGLE + (index * 2 * Math.PI) / inner.length;
        innerAngles[node.id] = angle;
        positions[node.id] = {
            x: Math.cos(angle) * ringRadius,
            y: Math.sin(angle) * ringRadius,
        };
    });

    if (outer.length === 0) {
        return positions;
    }

    // Which first-hop page each second-hop page hangs off. Direction is irrelevant here — the
    // question is only "which part of the ring does this belong near".
    const parentById = {};

    edges.forEach((edge) => {
        const ends = [edge.source, edge.target];

        ends.forEach((end, index) => {
            const other = ends[1 - index];

            if (parentById[end] === undefined && innerAngles[other] !== undefined) {
                parentById[end] = other;
            }
        });
    });

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

            positions[node.id] = {
                x: Math.cos(angle) * ringRadius * 2,
                y: Math.sin(angle) * ringRadius * 2,
            };
        });
    });

    // Reachable at depth 2 but with no first-hop neighbour in this payload. Should not happen — the
    // server drops nodes whose path back to the focus was filtered away — so this is a safety net,
    // not a layout case: spread them evenly rather than stacking them all at the origin.
    unattached.forEach((node, index) => {
        const angle = START_ANGLE + (index * 2 * Math.PI) / unattached.length;

        positions[node.id] = {
            x: Math.cos(angle) * ringRadius * 2.6,
            y: Math.sin(angle) * ringRadius * 2.6,
        };
    });

    return positions;
}
