/**
 * The level a likelihood/consequence pair will get, read from the criteria the server sent
 * (RiskScoringPolicy). Only a preview for the form: the server computes the level it shows.
 */
export function previewLevel(criteria, likelihood, consequence) {
    const l = Number(likelihood);
    const c = Number(consequence);

    if (! criteria
        || ! (criteria.likelihood ?? []).includes(l)
        || ! (criteria.consequence ?? []).includes(c)) {
        return null;
    }

    const score = l * c;
    const band = (criteria.bands ?? []).find((candidate) => score >= candidate.min && score <= candidate.max);

    return band ? { score, level: band.level } : null;
}

export const RISK_LEVEL_TONES = {
    low: 'emerald',
    moderate: 'amber',
    high: 'rose',
    very_high: 'red',
};
