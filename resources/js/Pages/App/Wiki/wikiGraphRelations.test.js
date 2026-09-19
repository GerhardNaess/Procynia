import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    buildRelationIndex,
    buildRelationPanel,
    describeRelationHover,
    highlightedEdgeIds,
    highlightedNodeIds,
    relationDirectionality,
    relationPairKey,
    relationTypeLabel,
} from './wikiGraphRelations.js';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * A line in Grafvisning has to mean something the data actually supports.
 *
 * Every graph edge is a link_type=wikilink row: page A's current text contains [[anchor]] resolving
 * to page B. The model has NO typed semantic relation — nothing says "is part of" or "is delivered
 * by" — so none is shown. What it does have, recorded when the page was written, is the reason the
 * source page gives for each link and the prose the link sits in. These tests pin down both halves:
 * that the real grounding is surfaced, and that nothing beyond it is invented.
 */

const NODES = {
    'page-1': { id: 'page-1', title: 'Security Operations Center (SOC)', slug: 'soc' },
    'page-2': { id: 'page-2', title: 'Threat Hunting', slug: 'threat-hunting' },
    'page-3': { id: 'page-3', title: 'Advania Norge', slug: 'advania-norge' },
};

const edge = (overrides = {}) => ({
    id: 'link-1',
    link_id: 1,
    source: 'page-1',
    target: 'page-2',
    from_page_id: 1,
    to_page_id: 2,
    link_type: 'wikilink',
    confidence: 'certain',
    origin: 'deterministic',
    anchor_text: 'Threat Hunting',
    intents: [{
        anchor_text: 'Threat Hunting',
        reason: 'Tilleggspraksis som utfyller SOC-overvåking.',
        context: '…Proaktiv Threat Hunting beskriver praksis, metoder og formål utover hendelsesdrevet overvåking.',
    }],
    ...overrides,
});

const reverseEdge = (overrides = {}) => edge({
    id: 'link-2',
    source: 'page-2',
    target: 'page-1',
    anchor_text: 'Security Operations Center (SOC)',
    intents: [{
        anchor_text: 'Security Operations Center (SOC)',
        reason: 'Sette threat hunting i kontekst av løpende deteksjon og overvåking.',
        context: '…og omsetter funn til nye deteksjoner innenfor et løpende overvåkingsmiljø i Security Operations Center (SOC).',
    }],
    ...overrides,
});

const PAIR = relationPairKey('page-1', 'page-2');

describe('relationPairKey — one drawn line is one selection', () => {
    test('both directions resolve to the same pair', () => {
        assert.equal(relationPairKey('page-1', 'page-2'), relationPairKey('page-2', 'page-1'));
    });

    test('different pairs stay distinct', () => {
        assert.notEqual(relationPairKey('page-1', 'page-2'), relationPairKey('page-1', 'page-3'));
    });
});

describe('directionality — when two rows may be presented as one relation', () => {
    test('one row is a directed relation', () => {
        assert.equal(relationDirectionality([edge()]), 'directed');
    });

    test('same type in both directions aggregates', () => {
        assert.equal(relationDirectionality([edge(), reverseEdge()]), 'reciprocal');
    });

    /**
     * The guard that makes aggregation safe. Direction can carry meaning — "A is delivered by B" is
     * not "B is delivered by A" — so the moment the pair holds more than one link_type the links
     * are listed separately instead of being summed into one symmetric statement.
     */
    test('different types between the same pair are never aggregated', () => {
        assert.equal(
            relationDirectionality([edge(), reverseEdge({ link_type: 'article_to_concept' })]),
            'mixed',
        );
    });

    test('two rows in the SAME direction are not reciprocal', () => {
        assert.equal(relationDirectionality([edge(), edge({ id: 'link-9' })]), 'directed');
    });
});

describe('hover — the pair, and why', () => {
    test('the recorded reason is the line the user gets', () => {
        const hover = describeRelationHover(PAIR, buildRelationIndex([edge()]), NODES);

        assert.equal(hover.headline, 'Security Operations Center (SOC) → Threat Hunting');
        assert.equal(hover.detail, 'Tilleggspraksis som utfyller SOC-overvåking.');
        assert.equal(hover.isTypeFallback, false);
    });

    /**
     * "Wiki-lenke" says how the software found the connection. A page that recorded why it links
     * somewhere has said something more useful, so the type gives way to it.
     */
    test('the link type never displaces a recorded reason', () => {
        const hover = describeRelationHover(PAIR, buildRelationIndex([edge(), reverseEdge()]), NODES);

        assert.equal(hover.detail, 'Tilleggspraksis som utfyller SOC-overvåking.');
        assert.equal(/Wiki-lenke|Gjensidig lenket/.test(hover.detail), false);
    });

    test('with no reason on record the type is all there is to say', () => {
        const hover = describeRelationHover(PAIR, buildRelationIndex([edge({ intents: [] })]), NODES);

        assert.equal(hover.detail, 'Wiki-lenke');
        assert.equal(hover.isTypeFallback, true);
    });

    test('an unexplained reciprocal pair still says it stands for two links', () => {
        const bare = buildRelationIndex([edge({ intents: [] }), reverseEdge({ intents: [] })]);
        const hover = describeRelationHover(PAIR, bare, NODES);

        assert.equal(hover.headline, 'Security Operations Center (SOC) ↔ Threat Hunting');
        assert.equal(hover.detail, 'Gjensidig lenket · 2 lenker');
    });

    test('the tooltip is two lines and carries nothing else', () => {
        const hover = describeRelationHover(PAIR, buildRelationIndex([edge()]), NODES);

        assert.deepEqual(Object.keys(hover).sort(), ['detail', 'headline', 'isTypeFallback']);
    });

    test('the tooltip never carries the panel\'s supporting detail', () => {
        const index = buildRelationIndex([edge({
            anchor_text: 'respons',
            intents: [{ anchor_text: 'respons', reason: 'Peker videre til responssiden.', context: '…automatisert respons håndteres et annet sted.' }],
        })]);
        const hover = describeRelationHover(PAIR, index, NODES);
        const rendered = JSON.stringify(hover);

        for (const value of ['respons»', '…automatisert respons', 'Utledet fra sidens tekst', 'soc']) {
            assert.equal(rendered.includes(value), false, `hover must not carry: ${value}`);
        }
    });

    test('an unknown link type is shown as itself rather than guessed at', () => {
        assert.equal(relationTypeLabel('article_to_concept', {}), 'article_to_concept');
    });

    test('a pair with no edges produces no tooltip', () => {
        assert.equal(describeRelationHover('nothing::here', buildRelationIndex([edge()]), NODES), null);
    });
});

describe('the detail panel — why these two pages are connected', () => {
    test('the node pair is stated once, at the top', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge()]), NODES);

        assert.equal(panel.headline, 'Security Operations Center (SOC) → Threat Hunting');
    });

    test('the recorded reason is the panel\'s content, quoted unchanged', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge()]), NODES);

        assert.equal(panel.hasStatedReason, true);
        assert.deepEqual(panel.reasons.map((r) => r.text), ['Tilleggspraksis som utfyller SOC-overvåking.']);
    });

    test('the context recorded with a reason sits with it', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge()]), NODES);

        assert.match(panel.reasons[0].context, /Proaktiv Threat Hunting beskriver praksis/);
    });

    test('no context recorded means no context shown', () => {
        const noContext = buildRelationIndex([edge({ intents: [{ reason: 'En grunn.', context: null }] })]);

        assert.equal(buildRelationPanel(PAIR, noContext, NODES).reasons[0].context, null);
    });

    /**
     * The technical fields this panel used to lead with. Each was accurate and each was an answer
     * to a question nobody asked while looking at a line in a graph.
     */
    test('anchor text, origin, link count and intent internals are gone from the view model', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge(), reverseEdge()]), NODES);
        const rendered = JSON.stringify(panel);

        for (const gone of ['anchorText', 'originLabel', 'links', 'showDirectionPerLink', 'intents', 'link_id']) {
            assert.equal(rendered.includes(gone), false, `${gone} must not reach the panel`);
        }

        for (const gone of ['Utledet fra sidens tekst', 'Threat Hunting»']) {
            assert.equal(rendered.includes(gone), false, `${gone} must not reach the panel`);
        }
    });

    test('the source page is still one click away', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge()]), NODES);

        assert.deepEqual(panel.sources, [{ slug: 'soc', title: 'Security Operations Center (SOC)' }]);
    });

    test('several reasons from one page still offer that page once', () => {
        // The real EDR → LimaCharlie edge: two intents, two reasons, one source page.
        const twoIntents = buildRelationIndex([edge({
            intents: [
                { reason: 'Navngitt plattform for regel- og indikatoroppdateringer', context: 'a' },
                { reason: 'Navngitt plattform for kontinuerlige deteksjonsoppdateringer', context: 'b' },
            ],
        })]);
        const panel = buildRelationPanel(PAIR, twoIntents, NODES);

        assert.equal(panel.reasons.length, 2);
        assert.equal(panel.sources.length, 1);
    });

    test('a reason recorded twice is one answer, not two', () => {
        const repeated = buildRelationIndex([edge({
            intents: [
                { reason: 'Samme grunn.', context: 'a' },
                { reason: 'samme grunn', context: 'b' },
            ],
        })]);

        assert.deepEqual(buildRelationPanel(PAIR, repeated, NODES).reasons.map((r) => r.text), ['Samme grunn.']);
    });
});

describe('when no page recorded a reason', () => {
    test('a one-way link says the only thing that is true', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge({ intents: [] })]), NODES);

        assert.equal(panel.hasStatedReason, false);
        assert.deepEqual(
            panel.reasons.map((r) => r.text),
            ['Sidene er koblet fordi den ene siden lenker til den andre.'],
        );
    });

    test('a reciprocal pair says its own version of it', () => {
        const bare = buildRelationIndex([edge({ intents: [] }), reverseEdge({ intents: [] })]);
        const panel = buildRelationPanel(PAIR, bare, NODES);

        assert.equal(panel.headline, 'Security Operations Center (SOC) ↔ Threat Hunting');
        assert.deepEqual(panel.reasons.map((r) => r.text), ['Sidene er koblet fordi de lenker til hverandre.']);
    });

    test('the fallback is one sentence, never one per link', () => {
        const bare = buildRelationIndex([edge({ intents: [] }), reverseEdge({ intents: [] })]);

        assert.equal(buildRelationPanel(PAIR, bare, NODES).reasons.length, 1);
    });

    test('the source page is still offered', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge({ intents: [] })]), NODES);

        assert.equal(panel.sources.length, 1);
    });

    test('nothing semantic is invented to fill the gap', () => {
        const panel = buildRelationPanel(PAIR, buildRelationIndex([edge({ intents: [] })]), NODES);

        for (const invented of ['inngår i', 'avhenger av', 'leveres av', 'støtter', 'bruker ']) {
            assert.equal(JSON.stringify(panel).includes(invented), false);
        }
    });
});

describe('two pages that link to each other', () => {
    const reciprocal = buildRelationIndex([edge(), reverseEdge()]);

    test('the panel answers the same question, with both accounts', () => {
        const panel = buildRelationPanel(PAIR, reciprocal, NODES);

        assert.equal(panel.headline, 'Security Operations Center (SOC) ↔ Threat Hunting');
        assert.deepEqual(panel.reasons.map((r) => r.text), [
            'Tilleggspraksis som utfyller SOC-overvåking.',
            'Sette threat hunting i kontekst av løpende deteksjon og overvåking.',
        ]);
    });

    test('each account keeps the page it came from, so both are reachable', () => {
        assert.deepEqual(
            buildRelationPanel(PAIR, reciprocal, NODES).sources.map((s) => s.slug),
            ['soc', 'threat-hunting'],
        );
    });

    test('two pages that gave the same account are not shown twice', () => {
        const same = buildRelationIndex([
            edge({ intents: [{ reason: 'De hører sammen i SOC-arbeidet.', context: null }] }),
            reverseEdge({ intents: [{ reason: 'De hører sammen i SOC-arbeidet.', context: null }] }),
        ]);

        assert.equal(buildRelationPanel(PAIR, same, NODES).reasons.length, 1);
    });
});

describe('visual highlight — what a hovered or selected line lights up', () => {
    const index = buildRelationIndex([
        edge(),
        reverseEdge(),
        edge({ id: 'link-3', source: 'page-1', target: 'page-3' }),
    ]);

    test('both endpoints are highlighted, and nothing else', () => {
        assert.deepEqual([...highlightedNodeIds(PAIR, index)].sort(), ['page-1', 'page-2']);
    });

    test('every edge of the pair is highlighted, so a reciprocal line lights fully', () => {
        assert.deepEqual([...highlightedEdgeIds(PAIR, index)].sort(), ['link-1', 'link-2']);
    });

    test('an unrelated edge is not part of the highlight', () => {
        assert.equal(highlightedEdgeIds(PAIR, index).has('link-3'), false);
        assert.equal(highlightedNodeIds(PAIR, index).has('page-3'), false);
    });

    test('no selection highlights nothing', () => {
        assert.equal(highlightedNodeIds('nothing::here', index).size, 0);
        assert.equal(highlightedEdgeIds('nothing::here', index).size, 0);
    });
});

describe('the index itself', () => {
    test('edges are grouped by pair, in payload order', () => {
        const index = buildRelationIndex([
            edge(),
            edge({ id: 'link-3', source: 'page-1', target: 'page-3' }),
            reverseEdge(),
        ]);

        assert.equal(index.size, 2);
        assert.deepEqual(index.get(PAIR).map((e) => e.id), ['link-1', 'link-2']);
    });

    test('malformed entries are skipped rather than indexed under undefined', () => {
        assert.equal(buildRelationIndex([null, {}, { source: 'page-1' }, edge()]).size, 1);
    });

    test('an empty payload is an empty index', () => {
        assert.equal(buildRelationIndex().size, 0);
        assert.equal(buildRelationIndex([]).size, 0);
    });
});

/**
 * Source-level guards on the wiring, in the same style as wikiBackLinkStyle.test.js — the project
 * has no JSX test renderer, and what needs protecting here is behaviour that is easy to break by a
 * well-meaning edit.
 */
describe('the wiring in Graph.jsx', () => {
    const graph = readFileSync(join(here, 'Graph.jsx'), 'utf8');

    /**
     * One handler's body: from its `renderer.on(...)` up to the next one. Splitting on '});'
     * instead would truncate at the first object literal argument inside the handler.
     */
    const handlerBody = (eventName) => {
        const start = graph.indexOf(`renderer.on('${eventName}'`);
        assert.notEqual(start, -1, `no handler for ${eventName}`);

        const next = graph.indexOf('renderer.on(', start + 1);

        return graph.slice(start, next === -1 ? undefined : next);
    };

    test('edge events are enabled — sigma emits none without this', () => {
        assert.match(graph, /enableEdgeEvents:\s*true/);
    });

    test('hover, click and leave are all handled for edges', () => {
        for (const event of ['enterEdge', 'leaveEdge', 'clickEdge']) {
            assert.match(graph, new RegExp(`renderer\\.on\\('${event}'`), `missing ${event}`);
        }
    });

    test('hover describes the pair, so either half of a reciprocal line answers the same', () => {
        assert.match(handlerBody('enterEdge'), /describeRelationHover\(pairKey/);
    });

    test('node click and edge click each clear the other selection', () => {
        assert.match(handlerBody('clickNode'), /setSelectedPairKey\(null\)/, 'a node click must close the relation panel');
        assert.match(handlerBody('clickEdge'), /setSelectedNode\(null\)/, 'an edge click must not leave the node panel open');
    });

    test('clicking empty space clears both selections', () => {
        const stageHandler = handlerBody('clickStage');

        assert.match(stageHandler, /setSelectedNode\(null\)/);
        assert.match(stageHandler, /setSelectedPairKey\(null\)/);
    });

    test('the two panels never occupy the right slot at once', () => {
        assert.match(graph, /\{! selectedNode && relationPanel &&/);
    });

    test('hover reads the graph payload and makes no request', () => {
        const hoverHandler = handlerBody('enterEdge');

        assert.equal(/fetch\(/.test(hoverHandler), false, 'hover must never hit the network');
        assert.match(hoverHandler, /relationIndexRef\.current/, 'hover must read the in-memory index');
    });

    test('the edge hit area is widened beyond sigma\'s thin default', () => {
        // Sigma picks edges from the rendered geometry, so the thickness IS the hit area.
        assert.match(graph, /const BASE_EDGE_SIZE = ([2-9]|\d{2})/);
        assert.match(graph, /minEdgeThickness:\s*MIN_EDGE_THICKNESS/);
    });

    test('the source page is still opened through the existing back_url mechanism', () => {
        assert.match(graph, /articleHrefFromGraph\(`\/app\/wiki\/\$\{source\.slug\}`, graphScope\)/);
    });

    test('the panel leads with the question, not with a technical field list', () => {
        assert.match(graph, /tw\.graph_relation_why \?\? 'Hvorfor er de koblet\?'/);

        // The fields that used to fill the panel before the reason was given the space.
        for (const gone of ['graph_relation_anchor', 'graph_relation_origin', 'graph_relation_links_heading', 'graph_relation_from']) {
            assert.equal(graph.includes(gone), false, `${gone} must no longer be rendered`);
        }
    });

    test('the hover shows the reason rather than the link type', () => {
        const hoverComponent = graph.slice(graph.indexOf('function EdgeTooltip'), graph.indexOf('function RelationPanel'));

        assert.match(hoverComponent, /\{hover\.detail\}/);
        assert.equal(/graph_relation_wikilink/.test(hoverComponent), false);
    });

    test('no text in the tooltip or the relation panel falls below 16px', () => {
        const start = graph.indexOf('function EdgeTooltip');
        const end = graph.indexOf('// ─── main component');
        const region = graph.slice(start, end);

        assert.ok(start !== -1 && end > start, 'could not locate the relation components');
        assert.equal(/text-xs\b/.test(region), false, 'text-xs is 12px');
        assert.equal(/text-sm\b/.test(region), false, 'text-sm is 14px');

        for (const [, px] of region.matchAll(/text-\[(\d+)px\]/g)) {
            assert.ok(Number(px) >= 16, `text-[${px}px] is below the 16px floor`);
        }
    });

    test('the relation headline stays one step above the rest', () => {
        assert.match(graph, /className="text-lg font-semibold leading-snug text-slate-950">\{panel\.headline\}/);
    });

    test('every paragraph in the panel is at the readable size', () => {
        const region = graph.slice(graph.indexOf('function RelationPanel'), graph.indexOf('// ─── main component'));

        for (const [, cls] of region.matchAll(/<p className="([^"]*)"/g)) {
            assert.match(cls, /\btext-(base|lg)\b/, `expected text-base or text-lg, got: ${cls}`);
        }
    });

    test('the open action is as readable as the text around it', () => {
        assert.match(graph, /text-base font-semibold text-slate-700 transition hover:border-slate-300/);
    });

    /**
     * The panel may now ask "Hvorfor er de koblet?" precisely because the answer is quoted from the
     * page's own recorded link intent rather than produced here. Nothing composes an explanation,
     * and the fallback states the link itself rather than implying a meaning it does not have.
     */
    test('the answer is quoted data or an honest fallback, never composed', () => {
        assert.match(graph, /\{reason\.text\}/);
        assert.match(graph, /\{reason\.context\}/);
        assert.equal(/graph_relation_reason\b/.test(graph), false, 'the old technical label is gone');
    });
});
