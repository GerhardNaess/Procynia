import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    DUE_DILIGENCE_AREAS,
    areaRows,
    dueDiligenceFormData,
    dueDiligenceMissing,
    mappingFacts,
    nextText,
    relatedRequirements,
    withConclusion,
} from './dueDiligence.js';
import { attentionFindingText } from './supplierManagement.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

describe('Aktsomhet og bærekraft (supplier-assurance-v2-plan §11, §15.1)', () => {
    test('the six areas are always all named, each with its level in words, and Ukjent is never green', () => {
        const rows = areaRows({ child_labour_risk: 'high', environment_risk: 'unknown', forced_labour_risk: 'low' });

        assert.deepEqual(rows.map((row) => row.area), DUE_DILIGENCE_AREAS);
        assert.equal(rows.length, 6);
        assert.deepEqual(rows.slice(0, 2).map((row) => [row.label, row.levelText, row.tone]), [['Barnearbeid', 'Høy', 'rose'], ['Tvangsarbeid', 'Lav', 'emerald']]);
        assert.deepEqual([rows[5].levelText, rows[5].tone], ['Ukjent', 'slate']);
        // A level missing from the data is not read as low.
        assert.equal(rows[3].levelText, 'Ikke vurdert');
    });

    test('the form starts with no level chosen, needs all six, and the interval follows the conclusion until chosen', () => {
        const data = dueDiligenceFormData('2026-10-08');

        assert.ok(DUE_DILIGENCE_AREAS.every((area) => data[area] === ''));
        assert.deepEqual(dueDiligenceMissing(data), [...DUE_DILIGENCE_AREAS, 'conclusion', 'rationale', 'review_interval_months']);

        const answered = { ...Object.fromEntries(DUE_DILIGENCE_AREAS.map((area) => [area, 'unknown'])), rationale: 'Vurdert.' };
        assert.equal(withConclusion({ ...data, ...answered }, 'no_significant_risk').review_interval_months, '24');
        assert.equal(withConclusion({ ...data, ...answered }, 'measures_required').review_interval_months, '12');
        // Once the person has chosen, the suggestion no longer overrides it.
        assert.equal(withConclusion({ ...data, review_interval_months: '6' }, 'no_significant_risk', true).review_interval_months, '6');
        assert.deepEqual(dueDiligenceMissing(withConclusion({ ...data, ...answered }, 'monitor')), []);
    });

    test('Kartlegg names the profile facts and keeps not answered apart from no', () => {
        const facts = mappingFacts({ production_outside_eea: 'unknown', high_risk_categories: ['textiles'], uses_subcontractors: null, labour_intensive: 'no' }, {
            profile: { high_risk_categories: { textiles: 'Tekstiler og arbeidstøy' } },
        });

        assert.deepEqual(facts.map((fact) => [fact.key, fact.text, fact.uncertain]), [
            ['production_outside_eea', 'Ikke avklart', true],
            ['high_risk_categories', 'Tekstiler og arbeidstøy', false],
            ['uses_subcontractors', 'Ikke besvart', true],
            ['labour_intensive', 'Nei', false],
        ]);
        assert.equal(mappingFacts({ high_risk_categories: [] })[1].text, 'Ingen');
    });

    test('the latest assessment says when the next falls due, and the related requirements are the HR, labour and environment themes', () => {
        const date = (iso) => `[${iso}]`;

        assert.equal(nextText({ next_on: '2027-10-08', overdue: false }, {}, date), 'Neste aktsomhetsvurdering [2027-10-08]');
        assert.equal(nextText({ next_on: '2026-10-07', overdue: true }, {}, date), 'Neste aktsomhetsvurdering var [2026-10-07] og er forfalt');
        assert.equal(nextText({ next_on: null }), null);
        assert.deepEqual(relatedRequirements([{ id: 1, theme: 'human_rights' }, { id: 2, theme: 'privacy' }, { id: 3, theme: 'environment' }, { id: 4, theme: 'labour_conditions' }]).map((row) => row.id), [1, 3, 4]);
    });

    test('signals 10 and 11 read as the register and the page show them', () => {
        assert.equal(attentionFindingText({ key: 'due_diligence_missing' }), 'Leverandørprofilen gjør en aktsomhetsvurdering relevant, og ingen er registrert.');
        assert.equal(attentionFindingText({ key: 'due_diligence_overdue', next_on: '2026-10-07' }, {}, (iso) => `[${iso}]`), 'Neste aktsomhetsvurdering var [2026-10-07] og er forfalt.');
    });

    test('the section shows no score, offers writing only by permission, and the follow-up only for «Tiltak kreves»', () => {
        const page = source('./SupplierDueDiligence.jsx');
        const code = page.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');

        assert.doesNotMatch(code, /score|prosent|percent|total/i);
        assert.match(page, /const canAct = data\.permissions\?\.can_assess && ! assessing;/);
        assert.match(page, /current\?\.conclusion === 'measures_required' && onFollowUp/);
        assert.match(page, /DUE_DILIGENCE_STEPS\.map/);
    });
});
