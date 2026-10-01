/**
 * Fitting the focus camera around the LABELS, not just the dots.
 *
 * Sigma's own auto-rescale frames the node positions. A label is not at its node's position: it is
 * drawn beside it, at a fixed pixel size that does not shrink when the camera zooms out. So a view
 * that is perfectly framed by node coordinates still cuts the outermost label in half at the left
 * and right edges — which is the one thing the reader was trying to read.
 *
 * The naive fix is to pad the whole viewport by a label's width on every side. That costs the same
 * ~180px at the top and bottom, where nothing needs it, and shrinks the graph for no reason. This
 * module instead takes each node's OWN extent — how far its disc and its label actually reach in
 * each of the four directions, in pixels — and finds the largest scale at which every one of those
 * extents still lands inside the viewport.
 *
 * WHY IT IS A PAIRWISE MINIMUM AND NOT A BOUNDING BOX. Node extents are in pixels and do not scale;
 * node positions do. So the content's width at scale s is not (box width × s) — it is
 * max over pairs of (sΔx + right_i + left_j). Fixing the scale from a box measured at the current
 * zoom would be wrong at the new one, and iterating to a fixed point only approximates what this
 * solves exactly: for every ordered pair, s ≤ (available − right_i − left_j) / Δx. The smallest such
 * bound is the answer, and with a focus neighbourhood's handful of nodes the quadratic cost is
 * nothing.
 *
 * Deliberately separate from wikiGraphFitView.js: that one frames the full graph, where labels are
 * decluttered away by Sigma and a symmetric pad is the right trade. Keeping them apart is what lets
 * the full graph stay exactly as it was.
 */

/** Breathing room at the viewport edge, in pixels. Label widths are already in the extents. */
export const FOCUS_FIT_PADDING_PX = 18;

/** The ratio to settle on when there is no extent to scale against — a single page, with no links. */
export const FOCUS_FIT_SINGLE_POINT_RATIO = 0.6;

/**
 * How far the fit may zoom IN. Without it, a two-node neighbourhood is blown up until its dots fill
 * the screen, which reads as a rendering bug rather than as a small neighbourhood.
 */
export const FOCUS_FIT_MAX_SCALE = 2.5;

/**
 * @typedef {object} FitItem
 * @property {number} x       Node centre, in viewport pixels.
 * @property {number} y       Node centre, in viewport pixels.
 * @property {number} left    How far the drawing reaches left of the centre, in pixels.
 * @property {number} right   …right.
 * @property {number} top     …up.
 * @property {number} bottom  …down.
 */

/**
 * @param {object} params
 * @param {FitItem[]} params.items
 * @param {number} params.width    Viewport width in pixels.
 * @param {number} params.height   Viewport height in pixels.
 * @param {number} params.ratio    The camera's current ratio.
 * @param {number} [params.padding]
 * @param {number} [params.minRatio]
 * @param {number} [params.maxRatio]
 * @returns {?{center: {x: number, y: number}, ratio: number}} null when there is nothing to fit.
 */
export function computeLabelAwareFit({
    items = [],
    width,
    height,
    ratio,
    padding = FOCUS_FIT_PADDING_PX,
    minRatio = null,
    maxRatio = null,
}) {
    const usable = items.filter((item) => Number.isFinite(item?.x) && Number.isFinite(item?.y));

    if (usable.length === 0 || !Number.isFinite(width) || !Number.isFinite(height)
        || width <= 0 || height <= 0 || !Number.isFinite(ratio) || ratio <= 0) {
        return null;
    }

    const extents = usable.map((item) => ({
        x: item.x,
        y: item.y,
        left: positiveOrZero(item.left),
        right: positiveOrZero(item.right),
        top: positiveOrZero(item.top),
        bottom: positiveOrZero(item.bottom),
    }));

    // A viewport narrower than the padding it asks for would leave nothing to fit into.
    const availableWidth = Math.max(width - 2 * padding, width / 2);
    const availableHeight = Math.max(height - 2 * padding, height / 2);

    const unbounded = Math.min(
        axisScale(extents.map((e) => ({ p: e.x, before: e.left, after: e.right })), availableWidth),
        axisScale(extents.map((e) => ({ p: e.y, before: e.top, after: e.bottom })), availableHeight),
    );

    // Every node at the same point — one page, or a layout that collapsed. There is no extent to
    // scale against, so centre on it and settle on a readable zoom rather than dividing by zero.
    if (!Number.isFinite(unbounded) || unbounded <= 0) {
        return {
            center: { x: mean(extents.map((e) => e.x)), y: mean(extents.map((e) => e.y)) },
            ratio: clampRatio(FOCUS_FIT_SINGLE_POINT_RATIO, minRatio, maxRatio),
        };
    }

    const scale = Math.min(unbounded, FOCUS_FIT_MAX_SCALE);

    // The centre is the middle of what is DRAWN, not the middle of the dots: a layout whose labels
    // all point outward from the centre is symmetric, but one with a long title on one side only is
    // not, and centring its dots would leave that title hanging over the edge.
    const center = {
        x: midpoint(extents.map((e) => e.x - e.left / scale), extents.map((e) => e.x + e.right / scale)),
        y: midpoint(extents.map((e) => e.y - e.top / scale), extents.map((e) => e.y + e.bottom / scale)),
    };

    // Sigma's ratio is inverse zoom: showing the content `scale` times larger divides it.
    return { center, ratio: clampRatio(ratio / scale, minRatio, maxRatio) };
}

/**
 * The largest scale at which no two items on this axis overlap the space the other needs, and
 * nothing runs past the available length.
 *
 * @param {Array<{p: number, before: number, after: number}>} items
 * @param {number} available
 * @returns {number} Infinity when no pair constrains the scale at all.
 */
function axisScale(items, available) {
    let scale = Infinity;

    for (let i = 0; i < items.length; i += 1) {
        for (let j = 0; j < items.length; j += 1) {
            const span = items[i].p - items[j].p;

            // Only ordered pairs constrain anything: i must stay right of j by enough room for i's
            // trailing extent and j's leading one. Two items at the same point impose a fixed cost
            // that no scale can change, so they are not a constraint on the scale.
            if (span <= 0) {
                continue;
            }

            // A pair whose two labels are together wider than the viewport leaves no room at all,
            // and no scale can satisfy it. Rather than hand the camera a zero or negative ratio,
            // such a pair is held to a floor: a tenth of the viewport still goes to the distance
            // between the nodes, their labels overlap the edges, and the user pans. On a phone held
            // against a long Norwegian page title that is the honest outcome.
            const room = Math.max(available - items[i].after - items[j].before, available * 0.1);

            scale = Math.min(scale, room / span);
        }
    }

    return scale;
}

function positiveOrZero(value) {
    return Number.isFinite(value) && value > 0 ? value : 0;
}

function mean(values) {
    return values.reduce((total, value) => total + value, 0) / values.length;
}

function midpoint(lows, highs) {
    return (Math.min(...lows) + Math.max(...highs)) / 2;
}

function clampRatio(value, minRatio, maxRatio) {
    let ratio = Number.isFinite(value) && value > 0 ? value : FOCUS_FIT_SINGLE_POINT_RATIO;

    if (Number.isFinite(minRatio)) {
        ratio = Math.max(ratio, minRatio);
    }

    if (Number.isFinite(maxRatio)) {
        ratio = Math.min(ratio, maxRatio);
    }

    return ratio;
}
