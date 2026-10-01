import { useEffect, useMemo, useRef, useState } from 'react';
import Graph from 'graphology';
import Sigma from 'sigma';
import { PRIMARY_COLOURS } from '../../../Support/actionStyles';
import { truncateLabelToWidth } from './graphLabelLogic';
import { articleHrefFromGraph } from './wikiGraphNavigation';
import { buildFocusUrl, focusErrorFromStatus, focusRadialLayout } from './wikiGraphFocus';

/**
 * The focus neighbourhood of a single Wiki page, drawn from /app/wiki/graph-focus.
 *
 * Deliberately a separate component with its own Sigma instance rather than a mode inside the full
 * graph: the two views answer different questions, read different endpoints, and share none of the
 * full graph's filter, document, owner and relation machinery. Keeping them apart is what lets the
 * SQL-backed full graph stay exactly as it was.
 *
 * The visual vocabulary below intentionally repeats Graph.jsx's — same colours per page type, same
 * label font — so switching modes does not feel like switching applications.
 */

const PAGE_TYPE_COLORS = {
    article: '#7c3aed',
    summary: '#0284c7',
    concept: '#0d9488',
    entity: '#ea580c',
};

const EDGE_COLOR = '#cbd5e1';
const NODE_LABEL_SIZE = 16;
const NODE_LABEL_COLOR = '#1e293b';
const NODE_LABEL_MAX_WIDTH_PX = 180;

// Size carries the hop distance, so depth is readable without consulting a legend: the focus page is
// unmistakably the subject, first-hop pages are full participants, second-hop pages are context.
const DEPTH_SIZES = { 0: 20, 1: 13, 2: 9 };

function nodeSizeForDepth(depth) {
    return DEPTH_SIZES[depth] ?? 9;
}

function drawTruncatedNodeLabel(context, data, settings) {
    if (!data.label) return;

    const size = settings.labelSize;
    const font = settings.labelFont;
    const weight = settings.labelWeight;

    context.fillStyle = settings.labelColor.color;
    context.font = `${weight} ${size}px ${font}`;

    const label = truncateLabelToWidth(
        (text) => context.measureText(text).width,
        data.label,
        NODE_LABEL_MAX_WIDTH_PX,
    );

    context.fillText(label, data.x + data.size + 3, data.y + size / 3);
}

function Message({ tone = 'slate', title, hint, children }) {
    const palette = {
        slate: 'border-slate-200 bg-white text-slate-600',
        amber: 'border-amber-200 bg-amber-50 text-amber-800',
        rose: 'border-rose-100 bg-rose-50 text-rose-700',
    }[tone];

    return (
        <div className="absolute inset-0 z-10 flex items-center justify-center bg-slate-50 p-8">
            <div className={`max-w-sm rounded-2xl border p-6 text-center ${palette}`}>
                <p className="text-sm font-semibold">{title}</p>
                {hint && <p className="mt-1 text-xs opacity-80">{hint}</p>}
                {children}
            </div>
        </div>
    );
}

/** The page the user clicked: what it is, how far out it sits, and the two things they can do next. */
function FocusNodeCard({ node, tw, graphScope, onClose, onMakeFocus }) {
    const typeLabel = {
        article: tw.page_type_article ?? 'Artikkel',
        summary: tw.page_type_summary ?? 'Sammendrag',
        concept: tw.page_type_concept ?? 'Konsept',
        entity: tw.page_type_entity ?? 'Entitet',
    }[node.pageType] ?? node.pageType;

    const depthLabel = node.depth === 0
        ? (tw.graph_focus_depth_centre ?? 'Fokusside')
        : (tw.graph_focus_depth_hops ?? ':count hopp unna').replace(':count', node.depth);

    // Anchored to the TOP of the canvas, not the bottom: the graph area is taller than the window on
    // a laptop, so a card at the bottom edge would hold its main action below the fold.
    return (
        <div className="absolute left-3 top-3 z-20 w-64 rounded-2xl border border-slate-200 bg-white p-4 shadow-lg">
            <div className="mb-3 flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-950" title={node.label}>
                        {node.label}
                    </p>
                    <div className="mt-1 flex items-center gap-1.5">
                        <span
                            className="inline-block h-2 w-2 rounded-full"
                            style={{ backgroundColor: PAGE_TYPE_COLORS[node.pageType] ?? '#6b7280' }}
                        />
                        <span className="text-xs text-slate-500">{typeLabel}</span>
                        <span className="h-3 w-px bg-slate-200" />
                        <span className="text-xs text-slate-500">{depthLabel}</span>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                    aria-label={tw.close ?? 'Lukk'}
                >
                    <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>
            </div>

            <div className="flex flex-col gap-2">
                {node.depth !== 0 && (
                    <button
                        type="button"
                        onClick={() => onMakeFocus(node.pageId)}
                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                    >
                        {tw.graph_focus_set_focus ?? 'Fokuser på denne siden'}
                    </button>
                )}
                <a
                    href={articleHrefFromGraph(node.url, graphScope)}
                    className={`flex w-full items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold transition ${PRIMARY_COLOURS}`}
                >
                    {tw.graph_node_open_page ?? 'Åpne side'}
                </a>
            </div>
        </div>
    );
}

export default function WikiGraphFocusView({
    pageId,
    depth,
    direction,
    tw = {},
    graphScope,
    onMakeFocus,
}) {
    const containerRef = useRef(null);
    const sigmaRef = useRef(null);

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [errorKind, setErrorKind] = useState(null);
    const [selectedNode, setSelectedNode] = useState(null);

    const url = useMemo(() => buildFocusUrl({ pageId, depth, direction }), [pageId, depth, direction]);

    useEffect(() => {
        if (url === null) {
            setData(null);
            setErrorKind(null);
            setLoading(false);

            return undefined;
        }

        let cancelled = false;

        setLoading(true);
        setErrorKind(null);
        setSelectedNode(null);

        fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw Object.assign(new Error(`HTTP ${response.status}`), { kind: focusErrorFromStatus(response.status) });
                }

                return response.json();
            })
            .then((payload) => {
                if (cancelled) return;

                setData(payload);
                setLoading(false);
            })
            .catch((err) => {
                if (cancelled) return;

                setErrorKind(err.kind ?? 'generic');
                setData(null);
                setLoading(false);
            });

        // A fast click through several pages must not let an earlier, slower answer overwrite the
        // one the user is now looking at.
        return () => { cancelled = true; };
    }, [url]);

    useEffect(() => {
        if (sigmaRef.current) {
            sigmaRef.current.kill();
            sigmaRef.current = null;
        }

        if (!containerRef.current || !data || (data.nodes ?? []).length === 0) {
            return undefined;
        }

        const positions = focusRadialLayout(data.nodes, data.edges ?? []);
        const graph = new Graph();

        data.nodes.forEach((node) => {
            const position = positions[node.id] ?? { x: 0, y: 0 };

            graph.addNode(node.id, {
                label: node.title,
                x: position.x,
                y: position.y,
                size: nodeSizeForDepth(Number(node.depth)),
                color: PAGE_TYPE_COLORS[node.page_type] ?? '#6b7280',
                // Sigma's own "this one matters" treatment: the focus page keeps its label boxed and
                // legible no matter how crowded the ring around it gets.
                highlighted: Boolean(node.is_focus),
                pageId: node.page_id,
                pageType: node.page_type,
                depth: Number(node.depth),
                url: node.url,
            });
        });

        (data.edges ?? []).forEach((edge) => {
            if (!graph.hasNode(edge.source) || !graph.hasNode(edge.target)) {
                return;
            }

            try {
                graph.addEdgeWithKey(edge.id, edge.source, edge.target, { size: 2, color: EDGE_COLOR });
            } catch {
                // Reciprocal links produce two rows for one visible line; the first one wins.
            }
        });

        const renderer = new Sigma(graph, containerRef.current, {
            renderEdgeLabels: false,
            defaultEdgeColor: EDGE_COLOR,
            labelFont: 'Inter, system-ui, sans-serif',
            labelSize: NODE_LABEL_SIZE,
            labelWeight: '500',
            labelColor: { color: NODE_LABEL_COLOR },
            defaultDrawNodeLabel: drawTruncatedNodeLabel,
            labelGridCellSize: 150,
            // Every page in a focus view is meant to be read — this is a small graph, so nothing is
            // decluttered away.
            labelRenderedSizeThreshold: 0,
        });

        renderer.on('clickNode', ({ node }) => {
            setSelectedNode({ id: node, ...graph.getNodeAttributes(node) });
        });

        renderer.on('clickStage', () => setSelectedNode(null));

        sigmaRef.current = renderer;

        return () => {
            renderer.kill();
            sigmaRef.current = null;
        };
    }, [data]);

    const nothingSelected = url === null;
    const isEmpty = data !== null && (data.nodes ?? []).length <= 1;

    return (
        <div className="absolute inset-0">
            {/* In focus mode the full graph's canvases are still in the DOM (hidden, so its layout
                and camera survive the trip). data-graph-view is what tells the two apart — to a
                reader, and to any test that needs to address one of them. */}
            <div ref={containerRef} data-graph-view="focus" className="absolute inset-0" />

            {nothingSelected && (
                <Message
                    title={tw.graph_focus_pick_page ?? 'Velg en side å fokusere på.'}
                    hint={tw.graph_focus_pick_page_hint ?? 'Fokusvisningen viser én side og naboene dens.'}
                />
            )}

            {!nothingSelected && loading && (
                <div className="absolute inset-0 z-10 flex items-center justify-center bg-slate-50">
                    <div className="flex flex-col items-center gap-3">
                        <svg className="h-6 w-6 animate-spin text-violet-500" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                        </svg>
                        <p className="text-sm text-slate-500">{tw.graph_focus_loading ?? 'Laster fokusvisning…'}</p>
                    </div>
                </div>
            )}

            {!loading && errorKind === 'unavailable' && (
                <Message
                    tone="amber"
                    title={tw.graph_focus_unavailable ?? 'Fokusvisningen er ikke tilgjengelig.'}
                    hint={tw.graph_focus_unavailable_hint ?? 'Grafprojeksjonen er ikke aktivert i dette miljøet. Fullgrafen virker som normalt.'}
                />
            )}

            {!loading && errorKind === 'invalid' && (
                <Message
                    tone="rose"
                    title={tw.graph_focus_invalid ?? 'Fant ikke siden.'}
                    hint={tw.graph_focus_invalid_hint ?? 'Siden finnes ikke, eller du har ikke tilgang til den.'}
                />
            )}

            {!loading && (errorKind === 'forbidden' || errorKind === 'generic') && (
                <Message
                    tone="rose"
                    title={tw.graph_focus_error ?? 'Kunne ikke laste fokusvisningen.'}
                />
            )}

            {!loading && !errorKind && isEmpty && (
                <Message
                    title={tw.graph_focus_empty ?? 'Denne siden har ingen naboer her.'}
                    hint={data?.focus?.projected === false
                        ? (tw.graph_focus_not_projected ?? 'Siden er ikke projisert til grafen ennå.')
                        : (tw.graph_focus_empty_hint ?? 'Prøv større dybde eller en annen retning.')}
                />
            )}

            {!loading && !errorKind && data && !isEmpty && (
                <div className="pointer-events-none absolute right-3 top-3">
                    <span className="inline-flex items-center rounded-full bg-white/80 px-2.5 py-1 text-[11px] font-medium text-slate-500 shadow-sm ring-1 ring-slate-200 backdrop-blur-sm">
                        {(tw.graph_focus_summary ?? ':nodes sider · :edges koblinger')
                            .replace(':nodes', data.summary?.node_count ?? 0)
                            .replace(':edges', data.summary?.edge_count ?? 0)}
                    </span>
                </div>
            )}

            {selectedNode && (
                <FocusNodeCard
                    node={selectedNode}
                    tw={tw}
                    graphScope={graphScope}
                    onClose={() => setSelectedNode(null)}
                    onMakeFocus={(id) => { setSelectedNode(null); onMakeFocus(id); }}
                />
            )}
        </div>
    );
}
