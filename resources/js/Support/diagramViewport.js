/**
 * The arithmetic behind zooming a diagram.
 *
 * This is view state and nothing else — it reads no blueprint, writes no blueprint, and the picture
 * it scales is still a pure function of the structure (see processBlueprintLayout.js). Zoom changes
 * how much of the drawing you can see at once; it never changes what is drawn.
 *
 * It lives apart from the component for the same reason the layout does: it is the part that can be
 * wrong in a way you cannot see by looking. A fit that is off by a few per cent, or a zoom that
 * drifts because floating point multiplication accumulates, is invisible in a screenshot and
 * obvious in an assertion.
 *
 * Sizes are CSS pixels throughout. A scale of 1 means the diagram is drawn at the size
 * layoutBlueprint() produced.
 */

/**
 * How far the user is allowed to go.
 *
 * The floor is set by what fit-to-view needs, not by what is comfortable to read. A fourteen-step
 * process across six lanes is some 3600px wide, and fitting that into a card is a quarter scale:
 * stopping at a readable 0.4 would mean the fit button could not show the whole flow on exactly the
 * diagrams it exists for. The labels are a smear down there, and that is what an overview of a long
 * process looks like — the + button is how you go and read one.
 *
 * The ceiling is roughly where a single node fills a laptop viewport, which is as close as reading
 * one box ever requires.
 */
export const ZOOM_LIMITS = { min: 0.2, max: 2.5 };

/** One press of + or −. A quarter step: coarse enough to feel, fine enough to land on a size. */
export const ZOOM_STEP = 1.25;

/**
 * The height the diagram surface is given.
 *
 * A short flow gets a surface its own size, so three nodes are not marooned in a half-empty panel
 * and "fit" is already true the moment the tab opens. A long one is capped, because a diagram that
 * pushes the editor below it off the screen is the problem fit-to-view exists to solve. The floor
 * is there so the controls are never cramped against the drawing.
 */
export const SURFACE_HEIGHT = { min: 220, max: 620 };

/** Scales are rounded before they are stored, so repeated zooming cannot accumulate drift. */
function round(scale) {
    return Math.round(scale * 1000) / 1000;
}

/**
 * @param {number} scale
 * @param {{min: number, max: number}} [limits]
 * @returns {number}
 */
export function clampScale(scale, limits = ZOOM_LIMITS) {
    if (! Number.isFinite(scale)) {
        return 1;
    }

    return round(Math.min(Math.max(scale, limits.min), limits.max));
}

/**
 * The surface height for this drawing in a surface of this width.
 *
 * A process is far wider than it is tall — six lanes and fourteen steps is 3664 × 656 — so fitting
 * one is almost always bounded by width. Giving the surface the full cap regardless would then open
 * a quarter-scale diagram 160px tall in a 620px box, centred in a field of white, which reads as a
 * rendering fault rather than as a fitted picture.
 *
 * So the height is what the drawing needs once width has had its say. The surface is sized from the
 * width alone and never from the current scale, which is what keeps this from chasing its own tail:
 * zooming in afterwards scrolls inside a surface that does not move.
 *
 * @param {{width: number, height: number}} content
 * @param {number} surfaceWidth measured; 0 before the first measurement
 * @param {{min: number, max: number}} [bounds]
 * @returns {number}
 */
export function surfaceHeight(content, surfaceWidth, bounds = SURFACE_HEIGHT) {
    const contentWidth = Number(content?.width) || 0;
    const contentHeight = Number(content?.height) || 0;

    if (contentHeight <= 0) {
        return bounds.min;
    }

    // Before the surface has been measured there is nothing to fit to, so the drawing is given the
    // height it asks for, capped. One paint later the measurement arrives and this settles.
    const width = Number(surfaceWidth) || 0;
    const needed = width > 0 && contentWidth > 0
        ? contentHeight * Math.min(1, width / contentWidth)
        : contentHeight;

    return Math.round(Math.min(Math.max(needed, bounds.min), bounds.max));
}

/**
 * The scale at which the whole drawing fits inside the surface.
 *
 * Capped at 1: fit means all of it is visible, not that a small flow is blown up to fill the panel.
 * Magnifying a four-node process to 2.5× would make the surface look like an error, and the user
 * who wants it bigger has the + button.
 *
 * @param {{width: number, height: number}} content
 * @param {{width: number, height: number}} surface
 * @param {{min: number, max: number}} [limits]
 * @returns {number}
 */
export function fitScale(content, surface, limits = ZOOM_LIMITS) {
    const contentWidth = Number(content?.width) || 0;
    const contentHeight = Number(content?.height) || 0;
    const surfaceWidth = Number(surface?.width) || 0;
    const surfaceHeightPx = Number(surface?.height) || 0;

    if (contentWidth <= 0 || contentHeight <= 0 || surfaceWidth <= 0 || surfaceHeightPx <= 0) {
        return 1;
    }

    const raw = Math.min(surfaceWidth / contentWidth, surfaceHeightPx / contentHeight, 1);

    // Rounded DOWN, unlike every other scale here: rounding 0.2456 up to 0.246 puts a 3664px
    // drawing a pixel outside a 900px surface, and a fit that leaves an edge off is not a fit.
    return clampScale(Math.floor(raw * 1000) / 1000, limits);
}

/**
 * Whether the drawing is bigger than the surface it has been given.
 *
 * What decides the opening scale: a flow that already fits opens at its own size, and only one that
 * does not is shrunk. Opening every diagram at a fitted scale would shrink flows that had no need
 * of it.
 *
 * @param {{width: number, height: number}} content
 * @param {{width: number, height: number}} surface
 * @returns {boolean}
 */
export function overflowsSurface(content, surface) {
    const surfaceWidth = Number(surface?.width) || 0;
    const surfaceHeightPx = Number(surface?.height) || 0;

    if (surfaceWidth <= 0 || surfaceHeightPx <= 0) {
        return false;
    }

    return (Number(content?.width) || 0) > surfaceWidth || (Number(content?.height) || 0) > surfaceHeightPx;
}

/**
 * The scale one press of + or − lands on.
 *
 * @param {number} scale
 * @param {number} direction 1 to zoom in, -1 to zoom out
 * @param {{min: number, max: number}} [limits]
 * @returns {number}
 */
export function steppedScale(scale, direction, limits = ZOOM_LIMITS) {
    const current = Number.isFinite(scale) && scale > 0 ? scale : 1;

    return clampScale(direction >= 0 ? current * ZOOM_STEP : current / ZOOM_STEP, limits);
}

/**
 * Where to scroll to after a zoom so the middle of the surface stays on the same part of the flow.
 *
 * Without this, zooming in on a wide process throws the user back to the left edge and they have to
 * find their place again — which makes the + button useless on exactly the diagrams that need it.
 *
 * @param {{scroll: number, surface: number, from: number, to: number}} change
 * @returns {number}
 */
export function anchoredScroll({ scroll, surface, from, to }) {
    const previous = Number(scroll) || 0;
    const visible = Number(surface) || 0;
    const previousScale = Number(from) || 1;
    const nextScale = Number(to) || 1;

    if (previousScale <= 0 || nextScale <= 0) {
        return previous;
    }

    const centre = (previous + (visible / 2)) / previousScale;

    return Math.max(0, Math.round((centre * nextScale) - (visible / 2)));
}
