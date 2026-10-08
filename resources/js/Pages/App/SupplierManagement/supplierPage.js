/**
 * The supplier page's structure (docs/supplier-assurance-v2-plan.md §22.1): which tabs it has, which
 * tab a link inside the page leads to, and the short summaries on Oversikt. Pure: everything here is
 * arranged from what the server already sent for the page — nothing is computed that the server did
 * not decide, and no summary is a share, a percentage or a score.
 */
import { profileAnswerLabel } from './supplierProfileAnswers.js';
import { summaryGroups } from './assuranceStatus.js';

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

/** How many days ahead documentation «utløper snart» — the same 60 days as Trenger oppmerksomhet. */
export const EXPIRING_SOON_DAYS = 60;

export function tabLabel(tab, tr = {}) {
    return tr.page?.tabs?.[tab] ?? TAB_FALLBACKS[tab] ?? tab;
}

/**
 * The tabs this supplier page has. Krav og kvalifikasjoner only when there is something to show
 * there — control requirements (or the right to start with one) or requirements from Etterlevelse og
 * revisjon; Aktsomhet only when the server sent it. The rest always.
 */
export function supplierPageTabs({ controlRequirements = null, requirements = null, dueDiligence = null } = {}) {
    return SUPPLIER_TABS.filter((tab) => {
        if (tab === 'requirements') {
            return controlRequirements !== null || requirements !== null;
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

    if (id === 'supplier-control-heading' || id === 'supplier-requirements-heading' || /^control-requirement-\d+$/.test(id)) {
        return 'requirements';
    }

    return {
        'supplier-documents-heading': 'documents',
        'supplier-assessment-heading': 'assessments',
        'supplier-due-diligence-heading': 'due_diligence',
        'supplier-history-heading': 'history',
        'supplier-details-heading': 'overview',
        'supplier-criticality-heading': 'overview',
        'supplier-profile-heading': 'overview',
        'supplier-security-privacy-heading': 'overview',
        'supplier-cases-heading': 'overview',
        'supplier-risks-heading': 'overview',
    }[id] ?? null;
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

/**
 * Dokumentasjon on Oversikt: how many rows are current and how they stand today — gyldig, utløper
 * snart, utløpt. Replaced rows are history, not counted. Counts only; which rows are named under
 * Trenger oppmerksomhet and on the Dokumentasjon tab.
 *
 * @param {object[]} documents  the page's `documents`
 * @param {string} today        YYYY-MM-DD
 */
export function documentationSummary(documents = [], today = '') {
    const current = (Array.isArray(documents) ? documents : []).filter((row) => row.status !== 'replaced');
    const soon = (row) => {
        if (row.status !== 'valid' || ! row.valid_until || ! today) {
            return false;
        }

        const days = Math.round((Date.parse(`${row.valid_until}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86400000);

        return days <= EXPIRING_SOON_DAYS;
    };

    return {
        total: current.length,
        valid: current.filter((row) => (row.status === 'valid' && ! soon(row)) || row.status === 'no_expiry').length,
        expiringSoon: current.filter(soon).length,
        expired: current.filter((row) => row.status === 'expired').length,
    };
}

/** «3 gyldige · 1 utløper snart · 1 utløpt», groups with 0 left out; «Ingen dokumentasjon registrert» when none. */
export function documentationSummaryText(summary, tr = {}) {
    const s = tr.page?.documents_summary ?? {};

    if (! summary || summary.total === 0) {
        return s.none ?? 'Ingen dokumentasjon registrert';
    }

    return [
        [summary.valid, s.valid ?? ':count gyldige'],
        [summary.expiringSoon, s.expiring_soon ?? ':count utløper snart'],
        [summary.expired, s.expired ?? ':count utløpt'],
    ]
        .filter(([count]) => count > 0)
        .map(([count, text]) => text.replace(':count', String(count)))
        .join(' · ');
}
