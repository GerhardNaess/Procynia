import { useState } from 'react';
import { formatKpiAmount } from './kpiTarget';

const WIDTH = 640;
const HEIGHT = 220;
const PAD = { top: 16, right: 16, bottom: 12, left: 16 };

/**
 * «Utvikling over tid»: one line through the current value of each measured period, oldest first,
 * against today's målverdi — a shaded band for an interval or an exact value, a dashed line for a
 * single bound — and the startverdi as a dotted line for a growth KPI.
 *
 * One series, so no legend: the heading names it, and the target and startverdi are labelled where
 * they are drawn. Hovering or focusing a point shows its period and value; the same values are in
 * Målehistorikk below as a table. Drawn from the plain values the server sends; the numbers are
 * only positions here, never compared or stored.
 *
 * @param {{points: Array, target_min: string|null, target_max: string|null, baseline: string|null}} trend
 */
export default function KpiTrend({ trend, kpi, tr, locale }) {
    const [active, setActive] = useState(null);
    const t = tr.trend ?? {};
    const points = trend?.points ?? [];

    if (points.length === 0) {
        return <p className="mt-4 text-base text-slate-500">{t.empty ?? 'Utviklingen vises når KPI-en har målinger.'}</p>;
    }

    const amount = (value) => formatKpiAmount(value, kpi, tr, locale);
    const values = points.map((point) => Number(point.value));
    const references = [trend.target_min, trend.target_max, trend.baseline].filter((value) => value !== null && value !== undefined).map(Number);
    let low = Math.min(...values, ...references);
    let high = Math.max(...values, ...references);

    if (low === high) {
        low -= Math.abs(low) * 0.1 || 1;
        high += Math.abs(high) * 0.1 || 1;
    }

    const margin = (high - low) * 0.1;
    low -= margin;
    high += margin;

    const plotWidth = WIDTH - PAD.left - PAD.right;
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const x = (index) => PAD.left + (points.length === 1 ? plotWidth / 2 : (plotWidth * index) / (points.length - 1));
    const y = (value) => PAD.top + plotHeight - ((Number(value) - low) / (high - low)) * plotHeight;
    const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(point.value).toFixed(1)}`).join(' ');
    const hasBand = trend.target_min !== null && trend.target_max !== null;
    const singleBound = hasBand ? null : (trend.target_min ?? trend.target_max);
    const last = points[points.length - 1];
    const summary = `${t.heading ?? 'Utvikling over tid'}: ${points.length} · ${last.label}: ${last.value_display}`;
    const shown = active !== null ? points[active] : null;

    return (
        <div className="relative mt-4">
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                className="h-auto w-full"
                role="img"
                aria-label={summary}
                data-testid="kpi-trend"
            >
                {hasBand && (
                    <rect
                        x={PAD.left}
                        width={plotWidth}
                        y={Math.min(y(trend.target_max), y(trend.target_min)) - (trend.target_min === trend.target_max ? 1 : 0)}
                        height={Math.max(2, Math.abs(y(trend.target_min) - y(trend.target_max)))}
                        className="fill-emerald-100"
                    />
                )}
                {singleBound !== null && (
                    <line x1={PAD.left} x2={WIDTH - PAD.right} y1={y(singleBound)} y2={y(singleBound)} className="stroke-emerald-600" strokeWidth="2" strokeDasharray="6 4" />
                )}
                {trend.baseline !== null && trend.baseline !== undefined && (
                    <line x1={PAD.left} x2={WIDTH - PAD.right} y1={y(trend.baseline)} y2={y(trend.baseline)} className="stroke-slate-400" strokeWidth="2" strokeDasharray="2 4" />
                )}
                <line x1={PAD.left} x2={WIDTH - PAD.right} y1={HEIGHT - PAD.bottom} y2={HEIGHT - PAD.bottom} className="stroke-slate-200" strokeWidth="1" />

                {points.length > 1 && <path d={path} fill="none" className="stroke-violet-600" strokeWidth="2" strokeLinejoin="round" />}
                {points.map((point, index) => (
                    <g key={point.id}>
                        <circle cx={x(index)} cy={y(point.value)} r="5" className="fill-violet-600 stroke-white" strokeWidth="2" />
                        <circle
                            cx={x(index)}
                            cy={y(point.value)}
                            r="14"
                            fill="transparent"
                            tabIndex={0}
                            aria-label={`${point.label}: ${point.value_display}`}
                            onMouseEnter={() => setActive(index)}
                            onMouseLeave={() => setActive(null)}
                            onFocus={() => setActive(index)}
                            onBlur={() => setActive(null)}
                            className="cursor-pointer focus:outline-none"
                        />
                    </g>
                ))}

            </svg>
            {/* Period labels in HTML, not in the SVG: they keep their 16 px however narrow the chart. */}
            <div className="flex justify-between gap-4 text-base text-slate-500" aria-hidden="true">
                <span>{points[0].label}</span>
                {points.length > 1 && <span className="text-right">{last.label}</span>}
            </div>

            {shown && (
                <div
                    className="pointer-events-none absolute rounded-lg bg-slate-900 px-3 py-2 text-base text-white shadow"
                    // Anchored inside the chart: from the left edge for the first points, from the
                    // right for the last, centred in between — never cut off by the card.
                    style={{
                        left: `${(x(active) / WIDTH) * 100}%`,
                        top: 0,
                        transform: `translateX(${x(active) / WIDTH < 0.2 ? '0' : (x(active) / WIDTH > 0.8 ? '-100%' : '-50%')})`,
                    }}
                    role="status"
                >
                    <span className="block font-semibold">{shown.value_display}</span>
                    <span className="block">{shown.label}</span>
                </div>
            )}

            <ul className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-base text-slate-600">
                {(hasBand || singleBound !== null) && (
                    <li className="flex items-center gap-2">
                        <span aria-hidden="true" className={hasBand ? 'inline-block h-3 w-6 rounded-sm bg-emerald-100' : 'inline-block w-6 border-t-2 border-dashed border-emerald-600'} />
                        {`${t.target ?? 'Målverdi'}: ${kpi.target_display}`}
                    </li>
                )}
                {trend.baseline !== null && trend.baseline !== undefined && (
                    <li className="flex items-center gap-2">
                        <span aria-hidden="true" className="inline-block w-6 border-t-2 border-dotted border-slate-400" />
                        {`${t.baseline ?? 'Startverdi'}: ${amount(trend.baseline)}`}
                    </li>
                )}
            </ul>
        </div>
    );
}
