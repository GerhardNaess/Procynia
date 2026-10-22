import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { buildGraphListRows, graphResultAnnouncement, relationDirectionFor } from '../Pages/App/Wiki/wikiGraphList.js';
import { buildRelationIndex, relationPairKey } from '../Pages/App/Wiki/wikiGraphRelations.js';
import { focusListGroups, focusViewAnnouncement, focusViewStateKey } from '../Pages/App/Wiki/wikiGraphFocusList.js';
import { ARTICLE_HEADING_SHIFT, markdownHeadingTag } from '../Pages/App/Wiki/wikiMarkdownHeadings.js';
import { articleEditErrorBlockKey, claimActionFocusCandidates } from '../Pages/App/Wiki/wikiShowA11y.js';
import { runStatusChanges, runStatusSnapshot } from '../Pages/App/Wiki/wikiRunAnnouncements.js';
import { askAnnouncement, askStateKey } from '../Pages/App/Wiki/wikiAskStatus.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, '..', ...parts), 'utf8');
const lang = (code) => readFileSync(join(here, '..', '..', '..', 'lang', code, 'procynia.php'), 'utf8');
const css = readFileSync(join(here, '..', '..', 'css', 'app.css'), 'utf8');

/**
 * Fase 5 of the WCAG plan (docs/wcag-accessibility-plan.md §19): Enterprise Wiki. The rules are pinned
 * here; behaviour in a real browser is in tests/e2e/a11y-wiki.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

// The graph payload's shape (EnterpriseWikiGraphDataService), as the canvas receives it.
const NODES = [
    { id: 'p1', page_id: 1, title: 'Leverandørsikkerhet', page_type: 'article', status: 'ok', url: '/app/wiki/a', claim_count: 3, source_reference_count: 1, lint_error_count: 0, lint_warning_count: 0 },
    { id: 'p2', page_id: 2, title: 'Kontinuitetsplan', page_type: 'concept', status: 'warning', url: '/app/wiki/b', claim_count: 1, source_reference_count: 0, lint_error_count: 0, lint_warning_count: 2 },
    { id: 'p3', page_id: 3, title: 'Tilgangsstyring', page_type: 'entity', status: 'ok', url: '/app/wiki/c' },
    { id: 'p4', page_id: 4, title: 'Avvik', page_type: 'concept', status: 'error', url: '/app/wiki/d', lint_error_count: 1 },
];
const EDGES = [
    { id: 'e1', source: 'p1', target: 'p2', link_type: 'wikilink' },
    { id: 'e2', source: 'p3', target: 'p1', link_type: 'wikilink' },
    { id: 'e3', source: 'p2', target: 'p1', link_type: 'wikilink' },
];

describe('the graph as a list is the same pages and lines as the canvas (A11Y-050)', () => {
    test('one row per displayed page, in title order', () => {
        const rows = buildGraphListRows(NODES, EDGES);

        assert.deepEqual(rows.map((row) => row.title), ['Avvik', 'Kontinuitetsplan', 'Leverandørsikkerhet', 'Tilgangsstyring']);
        assert.deepEqual(new Set(rows.map((row) => row.id)), new Set(NODES.map((node) => node.id)));
    });

    test('every relation is a pair the canvas draws, keyed as a clicked line is', () => {
        const rows = buildGraphListRows(NODES, EDGES);
        const canvasPairs = new Set(buildRelationIndex(EDGES).keys());
        const listPairs = new Set(rows.flatMap((row) => row.relations.map((relation) => relation.pairKey)));

        assert.deepEqual(listPairs, canvasPairs);
        assert.ok(listPairs.has(relationPairKey('p1', 'p2')));
    });

    test('each side of a line lists the other, with the direction seen from itself', () => {
        const rows = buildGraphListRows(NODES, EDGES);
        const article = rows.find((row) => row.id === 'p1');
        const entity = rows.find((row) => row.id === 'p3');

        assert.deepEqual(article.relations.map((relation) => [relation.otherTitle, relation.direction]), [
            ['Kontinuitetsplan', 'both'],
            ['Tilgangsstyring', 'incoming'],
        ]);
        assert.deepEqual(entity.relations.map((relation) => [relation.otherTitle, relation.direction]), [['Leverandørsikkerhet', 'outgoing']]);
        assert.equal(rows.find((row) => row.id === 'p4').relations.length, 0, 'an isolated page lists no relation');
    });

    test('a filtered-out page takes its lines with it — on both presentations, never only one', () => {
        const displayed = NODES.filter((node) => node.id !== 'p2');
        const rows = buildGraphListRows(displayed, EDGES);
        const article = rows.find((row) => row.id === 'p1');

        assert.deepEqual(article.relations.map((relation) => relation.otherTitle), ['Tilgangsstyring']);
        assert.equal(rows.some((row) => row.id === 'p2'), false);
    });

    test('directions are read off the edges between the two pages only', () => {
        assert.equal(relationDirectionFor('p1', [EDGES[0]]), 'outgoing');
        assert.equal(relationDirectionFor('p1', [EDGES[1]]), 'incoming');
        assert.equal(relationDirectionFor('p1', [EDGES[0], EDGES[2]]), 'both');
    });

    test('the counts a row shows come from the payload', () => {
        const row = buildGraphListRows(NODES, EDGES).find((candidate) => candidate.id === 'p2');

        assert.equal(row.claimCount, 1);
        assert.equal(row.lintWarnings, 2);
        assert.equal(row.status, 'warning');
    });

    test('the list is built from the canvas\'s own `displayed` set, and asks for nothing', () => {
        const graph = code(read('Pages', 'App', 'Wiki', 'Graph.jsx'));

        assert.match(graph, /buildGraphListRows\(displayed\.nodes, displayed\.edges/);
        assert.deepEqual(graph.match(/fetch\(/g), ['fetch('], 'still one request: the graph data');
        assert.equal(/fetch\(/.test(read('Pages', 'App', 'Wiki', 'WikiGraphListView.jsx')), false);
    });

    test('the list opens the same panels as a click on a dot or a line', () => {
        const graph = code(read('Pages', 'App', 'Wiki', 'Graph.jsx'));
        const showNode = graph.slice(graph.indexOf('const showNodeFromList'), graph.indexOf('const showRelationFromList'));
        const showRelation = graph.slice(graph.indexOf('const showRelationFromList'), graph.indexOf('// Into the panel'));

        assert.match(showNode, /setSelectedNode\(/);
        assert.match(showNode, /setSelectedPairKey\(null\)/);
        assert.match(showRelation, /setSelectedPairKey\(pairKey\)/);
        assert.match(showRelation, /setSelectedNode\(null\)/);
        assert.match(graph, /onShowNode=\{showNodeFromList\}/);
        assert.match(graph, /onShowRelation=\{showRelationFromList\}/);
        assert.match(graph, /onFocusPage=\{focusOnPage\}/);
    });

    test('a list action moves focus into the panel it opened, and closing gives it back', () => {
        const graph = code(read('Pages', 'App', 'Wiki', 'Graph.jsx'));

        assert.match(graph, /focusElement\(nodePanelRef\.current \?\? relationPanelRef\.current\)/);
        assert.match(graph, /const closeNodePanel = \(\) => \{\s*setSelectedNode\(null\);\s*refocusIfLost\(\[panelTriggerIdRef\.current\]\);/);
        assert.match(graph, /const closeRelationPanel = \(\) => \{\s*setSelectedPairKey\(null\);\s*refocusIfLost\(\[panelTriggerIdRef\.current\]\);/);
    });

    test('the switch is a named group of toggle buttons; the canvas is a named picture', () => {
        const graph = read('Pages', 'App', 'Wiki', 'Graph.jsx');

        assert.match(graph, /role="group" aria-label=\{ta\.wiki_graph_view_label \?\? 'Visning'\}/);
        assert.match(graph, /role="group" aria-label=\{ta\.wiki_graph_mode_label \?\? 'Grafmodus'\}/);
        assert.match(graph, /aria-pressed=\{view === key\}/);
        assert.match(graph, /data-graph-view="full"\s*\n\s*role="img"\s*\n\s*aria-label=\{canvasSummary\}/);
        assert.match(read('Pages', 'App', 'Wiki', 'WikiGraphFocusView.jsx'), /data-graph-view="focus"\s*\n\s*role="img"/);
    });

    test('page type and status are words in the list and the node panel, not only colours', () => {
        const list = read('Pages', 'App', 'Wiki', 'WikiGraphListView.jsx');
        const graph = read('Pages', 'App', 'Wiki', 'Graph.jsx');

        assert.match(list, /pageTypeText\(row\.pageType, tw\)/);
        assert.match(list, /lintStatusText\(row\.status, tw\)/);
        assert.match(graph, /lintStatusText\(node\.nodeStatus, tw\)/);
        assert.equal(graph.includes('{node.nodeStatus}</span>'), false, 'the raw status key is gone');
    });
});

describe('what the graph says when the search or a filter changes (4.1.3)', () => {
    test('nothing for the first load, nothing when the count is unchanged', () => {
        assert.equal(graphResultAnnouncement(null, '16', 16), null);
        assert.equal(graphResultAnnouncement('16', '16', 16), null);
    });

    test('the new count, out of all pages', () => {
        assert.equal(graphResultAnnouncement('16', '2', 16), 'Viser 2 av 16 sider.');
        assert.equal(graphResultAnnouncement('16', '2', 16, {}, { wiki_graph_results: 'Showing :shown of :total pages.' }), 'Showing 2 of 16 pages.');
    });

    test('an empty result says what it means', () => {
        assert.equal(graphResultAnnouncement('16', '0', 16), 'Ingen Wiki-sider matcher filtrene.');
    });

    test('the empty overlay keeps role="status" but is not live — the announcer speaks once', () => {
        assert.match(read('Pages', 'App', 'Wiki', 'Graph.jsx'), /role="status"\s*\n\s*aria-live="off"/);
    });
});

describe('focus mode as a list (A11Y-050)', () => {
    const nodes = [
        { id: 'a', page_id: 1, title: 'Fokus', depth: 0 },
        { id: 'c', page_id: 3, title: 'Bravo', depth: 1 },
        { id: 'b', page_id: 2, title: 'Alfa', depth: 1 },
        { id: 'd', page_id: 4, title: 'Charlie', depth: 2 },
    ];

    test('grouped by hop distance, the focus page first, titles in order', () => {
        assert.deepEqual(focusListGroups(nodes).map((group) => [group.depth, group.nodes.map((node) => node.title)]), [
            [0, ['Fokus']],
            [1, ['Alfa', 'Bravo']],
            [2, ['Charlie']],
        ]);
    });

    test('a loaded neighbourhood, an empty one and a failure are each said once', () => {
        const data = { focus: { page_id: 1 }, nodes, summary: { node_count: 4, edge_count: 3 } };

        assert.equal(focusViewStateKey({ nothingSelected: false, loading: true, errorKind: null, data: null }), 'loading');
        assert.equal(focusViewAnnouncement('loading', focusViewStateKey({ nothingSelected: false, loading: false, errorKind: null, data }), data), '4 sider · 3 koblinger');
        assert.equal(focusViewAnnouncement('loading', 'ready:1:1:0', { nodes: [nodes[0]] }), 'Denne siden har ingen naboer her.');
        assert.equal(focusViewAnnouncement('loading', 'error:unavailable', null), 'Fokusvisningen er ikke tilgjengelig.');
        assert.equal(focusViewAnnouncement('ready:1:4:3', 'loading', data), null, 'loading itself is not spoken');
    });

    test('another page with the same counts is still a change', () => {
        const one = focusViewStateKey({ nothingSelected: false, loading: false, errorKind: null, data: { focus: { page_id: 1 }, summary: { node_count: 2, edge_count: 1 } } });
        const two = focusViewStateKey({ nothingSelected: false, loading: false, errorKind: null, data: { focus: { page_id: 2 }, summary: { node_count: 2, edge_count: 1 } } });

        assert.notEqual(one, two);
    });
});

describe('the article: the page title is the only h1 (A11Y-046)', () => {
    test('Markdown headings render one level down, h6 is the floor', () => {
        assert.equal(ARTICLE_HEADING_SHIFT, 1);
        assert.equal(markdownHeadingTag(1), 'h2');
        assert.equal(markdownHeadingTag(2), 'h3');
        assert.equal(markdownHeadingTag(5), 'h6');
        assert.equal(markdownHeadingTag(6), 'h6');
    });

    test('Show.jsx renders the article through WikiMarkdown, never bare react-markdown', () => {
        const show = read('Pages', 'App', 'Wiki', 'Show.jsx');

        assert.equal(/from 'react-markdown'/.test(show), false);
        assert.equal((show.match(/<WikiMarkdown components=\{\{ a: WikiArticleLink \}\}>/g) ?? []).length, 2);
    });

    test('the heading keeps its Markdown level for the typography, so it looks as before', () => {
        const markdown = read('Pages', 'App', 'Wiki', 'WikiMarkdown.jsx');

        assert.match(markdown, /data-md-level=\{level\}/);
        assert.match(css, /& h1:not\(\[data-md-level\]\),\s*\n\s*& \[data-md-level='1'\]/);
        assert.match(css, /& h2:not\(\[data-md-level\]\),\s*\n\s*& \[data-md-level='2'\]/);
    });

    test('the stored Markdown and the parser are untouched: no plugins, no rewriting', () => {
        const markdown = read('Pages', 'App', 'Wiki', 'WikiMarkdown.jsx');

        assert.equal(/remarkPlugins|rehypePlugins|\.replace\(/.test(code(markdown)), false);
    });

    test('a long article reflows: words wrap, code blocks and images stay inside', () => {
        assert.match(css, /\.wiki-article \{[\s\S]*?overflow-wrap: anywhere;/);
        assert.match(css, /& pre \{\s*\n\s*max-width: 100%;\s*\n\s*overflow-x: auto;/);
        assert.match(css, /& img \{\s*\n\s*max-width: 100%;/);
    });

    test('a source table is named by its visible caption and scrolls in its own box (A11Y-113)', () => {
        const show = read('Pages', 'App', 'Wiki', 'Show.jsx');

        assert.match(show, /<table aria-labelledby=\{captionId\}/);
        assert.match(show, /<ScrollRegion label=\{captionText\}/);
    });
});

describe('claims are decided without losing focus (A11Y-014, -047, -117)', () => {
    test('focus goes to the next claim still to decide, wrapping round, then the claim, then the heading', () => {
        assert.deepEqual(claimActionFocusCandidates(2, [1, 2, 3]), ['claim-3', 'claim-1', 'claim-2', 'wiki-verification-toggle']);
        assert.deepEqual(claimActionFocusCandidates(3, [1, 2, 3]), ['claim-1', 'claim-2', 'claim-3', 'wiki-verification-toggle']);
        assert.deepEqual(claimActionFocusCandidates(9, []), ['claim-9', 'wiki-verification-toggle']);
    });

    test('every claim decision keeps the scroll position and refocuses only if focus was lost', () => {
        const show = code(read('Pages', 'App', 'Wiki', 'Show.jsx'));
        const options = show.slice(show.indexOf('const claimActionOptions'), show.indexOf('const approveClaim'));

        assert.match(options, /preserveScroll: true/);
        assert.match(options, /refocusIfLost\(claimActionFocusCandidates\(/);
        // One guarded dispatch carries the options for every decision.
        assert.equal((show.match(/claimActionOptions\(claim\)/g) ?? []).length, 1);
        assert.equal((show.match(/sendClaimAction\(\s*claim,/g) ?? []).length, 4, 'approve, reject, undo and blocking');
    });

    test('decision buttons stay focusable while saving, and say which claim they decide', () => {
        const show = code(read('Pages', 'App', 'Wiki', 'Show.jsx'));

        assert.equal(/disabled=\{claimProcessing === claim\.id/.test(show), false);
        // One claim card (the article only carries a short note): keep, remove, undo and the two
        // blocking choices.
        assert.ok((show.match(/aria-disabled=\{isBusy \|\| undefined\}/g) ?? []).length >= 5);
        assert.ok((show.match(/aria-describedby=\{claimDescribedBy\}/g) ?? []).length >= 5);
    });

    test('the comment has a label, not only a placeholder', () => {
        const show = read('Pages', 'App', 'Wiki', 'Show.jsx');

        assert.equal((show.match(/<label htmlFor=\{commentId\}/g) ?? []).length, 1);
        assert.match(show, /id=\{commentId\}/);
    });

    test('the blocking choice and the chosen source element are pressed buttons, not disabled ones', () => {
        const show = read('Pages', 'App', 'Wiki', 'Show.jsx');

        assert.equal((show.match(/aria-pressed=\{claim\.user_decision === 'blocking'\}/g) ?? []).length, 1);
        assert.equal((show.match(/aria-pressed=\{claim\.user_decision === 'not_blocking'\}/g) ?? []).length, 1);
        assert.match(show, /aria-pressed=\{isSelected\}/);
    });

    test('a claim card is named by its place in the work list', () => {
        const show = read('Pages', 'App', 'Wiki', 'Show.jsx');

        assert.equal((show.match(/aria-labelledby=\{claimLabelId\}/g) ?? []).length, 1);
    });
});

describe('article editing (A11Y-049)', () => {
    const show = read('Pages', 'App', 'Wiki', 'Show.jsx');

    test('each paragraph field is named by its place, and described by the Markdown hint', () => {
        assert.match(show, /ta\.wiki_article_edit_block_label \?\? 'Rediger avsnitt :number av :total'/);
        assert.match(show, /aria-describedby=\{\['wiki-article-edit-hint', isInvalid \? 'wiki-article-edit-error' : null\]/);
        assert.match(show, /id="wiki-article-edit-hint"/);
    });

    test('a refused save marks the paragraph it is about, and focus goes there', () => {
        assert.deepEqual(articleEditErrorBlockKey({ 'blocks.1.markdown': 'Et avsnitt kan ikke være tomt.' }, ['a', 'b']), 'b');
        assert.equal(articleEditErrorBlockKey({ expected_page_version_id: 'x' }, ['a']), null);
        assert.equal(articleEditErrorBlockKey({}, ['a']), null);
        assert.match(show, /onError: \(\) => requestArticleErrorFocus\(\)/);
        assert.match(show, /data-form-error-summary=""/);
    });

    test('focus moves into the editor and back out of it', () => {
        assert.match(show, /focusElement\(first \? document\.getElementById\(`wiki-article-edit-\$\{first\.block_key\}`\) : null\)/);
        assert.match(show, /refocusIfLost\(\['wiki-article-edit-button'\]\)/);
    });

    test('saving keeps focus: read-only fields and aria-disabled buttons, never disabled ones', () => {
        assert.match(show, /readOnly=\{isSavingArticle\}/);
        assert.equal(/disabled=\{isSavingArticle\}/.test(code(show)), false);
    });

    test('no autoFocus left in the Wiki pages', () => {
        for (const file of ['Show.jsx', 'Index.jsx', 'Graph.jsx', 'WikiReviewPanel.jsx', 'Ask.jsx', 'WikiGraphFocusView.jsx', 'WikiGraphListView.jsx']) {
            assert.equal(/\bautoFocus\b/.test(code(read('Pages', 'App', 'Wiki', file))), false, file);
        }
    });

    test('the page actions are a disclosure, not a menu without menu keys (A11Y-103)', () => {
        assert.equal(/role="menu(item)?"/.test(code(show)), false);
        assert.equal(/aria-haspopup="menu"/.test(code(show)), false);
        assert.match(show, /aria-controls="wiki-page-actions-panel"/);
    });

    test('collapsible sections are headings with a disclosure button (A11Y-028)', () => {
        assert.match(show, /<h2 className="text-base font-semibold">\s*\n\s*<button\s*\n\s*id="wiki-verification-toggle"/);
        for (const id of ['wiki-verification-content', 'wiki-verified-claims', 'wiki-structural-findings']) {
            assert.match(show, new RegExp(`aria-controls="${id}"`));
            assert.match(show, new RegExp(`id="${id}"`));
        }
    });
});

describe('review and publishing (A11Y-002, -014, -118)', () => {
    const panel = read('Pages', 'App', 'Wiki', 'WikiReviewPanel.jsx');

    test('the panel is a named, focusable section with an h2 above its h3 blocks', () => {
        assert.match(panel, /id="wiki-review-panel"\s*\n\s*tabIndex=\{-1\}\s*\n\s*aria-labelledby="wiki-review-panel-title"/);
        assert.match(panel, /<h2 id="wiki-review-panel-title" className="sr-only">/);
    });

    test('every dialog has a description, and falls back to the panel when its trigger is gone', () => {
        for (const id of ['wiki-qa-description', 'wiki-submit-description', 'wiki-changes-description']) {
            assert.match(panel, new RegExp(`descriptionId="${id}"`));
            assert.match(panel, new RegExp(`<p id="${id}"`));
        }
        assert.equal((panel.match(/fallbackFocusRef=\{panelRef\}/g) ?? []).length, 3);
    });

    test('after an action, focus lands where the person can continue', () => {
        assert.match(panel, /refocusIfLost\(\['wiki-review-panel'\]\)/);
        assert.match(panel, /refocusIfLost\(FOCUS_AFTER_ERROR\[lastActionRef\.current\]/);
    });

    test('the minimum length for «Send tilbake» is stated where it applies', () => {
        assert.match(panel, /ta\.wiki_review_reason_min \?\? 'Minst :min tegn\.'\)\.replace\(':min', String\(MIN_REASON\)\)/);
        assert.match(panel, /aria-required="true"/);
    });

    test('the review rules themselves are unchanged', () => {
        assert.match(panel, /const canSendBack = isInReview && reviewAssignment\.can_send_back === true;/);
        assert.match(panel, /const canApproveFinal = isInReview && reviewAssignment\.can_approve_final === true;/);
        assert.match(panel, /const MIN_REASON = 10;/);
    });
});

describe('the Wiki list, sources and runs', () => {
    const index = read('Pages', 'App', 'Wiki', 'Index.jsx');

    test('no hand-made modal is left: every dialog is ActionDialog (A11Y-002)', () => {
        assert.equal(/fixed inset-0/.test(index), false);
        assert.equal((index.match(/<ActionDialog\b/g) ?? []).length, 7, 'uploaded, owner edit, delete, decision, cancel run, retry, dismiss run');
        for (const id of ['wiki-source-owner-edit-file', 'wiki-source-delete-description', 'wiki-run-cancel-description', 'wiki-run-retry-description', 'wiki-run-dismiss-description']) {
            assert.match(index, new RegExp(`descriptionId="${id}"`));
        }
    });

    test('every filter and search is named (A11Y-048)', () => {
        for (const key of ['wiki_filter_page_type', 'wiki_filter_status', 'wiki_filter_document_owner', 'wiki_filter_lint', 'wiki_filter_sort', 'wiki_filter_decision', 'wiki_pages_search_label', 'wiki_sources_search_label', 'wiki_search_button']) {
            assert.match(index, new RegExp(`ta\\.${key}`), key);
        }
    });

    test('the run rows are not live regions; a status change is said once (A11Y-112)', () => {
        assert.equal(/aria-live=\{isActive/.test(index), false);
        assert.equal((index.match(/useRunStatusAnnouncements\(/g) ?? []).length, 2, 'Kildedokumenter and Kjøringer');
    });

    test('a run status change is described once, and only for a run that was already there', () => {
        const before = runStatusSnapshot([{ id: 1, statusKey: 'queued' }, { id: 2, statusKey: 'qa' }]);
        const changes = runStatusChanges(before, [
            { id: 1, statusKey: 'generating_pages', name: 'Rutiner.docx', statusLabel: 'Genererer sider' },
            { id: 2, statusKey: 'qa', name: 'Policy.docx', statusLabel: 'QA' },
            { id: 3, statusKey: 'queued', name: 'Ny.docx', statusLabel: 'I kø' },
        ]);

        assert.deepEqual(changes, [{ id: '1', message: 'Kjøring for Rutiner.docx: Genererer sider.', failed: false }]);
        assert.deepEqual(runStatusChanges(null, [{ id: 1, statusKey: 'failed', name: 'x', statusLabel: 'Feilet' }]), []);
        assert.equal(runStatusChanges(before, [{ id: 1, statusKey: 'failed', name: 'x', statusLabel: 'Feilet' }])[0].failed, true);
    });

    test('timeline steps say their state in words, and the current one is marked (A11Y-024)', () => {
        assert.match(index, /aria-current=\{isCurrent \? 'step' : undefined\}/);
        assert.match(index, /<span className="sr-only">, \{timelineStateText\(state, ta\)\}<\/span>/);
    });

    test('the upload field shows its focus ring and is described by file, formats and error (A11Y-011)', () => {
        assert.match(index, /peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-violet-600/);
        assert.equal(/peer- peer-/.test(index), false, 'the empty peer- classes are gone');
        assert.match(index, /\['wiki-source-file-name', 'wiki-source-file-hint', uploadForm\.errors\.file \? 'wiki-source-file-error' : null\]/);
        assert.match(index, /<p id="wiki-source-file-error" role="alert"/);
    });

    test('row actions are described by their document, and tables have captions and scoped headers', () => {
        // Both layouts (table from 1280px, cards below) name the row by its file and hand that id
        // to the shared row cells; every control in those cells is described by it.
        assert.equal((index.match(/<(?:span|h3) id=\{nameId\}/g) ?? []).length, 2, 'table row and card');
        for (const helper of ['renderWikiStatus', 'renderOwner', 'renderActions']) {
            assert.match(index, new RegExp(`\\{${helper}\\(source, nameId\\b`), helper);
        }
        assert.ok((index.match(/describedById=\{nameId\}/g) ?? []).length >= 4);
        for (const cell of ['IngestStatusBadge', 'SourceGeneratedPages', 'SourceOwner', 'SourceRowActions']) {
            const start = index.indexOf(`function ${cell}(`);
            const body = index.slice(start, index.indexOf('\nfunction ', start + 1));
            // Generated-page links are named by their own page title and are not counted.
            const controls = (body.match(/<button\b|<a\b|<Link href=\{detailsHref\}/g) ?? []).length;
            const described = (body.match(/aria-describedby=\{describedById\}/g) ?? []).length;
            assert.ok(start >= 0 && controls > 0, cell);
            assert.equal(described, controls, `${cell}: every button and download link is described by the file name`);
        }
        assert.ok((index.match(/<caption className="sr-only">/g) ?? []).length >= 4);
        assert.match(index, /<th scope="row"/);
    });

    test('findings filter chips are pressed buttons in a named group (A11Y-117)', () => {
        assert.match(index, /aria-pressed=\{localFilter === filterKey\}/);
        assert.match(index, /role="group" aria-label=\{ta\.wiki_findings_filter_label/);
    });
});

describe('Spør Wiki (A11Y-042)', () => {
    const ask = read('Pages', 'App', 'Wiki', 'Ask.jsx');

    test('one h1: the layout title is off', () => {
        assert.match(ask, /showPageTitle=\{false\}/);
    });

    test('the question is a FormField, and a refusal puts focus on it', () => {
        assert.match(ask, /<FormField\s*\n\s*id="wiki-question"/);
        assert.match(ask, /onError: \(\) => focusFirstError\(\)/);
    });

    test('only the kind of answer is spoken, never the answer itself', () => {
        assert.equal(askStateKey(true, null), 'asking');
        assert.equal(askAnnouncement('asking'), 'Søker i Wiki-en...');
        assert.equal(askAnnouncement(askStateKey(false, { answer_status: 'answered', answer: 'Et langt svar …', question: 'Q' })), 'Svaret er klart.');
        assert.equal(askAnnouncement(askStateKey(false, { answer_status: 'insufficient_evidence', answer: '', question: 'Q' })),
            'Jeg finner ikke tilstrekkelig dokumentert informasjon i Wiki-en til å svare sikkert på dette.');
        assert.equal(askAnnouncement('idle'), null);
    });
});

describe('shared components touched in Fase 5', () => {
    test('the filter dropdown takes focus into its portaled panel and out again (A11Y-026)', () => {
        const dropdown = read('Components', 'App', 'MultiSelectFilterDropdown.jsx');

        assert.match(dropdown, /const target = showSearch \? searchInputRef\.current : focusableWithin\(panelRef\.current\)\[0\];/);
        assert.match(dropdown, /handleTabOut\(event\);/);
        assert.equal(/text-slate-400|text-\[11px\]/.test(dropdown), false);
    });

    test('ScrollRegion has axis="both" for a capped box around a wide table', () => {
        const region = read('Components', 'App', 'ScrollRegion.jsx');

        assert.match(region, /return both \? tall \|\| wide : \(vertical \? tall : wide\);/);
    });

    test('the knowledge hand-over uses FormField, a named group, and focuses the first error', () => {
        const handoff = read('Components', 'App', 'WikiKnowledgeHandoffPanel.jsx');

        assert.equal((handoff.match(/<FormField\b/g) ?? []).length, 2);
        assert.match(handoff, /<FieldsetField\b/);
        assert.match(handoff, /onError: \(\) => focusFirstError\(\)/);
        assert.equal(/RequiredMark/.test(handoff), false);
    });
});

describe('Fase 5 strings exist in both languages', () => {
    const used = new Set();

    for (const file of [
        ['Pages', 'App', 'Wiki', 'Graph.jsx'], ['Pages', 'App', 'Wiki', 'WikiGraphListView.jsx'], ['Pages', 'App', 'Wiki', 'WikiGraphFocusView.jsx'],
        ['Pages', 'App', 'Wiki', 'Show.jsx'], ['Pages', 'App', 'Wiki', 'Index.jsx'], ['Pages', 'App', 'Wiki', 'WikiReviewPanel.jsx'],
        ['Pages', 'App', 'Wiki', 'Ask.jsx'], ['Pages', 'App', 'Wiki', 'wikiGraphList.js'], ['Pages', 'App', 'Wiki', 'wikiAskStatus.js'],
    ]) {
        for (const [, key] of read(...file).matchAll(/\bta\.(wiki_[a-z_]+)/g)) {
            used.add(key);
        }
    }

    for (const direction of ['outgoing', 'incoming', 'both']) {
        used.add(`wiki_graph_list_direction_${direction}`);
    }

    test('every a11y.wiki_* key the Wiki uses is in lang/no and lang/en', () => {
        assert.ok(used.size >= 40, `found ${used.size}`);

        for (const key of used) {
            assert.match(lang('no'), new RegExp(`'${key}' => '`), `missing ${key} in lang/no`);
            assert.match(lang('en'), new RegExp(`'${key}' => '`), `missing ${key} in lang/en`);
        }
    });
});
