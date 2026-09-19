import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { articleHrefFromGraph, graphReturnUrl } from './wikiGraphNavigation.js';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * The outbound half of graph → article → graph: the link the node panel renders.
 *
 * The return destination is decided here, in the URL, rather than by history.back(), so that
 * reloading the article, opening it in a new tab, or landing on it after other Inertia navigation
 * all resolve the same way. The server re-validates whatever is built here
 * (PreservesWikiReviewReturnUrl::normalizeGraphReturnUrl, covered by
 * WikiGraphReturnNavigationTest) — these tests cover what the graph actually sends.
 */
describe('graphReturnUrl — the graph destination a return link points at', () => {
    test('a graph with no scope returns to the whole-wiki graph', () => {
        assert.equal(graphReturnUrl(), '/app/wiki/graph');
        assert.equal(graphReturnUrl({}), '/app/wiki/graph');
        assert.equal(graphReturnUrl({ runId: null, pageId: null }), '/app/wiki/graph');
    });

    test('a run-scoped graph keeps its run', () => {
        assert.equal(graphReturnUrl({ runId: 24 }), '/app/wiki/graph?run_id=24');
    });

    test('a page-scoped graph keeps its page', () => {
        assert.equal(graphReturnUrl({ pageId: 77 }), '/app/wiki/graph?page_id=77');
    });

    test('page scope wins over run scope, the same precedence the graph itself applies', () => {
        // WikiGraph derives `scope` as page → run → customer; a return link that inverted that
        // would send the user back to a different view than the one they left.
        assert.equal(graphReturnUrl({ runId: 24, pageId: 77 }), '/app/wiki/graph?page_id=77');
    });
});

describe('articleHrefFromGraph — opening an article with the graph as its origin', () => {
    const nodeUrl = '/app/wiki/threat-hunting';

    test('the article link carries the graph as back_url', () => {
        const href = articleHrefFromGraph(nodeUrl, { runId: 24 });
        const [path, query] = href.split('?');

        assert.equal(path, nodeUrl);
        assert.equal(new URLSearchParams(query).get('back_url'), '/app/wiki/graph?run_id=24');
    });

    test('an unscoped graph still names itself as the origin', () => {
        const href = articleHrefFromGraph(nodeUrl, {});

        assert.equal(new URLSearchParams(href.split('?')[1]).get('back_url'), '/app/wiki/graph');
    });

    test('a query the node url already carries is preserved, not clobbered', () => {
        const href = articleHrefFromGraph('/app/wiki/threat-hunting?claim_id=9', { runId: 24 });
        const query = new URLSearchParams(href.split('?')[1]);

        assert.equal(query.get('claim_id'), '9');
        assert.equal(query.get('back_url'), '/app/wiki/graph?run_id=24');
    });

    test('an origin the url already has is never overwritten', () => {
        // A finding deep link that happens to pass through the graph keeps pointing at the finding.
        const findingUrl = '/app/wiki/threat-hunting?claim_id=9&back_url=%2Fapp%2Fwiki%3Ftab%3Druns';

        assert.equal(articleHrefFromGraph(findingUrl, { runId: 24 }), findingUrl);
    });

    test('a missing node url is returned untouched rather than turned into a bare query', () => {
        for (const missing of ['', '   ', null, undefined]) {
            assert.equal(articleHrefFromGraph(missing, { runId: 24 }), missing);
        }
    });
});

/**
 * Source-level guards, in the same style as wikiBackLinkStyle.test.js — the project has no JSX test
 * renderer, and what needs protecting is the wiring rather than the pixels.
 */
describe('the wiring between the graph, the article page and the label', () => {
    const graph = readFileSync(join(here, 'Graph.jsx'), 'utf8');
    const show = readFileSync(join(here, 'Show.jsx'), 'utf8');

    test('the node panel link goes through articleHrefFromGraph, not straight to node.url', () => {
        assert.match(graph, /articleHrefFromGraph\(node\.url, graphScope\)/);
        assert.equal(
            /href=\{node\.url\}/.test(graph),
            false,
            'a raw node.url href would drop the origin and bring back "Tilbake til Wiki"',
        );
    });

    test('the graph passes the scope it was itself opened with', () => {
        // Anything else would be a second, drifting definition of "which graph am I on".
        assert.match(graph, /runId: initialRunId, pageId: initialPageId/);
    });

    test('Show.jsx resolves the label from the return context', () => {
        assert.match(show, /navigation_origin: navigationOrigin = null/);
        assert.match(show, /resolveWikiBackLink\(reviewReference, structureFinding, navigationOrigin\)/);
        assert.match(show, /WIKI_BACK_LINK_LABELS\[topBackLink\.context\]\(tw\)/);
    });

    test('every context resolveWikiBackLink can report has a label', () => {
        for (const context of ['finding', 'graph', 'wiki']) {
            assert.match(show, new RegExp(`\\n\\s{4}${context}: \\(tw\\) =>`), `no label for ${context}`);
        }
    });

    test('the graph label is translatable and falls back to Norwegian', () => {
        assert.match(show, /tw\.back_to_graph \?\? 'Tilbake til Grafvisning'/);
    });
});
