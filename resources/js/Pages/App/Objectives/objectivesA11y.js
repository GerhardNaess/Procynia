/**
 * Text alternatives and focus targets for Mål og KPI (WCAG Fase 6), kept pure so they are unit-tested.
 * Nothing here judges a KPI: the status, the målverdi and the explanation are the server's words.
 */

const fill = (template, values) => Object.entries(values)
    .reduce((text, [name, value]) => text.replaceAll(`:${name}`, String(value ?? '')), template ?? '');

/**
 * Which way the line in «Utvikling over tid» goes, first period to last — what a sighted reader sees
 * at a glance, said in words. Not a judgement: rising is not «good», the status says that.
 *
 * @param {Array<{value: string|number}>} points Oldest first, as the chart draws them.
 * @returns {'rising'|'falling'|'unchanged'|null} null with fewer than two points.
 */
export function trendDirection(points = []) {
    if (points.length < 2) {
        return null;
    }

    const first = Number(points[0].value);
    const last = Number(points[points.length - 1].value);

    if (! Number.isFinite(first) || ! Number.isFinite(last)) {
        return null;
    }

    if (last > first) {
        return 'rising';
    }

    return last < first ? 'falling' : 'unchanged';
}

/**
 * The chart as one paragraph (1.1.1, A11Y-052): how many periods, the latest value, today's målverdi,
 * the status in words and the server's own explanation, the direction of the line — and where every
 * value can be read (Målehistorikk). The chart itself is then a picture with this as its name; its
 * points are not tab stops.
 *
 * @param {object} args
 * @param {Array}  args.points       The trend points, oldest first (label, value, value_display).
 * @param {object} args.kpi          The KPI detail (target_display, status_explanation).
 * @param {string} [args.heading]    «Utvikling over tid».
 * @param {string} [args.resultLabel] Today's status in words («På mål»).
 * @param {object} [args.ta]         translations.a11y.
 */
export function kpiTrendSummary({ points = [], kpi = {}, heading = 'Utvikling over tid', resultLabel = null, ta = {} }) {
    if (points.length === 0) {
        return '';
    }

    const first = points[0];
    const last = points[points.length - 1];
    const parts = [`${heading}.`];

    parts.push(points.length === 1
        ? fill(ta.objectives_trend_one ?? 'Én måling: :value for :period.', { value: last.value_display, period: last.label })
        : fill(ta.objectives_trend_many ?? ':count målinger fra :first til :last. Siste verdi er :value for :period.', {
            count: points.length,
            first: first.label,
            last: last.label,
            value: last.value_display,
            period: last.label,
        }));

    if (kpi.target_display) {
        parts.push(fill(ta.objectives_trend_target ?? 'Målverdi: :target.', { target: kpi.target_display }));
    }

    if (resultLabel) {
        parts.push(fill(ta.objectives_trend_status ?? 'Dagens status: :status.', { status: resultLabel }));
    }

    if (kpi.status_explanation) {
        parts.push(kpi.status_explanation);
    }

    const direction = trendDirection(points);
    const directionText = {
        rising: ta.objectives_trend_rising ?? 'Verdien har økt fra første til siste periode.',
        falling: ta.objectives_trend_falling ?? 'Verdien har gått ned fra første til siste periode.',
        unchanged: ta.objectives_trend_unchanged ?? 'Verdien er den samme i første og siste periode.',
    }[direction];

    if (directionText) {
        parts.push(directionText);
    }

    parts.push(ta.objectives_trend_table ?? 'Alle verdiene står i tabellen Målehistorikk.');

    return parts.join(' ');
}

/**
 * Where focus goes when an inline panel on a KPI or objective page closes (2.4.3, A11Y-014): the
 * button that opened it, if it is still there — a saved Avslutt KPI takes «Avslutt KPI» away — then
 * the page's other actions, and last the page heading, which always exists.
 *
 * @param {string|null} triggerId The id of the button that opened the panel.
 * @param {string[]} others       Other actions in the header, in order.
 * @param {string} headingId      The page heading.
 * @returns {string[]} Element ids for refocusIfLost.
 */
export function panelCloseFocusCandidates(triggerId, others = [], headingId) {
    return [...new Set([triggerId, ...others, headingId].filter(Boolean))];
}
