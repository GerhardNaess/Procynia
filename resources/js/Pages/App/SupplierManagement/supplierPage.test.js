import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    OVERVIEW_DETAILS,
    criticalitySummary,
    detailForAnchor,
    linkedCountSummary,
    nextControl,
    profileSummary,
    securityPrivacyFacts,
    securityPrivacySummary,
    supplierPageTabs,
    tabForAnchor,
    tabFromSearch,
    tabSearch,
} from './supplierPage.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const row = (id, theme, level, display_status, extra = {}) => ({ id, title: `Krav ${id}`, theme, level, display_status, anchor: null, ...extra });

const profile = (answers = {}, basis = { processes_personal_data: true, has_system_access: true }, visible = ['data_role', 'special_category_data', 'privileged_access', 'data_location', 'uses_subcontractors']) => ({
    answers: { data_role: 'processor', special_category_data: 'no', privileged_access: 'unknown', data_location: 'outside_eea', uses_subcontractors: 'yes', ...answers },
    basis,
    visible,
});

describe('The supplier page tabs (supplier-assurance-v2-plan §22.1)', () => {
    test('Krav og kvalifikasjoner and Aktsomhet are there only when the server sent something for them', () => {
        assert.deepEqual(supplierPageTabs(), ['overview', 'documents', 'assessments', 'history']);
        assert.deepEqual(supplierPageTabs({ controlRequirements: { applicable: [] }, dueDiligence: {} }), ['overview', 'requirements', 'documents', 'assessments', 'due_diligence', 'history']);
        // Earlier links to Etterlevelse og revisjon are under Historikk and never give the tab.
        assert.ok(! supplierPageTabs({ requirements: [] }).includes('requirements'));
    });

    test('?tab= opens a tab the page has, and Oversikt otherwise; Oversikt has no ?tab=', () => {
        const tabs = supplierPageTabs({ controlRequirements: {} });
        assert.equal(tabFromSearch('?tab=requirements', tabs), 'requirements');
        assert.equal(tabFromSearch('?tab=due_diligence', tabs), 'overview');
        assert.equal(tabFromSearch('?tab=nonsense', tabs), 'overview');
        assert.equal(tabFromSearch('', tabs), 'overview');
        assert.equal(tabSearch('?tab=documents&x=1', 'overview'), '?x=1');
        assert.equal(tabSearch('', 'history'), '?tab=history');
    });

    test('in-page links from Trenger oppmerksomhet and Neste kontroller lead to the tab their section is on', () => {
        assert.equal(tabForAnchor('#control-requirement-12'), 'requirements');
        assert.equal(tabForAnchor('supplier-control-heading'), 'requirements');
        assert.equal(tabForAnchor('supplier-documents-heading'), 'documents');
        assert.equal(tabForAnchor('supplier-assessment-heading'), 'assessments');
        assert.equal(tabForAnchor('supplier-due-diligence-heading'), 'due_diligence');
        assert.equal(tabForAnchor('supplier-profile-heading'), 'overview');
        assert.equal(tabForAnchor('supplier-risks-heading'), 'overview');
        // Sikkerhet og personvern sits beside the requirements; the history of criticality and profile under Historikk.
        assert.equal(tabForAnchor('supplier-security-privacy-heading'), 'requirements');
        assert.equal(tabForAnchor('supplier-criticality-history-heading'), 'history');
        assert.equal(tabForAnchor('supplier-profile-history-heading'), 'history');
        assert.equal(tabForAnchor('supplier-earlier-compliance-links-heading'), 'history');
        // Kontrollstatus is above the tabs, on every one of them.
        assert.equal(tabForAnchor('supplier-assurance-heading'), null);
        assert.equal(tabForAnchor('supplier-requirement-status-heading'), 'requirements');
        assert.equal(tabForAnchor('supplier-decision-history-heading'), 'history');
    });
});

describe('Sikkerhet og personvern (supplier-assurance-v2-plan §12)', () => {
    test('only security, privacy and continuity requirements, each with its own status, mandatory first; no score', () => {
        const summary = securityPrivacySummary(profile(), [
            row(1, 'privacy', 'mandatory', 'documented'),
            row(2, 'information_security', 'important', 'missing'),
            row(3, 'continuity', 'important', 'renewal_due'),
            row(4, 'privacy', 'mandatory', 'not_evaluated'),
            row(5, 'ethics', 'mandatory', 'missing'),
        ]);

        assert.equal(summary.applicableCount, 4);
        assert.deepEqual(summary.groups.map((group) => group.text), ['1 dokumentert', '1 mangler', '1 ikke vurdert', '1 forfalt']);
        assert.deepEqual(summary.open.map((item) => [item.id, item.displayStatus]), [[4, 'not_evaluated'], [2, 'missing'], [3, 'renewal_due']]);
        const text = JSON.stringify(summary);
        assert.doesNotMatch(text, /%|score|poeng|\d+\/\d+/i);
        // The anchor in Etterlevelse og revisjon, if the server sent one, is not part of the card.
        assert.doesNotMatch(JSON.stringify(securityPrivacySummary(profile(), [row(9, 'privacy', 'mandatory', 'missing', { anchor: { title: 'GDPR art. 28' } })])), /GDPR/);
    });

    test('no card when no such requirement applies', () => {
        assert.equal(securityPrivacySummary(profile(), [row(5, 'ethics', 'important', 'missing')]), null);
        assert.equal(securityPrivacySummary(profile(), []), null);
    });

    test('the profile facts in words; a question not asked is left out, an open answer is marked uncertain', () => {
        const facts = securityPrivacyFacts(profile());
        // Without translations the codes stand for the role and the place; the page names them.
        assert.deepEqual(facts.map((fact) => [fact.key, fact.text, fact.uncertain]), [
            ['personal_data', 'processor', false],
            ['special_category_data', 'Nei', false],
            ['system_access', 'Ja', false],
            ['privileged_access', 'Ikke avklart', true],
            ['data_location', 'outside_eea', false],
            ['uses_subcontractors', 'Ja', false],
        ]);
        assert.equal(facts.find((fact) => fact.key === 'privileged_access').text, 'Ikke avklart');

        const none = securityPrivacyFacts({ answers: null, basis: { processes_personal_data: false, has_system_access: false }, visible: ['uses_subcontractors'] });
        assert.deepEqual(none.map((fact) => [fact.key, fact.text]), [['personal_data', 'Nei'], ['system_access', 'Nei'], ['uses_subcontractors', 'Ikke besvart']]);
        assert.equal(none[2].uncertain, true);
    });

    test('the card reads only the page props — no request, no score, no Etterlevelse status', () => {
        const card = source('./SupplierOverview.jsx');
        assert.doesNotMatch(card, /router\.|fetch\(|axios|compliance|anchor\./i);
        assert.doesNotMatch(card, /percent|toFixed|Math\.round|\/ summary\.applicableCount/i);
    });
});

describe('Oversikt (redesign 2026-10-12)', () => {
    test('a link to a section under Detaljer names the section to open; other anchors none', () => {
        assert.equal(detailForAnchor('#supplier-profile-heading'), 'profile');
        assert.equal(detailForAnchor('supplier-criticality-heading'), 'criticality');
        assert.equal(detailForAnchor('supplier-cases-heading'), 'cases');
        assert.equal(detailForAnchor('supplier-control-heading'), null);
        assert.equal(detailForAnchor('supplier-details-heading'), null);
        assert.deepEqual(Object.keys(OVERVIEW_DETAILS), ['info', 'criticality', 'profile', 'risks', 'cases']);
    });

    test('Neste kontroll is the first entry of the server\'s plan, with the count; without a plan the next assessment', () => {
        const entries = [
            { kind: 'acceptance', date: '2026-10-30', overdue: false, requirement: { id: 3, title: 'MFA' }, document: null },
            { kind: 'control', date: '2027-01-01', overdue: false, requirement: { id: 4, title: 'Backup' }, document: null },
        ];
        assert.deepEqual(nextControl({ entries, on_change: [] }, {}), { entry: entries[0], count: 2, entries });
        // A plan with nothing dated says nothing — no assessment date is added beside the server's plan.
        assert.equal(nextControl({ entries: [], on_change: [] }, { next_review_on: '2027-02-01' }), null);
        assert.equal(nextControl(null, { next_review_on: '2027-02-01', status: 'active' }).entry.kind, 'assessment');
        assert.equal(nextControl(null, { next_review_on: '2027-02-01', status: 'ended' }), null);
        assert.equal(nextControl(null, {}), null);
    });

    test('the closed summaries say what a section holds, and never invent a value', () => {
        const date = (iso) => `[${iso}]`;
        assert.equal(criticalitySummary({ current: { criticality: 'critical', review_interval_months: 6 }, decided_at: '2026-10-12' }, {}, date), 'Kritisk · Hver 6. måned · Sist vurdert [2026-10-12]');
        assert.equal(criticalitySummary(null), 'Kritikalitet er ikke vurdert ennå.');
        assert.equal(profileSummary({ answers: null }), 'Ikke fylt ut · Avgjør hvilke kontrollkrav som gjelder');
        assert.equal(profileSummary({ answers: {}, complete: true }), 'Utfylt · Avgjør hvilke kontrollkrav som gjelder');
        assert.equal(profileSummary({ answers: {}, complete: false }), 'Delvis utfylt · Avgjør hvilke kontrollkrav som gjelder');
        const texts = { none: 'Ingen', one: '1 risiko', many: ':count risikoer' };
        assert.deepEqual([0, 1, 3].map((count) => linkedCountSummary(count, texts)), ['Ingen', '1 risiko', '3 risikoer']);
    });
});

describe('The page itself', () => {
    test('the handoffs open their section on Oversikt, and an ended supplier says so once', () => {
        const page = source('./Show.jsx');
        assert.match(page, /startFollowUp = \(value\) => \{ setFollowUp\(value\); openDetail\('cases'\); openTab\('overview'\); \}/);
        assert.match(page, /startRisk = \(value\) => \{ setRiskFrom\(value\); openDetail\('risks'\); openTab\('overview'\); \}/);
        assert.match(page, /supplier-ended-notice/);
        assert.match(page, /item\.status !== 'ended' && <p/);
    });

    test('Oversikt is its areas in order, the other tabs are not repeated there, and no decision is offered', () => {
        const page = source('./Show.jsx');
        const overview = page.slice(page.indexOf("{tab === 'overview' && ("), page.indexOf("{tab === 'requirements' && ("));
        const order = ['<OverviewAttention', '<SupplierKeyFacts', '<SupplierNextControl', 'data-testid="supplier-details"', 'data-testid="supplier-other-actions"'].map((marker) => overview.indexOf(marker));
        assert.ok(order.every((index) => index >= 0), 'every area is on Oversikt');
        assert.deepEqual([...order].sort((a, b) => a - b), order, 'in this order');
        // Dokumentasjon, Vurderinger and Aktsomhet have their own tabs; history has Historikk.
        assert.doesNotMatch(overview, /SupplierOverviewSummaries|<SupplierDocuments|<SupplierAssessment|<SupplierDueDiligence|<SecurityPrivacyCard|History/);
        assert.doesNotMatch(page, /SupplierAssuranceDecision|decision_form|Registrer beslutning/);
        // The criticality and profile history are on Historikk.
        const history = page.slice(page.indexOf("{tab === 'history' && ("));
        assert.match(history, /<SupplierCriticalityHistory/);
        assert.match(history, /<SupplierProfileHistory/);
    });

    test('a Detaljer section is a native button with aria-expanded, and a closed one keeps its content mounted', () => {
        const section = source('./SupplierDetailsSection.jsx');
        assert.match(section, /<button\s+type="button"\s+aria-expanded=\{open\}\s+aria-controls=\{panelId\}/);
        assert.match(section, /hidden=\{! open\}/);
        assert.doesNotMatch(section, /\{open && /, 'the content is never unmounted when closed');
    });
});
