/**
 * Aktsomhet og bærekraft (docs/supplier-assurance-v2-plan.md §11): how the page words an
 * aktsomhetsvurdering. Nothing here decides anything — no total, no computed conclusion, no level
 * derived from another. The server has the rules; this only names what it sent.
 */

/** The six areas, in the plan's order. */
export const DUE_DILIGENCE_AREAS = [
    'child_labour_risk',
    'forced_labour_risk',
    'working_conditions_risk',
    'discrimination_risk',
    'freedom_of_association_risk',
    'environment_risk',
];

/** Kartlegg → Vurder risiko → Undersøk → Tiltak → Følg opp → Dokumenter. */
export const DUE_DILIGENCE_STEPS = ['map', 'assess', 'investigate', 'measures', 'follow_up', 'document'];

/** Themes whose control requirements belong to «Undersøk» (§11.2) and the environment (§11.4). */
export const DUE_DILIGENCE_THEMES = ['human_rights', 'labour_conditions', 'environment'];

/** Ukjent is slate, not green: unknown is never low. */
export const DUE_DILIGENCE_LEVEL_TONES = {
    low: 'emerald',
    elevated: 'amber',
    high: 'rose',
    unknown: 'slate',
};

export const DUE_DILIGENCE_CONCLUSION_TONES = {
    no_significant_risk: 'emerald',
    monitor: 'amber',
    measures_required: 'rose',
};

const AREA_FALLBACKS = {
    child_labour_risk: 'Barnearbeid',
    forced_labour_risk: 'Tvangsarbeid',
    working_conditions_risk: 'Arbeidsforhold (lønn, arbeidstid, HMS)',
    discrimination_risk: 'Diskriminering',
    freedom_of_association_risk: 'Organisasjonsfrihet',
    environment_risk: 'Miljø',
};

const LEVEL_FALLBACKS = {
    low: 'Lav',
    elevated: 'Forhøyet',
    high: 'Høy',
    unknown: 'Ukjent',
};

const CONCLUSION_FALLBACKS = {
    no_significant_risk: 'Ingen vesentlig risiko avdekket',
    monitor: 'Risiko følges opp',
    measures_required: 'Tiltak kreves',
};

const ANSWER_FALLBACKS = { yes: 'Ja', no: 'Nei', unknown: 'Ikke avklart' };

export function areaLabel(area, tr = {}) {
    return tr.due_diligence?.areas?.[area] ?? AREA_FALLBACKS[area] ?? area;
}

export function levelLabel(level, tr = {}) {
    return tr.due_diligence?.levels?.[level] ?? LEVEL_FALLBACKS[level] ?? level;
}

export function conclusionLabel(conclusion, tr = {}) {
    return tr.due_diligence?.conclusions?.[conclusion] ?? CONCLUSION_FALLBACKS[conclusion] ?? conclusion;
}

/**
 * The six areas of one assessment as rows: the area's name and its level in words, with a tone.
 * Always all six, in order — a level missing from the data reads as such, never as «Lav».
 */
export function areaRows(areas = {}, tr = {}) {
    return DUE_DILIGENCE_AREAS.map((area) => {
        const level = areas?.[area] ?? null;

        return {
            area,
            label: areaLabel(area, tr),
            level,
            levelText: level ? levelLabel(level, tr) : (tr.due_diligence?.level_missing ?? 'Ikke vurdert'),
            tone: DUE_DILIGENCE_LEVEL_TONES[level] ?? 'slate',
        };
    });
}

/**
 * The interval the form suggests (plan §11.3): 24 months for «Ingen vesentlig risiko avdekket»,
 * otherwise 12. Only a suggestion; the person chooses.
 */
export function suggestedInterval(conclusion) {
    return conclusion === 'no_significant_risk' ? 24 : 12;
}

/** An empty assessment form. Every area starts unanswered: there is no default level. */
export function dueDiligenceFormData(today = '') {
    return {
        ...Object.fromEntries(DUE_DILIGENCE_AREAS.map((area) => [area, ''])),
        supply_chain_description: '',
        investigation_summary: '',
        conclusion: '',
        rationale: '',
        review_interval_months: '',
        assessed_on: today,
    };
}

/**
 * The form after the conclusion changes: the interval follows the suggestion until the person has
 * chosen one themselves.
 */
export function withConclusion(data, conclusion, intervalChosen = false) {
    return {
        ...data,
        conclusion,
        review_interval_months: intervalChosen && data.review_interval_months !== '' ? data.review_interval_months : String(suggestedInterval(conclusion)),
    };
}

/** What is still missing before the form can be sent: the unanswered areas, conclusion, begrunnelse. */
export function dueDiligenceMissing(data) {
    const missing = DUE_DILIGENCE_AREAS.filter((area) => ! data?.[area]);

    if (! data?.conclusion) {
        missing.push('conclusion');
    }

    if (! String(data?.rationale ?? '').trim()) {
        missing.push('rationale');
    }

    if (! data?.review_interval_months) {
        missing.push('review_interval_months');
    }

    if (! data?.assessed_on) {
        missing.push('assessed_on');
    }

    return missing;
}

/**
 * A profile answer as the Kartlegg block shows it: Ja / Nei / Ikke avklart, and «Ikke besvart» when
 * the question has no answer. Never «Nei» for an empty answer.
 */
export function answerText(value, tr = {}) {
    if (value === null || value === undefined) {
        return tr.due_diligence?.not_answered ?? 'Ikke besvart';
    }

    return tr.profile?.answers?.[value] ?? ANSWER_FALLBACKS[value] ?? value;
}

/**
 * Kartlegg: the profile facts the plan maps from (§11.2), in words. High-risk categories are named;
 * none chosen reads «Ingen», not answered reads «Ikke besvart».
 */
export function mappingFacts(mapping = {}, tr = {}) {
    const d = tr.due_diligence ?? {};
    const categories = mapping?.high_risk_categories;
    const categoriesText = Array.isArray(categories)
        ? (categories.length === 0 ? (d.none_chosen ?? 'Ingen') : categories.map((code) => tr.profile?.high_risk_categories?.[code] ?? code).join(', '))
        : answerText(null, tr);

    return [
        { key: 'production_outside_eea', label: d.facts?.production_outside_eea ?? 'Produksjon eller arbeid utenfor Norge/EØS', text: answerText(mapping?.production_outside_eea, tr), uncertain: mapping?.production_outside_eea !== 'yes' && mapping?.production_outside_eea !== 'no' },
        { key: 'high_risk_categories', label: d.facts?.high_risk_categories ?? 'Høyrisikokategorier', text: categoriesText, uncertain: ! Array.isArray(categories) },
        { key: 'uses_subcontractors', label: d.facts?.uses_subcontractors ?? 'Underleverandører i leveransen', text: answerText(mapping?.uses_subcontractors, tr), uncertain: mapping?.uses_subcontractors !== 'yes' && mapping?.uses_subcontractors !== 'no' },
        { key: 'labour_intensive', label: d.facts?.labour_intensive ?? 'Arbeidsintensiv leveranse', text: answerText(mapping?.labour_intensive, tr), uncertain: mapping?.labour_intensive !== 'yes' && mapping?.labour_intensive !== 'no' },
    ];
}

/** The control requirements that apply now on human rights, working conditions and environment. */
export function relatedRequirements(applicable = []) {
    return (applicable ?? []).filter((row) => DUE_DILIGENCE_THEMES.includes(row.theme));
}

/** «Vurdert 8. oktober 2026 av Kari Hansen» — «en tidligere bruker» when the person is deleted. */
export function dueDiligenceByline(entry, tr = {}, formatDate = (date) => date) {
    const d = tr.due_diligence ?? {};

    return (d.byline ?? 'Vurdert :date av :name')
        .replace(':date', formatDate(entry.assessed_on))
        .replace(':name', entry.assessed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/** Neste aktsomhetsvurdering, or that it is overdue. Null without an assessment. */
export function nextText(data, tr = {}, formatDate = (date) => date) {
    const d = tr.due_diligence ?? {};

    if (! data?.next_on) {
        return null;
    }

    return data.overdue
        ? (d.next_overdue ?? 'Neste aktsomhetsvurdering var :date og er forfalt').replace(':date', formatDate(data.next_on))
        : (d.next ?? 'Neste aktsomhetsvurdering :date').replace(':date', formatDate(data.next_on));
}
