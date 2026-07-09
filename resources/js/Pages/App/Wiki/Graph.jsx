import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Graph from 'graphology';
import Sigma from 'sigma';
import forceAtlas2 from 'graphology-layout-forceatlas2';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';

// ─── constants ───────────────────────────────────────────────────────────────

const PAGE_TYPE_COLORS = {
    article: '#7c3aed',
    summary: '#0284c7',
    concept: '#0d9488',
    entity: '#ea580c',
};

const PAGE_TYPE_SIZES = {
    article: 14,
    summary: 12,
    concept: 9,
    entity: 9,
};

const STATUS_RING = {
    error:   '#ef4444',
    warning: '#f59e0b',
    ok:      '#22c55e',
};

const DEFAULT_EDGE_COLOR = '#cbd5e1';
const SELECTED_NODE_BORDER = '#1e293b';

// ─── small helpers ────────────────────────────────────────────────────────────

function nodeColor(node) {
    return PAGE_TYPE_COLORS[node.page_type] ?? '#6b7280';
}

function nodeSize(node, degree) {
    const base = PAGE_TYPE_SIZES[node.page_type] ?? 9;
    return base + Math.min(degree * 0.8, 8);
}

// ─── sub-components ──────────────────────────────────────────────────────────

function SummaryPanel({ summary, tw }) {
    if (!summary) return null;
    const rows = [
        { label: tw.graph_summary_nodes    ?? 'Sider',           value: summary.node_count },
        { label: tw.graph_summary_edges    ?? 'Koblinger',        value: summary.edge_count },
        { label: tw.graph_summary_articles ?? 'Artikler',         value: summary.article_count },
        { label: tw.graph_summary_summaries ?? 'Sammendrag',      value: summary.summary_count },
        { label: tw.graph_summary_concepts ?? 'Konsepter',        value: summary.concept_count },
        { label: tw.graph_summary_entities ?? 'Entiteter',        value: summary.entity_count },
        { label: tw.graph_summary_errors   ?? 'Feil',             value: summary.lint_error_count,   accent: summary.lint_error_count   > 0 ? 'text-rose-600'   : null },
        { label: tw.graph_summary_warnings ?? 'Advarsler',        value: summary.lint_warning_count, accent: summary.lint_warning_count > 0 ? 'text-amber-600'  : null },
        { label: tw.graph_summary_orphans  ?? 'Isolerte sider',   value: summary.orphan_count,       accent: summary.orphan_count       > 0 ? 'text-slate-500'  : null },
    ];
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                {tw.graph_summary_title ?? 'Grafoversikt'}
            </h3>
            <dl className="space-y-1.5">
                {rows.map(({ label, value, accent }) => (
                    <div key={label} className="flex items-center justify-between gap-3">
                        <dt className="text-xs text-slate-500">{label}</dt>
                        <dd className={`text-xs font-semibold tabular-nums ${accent ?? 'text-slate-800'}`}>
                            {value ?? 0}
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

function Legend({ tw }) {
    const types = [
        { key: 'article', label: tw.page_type_article ?? 'Artikkel',   color: PAGE_TYPE_COLORS.article },
        { key: 'summary', label: tw.page_type_summary ?? 'Sammendrag', color: PAGE_TYPE_COLORS.summary },
        { key: 'concept', label: tw.page_type_concept ?? 'Konsept',    color: PAGE_TYPE_COLORS.concept },
        { key: 'entity',  label: tw.page_type_entity  ?? 'Entitet',    color: PAGE_TYPE_COLORS.entity },
    ];
    const statuses = [
        { key: 'error',   label: tw.lint_severity_error   ?? 'Feil',     color: STATUS_RING.error },
        { key: 'warning', label: tw.lint_severity_warning ?? 'Advarsel', color: STATUS_RING.warning },
        { key: 'ok',      label: tw.lint_summary_ok       ?? 'OK',       color: STATUS_RING.ok },
    ];
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                {tw.graph_legend_title ?? 'Tegnforklaring'}
            </h3>
            <div className="space-y-3">
                <div className="space-y-1.5">
                    {types.map(({ key, label, color }) => (
                        <div key={key} className="flex items-center gap-2">
                            <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: color }} />
                            <span className="text-xs text-slate-600">{label}</span>
                        </div>
                    ))}
                </div>
                <div className="border-t border-slate-100 pt-2 space-y-1.5">
                    <p className="text-[11px] text-slate-400">{tw.lint_health_title ?? 'Lint-status'}</p>
                    {statuses.map(({ key, label, color }) => (
                        <div key={key} className="flex items-center gap-2">
                            <span
                                className="h-3 w-3 shrink-0 rounded-full border-2"
                                style={{ borderColor: color, backgroundColor: 'white' }}
                            />
                            <span className="text-xs text-slate-600">{label}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function FilterPanel({ typeFilters, setTypeFilters, statusFilters, setStatusFilters, showOrphans, setShowOrphans, tw, onReset }) {
    const types = [
        { key: 'article', label: tw.page_type_article ?? 'Artikkel' },
        { key: 'summary', label: tw.page_type_summary ?? 'Sammendrag' },
        { key: 'concept', label: tw.page_type_concept ?? 'Konsept' },
        { key: 'entity',  label: tw.page_type_entity  ?? 'Entitet' },
    ];
    const statuses = [
        { key: 'error',   label: tw.lint_severity_error   ?? 'Feil' },
        { key: 'warning', label: tw.lint_severity_warning ?? 'Advarsel' },
        { key: 'ok',      label: tw.lint_summary_ok       ?? 'OK' },
    ];

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                    {tw.graph_filter_page_types ?? 'Sidetyper'}
                </h3>
                <button
                    type="button"
                    onClick={onReset}
                    className="text-[11px] text-violet-600 hover:underline"
                >
                    {tw.graph_filter_reset ?? 'Nullstill'}
                </button>
            </div>
            <div className="space-y-1.5 mb-4">
                {types.map(({ key, label }) => (
                    <label key={key} className="flex cursor-pointer items-center gap-2">
                        <input
                            type="checkbox"
                            checked={typeFilters[key]}
                            onChange={() => setTypeFilters(f => ({ ...f, [key]: !f[key] }))}
                            className="h-3.5 w-3.5 rounded border-slate-300 accent-violet-600"
                        />
                        <span className="flex items-center gap-1.5 text-xs text-slate-700">
                            <span
                                className="h-2.5 w-2.5 rounded-full"
                                style={{ backgroundColor: PAGE_TYPE_COLORS[key] }}
                            />
                            {label}
                        </span>
                    </label>
                ))}
            </div>
            <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400">
                {tw.graph_filter_status ?? 'Status'}
            </h3>
            <div className="space-y-1.5 mb-4">
                {statuses.map(({ key, label }) => (
                    <label key={key} className="flex cursor-pointer items-center gap-2">
                        <input
                            type="checkbox"
                            checked={statusFilters[key]}
                            onChange={() => setStatusFilters(f => ({ ...f, [key]: !f[key] }))}
                            className="h-3.5 w-3.5 rounded border-slate-300 accent-violet-600"
                        />
                        <span className="text-xs text-slate-700">{label}</span>
                    </label>
                ))}
            </div>
            <label className="flex cursor-pointer items-center gap-2">
                <input
                    type="checkbox"
                    checked={showOrphans}
                    onChange={() => setShowOrphans(v => !v)}
                    className="h-3.5 w-3.5 rounded border-slate-300 accent-violet-600"
                />
                <span className="text-xs text-slate-700">
                    {tw.graph_filter_show_orphans ?? 'Vis isolerte sider'}
                </span>
            </label>
        </div>
    );
}

function NodePanel({ node, tw, onClose }) {
    if (!node) return null;
    const statusColor = STATUS_RING[node.nodeStatus] ?? STATUS_RING.ok;
    const typeLabel = {
        article: tw.page_type_article ?? 'Artikkel',
        summary: tw.page_type_summary ?? 'Sammendrag',
        concept: tw.page_type_concept ?? 'Konsept',
        entity:  tw.page_type_entity  ?? 'Entitet',
    }[node.pageType] ?? node.pageType;

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
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
                        <span
                            className="inline-block h-2 w-2 rounded-full border-2"
                            style={{ borderColor: statusColor, backgroundColor: 'white' }}
                        />
                        <span className="text-xs text-slate-500">{node.nodeStatus}</span>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="shrink-0 inline-flex h-6 w-6 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                    aria-label="Lukk"
                >
                    <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>
            </div>

            <dl className="mb-4 space-y-1.5">
                {[
                    { label: tw.graph_node_claims        ?? 'Påstander',        value: node.claimCount },
                    { label: tw.graph_node_sources       ?? 'Kildereferanser',  value: node.sourceRefCount },
                    { label: tw.graph_node_lint_errors   ?? 'Lint-feil',        value: node.lintErrors,   accent: node.lintErrors   > 0 ? 'text-rose-600'  : null },
                    { label: tw.graph_node_lint_warnings ?? 'Lint-advarsler',   value: node.lintWarnings, accent: node.lintWarnings > 0 ? 'text-amber-600' : null },
                ].map(({ label, value, accent }) => (
                    <div key={label} className="flex items-center justify-between gap-2">
                        <dt className="text-xs text-slate-500">{label}</dt>
                        <dd className={`text-xs font-semibold tabular-nums ${accent ?? 'text-slate-800'}`}>
                            {value ?? 0}
                        </dd>
                    </div>
                ))}
            </dl>

            <a
                href={node.url}
                className="flex w-full items-center justify-center gap-1.5 rounded-xl bg-violet-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-violet-700"
            >
                {tw.graph_node_open_page ?? 'Åpne side'}
                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M5.22 14.78a.75.75 0 0 0 1.06 0l7.22-7.22v5.69a.75.75 0 0 0 1.5 0v-7.5a.75.75 0 0 0-.75-.75h-7.5a.75.75 0 0 0 0 1.5h5.69l-7.22 7.22a.75.75 0 0 0 0 1.06Z" clipRule="evenodd" />
                </svg>
            </a>
        </div>
    );
}

// ─── main component ───────────────────────────────────────────────────────────

export default function WikiGraph({ initialRunId = null, initialPageId = null }) {
    const { translations = {} } = usePage().props;
    const tw = translations?.wiki ?? {};

    const containerRef   = useRef(null);
    const sigmaRef       = useRef(null);
    const graphRef       = useRef(null);
    const displayedNodes = useRef(new Set());
    const displayedEdges = useRef(new Set());

    const [graphData,    setGraphData]    = useState(null);
    const [loading,      setLoading]      = useState(true);
    const [error,        setError]        = useState(null);
    const [selectedNode, setSelectedNode] = useState(null);

    const [typeFilters, setTypeFilters] = useState({
        article: true, summary: true, concept: true, entity: true,
    });
    const [statusFilters, setStatusFilters] = useState({
        error: true, warning: true, ok: true,
    });
    const [showOrphans, setShowOrphans] = useState(true);

    const scope = initialPageId
        ? { type: 'page', pageId: initialPageId }
        : initialRunId
        ? { type: 'run', runId: initialRunId }
        : { type: 'customer' };

    const scopeLabel = initialPageId
        ? `${tw.graph_scope_neighborhood ?? 'Nabolag'} #${initialPageId}`
        : initialRunId
        ? `${tw.graph_scope_run ?? 'Kjøring'} #${initialRunId}`
        : (tw.graph_scope_all ?? 'Hele wikien');

    // Build fetch URL from props (page-load-time scope; not reactive to URL changes)
    const fetchUrl = useMemo(() => {
        const params = new URLSearchParams();
        if (initialPageId) params.set('page_id', initialPageId);
        else if (initialRunId) params.set('run_id', initialRunId);
        return '/app/wiki/graph-data' + (params.toString() ? `?${params}` : '');
    }, [initialRunId, initialPageId]);

    // Fetch graph data
    useEffect(() => {
        setLoading(true);
        setError(null);

        fetch(fetchUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(res => {
                if (res.status === 422) throw Object.assign(new Error('422'), { is422: true });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then(data => {
                setGraphData(data);
                setLoading(false);
            })
            .catch(err => {
                setError(err.is422 ? '422' : 'generic');
                setLoading(false);
            });
    }, [fetchUrl]);

    // Build Sigma when graphData arrives
    useEffect(() => {
        if (!containerRef.current || !graphData) return;

        // Tear down previous renderer
        if (sigmaRef.current) {
            sigmaRef.current.kill();
            sigmaRef.current = null;
            graphRef.current = null;
        }

        if (graphData.nodes.length === 0) return;

        const graph = new Graph();

        // Add nodes with random seed positions
        graphData.nodes.forEach(node => {
            graph.addNode(node.id, {
                label:         node.title,
                x:             (Math.random() - 0.5) * 200,
                y:             (Math.random() - 0.5) * 200,
                size:          nodeSize(node, 0), // degree boosted after edges are added
                color:         nodeColor(node),
                // payload stored as custom attrs
                pageId:        node.page_id,
                slug:          node.slug,
                pageType:      node.page_type,
                nodeStatus:    node.status,
                url:           node.url,
                claimCount:    node.claim_count,
                sourceRefCount: node.source_reference_count,
                lintErrors:    node.lint_error_count,
                lintWarnings:  node.lint_warning_count,
            });
        });

        // Add edges using stable IDs
        graphData.edges.forEach(edge => {
            if (!graph.hasNode(edge.source) || !graph.hasNode(edge.target)) return;
            try {
                graph.addEdgeWithKey(edge.id, edge.source, edge.target, {
                    size:     1.5,
                    color:    DEFAULT_EDGE_COLOR,
                    label:    edge.link_type,
                });
            } catch {
                // duplicate — skip
            }
        });

        // Degree-based node size
        graph.nodes().forEach(n => {
            const deg = graph.degree(n);
            const attrs = graph.getNodeAttributes(n);
            graph.setNodeAttribute(n, 'size', nodeSize({ page_type: attrs.pageType }, deg));
        });

        // ForceAtlas2 layout
        if (graph.order > 1) {
            const settings = forceAtlas2.inferSettings(graph);
            forceAtlas2.assign(graph, {
                iterations: 150,
                settings: { ...settings, gravity: 1, scalingRatio: 2 },
            });
        }

        graphRef.current = graph;

        // Build initial displayed sets (all shown)
        displayedNodes.current = new Set(graph.nodes());
        displayedEdges.current = new Set(graph.edges());

        // Create Sigma renderer
        const renderer = new Sigma(graph, containerRef.current, {
            renderEdgeLabels:   false,
            defaultEdgeColor:   DEFAULT_EDGE_COLOR,
            labelFont:          'Inter, system-ui, sans-serif',
            labelSize:          11,
            labelWeight:        '500',
            minCameraRatio:     0.04,
            maxCameraRatio:     12,
            nodeReducer: (node, data) =>
                displayedNodes.current.has(node) ? data : { ...data, hidden: true },
            edgeReducer: (edge, data) =>
                displayedEdges.current.has(edge) ? data : { ...data, hidden: true },
        });

        sigmaRef.current = renderer;

        renderer.on('clickNode', ({ node }) => {
            setSelectedNode({ id: node, ...graph.getNodeAttributes(node) });
        });
        renderer.on('clickStage', () => setSelectedNode(null));

        return () => {
            renderer.kill();
            sigmaRef.current = null;
            graphRef.current = null;
        };
    }, [graphData]);

    // Apply filters by updating displayed sets and refreshing
    useEffect(() => {
        if (!graphData || !sigmaRef.current) return;

        const edgeById = Object.fromEntries(graphData.edges.map(e => [e.id, e]));

        const filteredNodes = new Set(
            graphData.nodes
                .filter(n => typeFilters[n.page_type] && statusFilters[n.status])
                .map(n => n.id),
        );

        let filteredEdges = new Set(
            graphData.edges
                .filter(e => filteredNodes.has(e.source) && filteredNodes.has(e.target))
                .map(e => e.id),
        );

        if (!showOrphans) {
            const connected = new Set();
            filteredEdges.forEach(eid => {
                const e = edgeById[eid];
                if (e) { connected.add(e.source); connected.add(e.target); }
            });
            filteredNodes.forEach(nid => { if (!connected.has(nid)) filteredNodes.delete(nid); });
            filteredEdges = new Set(
                [...filteredEdges].filter(eid => {
                    const e = edgeById[eid];
                    return e && filteredNodes.has(e.source) && filteredNodes.has(e.target);
                }),
            );
        }

        displayedNodes.current = filteredNodes;
        displayedEdges.current = filteredEdges;
        sigmaRef.current.refresh();

        // Deselect node if it became hidden
        if (selectedNode && !filteredNodes.has(selectedNode.id)) {
            setSelectedNode(null);
        }
    }, [graphData, typeFilters, statusFilters, showOrphans]);

    const fitView = () => sigmaRef.current?.getCamera().animatedReset();

    const resetFilters = () => {
        setTypeFilters({ article: true, summary: true, concept: true, entity: true });
        setStatusFilters({ error: true, warning: true, ok: true });
        setShowOrphans(true);
    };

    // ── render ────────────────────────────────────────────────────────────────

    return (
        <CustomerAppLayout title={tw.graph_view_title ?? 'Enterprise Wiki Graf'} showPageTitle={false}>
            <div className="flex h-[calc(100vh-80px)] min-h-[600px] flex-col gap-0">
                {/* Header bar */}
                <div className="flex shrink-0 items-center justify-between gap-4 pb-3">
                    <div className="flex items-center gap-3">
                        <Link
                            href="/app/wiki"
                            className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800"
                        >
                            <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fillRule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.25a.75.75 0 0 1 0-1.08l5.5-5.25a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clipRule="evenodd" />
                            </svg>
                            {tw.back ?? 'Tilbake til Wiki'}
                        </Link>
                        <span className="h-4 w-px bg-slate-200" />
                        <h1 className="text-base font-semibold text-slate-950">
                            {tw.graph_view_title ?? 'Enterprise Wiki Graf'}
                        </h1>
                        <span className="inline-flex h-6 items-center rounded-full bg-slate-100 px-2.5 text-xs font-medium text-slate-600">
                            {scopeLabel}
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={fitView}
                        disabled={!graphData || graphData.nodes.length === 0}
                        className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M13.28 7.78 19 2.06V6a.75.75 0 0 0 1.5 0V.75a.75.75 0 0 0-.75-.75H14a.75.75 0 0 0 0 1.5h3.94L12.22 7.22a.75.75 0 1 0 1.06 1.06ZM2 14a.75.75 0 0 0-1.5 0v5.25c0 .414.336.75.75.75H6a.75.75 0 0 0 0-1.5H2.06l5.72-5.72a.75.75 0 0 0-1.06-1.06L1 17.94V14Z" />
                        </svg>
                        {tw.graph_fit_view ?? 'Tilpass visning'}
                    </button>
                </div>

                {/* Main content */}
                <div className="flex min-h-0 flex-1 gap-4">
                    {/* Left sidebar */}
                    <div className="flex w-56 shrink-0 flex-col gap-3 overflow-y-auto">
                        <FilterPanel
                            typeFilters={typeFilters}
                            setTypeFilters={setTypeFilters}
                            statusFilters={statusFilters}
                            setStatusFilters={setStatusFilters}
                            showOrphans={showOrphans}
                            setShowOrphans={setShowOrphans}
                            tw={tw}
                            onReset={resetFilters}
                        />
                        {graphData && <SummaryPanel summary={graphData.summary} tw={tw} />}
                        <Legend tw={tw} />
                    </div>

                    {/* Graph canvas area */}
                    <div className="relative min-w-0 flex-1 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 shadow-sm">
                        {/* Loading */}
                        {loading && (
                            <div className="absolute inset-0 flex items-center justify-center bg-slate-50">
                                <div className="flex flex-col items-center gap-3">
                                    <svg
                                        className="h-6 w-6 animate-spin text-violet-500"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                                    </svg>
                                    <p className="text-sm text-slate-500">
                                        {tw.graph_loading ?? 'Laster graf…'}
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Error */}
                        {!loading && error && (
                            <div className="absolute inset-0 flex items-center justify-center bg-slate-50 p-8">
                                <div className="max-w-sm rounded-2xl border border-rose-100 bg-rose-50 p-6 text-center">
                                    <p className="text-sm font-semibold text-rose-700">
                                        {error === '422'
                                            ? (tw.graph_error_422 ?? 'Ugyldig scope – sjekk run_id eller page_id.')
                                            : (tw.graph_error_generic ?? 'Kunne ikke laste grafdata.')}
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Empty */}
                        {!loading && !error && graphData && graphData.nodes.length === 0 && (
                            <div className="absolute inset-0 flex items-center justify-center bg-slate-50 p-8">
                                <div className="max-w-sm text-center">
                                    <p className="text-sm font-semibold text-slate-600">
                                        {tw.graph_empty ?? 'Ingen sider å vise.'}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {tw.graph_empty_hint ?? 'Wikien er tom, eller alle sider er filtrert bort.'}
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Sigma container — always mounted so ref is stable */}
                        <div
                            ref={containerRef}
                            className="absolute inset-0"
                            style={{ visibility: (!loading && !error && graphData && graphData.nodes.length > 0) ? 'visible' : 'hidden' }}
                        />

                        {/* Scope badge overlay */}
                        {!loading && !error && graphData && graphData.nodes.length > 0 && (
                            <div className="pointer-events-none absolute bottom-3 right-3">
                                <span className="inline-flex items-center rounded-full bg-white/80 px-2.5 py-1 text-[11px] font-medium text-slate-500 shadow-sm ring-1 ring-slate-200 backdrop-blur-sm">
                                    {scopeLabel}
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Right sidebar */}
                    {selectedNode && (
                        <div className="w-56 shrink-0">
                            <NodePanel node={selectedNode} tw={tw} onClose={() => setSelectedNode(null)} />
                        </div>
                    )}
                </div>
            </div>
        </CustomerAppLayout>
    );
}
