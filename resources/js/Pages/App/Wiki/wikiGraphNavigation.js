/**
 * Opening a Wiki article from the graph, and getting back to the graph afterwards.
 *
 * The return context travels in the ARTICLE'S OWN URL (?back_url=), not in browser history, so it
 * survives a reload, a middle-click into a new tab, an Inertia visit that rewrote history, and a
 * link pasted to a colleague. WikiController::show() re-derives it from the query on every render
 * and revalidates it through PreservesWikiReviewReturnUrl::normalizeGraphReturnUrl(), so a
 * hand-edited back_url can never point the link off-site — what is built here is a convenience,
 * never the security boundary.
 */

/**
 * The graph's own URL for a given scope. Scope (run_id / page_id) is the graph's only state that
 * WikiGraphController reads back off the URL today — search, type/status filters, owner and
 * document selection, zoom and pan all live in React state and have no URL representation, so they
 * cannot be carried back without building a persistence layer this change deliberately does not.
 * Returning the user to the right SCOPE of the graph is the part that is actually representable.
 */
export function graphReturnUrl({ runId = null, pageId = null } = {}) {
    const params = new URLSearchParams();

    // Same precedence WikiGraph applies when it derives its scope: page_id wins over run_id.
    if (pageId !== null && pageId !== undefined && pageId !== '') {
        params.set('page_id', String(pageId));
    } else if (runId !== null && runId !== undefined && runId !== '') {
        params.set('run_id', String(runId));
    }

    const query = params.toString();

    return query === '' ? '/app/wiki/graph' : `/app/wiki/graph?${query}`;
}

/**
 * A node's article link, carrying the graph as its origin. `nodeUrl` comes from the server
 * (EnterpriseWikiGraphDataService) as "/app/wiki/{slug}"; any query it already has is preserved
 * rather than clobbered, and an existing back_url is never overwritten.
 */
export function articleHrefFromGraph(nodeUrl, scope = {}) {
    if (typeof nodeUrl !== 'string' || nodeUrl.trim() === '') {
        return nodeUrl;
    }

    const [path, existingQuery = ''] = nodeUrl.trim().split('?');
    const params = new URLSearchParams(existingQuery);

    if (params.has('back_url')) {
        return nodeUrl;
    }

    params.set('back_url', graphReturnUrl(scope));

    return `${path}?${params.toString()}`;
}
