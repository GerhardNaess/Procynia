/** The badge tone of each supplier status; the three differ. */
export const SUPPLIER_STATUS_TONES = {
    onboarding: 'sky',
    active: 'emerald',
    ended: 'slate',
};

/** The badge tone of each criticality level; the three differ, and none is a status tone of its own. */
export const CRITICALITY_TONES = {
    standard: 'blue',
    important: 'amber',
    critical: 'rose',
};

/**
 * The review interval a level fills in when it is chosen (plan §4.2). Only a starting point: the
 * person may choose another, and Standard starts without one.
 */
export const DEFAULT_REVIEW_INTERVALS = {
    standard: null,
    important: 24,
    critical: 12,
};

/** The four ja/nei questions, in the order the form asks them. */
export const CRITICALITY_QUESTIONS = ['processes_personal_data', 'has_system_access', 'supports_critical_delivery', 'hard_to_replace'];

const CRITICALITY_FALLBACKS = {
    standard: 'Standard',
    important: 'Viktig',
    critical: 'Kritisk',
};

const STATUS_FALLBACKS = {
    onboarding: 'Under vurdering',
    active: 'Aktiv',
    ended: 'Avsluttet',
};

const CATEGORY_FALLBACKS = {
    it_cloud: 'IT og skytjenester',
    consulting: 'Konsulent og rådgivning',
    goods: 'Varer og materiell',
    construction: 'Bygg og anlegg',
    transport_logistics: 'Transport og logistikk',
    operations_facilities: 'Drift og fasilitet',
    other: 'Annet',
};

/** A status as the page names it: «Under vurdering», «Aktiv», «Avsluttet». */
export function statusLabel(status, tr = {}) {
    return tr.statuses?.[status] ?? STATUS_FALLBACKS[status] ?? status;
}

/** A category as the page names it: «IT og skytjenester» … */
export function categoryLabel(category, tr = {}) {
    return tr.categories?.[category] ?? CATEGORY_FALLBACKS[category] ?? category;
}

/**
 * One status history entry as a sentence: «Tatt i bruk av Kari», «Avsluttet av Ola»,
 * «Gjenåpnet av Kari». Ending and reopening both land on a status that also has another route in
 * (ended, active), so the pair decides.
 *
 * @param {{from_status: string, to_status: string, changed_by_name: string|null}} entry
 * @param {object} tr  translations.supplier_management
 */
export function describeHistoryEntry(entry, tr = {}) {
    const kind = entry.to_status === 'ended'
        ? 'ended'
        : (entry.from_status === 'ended' ? 'reopened' : 'activated');
    const fallbacks = {
        activated: 'Tatt i bruk av :name',
        ended: 'Avsluttet av :name',
        reopened: 'Gjenåpnet av :name',
    };
    const template = tr.history?.[kind] ?? fallbacks[kind];

    return template.replace(':name', entry.changed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/** The registration as the history's first line: «Registrert som Under vurdering av Kari». */
export function describeRegistration(registered, tr = {}) {
    const template = tr.history?.registered ?? 'Registrert som :status av :name';

    return template
        .replace(':status', statusLabel(registered.status, tr))
        .replace(':name', registered.by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/** «12 leverandører» / «1 leverandør». */
export function countLabel(count, tr = {}) {
    return count === 1
        ? (tr.count_one ?? '1 leverandør')
        : (tr.count ?? ':count leverandører').replace(':count', String(count));
}

/** A criticality level as the page names it: «Standard», «Viktig», «Kritisk». */
export function criticalityLabel(level, tr = {}) {
    return tr.criticalities?.[level] ?? CRITICALITY_FALLBACKS[level] ?? level;
}

/** The classification a form starts from: nothing answered, nothing chosen. */
export function emptyCriticality() {
    return {
        criticality: '',
        review_interval_months: '',
        processes_personal_data: null,
        has_system_access: null,
        supports_critical_delivery: null,
        hard_to_replace: null,
    };
}

/** Viktig and Kritisk must have a review interval; Standard may be without. */
export function intervalRequired(level) {
    return level === 'important' || level === 'critical';
}

/**
 * The form data after choosing a level: the level, and the interval that level starts with. The
 * answers are left exactly as they were — they never decide the level, and the level never changes
 * them.
 */
export function chooseCriticality(data, level) {
    const interval = DEFAULT_REVIEW_INTERVALS[level];

    return { ...data, criticality: level, review_interval_months: interval ? String(interval) : '' };
}

/** «Hver 12. måned», or «Ingen fast vurdering» without an interval. */
export function intervalLabel(months, tr = {}) {
    const c = tr.criticality ?? {};

    return months
        ? (c.interval_option ?? 'Hver :months. måned').replace(':months', String(months))
        : (c.no_interval ?? 'Ingen fast vurdering');
}

/** «Ja» / «Nei». */
export function answerLabel(answer, tr = {}) {
    return answer ? (tr.criticality?.yes ?? 'Ja') : (tr.criticality?.no ?? 'Nei');
}

/**
 * One criticality change as a sentence: the first classification («Vurdert som Viktig av Kari»), a
 * new level («Endret fra Standard til Viktig av Kari»), or the same level with another interval or
 * other answers («Fortsatt Viktig – endret av Kari»).
 *
 * @param {{from: object|null, to: object, changed_by_name: string|null}} entry
 * @param {object} tr  translations.supplier_management
 */
export function describeCriticalityChange(entry, tr = {}) {
    const history = tr.criticality?.history ?? {};
    const name = entry.changed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker');
    const to = criticalityLabel(entry.to.criticality, tr);

    if (! entry.from) {
        return (history.first ?? 'Vurdert som :level av :name').replace(':level', to).replace(':name', name);
    }

    if (entry.from.criticality === entry.to.criticality) {
        return (history.kept ?? 'Fortsatt :level – endret av :name').replace(':level', to).replace(':name', name);
    }

    return (history.changed ?? 'Endret fra :from til :to av :name')
        .replace(':from', criticalityLabel(entry.from.criticality, tr))
        .replace(':to', to)
        .replace(':name', name);
}

/** The classification a supplier was registered with: «Vurdert som Kritisk ved registrering av Kari». */
export function describeCriticalityRegistration(registered, tr = {}) {
    return (tr.criticality?.history?.registered ?? 'Vurdert som :level ved registrering av :name')
        .replace(':level', criticalityLabel(registered.classification.criticality, tr))
        .replace(':name', registered.by_name ?? (tr.unknown_user ?? 'en tidligere bruker'));
}

/** The four fixed criteria of a supplier assessment, in the order the form asks them. */
export const ASSESSMENT_CRITERIA = ['quality_rating', 'delivery_rating', 'security_rating', 'compliance_rating'];

/** The badge tone of each overall result; the three differ. */
export const RESULT_TONES = {
    satisfactory: 'emerald',
    partially_satisfactory: 'amber',
    unsatisfactory: 'rose',
};

const CRITERION_FALLBACKS = {
    quality_rating: 'Kvalitet på leveransen',
    delivery_rating: 'Leveringspresisjon og respons',
    security_rating: 'Informasjonssikkerhet og personvern',
    compliance_rating: 'Etterlevelse av avtale og krav',
};

const RATING_FALLBACKS = {
    good: 'Bra',
    acceptable: 'Akseptabelt',
    poor: 'Svakt',
    not_relevant: 'Ikke relevant',
};

const RESULT_FALLBACKS = {
    satisfactory: 'Tilfredsstillende',
    partially_satisfactory: 'Delvis tilfredsstillende',
    unsatisfactory: 'Ikke tilfredsstillende',
};

/** «Kvalitet på leveransen» … */
export function criterionLabel(criterion, tr = {}) {
    return tr.assessment?.criteria?.[criterion] ?? CRITERION_FALLBACKS[criterion] ?? criterion;
}

/** «Bra», «Akseptabelt», «Svakt», «Ikke relevant». */
export function ratingLabel(rating, tr = {}) {
    return tr.assessment?.ratings?.[rating] ?? RATING_FALLBACKS[rating] ?? rating;
}

/** «Tilfredsstillende», «Delvis tilfredsstillende», «Ikke tilfredsstillende». */
export function resultLabel(result, tr = {}) {
    return tr.assessment?.results?.[result] ?? RESULT_FALLBACKS[result] ?? result;
}

/**
 * Neste vurdering as the page says it: a date when there is one; otherwise why there is none —
 * «Ikke vurdert» before the first assessment, «Ingen fast vurdering» without an interval.
 *
 * @param {{next_review_on: string|null, last_assessed_on: string|null}} supplier
 * @param {(date: string) => string} formatDate
 */
export function nextReviewText(supplier, tr = {}, formatDate = (date) => date) {
    if (supplier.next_review_on) {
        return formatDate(supplier.next_review_on);
    }

    return supplier.last_assessed_on
        ? (tr.assessment?.next_review_none_interval ?? 'Ingen fast vurdering')
        : (tr.assessment?.not_assessed ?? 'Ikke vurdert');
}

/** The form an assessment starts from: nothing rated, nothing chosen, dated today. */
export function emptyAssessment(today) {
    return {
        quality_rating: '',
        delivery_rating: '',
        security_rating: '',
        compliance_rating: '',
        overall_result: '',
        rationale: '',
        assessed_on: today,
    };
}

/** The badge tone of each documentation status; the four differ, and only Utløpt warns. */
export const DOCUMENT_STATUS_TONES = {
    valid: 'emerald',
    expired: 'rose',
    no_expiry: 'blue',
    replaced: 'slate',
};

const DOCUMENT_TYPE_FALLBACKS = {
    agreement: 'Avtale',
    data_processing_agreement: 'Databehandleravtale',
    confidentiality_agreement: 'Taushetserklæring',
    certificate: 'Sertifikat',
    insurance_certificate: 'Forsikringsbevis',
    security_documentation: 'Sikkerhetsdokumentasjon',
    other: 'Annet',
};

const DOCUMENT_STATUS_FALLBACKS = {
    valid: 'Gyldig',
    expired: 'Utløpt',
    no_expiry: 'Ingen utløpsdato',
    replaced: 'Erstattet',
};

/** «Avtale», «Databehandleravtale» … */
export function documentTypeLabel(type, tr = {}) {
    return tr.documents?.types?.[type] ?? DOCUMENT_TYPE_FALLBACKS[type] ?? type;
}

/** «Gyldig», «Utløpt», «Ingen utløpsdato», «Erstattet» — the server decides which. */
export function documentStatusLabel(status, tr = {}) {
    return tr.documents?.statuses?.[status] ?? DOCUMENT_STATUS_FALLBACKS[status] ?? status;
}

/**
 * Where a document is kept, as a link only when it is a plain web address; anything else — an
 * archive reference, a case number, another scheme — stays text. The page only links; nothing
 * fetches or previews the address.
 *
 * @param {string|null} location
 * @returns {string|null}
 */
export function locationHref(location) {
    const value = (location ?? '').trim();

    return /^https?:\/\/[^\s]+$/i.test(value) ? value : null;
}

/**
 * The form a documentation row starts from: empty for Legg til; the row as it is for Rediger; and
 * for Registrer fornyet the same type and name, with a new location, validity and comment to fill in.
 *
 * @param {'create'|'edit'|'renew'} mode
 * @param {object|null} document
 */
export function documentFormData(mode, document = null) {
    if (mode === 'edit' && document) {
        return {
            document_type: document.document_type,
            title: document.title ?? '',
            location: document.location ?? '',
            valid_from: document.valid_from ?? '',
            valid_until: document.valid_until ?? '',
            comment: document.comment ?? '',
        };
    }

    return {
        document_type: mode === 'renew' && document ? document.document_type : '',
        title: mode === 'renew' && document ? document.title : '',
        location: '',
        valid_from: '',
        valid_until: '',
        comment: '',
    };
}
