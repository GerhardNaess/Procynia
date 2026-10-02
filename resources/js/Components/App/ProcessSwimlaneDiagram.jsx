import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { METRICS, edgePath, layoutBlueprint, nodeShape } from '../../Support/processBlueprintLayout';
import {
    ZOOM_LIMITS,
    anchoredScroll,
    fitScale,
    overflowsSurface,
    steppedScale,
    surfaceHeight,
} from '../../Support/diagramViewport';

/**
 * A process blueprint, drawn.
 *
 * The component owns no state that the picture depends on and decides no geometry — every
 * coordinate comes from layoutBlueprint(), so what is on screen is a function of the blueprint and
 * nothing else. Edit the blueprint and the picture follows; there is nothing here to drag, and
 * nothing to get out of sync.
 *
 * Zoom is the one piece of state it does own, and it is deliberately not geometry: it scales the
 * finished drawing, so the same coordinates are being shown, larger or smaller. A zoom level is
 * never saved and never leaves the component — reload the page and the diagram is back to the size
 * the flow asks for.
 *
 * Inline SVG rather than an image: it is text, so it survives a browser zoom, it can be read by a
 * screen reader through the title/desc below, and no file has to be generated, stored or cleaned up.
 *
 * Colour is the lane's, shape is the node's. Shape is the part that has to survive being printed in
 * black and white, which is how a kvalitetshåndbok is still read in a lot of places.
 */

/** Rotated through the lanes so neighbouring bands are told apart without carrying any meaning. */
const LANE_TINTS = [
    { band: '#f8fafc', label: '#475569', edge: '#e2e8f0' },
    { band: '#ffffff', label: '#475569', edge: '#e2e8f0' },
];

/** The height the control strip keeps clear at the top of the surface. */
const CONTROL_STRIP = 48;

const NODE_TONES = {
    start: { fill: '#ecfdf5', stroke: '#6ee7b7', text: '#065f46' },
    end: { fill: '#eef2ff', stroke: '#a5b4fc', text: '#3730a3' },
    decision: { fill: '#fffbeb', stroke: '#fcd34d', text: '#92400e' },
    step: { fill: '#ffffff', stroke: '#cbd5e1', text: '#0f172a' },
};

export default function ProcessSwimlaneDiagram({ blueprint, title, emptyText, tb = {} }) {
    const layout = layoutBlueprint(blueprint);

    if (layout.isEmpty) {
        return (
            <p className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-base text-slate-600">
                {emptyText}
            </p>
        );
    }

    return <ZoomableDiagram layout={layout} title={title} tb={tb} />;
}

/**
 * The drawing, inside a surface it can be bigger than.
 *
 * Split out from the exported component so the hooks below are never conditional on an empty
 * blueprint — an empty flow renders a sentence, not a zoomable surface.
 */
function ZoomableDiagram({ layout, title, tb }) {
    const surfaceRef = useRef(null);
    const [width, setWidth] = useState(0);
    const [scale, setScale] = useState(null);

    // The width is whatever the card gives us, so it has to be measured rather than assumed, and
    // re-measured when the rail collapses or the window changes. The height follows from it.
    //
    // The strip is kept clear of the drawing rather than floated over it: the last node of a flow
    // sits at the top right, which is exactly where the controls are, and a fitted diagram whose
    // end step is hidden behind the fit button is not fitted.
    const drawable = surfaceHeight(layout, width);
    const surface = width > 0 ? { width, height: drawable } : null;

    useLayoutEffect(() => {
        const element = surfaceRef.current;

        if (! element) {
            return undefined;
        }

        const measure = () => setWidth(element.clientWidth);

        measure();

        if (typeof ResizeObserver === 'undefined') {
            return undefined;
        }

        const observer = new ResizeObserver(measure);

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    // The opening size, chosen once the surface is known: a flow that already fits is left alone,
    // and only one that overflows is fitted. Set before paint so the diagram does not appear at full
    // size and then jump.
    useLayoutEffect(() => {
        if (surface === null) {
            return;
        }

        setScale((current) => {
            if (current !== null) {
                return current;
            }

            return overflowsSurface(layout, surface) ? fitScale(layout, surface) : 1;
        });
    }, [width, drawable, layout.width, layout.height]);

    // Editing the flow changes the drawing's size under a scale that was chosen for the old one. A
    // diagram that was fitted stays fitted; one the user had set themselves is left at their size.
    const wasFittedRef = useRef(true);

    useEffect(() => {
        if (surface === null || scale === null || ! wasFittedRef.current) {
            return;
        }

        const fitted = overflowsSurface(layout, surface) ? fitScale(layout, surface) : 1;

        if (fitted !== scale) {
            setScale(fitted);
        }
    }, [layout.width, layout.height, width, drawable, scale]);

    const effective = scale ?? 1;

    /** Moves to a scale and re-points the scrollbars so the surface stays over the same step. */
    const applyScale = (next, { fitted }) => {
        wasFittedRef.current = fitted;

        if (next === effective) {
            return;
        }

        const element = surfaceRef.current;

        if (element) {
            const left = fitted ? 0 : anchoredScroll({
                scroll: element.scrollLeft,
                surface: element.clientWidth,
                from: effective,
                to: next,
            });
            const top = fitted ? 0 : anchoredScroll({
                scroll: element.scrollTop,
                surface: element.clientHeight,
                from: effective,
                to: next,
            });

            // After the browser has laid the resized drawing out; before that the scroll positions
            // would be clamped to the old size and the anchoring would be lost.
            requestAnimationFrame(() => {
                if (surfaceRef.current) {
                    surfaceRef.current.scrollLeft = left;
                    surfaceRef.current.scrollTop = top;
                }
            });
        }

        setScale(next);
    };

    const zoom = (direction) => applyScale(steppedScale(effective, direction), { fitted: false });
    const fit = () => applyScale(fitScale(layout, surface ?? { width: 0, height: drawable }), { fitted: true });

    const atMin = effective <= ZOOM_LIMITS.min;
    const atMax = effective >= ZOOM_LIMITS.max;

    return (
        <div className="relative rounded-2xl border border-slate-200 bg-white">
            <div
                ref={surfaceRef}
                // Scrolling rather than a permanently shrunk-to-fit diagram: a flow of ten columns
                // squeezed into a phone is unreadable, and an unreadable diagram is worse than one
                // you have to pan. Fit is a choice the user makes, not a cage.
                className="overflow-auto rounded-2xl"
                style={{ height: drawable + CONTROL_STRIP }}
            >
                {/* Centred while the drawing is smaller than the surface; the margins collapse to
                    nothing the moment it is larger, so scrolling starts at the top left corner. */}
                <div
                    className="flex min-w-full items-center justify-center"
                    style={{ minHeight: drawable, paddingTop: CONTROL_STRIP }}
                >
                    <svg
                        role="img"
                        aria-label={title}
                        width={layout.width * effective}
                        height={layout.height * effective}
                        viewBox={`0 0 ${layout.width} ${layout.height}`}
                        className="block shrink-0"
                    >
                        <title>{title}</title>

                        <defs>
                            <marker
                                id="procynia-flow-arrow"
                                viewBox="0 0 10 10"
                                refX="9"
                                refY="5"
                                markerWidth="7"
                                markerHeight="7"
                                orient="auto-start-reverse"
                            >
                                <path d="M 0 0 L 10 5 L 0 10 z" fill="#64748b" />
                            </marker>
                        </defs>

                        {layout.lanes.map((lane) => {
                            const tint = LANE_TINTS[lane.index % LANE_TINTS.length];

                            return (
                                <g key={lane.key}>
                                    <rect x="0" y={lane.y} width={layout.width} height={lane.height} fill={tint.band} />
                                    <line
                                        x1="0"
                                        y1={lane.y}
                                        x2={layout.width}
                                        y2={lane.y}
                                        stroke={tint.edge}
                                        strokeWidth="1"
                                    />
                                    <LaneLabel lane={lane} colour={tint.label} />
                                </g>
                            );
                        })}

                        {/* The line that separates the role column from the flow, drawn once over every band. */}
                        <line
                            x1={METRICS.laneLabelWidth}
                            y1={layout.lanes[0].y}
                            x2={METRICS.laneLabelWidth}
                            y2={layout.height - METRICS.paddingY}
                            stroke="#cbd5e1"
                            strokeWidth="1"
                        />

                        {/* Edges first so a node always covers the arrow arriving at it, never the reverse. */}
                        {layout.edges.map((edge) => (
                            <Edge key={`${edge.from}->${edge.to}#${edge.label ?? ''}`} edge={edge} />
                        ))}

                        {layout.nodes.map((node) => (
                            <Node key={node.key} node={node} />
                        ))}
                    </svg>
                </div>
            </div>

            <ZoomControls
                tb={tb}
                scale={effective}
                atMin={atMin}
                atMax={atMax}
                onZoomIn={() => zoom(1)}
                onZoomOut={() => zoom(-1)}
                onFit={fit}
            />
        </div>
    );
}

/**
 * The three controls, over the top right corner of the drawing.
 *
 * On the diagram rather than in the card heading because they act on the diagram and nothing else —
 * a control that sits beside the heading reads as acting on the section, and the page zoom the
 * browser already provides is the thing it would be confused with. They are small and neutral: this
 * changes what you can see, it commits nothing (see DISCLOSURE in actionStyles.js).
 */
function ZoomControls({ tb, scale, atMin, atMax, onZoomIn, onZoomOut, onFit }) {
    const zoomIn = tb.zoom_in ?? 'Zoom inn';
    const zoomOut = tb.zoom_out ?? 'Zoom ut';
    const zoomFit = tb.zoom_fit ?? 'Tilpass til vindu';

    return (
        <div
            className="pointer-events-none absolute right-3 top-3 flex items-center gap-1 rounded-xl border border-slate-200 bg-white/95 p-1 shadow-sm backdrop-blur"
            role="group"
            aria-label={tb.zoom_group ?? 'Visning av diagrammet'}
        >
            <span className="pointer-events-auto px-2 text-sm font-semibold tabular-nums text-slate-500" aria-live="polite">
                {Math.round(scale * 100)} %
            </span>
            <ZoomButton label={zoomOut} onClick={onZoomOut} disabled={atMin}>
                <path d="M5 10h10" />
            </ZoomButton>
            <ZoomButton label={zoomIn} onClick={onZoomIn} disabled={atMax}>
                <path d="M10 5v10" />
                <path d="M5 10h10" />
            </ZoomButton>
            <ZoomButton label={zoomFit} onClick={onFit}>
                <path d="M3.5 7.5v-4h4" />
                <path d="M16.5 7.5v-4h-4" />
                <path d="M3.5 12.5v4h4" />
                <path d="M16.5 12.5v4h-4" />
            </ZoomButton>
        </div>
    );
}

/**
 * Icon only, so the three fit over the drawing without covering the first node — the name is on the
 * button for a screen reader and in the tooltip for everyone else.
 */
function ZoomButton({ label, onClick, disabled = false, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={label}
            aria-label={label}
            className="pointer-events-auto inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500 disabled:cursor-not-allowed disabled:opacity-40"
        >
            <svg
                className="h-4 w-4"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.7"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                {children}
            </svg>
        </button>
    );
}

/**
 * The role, in the band's own left margin.
 *
 * Horizontal and wrapped rather than rotated: a rotated label is unreadable on screen and worse on
 * paper, and these are job titles, which are long.
 */
function LaneLabel({ lane, colour }) {
    const words = String(lane.label ?? '').split(/\s+/).filter(Boolean);
    const lines = [];
    let current = '';

    for (const word of words) {
        const candidate = current === '' ? word : `${current} ${word}`;

        if (candidate.length <= 18) {
            current = candidate;
        } else {
            if (current !== '') {
                lines.push(current);
            }

            current = word;
        }
    }

    if (current !== '') {
        lines.push(current);
    }

    const start = lane.y + (lane.height / 2) - (((lines.length - 1) * 16) / 2);

    return (
        <text x="20" fill={colour} fontSize="13" fontWeight="600">
            {lines.slice(0, 3).map((line, index) => (
                <tspan key={line + index} x="20" y={start + (index * 16)}>
                    {line}
                </tspan>
            ))}
        </text>
    );
}

function Edge({ edge }) {
    if (edge.points.length < 2) {
        return null;
    }

    return (
        <g>
            <path
                d={edgePath(edge.points)}
                fill="none"
                stroke="#94a3b8"
                strokeWidth="1.5"
                // A loop back to an earlier step is a different kind of statement from moving on,
                // so it is dashed as well as routed below the flow.
                strokeDasharray={edge.isBackward ? '5 4' : undefined}
                markerEnd="url(#procynia-flow-arrow)"
            />
            {edge.label && (
                <g>
                    {/* Knocked out of the line behind it, so the outcome stays readable where two
                        arrows cross. */}
                    <rect
                        x={edge.labelX - (edge.label.length * 3.6) - 5}
                        y={edge.labelY - 9}
                        width={(edge.label.length * 7.2) + 10}
                        height="17"
                        rx="8"
                        fill="#ffffff"
                        stroke="#e2e8f0"
                    />
                    <text
                        x={edge.labelX}
                        y={edge.labelY + 3}
                        textAnchor="middle"
                        fontSize="11"
                        fontWeight="600"
                        fill="#475569"
                    >
                        {edge.label}
                    </text>
                </g>
            )}
        </g>
    );
}

function Node({ node }) {
    const shape = nodeShape(node);
    const tone = NODE_TONES[node.type] ?? NODE_TONES.step;
    const firstLineY = node.y + (node.height / 2) - (((node.lines.length - 1) * 15) / 2) + 4;

    return (
        <g>
            {shape.kind === 'rect' ? (
                <rect
                    x={shape.x}
                    y={shape.y}
                    width={shape.width}
                    height={shape.height}
                    rx={shape.rx}
                    fill={tone.fill}
                    stroke={tone.stroke}
                    strokeWidth="1.5"
                />
            ) : (
                <polygon
                    points={shape.points.map((point) => `${point.x},${point.y}`).join(' ')}
                    fill={tone.fill}
                    stroke={tone.stroke}
                    strokeWidth="1.5"
                />
            )}

            <text fontSize="12.5" fontWeight="500" fill={tone.text} textAnchor="middle">
                {node.lines.map((line, index) => (
                    <tspan key={line + index} x={node.x + (node.width / 2)} y={firstLineY + (index * 15)}>
                        {line}
                    </tspan>
                ))}
            </text>
        </g>
    );
}
