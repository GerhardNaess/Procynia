import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { GROUP_TONES, SOURCE_COLUMNS, STAT_TILES, filterOptions, groupLabel, progressPercent, progressText, statLabel, worklistQuery } from './complianceAssessments.js';
import { originLine, originPrefix, requirementBackTarget, worklistUrl, rememberWorklist } from '../../../../Support/complianceNavigation.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

describe('Etterlevelsesvurdering: the figures', () => {
    test('the six figures the page shows, each a shortcut to its own list — Vurdert has none', () => {
        assert.deepEqual(STAT_TILES.map((tile) => tile.key), ['assessable', 'assessed', 'compliant', 'partially_compliant', 'non_compliant', 'not_assessed']);
        assert.equal(STAT_TILES.find((tile) => tile.key === 'assessed').filter, null);
        assert.equal(STAT_TILES.find((tile) => tile.key === 'assessable').filter, '');
        assert.deepEqual(SOURCE_COLUMNS, STAT_TILES.map((tile) => tile.key));
    });

    test('progress is measured against what can be assessed — never against what is excluded', () => {
        const stats = { assessable: 32, assessed: 8, not_applicable: 1, relevance_open: 90 };

        assert.equal(progressText(stats), '8 av 32 vurdert');
        assert.equal(progressText(stats, ':assessed of :total assessed'), '8 of 32 assessed');
        assert.equal(progressPercent(stats), 25);
        assert.equal(progressPercent({ assessable: 0, assessed: 0 }), 0);
    });

    test('labels fall back to Norwegian and read from translations', () => {
        assert.equal(statLabel('assessable'), 'Vurderbare krav');
        assert.equal(statLabel('relevance_open'), 'Relevans eller kontrollvalg ikke avklart');
        assert.equal(statLabel('compliant', { stats: { compliant: 'Compliant' } }), 'Compliant');
    });
});

describe('Etterlevelsesvurdering: the list', () => {
    test('«Vis» starts with every vurderbar requirement, then the server\'s filters in its order', () => {
        const options = filterOptions(['not_assessed', 'gaps', 'relevance_open']);

        assert.deepEqual(options.map((option) => option.value), ['', 'not_assessed', 'gaps', 'relevance_open']);
        assert.deepEqual(options.map((option) => option.label), ['Alle vurderbare krav', 'Ikke vurdert', 'Mangler (delvis eller ikke oppfylt)', 'Relevans eller kontrollvalg ikke avklart']);
        assert.equal(filterOptions([], { filters: { all: 'All assessable requirements' } })[0].label, 'All assessable requirements');
    });

    test('an Annex A control speaks of Kontrollvalg, never of relevance; its results read like any requirement\'s', () => {
        assert.equal(groupLabel('not_applicable'), 'Ikke relevant');
        assert.equal(groupLabel('not_applicable', true), 'Utelatt');
        assert.equal(groupLabel('relevance_open'), 'Relevans ikke avklart');
        assert.equal(groupLabel('relevance_open', true), 'Kontroll ikke valgt');
        assert.equal(groupLabel('partially_compliant', true), 'Delvis oppfylt');
        // An excluded control is never shown in a gap's colour.
        assert.equal(GROUP_TONES.not_applicable, 'slate');
        assert.notEqual(GROUP_TONES.relevance_open, GROUP_TONES.non_compliant);
    });

    test('the filter query leaves out what is empty, so the URL stays readable', () => {
        assert.deepEqual(worklistQuery({ source: '', status: '', search: '  ' }), {});
        assert.deepEqual(worklistQuery({ source: '7', status: 'gaps', search: ' 6.1.2 ' }), { source: '7', status: 'gaps', search: '6.1.2' });
    });

    test('the page opens a requirement from its row, with the assessment form ready when the server offers it', () => {
        const page = source('./Index.jsx');

        assert.match(page, /<RequirementOrigin origin=\{item\.origin\} href=\{item\.url\}/);
        assert.match(page, /\{item\.assess_url && \(/);
        assert.match(page, /\{item\.relevance_url && \(/);
        assert.match(page, /rememberWorklist\(page\.url\)/);
        assert.doesNotMatch(page, /\btext-(xs|sm)\b/);
    });
});

describe('the origin of a requirement, everywhere', () => {
    test('«ISO 14001:2026 · 6.1.2 · Miljøaspekter», with a missing part left out', () => {
        assert.equal(originLine({ source: 'ISO 14001:2026', reference: '6.1.2', title: 'Miljøaspekter' }), 'ISO 14001:2026 · 6.1.2 · Miljøaspekter');
        assert.equal(originLine({ source: 'GDPR', reference: null, title: 'Behandlingsprotokoll' }), 'GDPR · Behandlingsprotokoll');
        assert.equal(originPrefix({ source: 'ISO/IEC 27001:2022', reference: 'A.8.13', title: 'Sikkerhetskopiering' }), 'ISO/IEC 27001:2022 · A.8.13');
        assert.equal(originLine(null), '');
    });

    test('«Tilbake» leads to the area the requirement was opened from', () => {
        assert.deepEqual(requirementBackTarget('assessments'), { area: 'compliance-assessments', href: null });
        assert.deepEqual(requirementBackTarget(null), { area: 'compliance-requirements', href: '/app/compliance/requirements' });
    });

    test('the remembered worklist is only ever a worklist URL, and a missing storage falls back to the plain list', () => {
        assert.equal(worklistUrl(), '/app/compliance/assessments', 'no window, no storage');

        const store = new Map();
        globalThis.window = { sessionStorage: { setItem: (key, value) => store.set(key, value), getItem: (key) => store.get(key) ?? null } };
        try {
            rememberWorklist('/app/compliance/assessments?status=gaps&source=3');
            assert.equal(worklistUrl(), '/app/compliance/assessments?status=gaps&source=3');
            rememberWorklist('https://example.com/elsewhere');
            assert.equal(worklistUrl(), '/app/compliance/assessments');
        } finally {
            delete globalThis.window;
        }
    });
});
