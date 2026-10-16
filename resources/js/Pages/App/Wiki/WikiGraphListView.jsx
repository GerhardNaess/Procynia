import { articleHrefFromGraph } from './wikiGraphNavigation';

/**
 * «Liste» — the Wiki graph for someone who cannot use the canvas (A11Y-050).
 *
 * Not a description of the graph but the graph itself, in reading order: every page the canvas is
 * showing right now (the same search and filters apply, see wikiGraphList.js), what kind of page it
 * is and how it stands, and every page it is linked to. Each thing the canvas lets a pointer do has a
 * control here:
 *
 *   - click a dot          → «Vis detaljer»: the same node panel opens beside the list;
 *   - click a line         → «Hvorfor er de koblet?»: the same relation panel opens;
 *   - «Åpne side»          → the page title is a link, with the graph as its return context;
 *   - «Fokuser på siden»   → the same switch to focus mode.
 *
 * Page type and lint status are words here, never only the colours the canvas uses.
 */

const DIRECTION_FALLBACKS = {
    outgoing: 'Lenker til',
    incoming: 'Lenkes fra',
    both: 'Gjensidig lenket med',
};

const BUTTON = 'inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-base font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-950';

export function pageTypeText(pageType, tw) {
    return {
        article: tw.page_type_article ?? 'Artikkel',
        summary: tw.page_type_summary ?? 'Sammendrag',
        concept: tw.page_type_concept ?? 'Konsept',
        entity: tw.page_type_entity ?? 'Entitet',
    }[pageType] ?? pageType;
}

export function lintStatusText(status, tw) {
    return {
        error: tw.lint_severity_error ?? 'Feil',
        warning: tw.lint_severity_warning ?? 'Advarsel',
        ok: tw.lint_summary_ok ?? 'OK',
    }[status] ?? status;
}

export default function WikiGraphListView({
    rows,
    totalCount,
    tw,
    ta,
    graphScope,
    selectedNodeId = null,
    selectedPairKey = null,
    onShowNode,
    onShowRelation,
    onFocusPage,
}) {
    const headingId = 'wiki-graph-list-title';
    const summaryId = 'wiki-graph-list-summary';

    return (
        <section
            aria-labelledby={headingId}
            aria-describedby={summaryId}
            data-graph-list=""
            className="absolute inset-0 overflow-y-auto bg-white p-4 sm:p-5"
        >
            <h2 id={headingId} className="text-lg font-semibold text-slate-950">
                {ta.wiki_graph_list_title ?? 'Sider i grafen'}
            </h2>
            <p id={summaryId} className="mt-1 text-base text-slate-600">
                {(ta.wiki_graph_list_summary ?? 'Viser :shown av :total sider. Søk og filtre til venstre gjelder både grafen og listen.')
                    .replace(':shown', String(rows.length))
                    .replace(':total', String(totalCount))}
            </p>

            {rows.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">
                    {tw.graph_no_matches ?? 'Ingen Wiki-sider matcher filtrene.'}
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                    {rows.map((row) => {
                        const titleId = `wiki-graph-list-page-${row.id}`;
                        const detailsId = `wiki-graph-list-details-${row.id}`;
                        const isSelected = selectedNodeId === row.id;

                        return (
                            <li key={row.id} className={`py-4 ${isSelected ? 'bg-violet-50/60' : ''}`}>
                                <h3 id={titleId} className="text-base font-semibold text-slate-950">
                                    <a
                                        href={articleHrefFromGraph(row.url, graphScope)}
                                        className="break-words text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900"
                                    >
                                        {row.title}
                                    </a>
                                </h3>

                                <p className="mt-1 text-base text-slate-600">
                                    {pageTypeText(row.pageType, tw)}
                                    {' · '}
                                    {(ta.wiki_graph_list_status ?? 'Status: :status').replace(':status', lintStatusText(row.status, tw))}
                                    {' · '}
                                    {(ta.wiki_graph_list_claims ?? ':count påstander').replace(':count', String(row.claimCount))}
                                    {(row.lintErrors > 0 || row.lintWarnings > 0) && (
                                        <>
                                            {' · '}
                                            {(ta.wiki_graph_list_findings ?? ':errors feil, :warnings advarsler')
                                                .replace(':errors', String(row.lintErrors))
                                                .replace(':warnings', String(row.lintWarnings))}
                                        </>
                                    )}
                                </p>

                                <div className="mt-2 flex flex-wrap gap-2">
                                    <button
                                        id={detailsId}
                                        type="button"
                                        aria-describedby={titleId}
                                        aria-pressed={isSelected}
                                        onClick={() => onShowNode(row.id, detailsId)}
                                        className={BUTTON}
                                    >
                                        {ta.wiki_graph_list_show_details ?? 'Vis detaljer'}
                                    </button>
                                    <button
                                        type="button"
                                        aria-describedby={titleId}
                                        onClick={() => onFocusPage(row.pageId)}
                                        className={BUTTON}
                                    >
                                        {tw.graph_focus_set_focus ?? 'Fokuser på denne siden'}
                                    </button>
                                </div>

                                {row.relations.length === 0 ? (
                                    <p className="mt-2 text-base text-slate-600">
                                        {ta.wiki_graph_list_no_relations ?? 'Ingen koblinger i dette utvalget.'}
                                    </p>
                                ) : (
                                    <div className="mt-3">
                                        <p id={`${titleId}-relations`} className="text-base font-semibold text-slate-700">
                                            {(ta.wiki_graph_list_relations ?? 'Koblinger (:count)').replace(':count', String(row.relations.length))}
                                        </p>
                                        <ul aria-labelledby={`${titleId}-relations`} className="mt-1 space-y-2">
                                            {row.relations.map((relation) => {
                                                const relationId = `wiki-graph-list-relation-${row.id}-${relation.otherId}`;
                                                const relationTextId = `${relationId}-text`;

                                                return (
                                                    <li key={relation.pairKey} className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                                        <span id={relationTextId} className="text-base text-slate-700">
                                                            {ta[`wiki_graph_list_direction_${relation.direction}`] ?? DIRECTION_FALLBACKS[relation.direction]}
                                                            {' '}
                                                            {relation.otherUrl ? (
                                                                <a
                                                                    href={articleHrefFromGraph(relation.otherUrl, graphScope)}
                                                                    className="font-semibold text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900"
                                                                >
                                                                    {relation.otherTitle}
                                                                </a>
                                                            ) : (
                                                                <span className="font-semibold">{relation.otherTitle}</span>
                                                            )}
                                                        </span>
                                                        <button
                                                            id={relationId}
                                                            type="button"
                                                            aria-describedby={relationTextId}
                                                            aria-pressed={selectedPairKey === relation.pairKey}
                                                            onClick={() => onShowRelation(relation.pairKey, relationId)}
                                                            className={BUTTON}
                                                        >
                                                            {tw.graph_relation_why ?? 'Hvorfor er de koblet?'}
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
