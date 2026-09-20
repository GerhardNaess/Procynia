/**
 * Fitting Grafvisning's camera to the graph it is actually showing.
 *
 * WHY THIS IS NOT camera.animatedReset(). That call sets the camera to Sigma's default state
 * ({x: 0.5, y: 0.5, ratio: 1, angle: 0}) — a fixed framing that knows nothing about where the nodes
 * ended up. It restores the view the graph opened with, which is a different promise from showing
 * the graph: on a three-node layout a node sat outside the viewport both before and after pressing
 * it. "Tilpass visning" has to be computed from the content.
 *
 * THE MATH RUNS IN VIEWPORT PIXELS, deliberately. Sigma's camera→screen transform involves a
 * correction ratio, stage padding and the graph's own dimensions, and re-deriving that inverse here
 * would duplicate internals that are free to change between versions. Instead the caller hands over
 * the nodes' CURRENT pixel positions (via sigma.framedGraphToViewport) and this function answers in
 * the same space: which pixel should become the centre, and how much to scale. The transform is
 * linear, so scaling the current ratio by the measured factor is exact — and it stays correct under
 * rotation, because a rotated layout is already rotated in the pixel positions handed in.
 */

/**
 * Space left between the graph and the viewport edge, in pixels, on every side.
 *
 * Covers two things at once: a node is drawn as a disc around its position rather than at it, and
 * its label extends beyond that. Measuring label boxes would buy very little here — the point is
 * that nothing sits against the edge, not that the fit is tight to the last pixel.
 */
export const FIT_VIEW_PADDING_PX = 56;

/** The ratio to settle on when the extent has no size at all — one node, or several stacked. */
export const FIT_VIEW_SINGLE_POINT_RATIO = 0.5;

/**
 * @param {object} params
 * @param {Array<{x: number, y: number}>} params.points  Visible nodes, in viewport pixels.
 * @param {number} params.width                          Viewport width in pixels.
 * @param {number} params.height                         Viewport height in pixels.
 * @param {number} params.ratio                          The camera's current ratio.
 * @param {number} [params.padding]
 * @param {number} [params.minRatio]                     Sigma's minCameraRatio, if set.
 * @param {number} [params.maxRatio]                     Sigma's maxCameraRatio, if set.
 * @returns {?{center: {x: number, y: number}, ratio: number}} null when there is nothing to fit.
 */
export function computeFitToViewport({
    points = [],
    width,
    height,
    ratio,
    padding = FIT_VIEW_PADDING_PX,
    minRatio = null,
    maxRatio = null,
}) {
    const usable = points.filter((point) => Number.isFinite(point?.x) && Number.isFinite(point?.y));

    // Nothing on screen, or a container that has not been laid out yet: leave the camera alone
    // rather than animate it somewhere arbitrary.
    if (usable.length === 0 || !Number.isFinite(width) || !Number.isFinite(height)
        || width <= 0 || height <= 0 || !Number.isFinite(ratio) || ratio <= 0) {
        return null;
    }

    const xs = usable.map((point) => point.x);
    const ys = usable.map((point) => point.y);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);

    const center = { x: (minX + maxX) / 2, y: (minY + maxY) / 2 };

    // Padding is taken off both sides, but a viewport smaller than the padding it asks for would
    // leave nothing to fit into — fall back to using the whole of it rather than a negative space.
    const availableWidth = Math.max(width - 2 * padding, width / 2);
    const availableHeight = Math.max(height - 2 * padding, height / 2);

    const spanX = maxX - minX;
    const spanY = maxY - minY;

    // One node, or several at the same spot: there is no extent to scale against, so centre it and
    // settle on a readable zoom instead of dividing by zero.
    if (spanX < 1 && spanY < 1) {
        return { center, ratio: clampRatio(FIT_VIEW_SINGLE_POINT_RATIO, minRatio, maxRatio) };
    }

    // A graph that is wide but flat, or tall but narrow, must not be scaled by its empty axis, so
    // each axis constrains only when it has an extent to constrain with. The tighter one wins.
    const scales = [];

    if (spanX >= 1) {
        scales.push(availableWidth / spanX);
    }

    if (spanY >= 1) {
        scales.push(availableHeight / spanY);
    }

    const scale = Math.min(...scales);

    // Sigma's ratio is inverse zoom: showing the content `scale` times larger divides it.
    return { center, ratio: clampRatio(ratio / scale, minRatio, maxRatio) };
}

function clampRatio(value, minRatio, maxRatio) {
    let ratio = Number.isFinite(value) && value > 0 ? value : FIT_VIEW_SINGLE_POINT_RATIO;

    if (Number.isFinite(minRatio)) {
        ratio = Math.max(ratio, minRatio);
    }

    if (Number.isFinite(maxRatio)) {
        ratio = Math.min(ratio, maxRatio);
    }

    return ratio;
}
