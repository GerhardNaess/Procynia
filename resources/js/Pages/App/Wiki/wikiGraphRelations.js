/**
 * What a line between two nodes in Grafvisning actually means, derived from the edge payload and
 * nothing else.
 *
 * THE DOMAIN, STATED PLAINLY. EnterpriseWikiGraphDataService only ever emits edges with
 * link_type = wikilink: a row exists because the SOURCE page's current content_markdown literally
 * contains [[anchor_text]] resolving to the TARGET page (EnterpriseWikiBuildPageLinksService::
 * materializeWikilinksForPage). There is no typed semantic relation in this model — no "is part
 * of", "is delivered by", "depends on" — and none is invented here. What the graph CAN say beyond
 * "these two pages are linked" comes from the link intent the source page's own content recorded:
 * the anchor, the reason the page gives for the link, and the prose the link sits in. That is
 * grounding, not a relation type, and it is labelled as such.
 *
 * DIRECTION IS REAL, not an artifact of how a graph library stores an edge: "page A's text links
 * to page B" is asymmetric. Both directions can exist as two separate rows, which Sigma draws as
 * one overlapping line — so the PAIR, not the single edge, is the unit the UI describes.
 */

/**
 * The label a link_type gets in the UI. Only 'wikilink' reaches the graph today.
 *
 * "Wiki-lenke" rather than "Lenke i artikkel": a link's source page is usually NOT an article —
 * concept and entity pages make up almost the whole graph — so naming the page type would be wrong
 * for most edges. And rather than the earlier "Lenket i teksten", which described how the row came
 * to exist and therefore said the same thing twice alongside the per-link "Opprinnelse". The
 * relation is a wiki link; how it was established is a separate field.
 */
export const RELATION_TYPE_LABELS = {
    wikilink: (tw) => tw?.graph_relation_wikilink ?? 'Wiki-lenke',
};

/**
 * A link_type with no label of its own is shown as itself rather than as a guess: an unlabelled but
 * truthful "article_to_concept" beats a plausible-sounding invention.
 */
export function relationTypeLabel(linkType, tw) {
    return RELATION_TYPE_LABELS[linkType]?.(tw) ?? String(linkType ?? '');
}

/**
 * The unordered key for the two endpoints, so A→B and B→A resolve to the same pair. Sigma draws
 * them as one line and the user perceives one line; treating them as two unrelated selections
 * would mean clicking the same pixels could show either half of the story.
 */
export function relationPairKey(source, target) {
    return [String(source), String(target)].sort().join('::');
}

/**
 * pairKey → every edge between those two nodes, in payload order.
 *
 * @returns {Map<string, Array<object>>}
 */
export function buildRelationIndex(edges = []) {
    const index = new Map();

    for (const edge of edges) {
        if (!edge || edge.source === undefined || edge.target === undefined) {
            continue;
        }

        const key = relationPairKey(edge.source, edge.target);

        if (!index.has(key)) {
            index.set(key, []);
        }

        index.get(key).push(edge);
    }

    return index;
}

const nodeTitle = (nodesById, id) => nodesById?.[id]?.title ?? String(id ?? '');

const cleanText = (value) => (typeof value === 'string' && value.trim() !== '' ? value.trim() : null);

/**
 * How a pair of edges may be presented as one relation.
 *
 * 'reciprocal' is an aggregation of SAME-TYPE links in both directions: two pure wikilinks pointing
 * at each other say one thing — these pages cross-reference each other — and showing them as two
 * near-identical cards buries that. The aggregation is only safe because the type is identical in
 * both directions; the moment the pair carries more than one link_type, direction could mean
 * something ("A is delivered by B" is not "B is delivered by A") and the links are listed
 * separately instead. That guard is the reason this is derived from link_type rather than from
 * "there happen to be two of them".
 */
export function relationDirectionality(edges = []) {
    if (edges.length === 0) {
        return 'none';
    }

    const types = new Set(edges.map((edge) => String(edge.link_type)));

    if (types.size > 1) {
        return 'mixed';
    }

    const directions = new Set(edges.map((edge) => `${edge.source}->${edge.target}`));

    return directions.size > 1 ? 'reciprocal' : 'directed';
}

/**
 * The hover tooltip: the node pair, and the one line that answers why they are connected.
 *
 * The page's own recorded reason wins over the link type, because "Navngitt plattform for regel- og
 * indikatoroppdateringer" tells the user something and "Wiki-lenke" tells them how the software
 * works. The type appears only when no reason was recorded and there is nothing better to say.
 *
 * Nothing the panel carries beyond that first line appears here — no anchor, origin, context or
 * source page. A click is one gesture away.
 *
 * @returns {?{headline: string, detail: string, isTypeFallback: boolean}}
 */
export function describeRelationHover(pairKey, relationIndex, nodesById = {}, tw = {}) {
    const edges = relationIndex?.get?.(pairKey) ?? [];

    if (edges.length === 0) {
        return null;
    }

    const directionality = relationDirectionality(edges);
    const [first] = edges;
    const arrow = directionality === 'directed' ? '→' : '↔';

    const reason = edges
        .flatMap((edge) => edge.intents ?? [])
        .map((intent) => cleanText(intent?.reason))
        .find((text) => text !== null && text !== undefined) ?? null;

    let detail = reason;

    if (detail === null) {
        // No recorded reason: say what kind of connection it is, and — for an aggregate — that it
        // stands for more than one link, which a click would not otherwise make obvious.
        detail = directionality === 'reciprocal'
            ? `${tw?.graph_relation_reciprocal_type ?? 'Gjensidig lenket'} · `
                + (tw?.graph_relation_link_count ?? ':count lenker').replace(':count', edges.length)
            : directionality === 'mixed'
                ? (tw?.graph_relation_count ?? ':count relasjoner').replace(':count', edges.length)
                : relationTypeLabel(first.link_type, tw);
    }

    return {
        headline: `${nodeTitle(nodesById, first.source)} ${arrow} ${nodeTitle(nodesById, first.target)}`,
        detail,
        isTypeFallback: reason === null,
    };
}

/**
 * The detail panel's view model for one selected pair.
 *
 * ONE QUESTION. A user who clicks a line wants to know why these two pages are connected, so that
 * is what the panel answers and almost all of what it contains. The link type, the anchor words,
 * how the row was established and how many rows there are behind the line are all real, but they
 * are answers to questions nobody asked at that moment — they stay in the payload and out of the
 * panel.
 *
 * THE ANSWER IS QUOTED, NEVER COMPOSED. Each reason is the sentence the source page's own content
 * recorded for that link (the link intent's `reason`), passed through untouched. When no page
 * recorded one, the panel says the only thing that is then true — that one page links to the other
 * — rather than dressing the absence up as meaning.
 *
 * Distinct reasons are all shown; exact repeats are folded, because a page can record the same
 * sentence for two links to the same target. Two reasons that merely say similar things in
 * different words are both kept: deciding they are "the same" would need a similarity guess, and a
 * guess about the user's own content is worse than one extra line.
 *
 * @returns {?{
 *   pairKey: string,
 *   headline: string,
 *   typeLabel: string,
 *   directionality: 'directed'|'reciprocal'|'mixed',
 *   hasStatedReason: boolean,
 *   reasons: Array<{text: string, context: ?string, sourceSlug: ?string, sourceTitle: string}>,
 *   sources: Array<{slug: string, title: string}>
 * }}
 */
export function buildRelationPanel(pairKey, relationIndex, nodesById = {}, tw = {}) {
    const edges = relationIndex?.get?.(pairKey) ?? [];

    if (edges.length === 0) {
        return null;
    }

    const directionality = relationDirectionality(edges);
    const [first] = edges;
    const aggregated = directionality === 'reciprocal' || directionality === 'mixed';
    const arrow = aggregated ? '↔' : '→';

    const reasons = [];
    const seen = new Set();

    for (const edge of edges) {
        for (const intent of edge.intents ?? []) {
            const text = cleanText(intent?.reason);

            if (text === null) {
                continue;
            }

            // Fold an exact repeat, ignoring case and trailing punctuation — the same sentence
            // recorded twice is one answer, not two.
            const fingerprint = text.toLocaleLowerCase().replace(/[.\s]+$/u, '');

            if (seen.has(fingerprint)) {
                continue;
            }

            seen.add(fingerprint);
            reasons.push({
                text,
                context: cleanText(intent?.context),
                sourceSlug: nodesById?.[edge.source]?.slug ?? null,
                sourceTitle: nodeTitle(nodesById, edge.source),
            });
        }
    }

    const hasStatedReason = reasons.length > 0;

    if (! hasStatedReason) {
        // The honest floor. It states the mechanism as the reason precisely because there is no
        // other reason on record — not as a stand-in for one.
        reasons.push({
            text: aggregated
                ? (tw?.graph_relation_fallback_reciprocal ?? 'Sidene er koblet fordi de lenker til hverandre.')
                : (tw?.graph_relation_fallback_directed ?? 'Sidene er koblet fordi den ene siden lenker til den andre.'),
            context: null,
            sourceSlug: nodesById?.[first.source]?.slug ?? null,
            sourceTitle: nodeTitle(nodesById, first.source),
        });
    }

    // One action per page a reason actually came from: a reciprocal pair has two, and every other
    // case has one. Rendering a button per reason would repeat the same destination.
    const sources = [];
    const seenSlugs = new Set();

    for (const reason of reasons) {
        if (reason.sourceSlug !== null && ! seenSlugs.has(reason.sourceSlug)) {
            seenSlugs.add(reason.sourceSlug);
            sources.push({ slug: reason.sourceSlug, title: reason.sourceTitle });
        }
    }

    return {
        pairKey,
        headline: `${nodeTitle(nodesById, first.source)} ${arrow} ${nodeTitle(nodesById, first.target)}`,
        // Kept for the hover, which falls back to it when no page recorded a reason. The panel
        // itself no longer renders it.
        typeLabel: directionality === 'reciprocal'
            ? (tw?.graph_relation_reciprocal_type ?? 'Gjensidig lenket')
            : directionality === 'mixed'
                ? (tw?.graph_relation_mixed_type ?? 'Flere relasjonstyper')
                : relationTypeLabel(first.link_type, tw),
        directionality,
        hasStatedReason,
        reasons,
        sources,
    };
}

/**
 * The node ids a selected or hovered pair highlights: the two endpoints, nothing else. Returned as
 * a Set so the Sigma reducers can test membership per frame without rebuilding anything.
 */
export function highlightedNodeIds(pairKey, relationIndex) {
    const edges = relationIndex?.get?.(pairKey) ?? [];
    const ids = new Set();

    for (const edge of edges) {
        ids.add(String(edge.source));
        ids.add(String(edge.target));
    }

    return ids;
}

/** The edge keys drawn as the selected relation — both directions when both exist. */
export function highlightedEdgeIds(pairKey, relationIndex) {
    return new Set((relationIndex?.get?.(pairKey) ?? []).map((edge) => String(edge.id)));
}
