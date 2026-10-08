import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    documentationSummary,
    documentationSummaryText,
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
        // Requirements from Etterlevelse og revisjon alone also give the tab.
        assert.ok(supplierPageTabs({ requirements: [] }).includes('requirements'));
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
        // Kontrollstatus is above the tabs, on every one of them.
        assert.equal(tabForAnchor('supplier-assurance-heading'), null);
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

describe('Dokumentasjon on Oversikt', () => {
    test('counts current rows by how they stand today; replaced rows are history', () => {
        const summary = documentationSummary([
            { status: 'valid', valid_until: '2027-06-01' },
            { status: 'valid', valid_until: '2026-11-01' },
            { status: 'no_expiry', valid_until: null },
            { status: 'expired', valid_until: '2026-01-01' },
            { status: 'replaced', valid_until: '2025-01-01' },
        ], '2026-10-08');

        assert.deepEqual(summary, { total: 4, valid: 2, expiringSoon: 1, expired: 1 });
        assert.equal(documentationSummaryText(summary), '2 gyldige · 1 utløper snart · 1 utløpt');
        assert.equal(documentationSummaryText(documentationSummary([], '2026-10-08')), 'Ingen dokumentasjon registrert');
    });
});

describe('The page itself', () => {
    test('Kontrollstatus stays above the tabs, the handoffs open Oversikt, and an ended supplier says so once', () => {
        const page = source('./Show.jsx');
        assert.ok(page.indexOf('<SupplierAssuranceStatus') < page.indexOf('data-testid="supplier-page-tabs"'));
        assert.match(page, /startFollowUp = \(value\) => \{ setFollowUp\(value\); openTab\('overview'\); \}/);
        assert.match(page, /supplier-ended-notice/);
        assert.match(page, /item\.status !== 'ended' && <p/);
    });
});
