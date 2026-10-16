/**
 * The graph as a list (WCAG 2.1.1, 1.1.1, 1.3.1; docs/wcag-accessibility-plan.md A11Y-050).
 *
 * Sigma draws the Wiki graph on a canvas: a page is a dot, a link is a line, and both can only be
 * picked with a pointer. The list view is the same graph in a form a keyboard and a screen reader can
 * work through — every page that is on the canvas, and every line that touches it — and it opens the
 * same node and relation panels the canvas does.
 *
 * SAME DATA, NOT A COPY. Everything here is derived from the `displayed` set Graph.jsx already
 * computes for the canvas: the nodes that pass the search and every filter, and the edges between
 * them. Nothing is fetched, nothing is filtered again, and no relation is invented — a relation is a
 * pair of displayed pages with at least one displayed edge between them, keyed exactly the way the
 * canvas keys a clicked line (relationPairKey), so the list and the canvas open the same panel.
 */
import { relationPairKey } from './wikiGraphRelations.js';

/** Which way the links between `nodeId` and its neighbour point, seen from `nodeId`. */
export function relationDirectionFor(nodeId, edges) {
    const id = String(nodeId);
    const outgoing = edges.some((edge) => String(edge.source) === id);
    const incoming = edges.some((edge) => String(edge.target) === id);

    if (outgoing && incoming) {
        return 'both';
    }

    return outgoing ? 'outgoing' : 'incoming';
}

/**
 * One row per displayed page, in title order, each with the pages it is linked to.
 *
 * @param {Array<object>} nodes  The displayed nodes (EnterpriseWikiGraphDataService payload shape).
 * @param {Array<object>} edges  The displayed edges.
 * @param {string} [locale]
 * @returns {Array<{
 *   id: string, pageId: number, title: string, pageType: string, status: string, url: string,
 *   claimCount: number, sourceRefCount: number, lintErrors: number, lintWarnings: number,
 *   relations: Array<{ pairKey: string, otherId: string, otherTitle: string, otherUrl: string, direction: 'outgoing'|'incoming'|'both' }>
 * }>}
 */
export function buildGraphListRows(nodes = [], edges = [], locale = 'nb') {
    const byId = new Map(nodes.map((node) => [String(node.id), node]));
    const compare = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), locale, { sensitivity: 'base' });

    // pairKey → the edges between those two pages, only between pages that are both displayed.
    const pairs = new Map();

    for (const edge of edges) {
        const source = String(edge?.source);
        const target = String(edge?.target);

        if (! byId.has(source) || ! byId.has(target) || source === target) {
            continue;
        }

        const key = relationPairKey(source, target);

        if (! pairs.has(key)) {
            pairs.set(key, []);
        }

        pairs.get(key).push(edge);
    }

    const relationsById = new Map(nodes.map((node) => [String(node.id), []]));

    for (const [pairKey, pairEdges] of pairs) {
        const [a, b] = pairKey.split('::');

        for (const [self, other] of [[a, b], [b, a]]) {
            const otherNode = byId.get(other);

            relationsById.get(self)?.push({
                pairKey,
                otherId: other,
                otherTitle: otherNode?.title ?? other,
                otherUrl: otherNode?.url ?? null,
                direction: relationDirectionFor(self, pairEdges),
            });
        }
    }

    return nodes
        .map((node) => ({
            id: String(node.id),
            pageId: node.page_id,
            title: node.title ?? '',
            pageType: node.page_type,
            status: node.status,
            url: node.url,
            claimCount: node.claim_count ?? 0,
            sourceRefCount: node.source_reference_count ?? 0,
            lintErrors: node.lint_error_count ?? 0,
            lintWarnings: node.lint_warning_count ?? 0,
            relations: (relationsById.get(String(node.id)) ?? [])
                .sort((x, y) => compare(x.otherTitle, y.otherTitle)),
        }))
        .sort((x, y) => compare(x.title, y.title));
}

/**
 * What a screen reader hears after the search or a filter changed what is shown — once, after the
 * user has stopped typing, and never for the first load (the count is on screen and in the summary).
 * The zero case says what to do about it, because the canvas and the list are both empty then.
 *
 * @returns {?string}
 */
export function graphResultAnnouncement(previous, next, total, tw = {}, ta = {}) {
    if (previous === null || previous === undefined || next === null || next === undefined || previous === next) {
        return null;
    }

    if (Number(next) === 0) {
        return tw.graph_no_matches ?? 'Ingen Wiki-sider matcher filtrene.';
    }

    return (ta.wiki_graph_results ?? 'Viser :shown av :total sider.')
        .replace(':shown', String(next))
        .replace(':total', String(total));
}
