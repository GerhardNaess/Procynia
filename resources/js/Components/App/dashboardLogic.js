/**
 * Derivations behind the dashboard's three questions: what needs following up, where the cases
 * are, and what came of them.
 *
 * All of it is read off the existing cockpit payload — nothing here aggregates or recomputes.
 * The point is to decide what is worth showing, which is where the old dashboard went wrong.
 */

/** The signals that carry an action, in the order they should be read. */
export const FOLLOW_UP_KEYS = [
    'go-no-go-pending',
    'missing-bid-manager',
    'deadline-soon',
    'inactive-seven-days',
];

/**
 * The four follow-up signals, in priority order, each with a short display label.
 *
 * A signal missing from the payload is dropped rather than faked. A signal at zero is kept — "no
 * cases are missing a bid manager" is an answer, not an absence — and the view mutes it.
 *
 * The description is the translated one where available; the backend ships a hardcoded Norwegian
 * subtitle, so it is only the fallback.
 *
 * @param {Array<{key: string}>} attentionItems
 * @param {Record<string, string>} labels Keyed by attention key.
 * @param {Record<string, string>} descriptions Keyed by attention key.
 * @returns {Array<object>}
 */
export function followUpSignals(attentionItems = [], labels = {}, descriptions = {}) {
    return FOLLOW_UP_KEYS
        .map((key) => {
            const item = (attentionItems ?? []).find((candidate) => candidate.key === key);

            return item
                ? {
                    ...item,
                    label: labels[key] ?? item.title,
                    description: descriptions[key] ?? item.subtitle,
                }
                : null;
        })
        .filter(Boolean);
}

/**
 * Secondary management figures, as plain "covered / total" ratios rather than percentages.
 *
 * Coverage with no cases to cover is not 0 % — it is unmeasured, and says so.
 *
 * @param {{items?: Array<object>}} bidQuality
 * @param {{activity?: {activity_count_14_days?: number}}} responsibility
 * @param {Record<string, string>} labels
 * @param {string} noMeasurementLabel
 * @returns {Array<{key: string, label: string, value: string}>}
 */
export function managementRows(bidQuality = {}, responsibility = {}, labels = {}, noMeasurementLabel = '—') {
    const metric = (key) => (bidQuality.items ?? []).find((item) => item.key === key) ?? null;
    const ratio = (item) => (item && Number(item.denominator ?? 0) > 0
        ? `${item.numerator} / ${item.denominator}`
        : noMeasurementLabel);

    return [
        {
            key: 'opportunity_owner',
            label: labels.opportunity_owner,
            value: ratio(metric('opportunity_owner_coverage')),
        },
        {
            key: 'bid_manager',
            label: labels.bid_manager,
            value: ratio(metric('bid_manager_coverage')),
        },
        {
            key: 'activity_14_days',
            label: labels.activity_14_days,
            value: String(responsibility?.activity?.activity_count_14_days ?? 0),
        },
    ];
}

/**
 * The outcomes worth a tile.
 *
 * "Arkiv" is a legacy bid status, not a business result, and reads as the History placement it is
 * not — so it only appears when cases actually hold it, and never leads the section.
 *
 * @param {Array<{key: string, count: number}>} outcomes
 * @returns {Array<object>}
 */
export function visibleOutcomes(outcomes = []) {
    return (outcomes ?? []).filter(
        (outcome) => outcome.key !== 'archived' || Number(outcome.count ?? 0) > 0,
    );
}

/**
 * Whether any case has reached an outcome at all.
 *
 * @param {Array<{count: number}>} outcomes
 * @returns {boolean}
 */
export function hasAnyOutcome(outcomes = []) {
    return (outcomes ?? []).some((outcome) => Number(outcome.count ?? 0) > 0);
}

/**
 * The win rate, or null when there is no basis for one.
 *
 * A win rate over zero closed cases is not 0 % and not "—" in a big tile; it is a number nobody
 * should be shown, so the view omits the row entirely.
 *
 * @param {{items?: Array<object>}} bidQuality
 * @returns {object|null}
 */
export function winRateMetric(bidQuality = {}) {
    const metric = (bidQuality.items ?? []).find((item) => item.key === 'win_rate_90d') ?? null;

    if (!metric || Number(metric.denominator ?? 0) <= 0 || metric.value === null || metric.value === undefined) {
        return null;
    }

    return metric;
}
