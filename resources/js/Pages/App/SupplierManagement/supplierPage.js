/**
 * The supplier page's structure (docs/supplier-assurance-v2-plan.md §22.1): which tabs it has, which
 * tab a link inside the page leads to, and the short summaries on Oversikt and its closed sections.
 * Pure: everything here is arranged from what the server already sent for the page — nothing is
 * computed that the server did not decide, and no summary is a share, a percentage or a score.
 */
import { profileAnswerLabel } from './supplierProfileAnswers.js';
import { summaryGroups } from './assuranceStatus.js';
import { criticalityLabel, intervalLabel } from './supplierManagement.js';

/** The tabs, in order. Oversikt is the default and has no ?tab= of its own. */
export const SUPPLIER_TABS = ['overview', 'requirements', 'documents', 'assessments', 'due_diligence', 'history'];

const TAB_FALLBACKS = {
    overview: 'Oversikt',
    requirements: 'Krav og kvalifikasjoner',
    documents: 'Dokumentasjon',
    assessments: 'Vurderinger',
    due_diligence: 'Aktsomhet',
    history: 'Historikk',
};

/** The themes «Sikkerhet og personvern» shows (plan §12). */
export const SECURITY_PRIVACY_THEMES = ['information_security', 'privacy', 'continuity'];

export function tabLabel(tab, tr = {}) {
    return tr.page?.tabs?.[tab] ?? TAB_FALLBACKS[tab] ?? tab;
}

/**
 * The tabs this supplier page has. Krav og kvalifikasjoner only when there is something to show
 * there — control requirements, or the right to start with one; the earlier links to requirements in
 * Etterlevelse og revisjon are under Historikk, which is always there. Aktsomhet only when the server
 * sent it. The rest always.
 */
export function supplierPageTabs({ controlRequirements = null, dueDiligence = null } = {}) {
    return SUPPLIER_TABS.filter((tab) => {
        if (tab === 'requirements') {
            return controlRequirements !== null;
        }

        if (tab === 'due_diligence') {
            return dueDiligence !== null;
        }

        return true;
    });
}

/** The tab named by ?tab=, or Oversikt when it names none or one this page does not have. */
export function tabFromSearch(search, tabs = SUPPLIER_TABS) {
    const value = new URLSearchParams(search ?? '').get('tab');

    return value && tabs.includes(value) ? value : 'overview';
}

/** The page's own address for a tab: Oversikt without ?tab=, the rest with it. */
export function tabSearch(search, tab) {
    const params = new URLSearchParams(search ?? '');

    if (tab === 'overview') {
        params.delete('tab');
    } else {
        params.set('tab', tab);
    }

    const query = params.toString();

    return query === '' ? '' : `?${query}`;
}

/**
 * Which tab an in-page link (#anchor) leads to — Trenger oppmerksomhet, Neste kontroller and the
 * summaries link to sections that live on another tab. Null for an anchor that is on every tab
 * (Kontrollstatus) or not known.
 */
export function tabForAnchor(anchor) {
    const id = String(anchor ?? '').replace(/^#/, '');

    if (id === 'supplier-control-heading' || id === 'supplier-requirement-status-heading' || /^control-requirement-\d+$/.test(id)) {
        return 'requirements';
    }

    return {
        'supplier-documents-heading': 'documents',
        'supplier-assessment-heading': 'assessments',
        'supplier-due-diligence-heading': 'due_diligence',
        'supplier-history-heading': 'history',
        'supplier-decision-history-heading': 'history',
        'supplier-criticality-history-heading': 'history',
        'supplier-profile-history-heading': 'history',
        'supplier-earlier-compliance-links-heading': 'history',
        'supplier-security-privacy-heading': 'requirements',
        'supplier-follow-up-heading': 'overview',
        'supplier-attention-heading': 'overview',
        'supplier-details-heading': 'overview',
        ...Object.fromEntries(Object.values(OVERVIEW_DETAILS).map((anchor) => [anchor, 'overview'])),
    }[id] ?? null;
}

/**
 * The sections under «Detaljer» on Oversikt, closed until opened, and the anchor of each. A link to
 * one of them — «Gå til leverandørprofil» under Trenger oppfølging — opens it before it scrolls there.
 */
export const OVERVIEW_DETAILS = {
    info: 'supplier-info-heading',
    criticality: 'supplier-criticality-heading',
    profile: 'supplier-profile-heading',
    risks: 'supplier-risks-heading',
    cases: 'supplier-cases-heading',
};

/** Which section under «Detaljer» an in-page link (#anchor) leads to, or null. */
export function detailForAnchor(anchor) {
    const id = String(anchor ?? '').replace(/^#/, '');

    return Object.keys(OVERVIEW_DETAILS).find((key) => OVERVIEW_DETAILS[key] === id) ?? null;
}

/**
 * «Neste kontroll» among the key facts on Oversikt: the first entry of the plan the server built
 * (SupplierFollowUpPlan — overdue first, then nearest), and how many there are in all. Without a
 * plan (Leverandørkontroll not started, or an ended supplier) the next supplier assessment the
 * server dated, if any. Null when nothing is dated — nothing is computed here.
 *
 * @returns {{ entry: object, count: number, entries: object[] }|null}
 */
export function nextControl(plan = null, supplier = {}) {
    const entries = Array.isArray(plan?.entries) ? plan.entries : [];

    if (entries.length > 0) {
        return { entry: entries[0], count: entries.length, entries };
    }

    if (! plan && supplier?.next_review_on && supplier.status !== 'ended') {
        const entry = { kind: 'assessment', date: supplier.next_review_on, overdue: false, requirement: null, document: null };

        return { entry, count: 1, entries: [entry] };
    }

    return null;
}

/**
 * The closed summary of Kritikalitet: «Kritisk · Hver 6. måned · Sist vurdert 12. oktober 2026», or
 * that it is not assessed yet.
 */
export function criticalitySummary(criticality, tr = {}, formatDate = (date) => date) {
    const c = tr.criticality ?? {};
    const current = criticality?.current ?? null;

    if (! current) {
        return c.not_classified ?? 'Kritikalitet er ikke vurdert ennå.';
    }

    const parts = [criticalityLabel(current.criticality, tr), intervalLabel(current.review_interval_months, tr)];

    if (criticality.decided_at) {
        parts.push((c.last_decided_on ?? 'Sist vurdert :date').replace(':date', formatDate(criticality.decided_at)));
    }

    return parts.join(' · ');
}

/** The closed summary of Leverandørprofil: whether it is filled in, and what it is for. */
export function profileSummary(profile, tr = {}) {
    const p = tr.profile ?? {};
    const state = profile?.answers == null
        ? (p.summary_not_filled ?? 'Ikke fylt ut')
        : (profile.complete ? (p.summary_complete ?? 'Utfylt') : (p.summary_incomplete ?? 'Delvis utfylt'));

    return `${state} · ${p.summary_purpose ?? 'Avgjør hvilke kontrollkrav som gjelder'}`;
}

/** «2 risikoer» / «Ingen tilknyttede risikoer» — the closed summary of a linked list. */
export function linkedCountSummary(count, texts = {}) {
    if (! count) {
        return texts.none;
    }

    return count === 1 ? texts.one : texts.many.replace(':count', String(count));
}

/**
 * «Sikkerhet og personvern» (plan §12, §22.1): a filtered view of Krav og kvalifikasjoner — the
 * security, privacy and continuity requirements that apply, with their visningsstatus as the server
 * computed it — and the profile facts that make them apply. No security score, no privacy score: the
 * counts are per status, like Kontrollstatus, and every requirement keeps its own status. Null when
 * no such requirement applies, so the card is not shown.
 *
 * @param {object|null} profile     the page's `profile` prop
 * @param {object[]} applicable     control_requirements.applicable
 * @param {object} tr               translations.supplier_management
 */
export function securityPrivacySummary(profile, applicable = [], tr = {}) {
    const rows = (Array.isArray(applicable) ? applicable : []).filter((row) => SECURITY_PRIVACY_THEMES.includes(row.theme));

    if (rows.length === 0) {
        return null;
    }

    const counts = {};

    for (const row of rows) {
        counts[row.display_status] = (counts[row.display_status] ?? 0) + 1;
    }

    return {
        facts: securityPrivacyFacts(profile, tr),
        applicableCount: rows.length,
        groups: summaryGroups(counts, tr),
        // Everything short of Dokumentert, mandatory first — what still needs a control or a document.
        open: rows
            .filter((row) => row.display_status !== 'documented')
            .sort((a, b) => Number(b.level === 'mandatory') - Number(a.level === 'mandatory'))
            .map((row) => ({ id: row.id, title: row.title, level: row.level, displayStatus: row.display_status })),
    };
}

/**
 * The profile facts behind the security and privacy requirements, in words: personal data and the
 * role, special categories, system and privileged access, where the data is, subcontractors. A
 * question the profile does not ask for this supplier is left out; one not answered says so. An
 * answer that is not settled («Ikke avklart», not answered) is marked uncertain — it makes the
 * requirements apply.
 */
export function securityPrivacyFacts(profile, tr = {}) {
    const s = tr.page?.security_privacy ?? {};
    const answers = profile?.answers ?? {};
    const basis = profile?.basis ?? null;
    const visible = Array.isArray(profile?.visible) ? profile.visible : [];
    const uncertain = (value) => value === null || value === undefined || value === 'unknown';
    const facts = [];
    const yesNo = (value) => (value ? (s.yes ?? 'Ja') : (s.no ?? 'Nei'));

    facts.push({
        key: 'personal_data',
        label: s.personal_data ?? 'Personopplysninger',
        text: basis?.processes_personal_data
            ? (answers.data_role ? profileAnswerLabel('data_role', answers.data_role, tr) : (s.personal_data_role_unanswered ?? 'Ja – rollen er ikke besvart'))
            : (basis ? yesNo(false) : (s.not_classified ?? 'Kritikalitet ikke vurdert')),
        uncertain: basis === null || (basis.processes_personal_data === true && uncertain(answers.data_role)),
    });

    if (visible.includes('special_category_data')) {
        facts.push(answerFact('special_category_data', s.special_category_data ?? 'Særlige kategorier personopplysninger', answers, tr, uncertain));
    }

    facts.push({
        key: 'system_access',
        label: s.system_access ?? 'Tilgang til våre systemer',
        text: basis ? yesNo(basis.has_system_access === true) : (s.not_classified ?? 'Kritikalitet ikke vurdert'),
        uncertain: basis === null,
    });

    if (visible.includes('privileged_access')) {
        facts.push(answerFact('privileged_access', s.privileged_access ?? 'Privilegert tilgang', answers, tr, uncertain));
    }

    if (visible.includes('data_location')) {
        facts.push(answerFact('data_location', s.data_location ?? 'Hvor dataene behandles', answers, tr, uncertain));
    }

    facts.push(answerFact('uses_subcontractors', s.uses_subcontractors ?? 'Underleverandører', answers, tr, uncertain));

    return facts;
}

function answerFact(field, label, answers, tr, uncertain) {
    const value = answers?.[field] ?? null;

    return { key: field, label, text: profileAnswerLabel(field, value, tr), uncertain: uncertain(value) };
}
