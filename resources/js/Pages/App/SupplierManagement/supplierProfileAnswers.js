/**
 * Leverandørprofil: the pure rules behind the profile card and form (docs/supplier-assurance-v2-plan.md
 * §4). The server holds the same rules and is the one that counts; these keep the page in step while
 * the person answers.
 */

/** The yes/no/unknown questions. Every other question is a single choice or a list. */
export const PROFILE_ANSWER_FIELDS = [
    'special_category_data',
    'stores_our_data',
    'confidential_information',
    'privileged_access',
    'uses_subcontractors',
    'production_outside_eea',
    'on_site_work',
    'labour_intensive',
    'public_contract_terms',
    'significant_environmental_impact',
];

export const PROFILE_LIST_FIELDS = ['high_risk_categories', 'sectors'];

const ANSWER_FALLBACKS = { yes: 'Ja', no: 'Nei', unknown: 'Ikke avklart' };

/** Which code list names a single-choice or list field's values in translations.supplier_management.profile. */
const VALUE_LISTS = {
    data_role: 'data_roles',
    data_location: 'data_locations',
    sectors: 'sectors',
    high_risk_categories: 'high_risk_categories',
};

export function isListField(field) {
    return PROFILE_LIST_FIELDS.includes(field);
}

/**
 * The questions asked for this supplier (plan §4.2 «Vises når»): the personal-data questions only when
 * the supplier processes personal data for us, privileged access only with system access, and where
 * the data is only unless the supplier is known not to store our data.
 *
 * @param {string[]} fields   every profile question, in order
 * @param {object|null} basis the four criticality answers, null when not classified
 * @param {object} answers
 */
export function visibleProfileFields(fields, basis, answers = {}) {
    const hidden = new Set();

    if (basis?.processes_personal_data !== true) {
        hidden.add('data_role');
        hidden.add('special_category_data');
    }

    if (basis?.has_system_access !== true) {
        hidden.add('privileged_access');
    }

    if (answers?.stores_our_data === 'no') {
        hidden.add('data_location');
    }

    return fields.filter((field) => ! hidden.has(field));
}

/**
 * One answer as the page names it. Not answered (null) is «Ikke besvart», which is never the same as
 * «Ikke avklart» (the answer unknown) or «Nei». An empty list is the answer «Ingen av disse».
 *
 * @param {string} field
 * @param {string|string[]|null} value
 * @param {object} tr  translations.supplier_management
 */
export function profileAnswerLabel(field, value, tr = {}) {
    const p = tr.profile ?? {};

    if (value === null || value === undefined) {
        return p.not_answered ?? 'Ikke besvart';
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return p.none_selected ?? 'Ingen av disse';
        }

        return value.map((code) => p[VALUE_LISTS[field]]?.[code] ?? code).join(', ');
    }

    if (VALUE_LISTS[field]) {
        return p[VALUE_LISTS[field]]?.[value] ?? ANSWER_FALLBACKS[value] ?? value;
    }

    return p.answers?.[value] ?? ANSWER_FALLBACKS[value] ?? value;
}

/** The form's starting values: the current answers, or nothing answered. */
export function profileFormData(fields, answers = null) {
    const data = {};

    for (const field of fields) {
        const value = answers?.[field] ?? null;
        data[field] = Array.isArray(value) ? [...value] : value;
    }

    data.reason = '';

    return data;
}

/**
 * Ticking or unticking one code in a list. «Ingen av disse» (code null) is the answer [] and clears
 * the codes; ticking a code clears «Ingen av disse». Unticking the last code leaves the question
 * unanswered, not answered «none».
 *
 * @param {string[]|null} current
 * @param {string|null} code   null for «Ingen av disse»
 * @param {string[]} order     the list's codes, in their fixed order
 */
export function toggleListAnswer(current, code, order = []) {
    if (code === null) {
        return Array.isArray(current) && current.length === 0 ? null : [];
    }

    const chosen = new Set(Array.isArray(current) ? current : []);

    if (chosen.has(code)) {
        chosen.delete(code);
    } else {
        chosen.add(code);
    }

    return chosen.size === 0 ? null : order.filter((value) => chosen.has(value));
}

function sameAnswer(a, b) {
    if (Array.isArray(a) || Array.isArray(b)) {
        return Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((value, index) => value === b[index]);
    }

    return (a ?? null) === (b ?? null);
}

/**
 * One history entry as lines a reader understands: «Bruker underleverandører: Nei → Ja» for every
 * question that changed. The first save, where nothing came before, lists every answer given:
 * «Bruker underleverandører: Nei».
 *
 * @param {{from: object|null, to: object}} entry
 * @param {string[]} fields  every profile question, in order
 * @param {object} tr        translations.supplier_management
 * @returns {string[]}
 */
export function profileChangeLines(entry, fields, tr = {}) {
    const p = tr.profile ?? {};
    const label = (field) => p.labels?.[field] ?? field;
    const from = entry?.from ?? null;
    const to = entry?.to ?? {};

    if (from === null) {
        return fields
            .filter((field) => to[field] !== null && to[field] !== undefined)
            .map((field) => `${label(field)}: ${profileAnswerLabel(field, to[field], tr)}`);
    }

    const template = p.history?.change_line ?? ':question: :from → :to';

    return fields
        .filter((field) => ! sameAnswer(from[field], to[field]))
        .map((field) => template
            .replace(':question', label(field))
            .replace(':from', profileAnswerLabel(field, from[field] ?? null, tr))
            .replace(':to', profileAnswerLabel(field, to[field] ?? null, tr)));
}
