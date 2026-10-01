import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    COLUMN_ROW_PITCH,
    DEFAULT_FOCUS_DEPTH,
    DEFAULT_FOCUS_DIRECTION,
    FOCUS_DEPTHS,
    FOCUS_DIRECTIONS,
    OUTER_RING_MAX_SPOKES,
    RING_MAX_SPOKES,
    buildFocusUrl,
    focusErrorFromStatus,
    focusLayout,
    focusLayoutStrategy,
    focusPageOptions,
    labelSideForX,
} from './wikiGraphFocus.js';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * The request the focus view sends.
 *
 * The server validates depth and direction again and answers 422 for anything outside its own
 * whitelist (GraphFocusQuery). A 422 the UI caused by sending its own state unchecked would be a bug
 * the user pays for, so the normalisation happens here too — belt and braces, on purpose.
 */
describe('buildFocusUrl — the focus request', () => {
    test('a page, a depth and a direction become one query', () => {
        assert.equal(
            buildFocusUrl({ pageId: 82, depth: 2, direction: 'incoming' }),
            '/app/wiki/graph-focus?page_id=82&depth=2&direction=incoming',
        );
    });

    test('depth and direction default rather than being omitted', () => {
        assert.equal(
            buildFocusUrl({ pageId: 82 }),
            `/app/wiki/graph-focus?page_id=82&depth=${DEFAULT_FOCUS_DEPTH}&direction=${DEFAULT_FOCUS_DIRECTION}`,
        );
    });

    test('no page means no request at all, not a request without a page', () => {
        for (const pageId of [null, undefined, '', 0, -1, 'abc', {}]) {
            assert.equal(buildFocusUrl({ pageId }), null, `page_id ${JSON.stringify(pageId)} must not produce a URL`);
        }

        assert.equal(buildFocusUrl(), null);
    });

    test('a depth the server would reject is replaced, never forwarded', () => {
        for (const depth of [0, 3, 99, -1, 'deep', null]) {
            const url = buildFocusUrl({ pageId: 82, depth });
            assert.match(url, new RegExp(`depth=${DEFAULT_FOCUS_DEPTH}(&|$)`), `depth ${depth} leaked through`);
        }
    });

    test('a direction the server would reject is replaced, never forwarded', () => {
        for (const direction of ['sideways', '', null, 'OUTGOING']) {
            const url = buildFocusUrl({ pageId: 82, direction });
            assert.match(url, new RegExp(`direction=${DEFAULT_FOCUS_DIRECTION}$`), `direction ${direction} leaked through`);
        }
    });

    test('a numeric string page id is accepted — a <select> value is always a string', () => {
        assert.equal(
            buildFocusUrl({ pageId: '82', depth: '2', direction: 'outgoing' }),
            '/app/wiki/graph-focus?page_id=82&depth=2&direction=outgoing',
        );
    });

    test('the offered options are exactly the ones the server accepts', () => {
        // GraphFocusQuery::MAX_DEPTH is 2 and GraphDirection has three cases. If the server ever
        // widens either, this test is the reminder that the UI has not.
        assert.deepEqual(FOCUS_DEPTHS, [1, 2]);
        assert.deepEqual([...FOCUS_DIRECTIONS].sort(), ['both', 'incoming', 'outgoing']);
    });
});

/**
 * Three failures with three different remedies, so they must not collapse into one message:
 * the projection being off is not the user's problem, an unknown page might be.
 */
describe('focusErrorFromStatus — telling the user which thing went wrong', () => {
    test('503 is the projection being off or unreachable', () => {
        assert.equal(focusErrorFromStatus(503), 'unavailable');
    });

    test('422 is an unknown, hidden or out-of-range page', () => {
        assert.equal(focusErrorFromStatus(422), 'invalid');
    });

    test('403 is a missing customer context', () => {
        assert.equal(focusErrorFromStatus(403), 'forbidden');
    });

    test('anything else is generic rather than guessed at', () => {
        for (const status of [500, 404, 0, undefined]) {
            assert.equal(focusErrorFromStatus(status), 'generic');
        }
    });
});

describe('focusPageOptions — the page picker', () => {
    const nodes = [
        { page_id: 9, title: 'Åpenhet' },
        { page_id: 3, title: 'Sikkerhet' },
        { page_id: 7, title: 'Arkitektur' },
    ];

    test('options are sorted by title using Norwegian collation', () => {
        // Å sorts last in Norwegian and first in a naive code-point sort — the difference is
        // visible to every user of this picker.
        assert.deepEqual(focusPageOptions(nodes).map((o) => o.title), ['Arkitektur', 'Sikkerhet', 'Åpenhet']);
    });

    test('each option carries the numeric page id the endpoint expects', () => {
        assert.deepEqual(focusPageOptions(nodes).map((o) => o.pageId), [7, 3, 9]);
    });

    test('a page with no usable title is still selectable', () => {
        assert.deepEqual(focusPageOptions([{ page_id: 5, title: '  ' }]), [{ pageId: 5, title: '#5' }]);
    });

    test('an empty graph produces an empty picker rather than throwing', () => {
        assert.deepEqual(focusPageOptions(), []);
        assert.deepEqual(focusPageOptions([]), []);
    });
});

const focusNode = { id: 'page-82', depth: 0, is_focus: true };

const neighbourhood = (count, depth = 1) =>
    Array.from({ length: count }, (_, i) => ({ id: `page-${depth}-${i}`, depth }));

/** A hub: one focus page, `count` first-hop pages, and the edges that attach them. */
const hub = (count) => ({
    nodes: [focusNode, ...neighbourhood(count)],
    edges: neighbourhood(count).map((node) => ({ source: 'page-82', target: node.id })),
});

/**
 * Ring while a ring reads, columns once it does not.
 *
 * A label is horizontal and fixed in pixels, and a ring gives two neighbouring pages almost no
 * HORIZONTAL separation at the top and bottom of the circle. Making the ring bigger cannot help: the
 * camera fits it to the viewport, so a bigger ring is drawn smaller. Past a count, the arrangement
 * itself has to change.
 */
describe('focusLayoutStrategy — which arrangement this neighbourhood gets', () => {
    test('a handful of neighbours keeps the ring', () => {
        assert.equal(focusLayoutStrategy(0, 0), 'ring');
        assert.equal(focusLayoutStrategy(RING_MAX_SPOKES, 0), 'ring');
    });

    test('a crowded first hop switches to columns', () => {
        assert.equal(focusLayoutStrategy(RING_MAX_SPOKES + 1, 0), 'columns');
        assert.equal(focusLayoutStrategy(24, 0), 'columns');
    });

    test('a crowded SECOND hop switches too, even behind a small first hop', () => {
        // Six neighbours with forty children between them is a hairball on a ring, and the first-hop
        // count alone would never notice.
        assert.equal(focusLayoutStrategy(6, OUTER_RING_MAX_SPOKES), 'ring');
        assert.equal(focusLayoutStrategy(6, OUTER_RING_MAX_SPOKES + 1), 'columns');
    });
});

describe('labelSideForX — which way a title is written', () => {
    test('a page left of the centre writes its title leftward, away from the picture', () => {
        assert.equal(labelSideForX(-1), 'left');
        assert.equal(labelSideForX(-0.0001), 'left');
    });

    test('the centre and everything right of it writes rightward', () => {
        assert.equal(labelSideForX(0), 'right');
        assert.equal(labelSideForX(140), 'right');
    });
});

/**
 * The layout IS the readability of this view. A force simulation would place the focus page wherever
 * the physics settled and would move every page each time the depth changed; these assertions pin
 * the properties that make the picture answerable at a glance.
 */
describe('focusLayout — the ring, for a neighbourhood that fits on one', () => {
    test('the focus page sits at the centre, which is what makes it the focus', () => {
        const positions = focusLayout([focusNode, ...neighbourhood(3)], []);

        assert.deepEqual(positions['page-82'], { x: 0, y: 0, labelSide: 'right' });
    });

    test('the ring starts directly above the focus page, where a reader looks first', () => {
        // Sigma renders positive y upward. Getting this sign wrong is invisible in every assertion
        // about distances and relative angles, and puts the first page at the bottom on screen.
        const positions = focusLayout([focusNode, ...neighbourhood(4)], [], { ringRadius: 100 });
        const first = positions['page-1-0'];

        assert.ok(Math.abs(first.x) < 1e-9, 'the first ring page must sit on the vertical axis');
        assert.ok(first.y > 0, 'the first ring page must sit ABOVE the focus page, not below it');
    });

    test('first-hop pages share one radius — the ring IS the hop distance', () => {
        const positions = focusLayout([focusNode, ...neighbourhood(5)], [], { ringRadius: 100 });

        for (const node of neighbourhood(5)) {
            const { x, y } = positions[node.id];
            assert.ok(Math.abs(Math.hypot(x, y) - 100) < 1e-6, `${node.id} is off the ring`);
        }
    });

    test('second-hop pages sit on an outer ring, never mixed in with the first hop', () => {
        const nodes = [focusNode, { id: 'page-1', depth: 1 }, { id: 'page-2', depth: 2 }];
        const edges = [
            { source: 'page-82', target: 'page-1' },
            { source: 'page-1', target: 'page-2' },
        ];

        const positions = focusLayout(nodes, edges, { ringRadius: 100 });

        assert.ok(Math.abs(Math.hypot(positions['page-1'].x, positions['page-1'].y) - 100) < 1e-6);
        assert.ok(Math.abs(Math.hypot(positions['page-2'].x, positions['page-2'].y) - 200) < 1e-6);
    });

    test('a second-hop page is placed beside the first-hop page it hangs off', () => {
        // Without this, the outer ring is assigned in arbitrary order and most second-hop edges
        // cross the middle of the picture — the difference between a readable view and a hairball.
        const nodes = [
            focusNode,
            { id: 'a', depth: 1 },
            { id: 'b', depth: 1 },
            { id: 'a-child', depth: 2 },
            { id: 'b-child', depth: 2 },
        ];
        const edges = [
            { source: 'page-82', target: 'a' },
            { source: 'page-82', target: 'b' },
            { source: 'a', target: 'a-child' },
            { source: 'b', target: 'b-child' },
        ];

        const positions = focusLayout(nodes, edges, { ringRadius: 100 });
        const angle = (id) => Math.atan2(positions[id].y, positions[id].x);
        const gap = (one, two) => Math.abs(Math.atan2(Math.sin(angle(one) - angle(two)), Math.cos(angle(one) - angle(two))));

        assert.ok(gap('a-child', 'a') < gap('a-child', 'b'), 'a-child must sit nearer its own parent');
        assert.ok(gap('b-child', 'b') < gap('b-child', 'a'), 'b-child must sit nearer its own parent');
    });

    test('an incoming edge attaches a child just as well as an outgoing one', () => {
        // Direction=incoming produces a payload whose edges all point AT the focus. The layout must
        // still find each second-hop page's parent, or the whole outer ring lands in the fallback.
        const nodes = [focusNode, { id: 'a', depth: 1 }, { id: 'a-child', depth: 2 }];
        const edges = [
            { source: 'a', target: 'page-82' },
            { source: 'a-child', target: 'a' },
        ];

        const positions = focusLayout(nodes, edges, { ringRadius: 100 });
        const angle = (id) => Math.atan2(positions[id].y, positions[id].x);

        assert.ok(Math.abs(angle('a-child') - angle('a')) < 1e-6, 'a single child sits on its parent\'s own bearing');
    });

    test('a ring page left of the centre writes its title outward', () => {
        const positions = focusLayout([focusNode, ...neighbourhood(4)], [], { ringRadius: 100 });

        for (const [id, position] of Object.entries(positions)) {
            assert.equal(position.labelSide, position.x < 0 ? 'left' : 'right', `${id} writes its title inward`);
        }
    });

    test('the same neighbourhood always draws the same way', () => {
        const nodes = [focusNode, ...neighbourhood(4), ...neighbourhood(3, 2)];
        const edges = [{ source: 'page-1-0', target: 'page-2-0' }];

        assert.deepEqual(focusLayout(nodes, edges), focusLayout(nodes, edges));
    });

    test('a page with no path back to the first hop is still given a position', () => {
        // The server should never send one; if it ever does, the view must not stack it on the
        // focus page and claim they are the same point.
        const positions = focusLayout([focusNode, { id: 'stray', depth: 2 }], []);

        assert.notEqual(positions.stray.x === 0 && positions.stray.y === 0, true);
        assert.ok(Number.isFinite(positions.stray.x) && Number.isFinite(positions.stray.y));
    });

    test('a lone focus page lays out without dividing by zero', () => {
        assert.deepEqual(focusLayout([focusNode], []), { 'page-82': { x: 0, y: 0, labelSide: 'right' } });
        assert.deepEqual(focusLayout([], []), {});
    });
});

/**
 * The arrangement that has to survive a hub.
 *
 * Twenty-four neighbours on a ring is unreadable whatever the radius; in two columns it is twelve
 * rows a side, which is ordinary. These assertions pin the properties that make that true — every
 * page on its own row, both columns the same height, children in line with their own parent, and
 * titles written away from the middle.
 */
describe('focusLayout — columns, for a neighbourhood a ring cannot hold', () => {
    const rowsOf = (positions, ids) => ids.map((id) => positions[id].y);

    test('the focus page keeps the centre', () => {
        const { nodes, edges } = hub(24);
        const positions = focusLayout(nodes, edges);

        assert.deepEqual(positions['page-82'], { x: 0, y: 0, labelSide: 'right' });
    });

    test('neighbours land in exactly two columns, one either side of the focus page', () => {
        const { nodes, edges } = hub(24);
        const positions = focusLayout(nodes, edges);
        const xs = new Set(neighbourhood(24).map((node) => positions[node.id].x));

        assert.equal(xs.size, 2, 'a first hop must occupy one column per side and no more');
        assert.deepEqual([...xs].map(Math.sign).sort(), [-1, 1]);
    });

    test('the two columns stay within one page of each other, however lopsided the links', () => {
        // A side chosen by edge direction would put all 24 of an outgoing-only hub in one column,
        // twice as tall as the viewport and half of it empty.
        for (const count of [3, 11, 24, 25]) {
            const { nodes, edges } = hub(count);
            const positions = focusLayout(nodes, edges, { strategy: 'columns' });
            const left = neighbourhood(count).filter((n) => positions[n.id].x < 0).length;
            const right = count - left;

            assert.ok(Math.abs(left - right) <= 1, `${count} neighbours split ${left}/${right}`);
        }
    });

    test('no two pages in a column share a row — this is what a ring could not promise', () => {
        const { nodes, edges } = hub(24);
        const positions = focusLayout(nodes, edges);

        for (const side of [-1, 1]) {
            const ys = neighbourhood(24)
                .filter((node) => Math.sign(positions[node.id].x) === side)
                .map((node) => positions[node.id].y);

            assert.equal(new Set(ys).size, ys.length, 'two pages were put on the same row');
            assert.ok(
                Math.min(...ys.slice(1).map((y, i) => Math.abs(y - ys[i]))) >= COLUMN_ROW_PITCH - 1e-9,
                'rows are closer together than the pitch allows',
            );
        }
    });

    test('a column is centred on the focus page rather than hanging off one side of it', () => {
        const { nodes, edges } = hub(24);
        const positions = focusLayout(nodes, edges);
        const ys = neighbourhood(24).map((node) => positions[node.id].y);

        assert.ok(Math.abs(Math.min(...ys) + Math.max(...ys)) < 1e-9, 'the columns are not centred vertically');
    });

    test('a second-hop page sits further out than its parent, on the same side', () => {
        const nodes = [focusNode, ...neighbourhood(12), ...neighbourhood(20, 2)];
        const edges = [
            ...neighbourhood(12).map((node) => ({ source: 'page-82', target: node.id })),
            ...neighbourhood(20, 2).map((node, i) => ({ source: `page-1-${i % 12}`, target: node.id })),
        ];

        const positions = focusLayout(nodes, edges);

        for (const [i, child] of neighbourhood(20, 2).entries()) {
            const parent = positions[`page-1-${i % 12}`];
            const own = positions[child.id];

            assert.equal(Math.sign(own.x), Math.sign(parent.x), `${child.id} changed sides`);
            assert.ok(Math.abs(own.x) > Math.abs(parent.x), `${child.id} is not beyond its parent`);
        }
    });

    test('a page with one child is level with it, so the edge between them is a short line', () => {
        const nodes = [focusNode, ...neighbourhood(12), { id: 'only-child', depth: 2 }];
        const edges = [
            ...neighbourhood(12).map((node) => ({ source: 'page-82', target: node.id })),
            { source: 'page-1-0', target: 'only-child' },
        ];

        const positions = focusLayout(nodes, edges);

        assert.ok(Math.abs(positions['only-child'].y - positions['page-1-0'].y) < 1e-9);
    });

    test('a page with several children sits at the middle of them', () => {
        const children = neighbourhood(3, 2);
        const nodes = [focusNode, ...neighbourhood(12), ...children];
        const edges = [
            ...neighbourhood(12).map((node) => ({ source: 'page-82', target: node.id })),
            ...children.map((child) => ({ source: 'page-1-0', target: child.id })),
        ];

        const positions = focusLayout(nodes, edges);
        const ys = rowsOf(positions, children.map((child) => child.id));

        assert.ok(Math.abs(positions['page-1-0'].y - (Math.min(...ys) + Math.max(...ys)) / 2) < 1e-9);
    });

    test('titles are written outward, so a column never writes across the middle', () => {
        const { nodes, edges } = hub(24);
        const positions = focusLayout(nodes, edges);

        for (const node of neighbourhood(24)) {
            const position = positions[node.id];
            assert.equal(position.labelSide, position.x < 0 ? 'left' : 'right');
        }
    });

    test('the columns stand further apart as they grow taller, so the gap on screen does not close', () => {
        // The camera fits the layout's HEIGHT into the viewport, so a taller layout is drawn at a
        // smaller scale. A gap fixed in layout units would therefore shrink on screen exactly when
        // there are the most titles to keep apart; it has to grow with the height instead.
        const gapFor = (count) => {
            const { nodes, edges } = hub(count);

            return Math.abs(focusLayout(nodes, edges, { strategy: 'columns' })[`page-1-0`].x);
        };

        const tallHeight = 24 * COLUMN_ROW_PITCH;
        assert.ok(gapFor(48) > gapFor(12), 'a taller layout must spread its columns wider');
        assert.ok(gapFor(48) / gapFor(12) > 1.5, `a ${tallHeight}-unit column barely widened its gap`);
    });

    test('an unattached second-hop page goes below the shorter column, never onto the focus page', () => {
        const nodes = [focusNode, ...neighbourhood(12), { id: 'stray', depth: 2 }];
        const edges = neighbourhood(12).map((node) => ({ source: 'page-82', target: node.id }));

        const positions = focusLayout(nodes, edges);

        assert.ok(Number.isFinite(positions.stray.x) && Number.isFinite(positions.stray.y));
        assert.notEqual(positions.stray.x, 0);
    });

    test('the same hub always draws the same way', () => {
        const { nodes, edges } = hub(24);

        assert.deepEqual(focusLayout(nodes, edges), focusLayout(nodes, edges));
    });
});

/**
 * Wiring that cannot be asserted through a renderer here (there is no JSX test runner in this repo,
 * and Sigma cannot be driven headlessly), but which is exactly what "the full graph is unchanged"
 * means in practice.
 */
describe('the wiring in Graph.jsx and WikiGraphFocusView.jsx', () => {
    const graph = readFileSync(join(here, 'Graph.jsx'), 'utf8');
    const focus = readFileSync(join(here, 'WikiGraphFocusView.jsx'), 'utf8');

    test('the full graph still reads its own SQL-backed endpoint', () => {
        assert.match(graph, /return '\/app\/wiki\/graph-data' \+/);

        // One request, and it is the SQL-backed one. The focus request is built and sent entirely
        // inside the focus view, so adding focus mode cannot change what the full graph loads.
        assert.deepEqual(graph.match(/fetch\(/g), ['fetch(']);
        assert.match(graph, /fetch\(fetchUrl, \{/);
    });

    test('focus mode reads the Neo4j-backed endpoint, and only that', () => {
        assert.match(focus, /buildFocusUrl\(\{ pageId, depth, direction \}\)/);
        assert.equal(/graph-data/.test(focus), false);
    });

    test('exactly one renderer is live at a time', () => {
        // The focus canvas mounts only in focus mode; the full graph's Sigma stays constructed but
        // hidden, so returning to it does not re-run ForceAtlas2 and move every page.
        assert.match(graph, /\{mode === 'focus' && \(\s*<WikiGraphFocusView/);
        assert.match(graph, /visibility: \(mode === 'full' &&/);
    });

    test('the mode switch offers both views and nothing else', () => {
        assert.match(graph, /tw\.graph_mode_full \?\? 'Fullgraf'/);
        assert.match(graph, /tw\.graph_mode_focus \?\? 'Fokus'/);
    });

    test('a node in the full graph can be turned into the focus of a focus view', () => {
        assert.match(graph, /onFocus=\{focusOnPage\}/);
        assert.match(graph, /const focusOnPage = \(pageId\) => \{/);
    });

    test('a neighbour in the focus view can become the next focus', () => {
        assert.match(focus, /onMakeFocus\(node\.pageId\)/);
    });

    test('the three focus states a user can land in are all handled', () => {
        for (const kind of ['unavailable', 'invalid', 'generic']) {
            assert.match(focus, new RegExp(`errorKind === '${kind}'`), `no branch for ${kind}`);
        }

        assert.match(focus, /graph_focus_loading/);
    });

    test('Neo4j being off never reaches the full graph', () => {
        // The unavailable message says so explicitly, because the user can still work.
        assert.match(focus, /tw\.graph_focus_unavailable_hint/);
    });

    test('a slower earlier answer cannot overwrite the view the user is now on', () => {
        assert.match(focus, /cancelled = true/);
    });

    test('the camera is fitted around the labels, not around the dots', () => {
        // Sigma's own auto-rescale frames node positions; a label is drawn beside its node at a
        // fixed pixel size, so without this the outermost title on each side is clipped.
        assert.match(focus, /computeLabelAwareFit/);
        assert.match(focus, /fitCameraToLabels\(renderer/);
    });

    test('a resize re-fits rather than leaving the old framing in place', () => {
        // A rotated phone or an opened sidebar changes how much room the labels have.
        assert.match(focus, /new ResizeObserver\(/);
        assert.match(focus, /observer\.disconnect\(\)/);
    });

    test('the label a page may draw is tied to the canvas it is drawn on', () => {
        assert.match(focus, /labelWidthForCanvas/);
    });

    test('the full graph keeps its own fit, untouched by any of this', () => {
        assert.match(graph, /computeFitToViewport/);
        assert.equal(/wikiGraphFocusFit/.test(graph), false);
    });

    test('focus labels are translatable and fall back to Norwegian in both files', () => {
        const no = readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', 'no', 'procynia.php'), 'utf8');
        const en = readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', 'en', 'procynia.php'), 'utf8');

        for (const key of ['graph_mode_focus', 'graph_focus_title', 'graph_focus_direction_both', 'graph_focus_unavailable']) {
            assert.match(no, new RegExp(`'${key}' => '`), `missing ${key} in lang/no`);
            assert.match(en, new RegExp(`'${key}' => '`), `missing ${key} in lang/en`);
        }
    });
});
