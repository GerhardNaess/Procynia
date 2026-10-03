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

export default function ProcessSwimlaneDiagram({
    blueprint,
    title,
    emptyText,
    tb = {},
    onOpenSubprocess = null,
    onOpenActivity = null,
}) {
    const layout = layoutBlueprint(blueprint);

    if (layout.isEmpty) {
        return (
            <p className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-base text-slate-600">
                {emptyText}
            </p>
        );
    }

    return (
        <ZoomableDiagram
            layout={layout}
            title={title}
            tb={tb}
            onOpenSubprocess={onOpenSubprocess}
            onOpenActivity={onOpenActivity}
        />
    );
}

/**
 * The drawing, inside a surface it can be bigger than.
 *
 * Split out from the exported component so the hooks below are never conditional on an empty
 * blueprint — an empty flow renders a sentence, not a zoomable surface.
 */
function ZoomableDiagram({ layout, title, tb, onOpenSubprocess, onOpenActivity }) {
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
                            <Node
                                key={node.key}
                                node={node}
                                tb={tb}
                                onOpenSubprocess={onOpenSubprocess}
                                onOpenActivity={onOpenActivity}
                            />
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

/**
 * One node, and — when it stands for another process, or has produced knowledge in Wiki — the way
 * into it.
 *
 * A subprocess node is still a node: same box, same lane colour, same shape, because it is still a
 * step in this flow and redrawing it as something else would make the diagram harder to read for
 * the sake of a detail most readers do not need. What marks it is a small pill on its bottom edge
 * saying how many steps the other flow holds, which is the one thing a reader has to know before
 * deciding whether to open it: BPMN's ⊞ with the number spelled out, because a kvalitetshåndbok is
 * read by people who do not know BPMN.
 *
 * An activity that has produced Wiki articles is marked the same way and just as quietly, by a
 * count on its top edge. The count is the whole statement — what the articles say is Wiki's
 * business, and the diagram would be unreadable if it tried to say any of it.
 *
 * The whole node becomes the control, not the pill. The pill is 16 pixels tall on a diagram that is
 * routinely viewed at 60 %, and a target that small is a target nobody hits — so the box is the
 * button, the pill is the sign that says so.
 *
 * ONE NODE, ONE ACTION. A node that both stands for a process and has produced articles opens the
 * process: drilling in is the bigger move, and the reader who went in can still reach the articles
 * from the step list either side of the trail. Two controls on one 64-pixel box would be two
 * targets nobody can tell apart at the zoom these diagrams are actually read at.
 */
function Node({ node, tb = {}, onOpenSubprocess = null, onOpenActivity = null }) {
    const shape = nodeShape(node);
    const tone = NODE_TONES[node.type] ?? NODE_TONES.step;
    const firstLineY = node.y + (node.height / 2) - (((node.lines.length - 1) * 15) / 2) + 4;

    const subprocess = node.subprocess ?? null;
    const articles = node.articles ?? [];

    const opensSubprocess = subprocess !== null && typeof onOpenSubprocess === 'function';
    const opensActivity = ! opensSubprocess && articles.length > 0 && typeof onOpenActivity === 'function';
    const openable = opensSubprocess || opensActivity;

    const open = openable
        ? (event) => {
            event.stopPropagation();

            if (opensSubprocess) {
                onOpenSubprocess(subprocess, node);

                return;
            }

            onOpenActivity(node);
        }
        : undefined;

    const body = (
        <>
            {shape.kind === 'rect' ? (
                <rect
                    x={shape.x}
                    y={shape.y}
                    width={shape.width}
                    height={shape.height}
                    rx={shape.rx}
                    fill={tone.fill}
                    stroke={tone.stroke}
                    strokeWidth={subprocess ? 2.5 : 1.5}
                />
            ) : (
                <polygon
                    points={shape.points.map((point) => `${point.x},${point.y}`).join(' ')}
                    fill={tone.fill}
                    stroke={tone.stroke}
                    strokeWidth={subprocess ? 2.5 : 1.5}
                />
            )}

            <text fontSize="12.5" fontWeight="500" fill={tone.text} textAnchor="middle">
                {node.lines.map((line, index) => (
                    <tspan key={line + index} x={node.x + (node.width / 2)} y={firstLineY + (index * 15)}>
                        {line}
                    </tspan>
                ))}
            </text>

            {subprocess && <SubprocessMarker node={node} subprocess={subprocess} tb={tb} />}

            {articles.length > 0 && <ArticleMarker node={node} count={articles.length} tb={tb} />}
        </>
    );

    if (! openable) {
        return <g>{body}</g>;
    }

    const name = opensSubprocess
        ? (tb.subprocess_open ?? 'Åpne underprosessen :title').replace(':title', subprocess.title ?? '')
        : (tb.articles_open ?? 'Åpne kunnskapen bak :label').replace(':label', node.label ?? '');

    return (
        <g
            role="button"
            tabIndex={0}
            aria-label={name}
            className={`cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${opensSubprocess ? 'focus-visible:outline-violet-600' : 'focus-visible:outline-sky-600'}`}
            onClick={open}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    open(event);
                }
            }}
        >
            <title>{name}</title>
            {body}
        </g>
    );
}

/**
 * The pill that says this step is a process of its own.
 *
 * Centred on the bottom edge and hanging half outside the box: inside, it would take a third of a
 * 64-pixel node that already carries up to three lines of label. The 8 pixels it uses below the box
 * come out of the 20-pixel row gap, so it can never reach the node beneath it.
 *
 * Width is counted from the text rather than measured, for the same reason wrapLabel() counts
 * characters: measuring needs a DOM, and this has to draw identically everywhere.
 */
function SubprocessMarker({ node, subprocess, tb }) {
    const steps = Number(subprocess.step_count ?? 0);
    const text = steps > 0
        ? (tb.subprocess_steps ?? ':count steg').replace(':count', String(steps))
        : (tb.subprocess_no_flow ?? 'Underprosess');

    // 16 for the ⊞, 6.4 per character, 10 of padding, 12 for the chevron.
    const width = 16 + (text.length * 6.4) + 22;
    const x = node.x + (node.width / 2) - (width / 2);
    const y = node.y + node.height - 8;

    return (
        <g aria-hidden="true">
            <rect x={x} y={y} width={width} height="17" rx="8.5" fill="#ffffff" stroke="#c4b5fd" strokeWidth="1.2" />

            {/* BPMN's collapsed-subprocess mark, small enough to read as a bullet. */}
            <rect x={x + 7} y={y + 4.5} width="8" height="8" rx="1.5" fill="none" stroke="#7c3aed" strokeWidth="1.2" />
            <path d={`M ${x + 11} ${y + 6.5} v 4 M ${x + 9} ${y + 8.5} h 4`} stroke="#7c3aed" strokeWidth="1.2" strokeLinecap="round" />

            <text x={x + 20} y={y + 12} fontSize="10.5" fontWeight="600" fill="#5b21b6">{text}</text>

            <path
                d={`M ${x + width - 12} ${y + 5.5} l 3.5 3 l -3.5 3`}
                fill="none"
                stroke="#7c3aed"
                strokeWidth="1.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </g>
    );
}

/**
 * The pill that says this activity has produced knowledge in Wiki.
 *
 * A count and nothing else. The Wiki pages have titles that are routinely longer than the node, and
 * a diagram that tried to name them would stop being a diagram — so this says how many there are,
 * which is the one thing a reader needs in order to decide whether to open the activity. The names
 * are in the panel that opens, where they are links.
 *
 * On the top edge, right-aligned, because the bottom centre belongs to the subprocess pill: a step
 * that is both a process and a source of articles has to be able to say both at once. The 9 pixels it
 * hangs above the box come out of the 20-pixel row gap, so it can never reach the node above it.
 *
 * Width is counted from the text rather than measured, for the same reason wrapLabel() counts
 * characters: measuring needs a DOM, and this has to draw identically everywhere.
 */
function ArticleMarker({ node, count, tb }) {
    // One page is a case a reader meets routinely, and ":count kunnskapssider" reads as a bug
    // when the count is 1. The grammatical number is part of the string, so it is a key of its own.
    const template = count === 1
        ? (tb.articles_count_one ?? ':count kunnskapsside')
        : (tb.articles_count ?? ':count kunnskapssider');
    const text = template.replace(':count', String(count));

    // 16 for the glyph, 6.4 per character, 10 of padding.
    const width = 16 + (text.length * 6.4) + 10;
    const x = node.x + node.width - width - 10;
    const y = node.y - 9;

    return (
        <g aria-hidden="true">
            <rect x={x} y={y} width={width} height="17" rx="8.5" fill="#ffffff" stroke="#7dd3fc" strokeWidth="1.2" />

            {/* An open book, small enough to read as a bullet. */}
            <path
                d={`M ${x + 7} ${y + 5} h 3.5 v 7 h -3.5 z M ${x + 11.5} ${y + 5} h 3.5 v 7 h -3.5 z`}
                fill="none"
                stroke="#0284c7"
                strokeWidth="1.1"
                strokeLinejoin="round"
            />

            <text x={x + 19} y={y + 12} fontSize="10.5" fontWeight="600" fill="#075985">{text}</text>
        </g>
    );
}
