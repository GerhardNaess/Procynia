import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    DEFAULT_FOCUS_DEPTH,
    DEFAULT_FOCUS_DIRECTION,
    FOCUS_DEPTHS,
    FOCUS_DIRECTIONS,
    buildFocusUrl,
    focusErrorFromStatus,
    focusPageOptions,
    focusRadialLayout,
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

/**
 * The layout IS the readability of this view. A force simulation would place the focus page wherever
 * the physics settled and would move every page each time the depth changed; these assertions pin
 * the three properties that make the picture answerable at a glance.
 */
describe('focusRadialLayout — where each page goes', () => {
    const focusNode = { id: 'page-82', depth: 0, is_focus: true };

    const neighbourhood = (count, depth = 1) =>
        Array.from({ length: count }, (_, i) => ({ id: `page-${depth}${i}`, depth }));

    test('the focus page sits at the centre, which is what makes it the focus', () => {
        const positions = focusRadialLayout([focusNode, ...neighbourhood(3)], []);

        assert.deepEqual(positions['page-82'], { x: 0, y: 0 });
    });

    test('the ring starts directly above the focus page, where a reader looks first', () => {
        // Sigma renders positive y upward. Getting this sign wrong is invisible in every assertion
        // about distances and relative angles, and puts the first page at the bottom on screen.
        const positions = focusRadialLayout([focusNode, ...neighbourhood(4)], [], { ringRadius: 100 });
        const first = positions['page-10'];

        assert.ok(Math.abs(first.x) < 1e-9, 'the first ring page must sit on the vertical axis');
        assert.ok(first.y > 0, 'the first ring page must sit ABOVE the focus page, not below it');
    });

    test('first-hop pages share one radius — the ring IS the hop distance', () => {
        const nodes = [focusNode, ...neighbourhood(5)];
        const positions = focusRadialLayout(nodes, [], { ringRadius: 100 });

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

        const positions = focusRadialLayout(nodes, edges, { ringRadius: 100 });

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

        const positions = focusRadialLayout(nodes, edges, { ringRadius: 100 });
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

        const positions = focusRadialLayout(nodes, edges, { ringRadius: 100 });
        const angle = (id) => Math.atan2(positions[id].y, positions[id].x);

        assert.ok(Math.abs(angle('a-child') - angle('a')) < 1e-6, 'a single child sits on its parent\'s own bearing');
    });

    test('the same neighbourhood always draws the same way', () => {
        const nodes = [focusNode, ...neighbourhood(4), ...neighbourhood(3, 2)];
        const edges = [{ source: 'page-10', target: 'page-20' }];

        assert.deepEqual(focusRadialLayout(nodes, edges), focusRadialLayout(nodes, edges));
    });

    test('a page with no path back to the first hop is still given a position', () => {
        // The server should never send one; if it ever does, the view must not stack it on the
        // focus page and claim they are the same point.
        const positions = focusRadialLayout([focusNode, { id: 'stray', depth: 2 }], []);

        assert.notDeepEqual(positions.stray, { x: 0, y: 0 });
        assert.ok(Number.isFinite(positions.stray.x) && Number.isFinite(positions.stray.y));
    });

    test('a lone focus page lays out without dividing by zero', () => {
        const positions = focusRadialLayout([focusNode], []);

        assert.deepEqual(positions, { 'page-82': { x: 0, y: 0 } });
        assert.deepEqual(focusRadialLayout([], []), {});
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

    test('focus labels are translatable and fall back to Norwegian in both files', () => {
        const no = readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', 'no', 'procynia.php'), 'utf8');
        const en = readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', 'en', 'procynia.php'), 'utf8');

        for (const key of ['graph_mode_focus', 'graph_focus_title', 'graph_focus_direction_both', 'graph_focus_unavailable']) {
            assert.match(no, new RegExp(`'${key}' => '`), `missing ${key} in lang/no`);
            assert.match(en, new RegExp(`'${key}' => '`), `missing ${key} in lang/en`);
        }
    });
});
