/**
 * Focus mode as a list, and what is said when a neighbourhood loads (A11Y-050, 4.1.3).
 *
 * Pure, so the rules are unit-tested without a renderer. The list reads the same /graph-focus answer
 * the canvas draws; it never asks for anything else.
 */

/**
 * The neighbourhood grouped by hop distance: the focus page first, then one hop, then two. Titles in
 * alphabetical order inside each group.
 *
 * @returns {Array<{ depth: number, nodes: Array<object> }>}
 */
export function focusListGroups(nodes = [], locale = 'nb') {
    const byDepth = new Map();

    for (const node of nodes) {
        const depth = Number(node?.depth);

        if (! Number.isInteger(depth) || depth < 0) {
            continue;
        }

        if (! byDepth.has(depth)) {
            byDepth.set(depth, []);
        }

        byDepth.get(depth).push(node);
    }

    return [...byDepth.entries()]
        .sort(([a], [b]) => a - b)
        .map(([depth, group]) => ({
            depth,
            nodes: [...group].sort((x, y) => String(x.title ?? '').localeCompare(String(y.title ?? ''), locale, { sensitivity: 'base' })),
        }));
}

/** The few states the focus view can be in, as a key for useTransitionAnnouncement. */
export function focusViewStateKey({ nothingSelected, loading, errorKind, data }) {
    if (nothingSelected) {
        return 'none';
    }

    if (loading) {
        return 'loading';
    }

    if (errorKind) {
        return `error:${errorKind}`;
    }

    if (! data) {
        return 'none';
    }

    // Two answers with the same size are still two different neighbourhoods; the focus page tells
    // them apart, so choosing another page is heard even when the counts happen to match.
    return `ready:${data.focus?.page_id ?? ''}:${data.summary?.node_count ?? 0}:${data.summary?.edge_count ?? 0}`;
}

/**
 * The sentence for a change of state, or null. Loading itself is not spoken (it is short, and the
 * answer follows); the answer is, and so is a failure.
 */
export function focusViewAnnouncement(previous, next, data, tw = {}) {
    if (next === 'loading' || next === 'none') {
        return null;
    }

    if (next.startsWith('error:')) {
        const kind = next.slice('error:'.length);

        if (kind === 'unavailable') {
            return tw.graph_focus_unavailable ?? 'Fokusvisningen er ikke tilgjengelig.';
        }

        if (kind === 'invalid') {
            return tw.graph_focus_invalid ?? 'Fant ikke siden.';
        }

        return tw.graph_focus_error ?? 'Kunne ikke laste fokusvisningen.';
    }

    if ((data?.nodes ?? []).length <= 1) {
        return tw.graph_focus_empty ?? 'Denne siden har ingen naboer her.';
    }

    return (tw.graph_focus_summary ?? ':nodes sider · :edges koblinger')
        .replace(':nodes', String(data?.summary?.node_count ?? 0))
        .replace(':edges', String(data?.summary?.edge_count ?? 0));
}
