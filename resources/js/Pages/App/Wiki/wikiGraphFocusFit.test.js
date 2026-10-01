import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    FOCUS_FIT_MAX_SCALE,
    FOCUS_FIT_PADDING_PX,
    FOCUS_FIT_SINGLE_POINT_RATIO,
    computeLabelAwareFit,
} from './wikiGraphFocusFit.js';

const VIEWPORT = { width: 1000, height: 600 };

/** A node with no label: a bare disc of the given radius. */
const dot = (x, y, radius = 10) => ({ x, y, left: radius, right: radius, top: radius, bottom: radius });

/** A node whose title is written to the right of it, as the right-hand column's are. */
const labelledRight = (x, y, labelWidth, radius = 10) => ({
    ...dot(x, y, radius),
    right: radius + labelWidth,
});

/** …and to the left of it, as the left-hand column's are. */
const labelledLeft = (x, y, labelWidth, radius = 10) => ({
    ...dot(x, y, radius),
    left: radius + labelWidth,
});

/**
 * Where each item ends up after the fit, in viewport pixels, so an assertion can ask the only
 * question that matters: is any part of it off the screen?
 */
function framed(items, fit, { width, height } = VIEWPORT, ratio = 1) {
    const scale = ratio / fit.ratio;

    return items.map((item) => {
        const x = (item.x - fit.center.x) * scale + width / 2;
        const y = (item.y - fit.center.y) * scale + height / 2;

        return {
            left: x - item.left,
            right: x + item.right,
            top: y - item.top,
            bottom: y + item.bottom,
        };
    });
}

/**
 * The whole point of this module: Sigma frames node POSITIONS, and a label is not at its node's
 * position. These assertions are written against the drawn extent, not the dot, because the drawn
 * extent is what the user sees clipped.
 */
describe('computeLabelAwareFit — nothing drawn may fall off the edge', () => {
    test('a long title on the right-hand column stays inside the viewport', () => {
        const items = [dot(0, 0, 20), labelledRight(400, 0, 180)];
        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });

        for (const box of framed(items, fit)) {
            assert.ok(box.right <= VIEWPORT.width, `a title ran ${box.right - VIEWPORT.width}px past the right edge`);
            assert.ok(box.left >= 0, `a title ran ${-box.left}px past the left edge`);
        }
    });

    test('a long title on the left-hand column stays inside it too', () => {
        const items = [dot(0, 0, 20), labelledLeft(-400, 0, 180)];
        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });

        for (const box of framed(items, fit)) {
            assert.ok(box.left >= 0, `a title ran ${-box.left}px past the left edge`);
            assert.ok(box.right <= VIEWPORT.width);
        }
    });

    test('titles on both sides at once, which is what the column layout actually produces', () => {
        const items = [
            dot(0, 0, 20),
            labelledLeft(-300, -150, 170), labelledLeft(-300, 150, 170),
            labelledRight(300, -150, 170), labelledRight(300, 150, 170),
            labelledLeft(-600, 0, 170), labelledRight(600, 0, 170),
        ];

        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });

        for (const box of framed(items, fit)) {
            assert.ok(box.left >= 0 && box.right <= VIEWPORT.width, 'a title was clipped horizontally');
            assert.ok(box.top >= 0 && box.bottom <= VIEWPORT.height, 'a node was clipped vertically');
        }
    });

    test('a lopsided layout is centred on what is DRAWN, not on the dots', () => {
        // One page on the right carries a long title and the left-hand side carries none. Centring
        // the dots would leave that title hanging over the right edge.
        const items = [dot(-300, 0, 10), labelledRight(300, 0, 200)];
        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });
        const boxes = framed(items, fit);

        assert.ok(boxes[1].right <= VIEWPORT.width);
        assert.ok(fit.center.x > 0, 'the camera must lean toward the side that is actually drawn on');
    });

    test('the fit is not paid for by shrinking the graph into the middle of the screen', () => {
        // The lazy fix is to pad every side by a label's width. That spends the same ~150px at the
        // TOP and bottom, where nothing but a 16px line of text needs it, and on a 600px viewport
        // that is a quarter of the graph thrown away for nothing.
        const items = [
            dot(0, 0, 20),
            labelledRight(300, -200, 150), labelledRight(300, 200, 150),
            labelledLeft(-300, -200, 150), labelledLeft(-300, 200, 150),
        ];

        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });
        const scale = 1 / fit.ratio;

        const uniformlyPadded = Math.min(
            (VIEWPORT.width - 2 * 150) / 600,   // the x extent of the dots
            (VIEWPORT.height - 2 * 150) / 400,  // their y extent
        );

        assert.ok(scale > uniformlyPadded * 1.3, `scale ${scale.toFixed(2)} is no better than padding every side`);

        // …and it is still a fit: nothing drawn is outside the viewport.
        for (const box of framed(items, fit)) {
            assert.ok(box.left >= 0 && box.right <= VIEWPORT.width);
            assert.ok(box.top >= 0 && box.bottom <= VIEWPORT.height);
        }
    });

    test('the tighter axis wins — a tall narrow layout is not scaled by its width', () => {
        const items = [dot(0, -2000, 10), dot(0, 2000, 10), dot(-10, 0, 10)];
        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });

        for (const box of framed(items, fit)) {
            assert.ok(box.top >= 0 && box.bottom <= VIEWPORT.height, 'the vertical extent was not what constrained the fit');
        }
    });
});

describe('computeLabelAwareFit — the cases that must not produce a broken camera', () => {
    test('nothing to fit leaves the camera alone', () => {
        assert.equal(computeLabelAwareFit({ items: [], ...VIEWPORT, ratio: 1 }), null);
        assert.equal(computeLabelAwareFit({ items: [dot(NaN, 0)], ...VIEWPORT, ratio: 1 }), null);
    });

    test('a container that has not been laid out yet leaves the camera alone', () => {
        for (const viewport of [{ width: 0, height: 600 }, { width: 1000, height: 0 }, { width: NaN, height: 600 }]) {
            assert.equal(computeLabelAwareFit({ items: [dot(0, 0)], ...viewport, ratio: 1 }), null);
        }
    });

    test('a nonsensical current ratio leaves the camera alone rather than producing another one', () => {
        for (const ratio of [0, -1, NaN, undefined]) {
            assert.equal(computeLabelAwareFit({ items: [dot(0, 0)], ...VIEWPORT, ratio }), null);
        }
    });

    test('a single page settles on a readable zoom instead of dividing by zero', () => {
        const fit = computeLabelAwareFit({ items: [labelledRight(40, 60, 120)], ...VIEWPORT, ratio: 1 });

        assert.deepEqual(fit.center, { x: 40, y: 60 });
        assert.equal(fit.ratio, FOCUS_FIT_SINGLE_POINT_RATIO);
    });

    test('a two-page neighbourhood is not blown up until its dots fill the screen', () => {
        const items = [dot(0, 0, 20), dot(20, 0, 10)];
        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });

        assert.ok(fit.ratio >= 1 / FOCUS_FIT_MAX_SCALE - 1e-9, `zoomed in to ratio ${fit.ratio}`);
    });

    test('two titles wider than the whole viewport still produce a usable camera', () => {
        // A phone against two long Norwegian page titles. Something has to overflow; what must not
        // happen is a zero, negative or infinite ratio, which is a blank canvas.
        const items = [labelledLeft(-100, 0, 400), labelledRight(100, 0, 400)];
        const fit = computeLabelAwareFit({ items, width: 390, height: 700, ratio: 1 });

        assert.ok(Number.isFinite(fit.ratio) && fit.ratio > 0, `ratio was ${fit.ratio}`);
        assert.ok(Number.isFinite(fit.center.x) && Number.isFinite(fit.center.y));
    });

    test('the camera ratio is held inside the limits the renderer was given', () => {
        const items = [dot(-5000, 0), dot(5000, 0)];

        assert.equal(
            computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1, maxRatio: 4 }).ratio,
            4,
        );
        assert.equal(
            computeLabelAwareFit({ items: [dot(0, 0), dot(1, 0)], ...VIEWPORT, ratio: 1, minRatio: 2 }).ratio,
            2,
        );
    });

    test('the default padding keeps the drawing off the very edge', () => {
        const items = [labelledLeft(-300, 0, 150), labelledRight(300, 0, 150)];
        const fit = computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 });
        const boxes = framed(items, fit);

        assert.ok(Math.min(...boxes.map((b) => b.left)) >= FOCUS_FIT_PADDING_PX - 1e-6);
        assert.ok(Math.max(...boxes.map((b) => b.right)) <= VIEWPORT.width - FOCUS_FIT_PADDING_PX + 1e-6);
    });

    test('the same neighbourhood always fits the same way', () => {
        const items = [dot(0, 0, 20), labelledRight(300, 100, 160), labelledLeft(-300, -100, 160)];

        assert.deepEqual(
            computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 }),
            computeLabelAwareFit({ items, ...VIEWPORT, ratio: 1 }),
        );
    });
});
