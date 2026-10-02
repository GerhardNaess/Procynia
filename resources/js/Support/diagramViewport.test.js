import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { layoutBlueprint } from './processBlueprintLayout.js';
import {
    SURFACE_HEIGHT,
    ZOOM_LIMITS,
    anchoredScroll,
    clampScale,
    fitScale,
    overflowsSurface,
    steppedScale,
    surfaceHeight,
} from './diagramViewport.js';

/**
 * What these tests defend is that zoom stays a view: the drawing is scaled, never re-laid out, and
 * the user cannot end up somewhere they cannot get back from — not zoomed to a size where the flow
 * is unreadable, not fitted to a scale that leaves part of the graph outside the surface, and not
 * dumped at the far left of a wide process every time they press +.
 *
 * Both sizes of graph the feature has to survive are built from real layouts rather than invented
 * numbers, so a change to METRICS that makes a small flow overflow shows up here.
 */

/** Two nodes in one lane: comfortably smaller than the surface a laptop card gives it. */
const SMALL = {
    lanes: [{ key: 'saksbehandler', label: 'Saksbehandler' }],
    nodes: [
        { key: 'start', lane: 'saksbehandler', type: 'start', label: 'Avvik meldes' },
        { key: 'slutt', lane: 'saksbehandler', type: 'end', label: 'Avviket er lukket' },
    ],
    edges: [{ from: 'start', to: 'slutt', label: null }],
};

/** Six lanes and a long chain: wider and taller than the surface on any ordinary screen. */
const LARGE = (() => {
    const lanes = Array.from({ length: 6 }, (_, index) => ({
        key: `lane-${index}`,
        label: `Rolle nummer ${index + 1}`,
    }));
    const nodes = Array.from({ length: 14 }, (_, index) => ({
        key: `node-${index}`,
        lane: `lane-${index % 6}`,
        type: index === 0 ? 'start' : (index === 13 ? 'end' : 'step'),
        label: `Steg ${index + 1} i en lang prosess`,
    }));
    const edges = nodes.slice(1).map((node, index) => ({
        from: `node-${index}`,
        to: node.key,
        label: null,
    }));

    return { lanes, nodes, edges };
})();

const SMALL_LAYOUT = layoutBlueprint(SMALL);
const LARGE_LAYOUT = layoutBlueprint(LARGE);

/** The width a laptop-sized card gives the diagram in the Flyt tab, and the surface that follows. */
const CARD_WIDTH = 900;

function surfaceFor(layout, cardWidth = CARD_WIDTH) {
    return { width: cardWidth, height: surfaceHeight(layout, cardWidth) };
}

const LAPTOP = surfaceFor(LARGE_LAYOUT);

describe('the two graphs the feature has to survive', () => {
    test('the small flow fits its surface and the large one does not', () => {
        assert.equal(overflowsSurface(SMALL_LAYOUT, surfaceFor(SMALL_LAYOUT)), false);
        assert.equal(overflowsSurface(LARGE_LAYOUT, surfaceFor(LARGE_LAYOUT)), true);
    });

    test('a wide flow is not given a tall surface it cannot fill', () => {
        const surface = surfaceFor(LARGE_LAYOUT);
        const fitted = fitScale(LARGE_LAYOUT, surface);

        // The drawing at its fitted scale leaves no more than the floor's worth of slack: a
        // quarter-scale diagram must not open 160px tall in a 620px box.
        assert.ok(surface.height - (LARGE_LAYOUT.height * fitted) < SURFACE_HEIGHT.min);
    });

    test('a short flow gets a surface its own size, and a very long one is capped', () => {
        assert.equal(surfaceHeight(SMALL_LAYOUT, 2000), SURFACE_HEIGHT.min);
        assert.equal(surfaceHeight({ width: 400, height: 4000 }, 2000), SURFACE_HEIGHT.max);
    });
});

describe('clampScale', () => {
    test('it keeps the user inside the limits', () => {
        assert.equal(clampScale(99), ZOOM_LIMITS.max);
        assert.equal(clampScale(0.001), ZOOM_LIMITS.min);
        assert.equal(clampScale(1), 1);
    });

    test('a scale that is not a number falls back to natural size rather than breaking the picture', () => {
        assert.equal(clampScale(Number.NaN), 1);
        assert.equal(clampScale(undefined), 1);
    });

    test('every scale it produces is rounded, so zooming cannot accumulate float drift', () => {
        let scale = 1;

        for (let index = 0; index < 4; index += 1) {
            scale = steppedScale(scale, 1);

            assert.ok((String(scale).split('.')[1] ?? '').length <= 3, `${scale} is not rounded`);
        }

        for (let index = 0; index < 4; index += 1) {
            scale = steppedScale(scale, -1);

            assert.ok((String(scale).split('.')[1] ?? '').length <= 3, `${scale} is not rounded`);
        }

        // Four steps up stays under the ceiling, so four back down lands exactly where it started.
        assert.equal(scale, 1);
    });
});

describe('surfaceHeight', () => {
    test('a drawing with no height still gets a usable surface', () => {
        assert.equal(surfaceHeight({ width: 0, height: 0 }, 900), SURFACE_HEIGHT.min);
        assert.equal(surfaceHeight(null, 900), SURFACE_HEIGHT.min);
    });

    test('it never exceeds the cap, however long the flow', () => {
        assert.equal(surfaceHeight({ width: 500, height: 100000 }, 900), SURFACE_HEIGHT.max);
    });

    test('before the surface is measured the drawing gets the height it asks for', () => {
        assert.equal(surfaceHeight({ width: 1000, height: 400 }, 0), 400);
    });

    test('a narrower card gives a shorter surface, because the fit is tighter', () => {
        const wide = surfaceHeight({ width: 2000, height: 1000 }, 1000);
        const narrow = surfaceHeight({ width: 2000, height: 1000 }, 500);

        assert.equal(wide, 500);
        assert.equal(narrow, 250);
    });
});

describe('fitScale', () => {
    test('the fitted large graph is fully inside the surface on both axes', () => {
        const scale = fitScale(LARGE_LAYOUT, LAPTOP);

        assert.ok(scale > ZOOM_LIMITS.min, 'the floor must leave room to fit a realistic large flow');
        assert.ok(scale < 1, 'a graph larger than the surface must be scaled down');
        assert.ok(LARGE_LAYOUT.width * scale <= LAPTOP.width + 0.5);
        assert.ok(LARGE_LAYOUT.height * scale <= LAPTOP.height + 0.5);
    });

    test('it is bounded by whichever axis overflows most', () => {
        // Twice as wide as the surface, same height: width decides.
        assert.equal(fitScale({ width: 1000, height: 400 }, { width: 500, height: 400 }), 0.5);
        // Twice as tall, same width: height decides.
        assert.equal(fitScale({ width: 500, height: 800 }, { width: 500, height: 400 }), 0.5);
    });

    test('fitting never magnifies a small flow to fill the panel', () => {
        assert.equal(fitScale(SMALL_LAYOUT, LAPTOP), 1);
        assert.equal(fitScale({ width: 10, height: 10 }, { width: 900, height: 600 }), 1);
    });

    test('a fit that would fall below the floor is clamped rather than disappearing', () => {
        assert.equal(fitScale({ width: 100000, height: 100 }, { width: 500, height: 400 }), ZOOM_LIMITS.min);
    });

    test('an unmeasured surface leaves the diagram at natural size', () => {
        assert.equal(fitScale(LARGE_LAYOUT, { width: 0, height: 0 }), 1);
        assert.equal(fitScale(LARGE_LAYOUT, null), 1);
    });
});

describe('overflowsSurface', () => {
    test('overflow on either axis counts', () => {
        assert.equal(overflowsSurface({ width: 600, height: 100 }, { width: 500, height: 400 }), true);
        assert.equal(overflowsSurface({ width: 100, height: 600 }, { width: 500, height: 400 }), true);
        assert.equal(overflowsSurface({ width: 500, height: 400 }, { width: 500, height: 400 }), false);
    });

    test('an unmeasured surface does not claim the diagram overflows', () => {
        assert.equal(overflowsSurface(LARGE_LAYOUT, { width: 0, height: 0 }), false);
    });
});

describe('steppedScale', () => {
    test('one press in and one press out returns to where it started', () => {
        assert.equal(steppedScale(steppedScale(1, 1), -1), 1);
    });

    test('pressing past a limit stops there instead of running away', () => {
        let scale = 1;

        for (let index = 0; index < 40; index += 1) {
            scale = steppedScale(scale, 1);
        }

        assert.equal(scale, ZOOM_LIMITS.max);

        for (let index = 0; index < 80; index += 1) {
            scale = steppedScale(scale, -1);
        }

        assert.equal(scale, ZOOM_LIMITS.min);
    });

    test('zooming out from a fitted large graph still stays above the floor', () => {
        assert.ok(steppedScale(fitScale(LARGE_LAYOUT, LAPTOP), -1) >= ZOOM_LIMITS.min);
    });
});

describe('anchoredScroll', () => {
    test('what was in the middle of the surface is still in the middle after a zoom', () => {
        const surface = 900;
        const scroll = 400;
        const from = 1;
        const to = 2;

        const next = anchoredScroll({ scroll, surface, from, to });

        // The flow coordinate under the centre of the surface, before and after.
        assert.equal((scroll + (surface / 2)) / from, (next + (surface / 2)) / to);
    });

    test('it never scrolls to a negative offset', () => {
        assert.equal(anchoredScroll({ scroll: 0, surface: 900, from: 1, to: 0.5 }), 0);
    });

    test('a scale of zero leaves the scroll position alone rather than dividing by it', () => {
        assert.equal(anchoredScroll({ scroll: 120, surface: 900, from: 0, to: 1 }), 120);
    });
});
