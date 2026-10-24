/**
 * What an integration can read through the API, as one line per readable module — from the same
 * summary GET /api/v1/me returns. A module the customer has not ordered, or the integration's roles
 * do not open, is left out: the list says what is readable, nothing else.
 */
const MODULE_LABELS = {
    risk: 'Risiko',
    deviations: 'Avvik og forbedringer',
    objectives: 'Mål og KPI',
    management_review: 'Ledelsens gjennomgang',
    compliance: 'Etterlevelse og revisjon',
    suppliers: 'Leverandøroppfølging',
    quality: 'Kvalitet',
    wiki: 'Enterprise Wiki (publisert innhold)',
    tender: 'Anbud (alle anbudssaker og krav)',
};

export function integrationAccessLines(access = {}, t = {}) {
    return Object.entries(access ?? {})
        .filter(([, module]) => module?.entitled && module?.readable)
        .map(([key, module]) => {
            const label = t.modules?.[key] ?? MODULE_LABELS[key] ?? key;
            // null: the module has no fagområder and is read across the whole customer environment.
            const areas = module.business_areas === null
                ? (t.customer_wide ?? 'hele kundemiljøet')
                : module.business_areas === 'all'
                    ? (t.all_areas ?? 'alle fagområder')
                    : (module.business_areas ?? []).map((area) => area.name).join(', ');

            return `${label}: ${areas}`;
        });
}
