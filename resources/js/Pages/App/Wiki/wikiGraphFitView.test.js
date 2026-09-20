import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    FIT_VIEW_PADDING_PX,
    FIT_VIEW_SINGLE_POINT_RATIO,
    computeFitToViewport,
} from './wikiGraphFitView.js';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * "Tilpass visning" has to be computed from what the graph is showing.
 *
 * It used to call camera.animatedReset(), which sets a fixed default camera state and knows nothing
 * about where the nodes are — on a three-node layout a node sat outside the viewport both before
 * and after pressing it. These tests pin down the arithmetic that replaces it, and the degenerate
 * inputs a real graph will eventually hand it.
 */

const VIEWPORT = { width: 1000, height: 600, ratio: 1 };
const fit = (points, overrides = {}) => computeFitToViewport({ points, ...VIEWPORT, ...overrides });

/** The pixel half-extent a fitted graph occupies, given the scale the fit chose. */
const halfSpanAfterFit = (span, result) => (span / 2) * (VIEWPORT.ratio / result.ratio);

describe('nothing to fit', () => {
    test('an empty graph leaves the camera alone', () => {
        assert.equal(fit([]), null);
        assert.equal(computeFitToViewport({ ...VIEWPORT }), null);
    });

    test('a container with no size is a no-op rather than a division by zero', () => {
        assert.equal(fit([{ x: 10, y: 10 }], { width: 0 }), null);
        assert.equal(fit([{ x: 10, y: 10 }], { height: 0 }), null);
        assert.equal(fit([{ x: 10, y: 10 }], { width: -5, height: -5 }), null);
    });

    test('a missing or nonsensical camera ratio is a no-op', () => {
        assert.equal(fit([{ x: 10, y: 10 }], { ratio: 0 }), null);
        assert.equal(fit([{ x: 10, y: 10 }], { ratio: Number.NaN }), null);
    });

    test('points that are not points are ignored, not fitted to', () => {
        assert.equal(fit([null, undefined, {}, { x: Number.NaN, y: 3 }]), null);
    });
});

describe('a single node', () => {
    const result = fit([{ x: 720, y: 140 }]);

    test('is centred on itself', () => {
        assert.deepEqual(result.center, { x: 720, y: 140 });
    });

    test('gets a readable zoom instead of an infinite one', () => {
        assert.equal(result.ratio, FIT_VIEW_SINGLE_POINT_RATIO);
        assert.ok(Number.isFinite(result.ratio) && result.ratio > 0);
    });

    test('several nodes stacked on the same spot behave the same way', () => {
        const stacked = fit([{ x: 300, y: 300 }, { x: 300, y: 300 }, { x: 300.4, y: 299.8 }]);

        assert.ok(Number.isFinite(stacked.ratio) && stacked.ratio > 0);
        assert.equal(stacked.ratio, FIT_VIEW_SINGLE_POINT_RATIO);
        assert.ok(Math.abs(stacked.center.x - 300.2) < 1);
    });
});

describe('the extent of the visible nodes', () => {
    test('the centre is the middle of the bounding box, not the average of the nodes', () => {
        // Nine nodes clustered left and one far right: the centre must sit between the extremes,
        // otherwise the outlier ends up off screen.
        const points = [...Array(9)].map((_, i) => ({ x: 100 + i, y: 300 }));
        points.push({ x: 900, y: 300 });

        assert.equal(fit(points).center.x, 500);
    });

    test('every node is inside the viewport afterwards', () => {
        const points = [{ x: 120, y: 90 }, { x: 880, y: 510 }, { x: 400, y: 260 }];
        const result = fit(points);

        const spanX = 880 - 120;
        const spanY = 510 - 90;

        assert.ok(halfSpanAfterFit(spanX, result) <= VIEWPORT.width / 2);
        assert.ok(halfSpanAfterFit(spanY, result) <= VIEWPORT.height / 2);
    });
});

describe('the axis that constrains', () => {
    /**
     * A wide, flat graph must be scaled by its width and a tall, narrow one by its height. Scaling
     * by the wrong axis is what puts content off screen, and scaling by an axis with no extent is
     * what produces Infinity.
     */
    test('a wide graph is fitted by its width', () => {
        const result = fit([{ x: 0, y: 300 }, { x: 1000, y: 300 }]);

        // 1000px of content into 1000 - 2*56 = 888 usable pixels.
        assert.ok(Math.abs(result.ratio - 1000 / (1000 - 2 * FIT_VIEW_PADDING_PX)) < 1e-9);
        assert.ok(halfSpanAfterFit(1000, result) <= (VIEWPORT.width - 2 * FIT_VIEW_PADDING_PX) / 2 + 1e-9);
    });

    test('a tall graph is fitted by its height', () => {
        const result = fit([{ x: 500, y: 0 }, { x: 500, y: 600 }]);

        assert.ok(Math.abs(result.ratio - 600 / (600 - 2 * FIT_VIEW_PADDING_PX)) < 1e-9);
        assert.ok(halfSpanAfterFit(600, result) <= (VIEWPORT.height - 2 * FIT_VIEW_PADDING_PX) / 2 + 1e-9);
    });

    test('the tighter of the two axes wins', () => {
        // Wide AND tall: the height is the binding constraint in a 1000x600 viewport.
        const result = fit([{ x: 0, y: 0 }, { x: 1000, y: 600 }]);

        assert.ok(halfSpanAfterFit(1000, result) <= (VIEWPORT.width - 2 * FIT_VIEW_PADDING_PX) / 2 + 1e-9);
        assert.ok(halfSpanAfterFit(600, result) <= (VIEWPORT.height - 2 * FIT_VIEW_PADDING_PX) / 2 + 1e-9);
    });

    test('a flat line has no height to be scaled by', () => {
        const result = fit([{ x: 0, y: 300 }, { x: 800, y: 300 }]);

        assert.ok(Number.isFinite(result.ratio));
        assert.ok(Math.abs(result.ratio - 800 / (1000 - 2 * FIT_VIEW_PADDING_PX)) < 1e-9);
    });
});

describe('padding', () => {
    test('the graph does not reach the viewport edge', () => {
        const result = fit([{ x: 0, y: 0 }, { x: 1000, y: 600 }]);
        const marginX = VIEWPORT.width / 2 - halfSpanAfterFit(1000, result);
        const marginY = VIEWPORT.height / 2 - halfSpanAfterFit(600, result);

        assert.ok(marginX >= FIT_VIEW_PADDING_PX - 1e-9, `only ${marginX}px of horizontal margin`);
        assert.ok(marginY >= FIT_VIEW_PADDING_PX - 1e-9, `only ${marginY}px of vertical margin`);
    });

    test('the graph still uses most of the space it is given', () => {
        const result = fit([{ x: 0, y: 0 }, { x: 1000, y: 600 }]);

        // The binding axis fills its usable width exactly — padding must not make the graph small.
        assert.ok(halfSpanAfterFit(600, result) * 2 >= VIEWPORT.height - 2 * FIT_VIEW_PADDING_PX - 1e-9);
    });

    test('a viewport smaller than its own padding still produces a usable fit', () => {
        const result = fit([{ x: 0, y: 0 }, { x: 80, y: 40 }], { width: 90, height: 60 });

        assert.ok(Number.isFinite(result.ratio) && result.ratio > 0);
    });
});

describe('the camera bounds are respected', () => {
    test('a fit never asks to zoom in past the camera limit', () => {
        const result = fit([{ x: 499, y: 299 }, { x: 501, y: 301 }], { minRatio: 0.04, maxRatio: 12 });

        assert.ok(result.ratio >= 0.04);
    });

    test('a fit never asks to zoom out past the camera limit', () => {
        const result = fit([{ x: -500000, y: 0 }, { x: 500000, y: 0 }], { minRatio: 0.04, maxRatio: 12 });

        assert.ok(result.ratio <= 12);
    });

    test('a result is always a finite, positive ratio', () => {
        for (const points of [
            [{ x: 0, y: 0 }, { x: 1e9, y: 1e9 }],
            [{ x: 0, y: 0 }, { x: 1e-9, y: 1e-9 }],
            [{ x: -1e6, y: -1e6 }, { x: 1e6, y: 1e6 }],
        ]) {
            const result = fit(points);

            assert.ok(Number.isFinite(result.ratio), `not finite for ${JSON.stringify(points)}`);
            assert.ok(result.ratio > 0);
            assert.ok(Number.isFinite(result.center.x) && Number.isFinite(result.center.y));
        }
    });
});

/**
 * Source-level guards on the wiring, in the same style as the other Wiki tests — the project has no
 * JSX test renderer, and Sigma cannot be driven headlessly here.
 */
describe('the wiring in Graph.jsx', () => {
    const graph = readFileSync(join(here, 'Graph.jsx'), 'utf8');
    const handler = graph.slice(graph.indexOf('const fitView = ()'), graph.indexOf('const resetFilters'));

    test('the button calls the fit handler', () => {
        assert.match(graph, /onClick=\{fitView\}/);
    });

    test('the handler computes a fit instead of resetting the camera', () => {
        assert.match(handler, /computeFitToViewport\(\{/);
        assert.equal(/animatedReset/.test(graph), false, 'the old reset must be gone entirely');
    });

    test('it measures the nodes that are actually displayed', () => {
        // displayedNodes is the set the filters maintain, so hidden nodes cannot stretch the fit.
        assert.match(handler, /displayedNodes\.current\.forEach/);
        assert.match(handler, /renderer\.getNodeDisplayData\(node\)/);
        assert.match(handler, /renderer\.framedGraphToViewport\(/);
    });

    test('it converts the chosen centre back into the camera\'s own space', () => {
        assert.match(handler, /renderer\.viewportToFramedGraph\(fit\.center\)/);
    });

    test('it animates rather than teleporting', () => {
        assert.match(handler, /camera\.animate\(/);
        assert.match(handler, /duration: \d+/);
    });

    test('it stays inside the camera bounds the renderer was given', () => {
        assert.match(handler, /minRatio: MIN_CAMERA_RATIO/);
        assert.match(handler, /maxRatio: MAX_CAMERA_RATIO/);
        assert.match(graph, /minCameraRatio:\s+MIN_CAMERA_RATIO/);
        assert.match(graph, /maxCameraRatio:\s+MAX_CAMERA_RATIO/);
    });

    test('a missing renderer is handled rather than thrown on', () => {
        assert.match(handler, /if \(!renderer \|\| !graph\) \{/);
        assert.match(handler, /if \(fit === null\) \{/);
    });

    test('the rotation the user set is preserved', () => {
        assert.match(handler, /angle: camera\.getState\(\)\.angle/);
    });
});

describe('the label', () => {
    const graph = readFileSync(join(here, 'Graph.jsx'), 'utf8');
    const lang = (locale) => readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', locale, 'procynia.php'), 'utf8');

    test('there is one camera button, not a fit and a reset', () => {
        for (const other of ['Tilbakestill visning', 'Reset view', 'Zoom ut', 'Zoom - fit']) {
            assert.equal(graph.includes(other), false, `must not offer: ${other}`);
            assert.equal(lang('no').includes(`'graph_fit_view' => '${other}'`), false);
        }
    });

    test('the Norwegian label is "Tilpass visning"', () => {
        assert.match(lang('no'), /'graph_fit_view' => 'Tilpass visning',/);
        assert.match(graph, /tw\.graph_fit_view \?\? 'Tilpass visning'/);
    });

    test('the English label is "Fit view"', () => {
        assert.match(lang('en'), /'graph_fit_view' => 'Fit view',/);
    });
});
