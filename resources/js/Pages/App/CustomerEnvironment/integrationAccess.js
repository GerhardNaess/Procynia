/**
 * What an integration can read through the API, as one line per readable module — from the same
 * summary GET /api/v1/me returns. A module the customer has not ordered, or the integration's roles
 * do not open, is left out: the list says what is readable, nothing else.
 */
const MODULE_LABELS = { risk: 'Risiko', deviations: 'Avvik' };

export function integrationAccessLines(access = {}, t = {}) {
    return Object.entries(access ?? {})
        .filter(([, module]) => module?.entitled && module?.readable)
        .map(([key, module]) => {
            const label = t.modules?.[key] ?? MODULE_LABELS[key] ?? key;
            const areas = module.business_areas === 'all'
                ? (t.all_areas ?? 'alle fagområder')
                : (module.business_areas ?? []).map((area) => area.name).join(', ');

            return `${label}: ${areas}`;
        });
}
