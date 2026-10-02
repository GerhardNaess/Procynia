import { METRICS, edgePath, layoutBlueprint, nodeShape } from '../../Support/processBlueprintLayout';

/**
 * A process blueprint, drawn.
 *
 * The component owns no state and decides no geometry — every coordinate comes from
 * layoutBlueprint(), so what is on screen is a function of the blueprint and nothing else. Edit the
 * blueprint and the picture follows; there is nothing here to drag, and nothing to get out of sync.
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

const NODE_TONES = {
    start: { fill: '#ecfdf5', stroke: '#6ee7b7', text: '#065f46' },
    end: { fill: '#eef2ff', stroke: '#a5b4fc', text: '#3730a3' },
    decision: { fill: '#fffbeb', stroke: '#fcd34d', text: '#92400e' },
    step: { fill: '#ffffff', stroke: '#cbd5e1', text: '#0f172a' },
};

export default function ProcessSwimlaneDiagram({ blueprint, title, emptyText }) {
    const layout = layoutBlueprint(blueprint);

    if (layout.isEmpty) {
        return (
            <p className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-base text-slate-600">
                {emptyText}
            </p>
        );
    }

    return (
        // Horizontal scroll rather than a shrunk-to-fit diagram: a flow of ten columns squeezed
        // into a phone is unreadable, and an unreadable diagram is worse than one you have to pan.
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <svg
                role="img"
                aria-label={title}
                width={layout.width}
                height={layout.height}
                viewBox={`0 0 ${layout.width} ${layout.height}`}
                className="block"
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
