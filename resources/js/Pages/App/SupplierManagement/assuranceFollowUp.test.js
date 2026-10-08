import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { attentionFindingItems, followUpEntryDate, followUpEntryText, followUpView, nextControlText } from './assuranceFollowUp.js';
import { ATTENTION_TARGETS, attentionFindingText } from './supplierManagement.js';

const tr = {
    control: { levels: { mandatory: 'Obligatorisk', important: 'Viktig' }, display_statuses: { not_evaluated: 'Ikke vurdert' } },
    criticality: { levels: { critical: 'Kritisk' } },
};
const date = (value) => `«${value}»`;

describe('Leverandørkontroll in Trenger oppmerksomhet', () => {
    test('a finding counts its requirements and lists each with why', () => {
        const overdue = {
            key: 'control_overdue',
            requirements: [
                { id: 1, title: 'Sikkerhetsrapport', reason: 'control_interval', date: '2026-10-07' },
                { id: 2, title: 'ISO', reason: 'document_expired', date: '2026-10-01', document_title: 'SOC 2' },
                { id: 3, title: 'DBA', reason: 'acceptance_expired', date: '2026-10-05' },
                { id: 4, title: 'Forsikring', reason: 'document_replaced', date: null, document_title: 'Polise 2025' },
            ],
        };

        assert.equal(attentionFindingText(overdue, tr), '4 kontrollkrav er forfalt:');
        assert.deepEqual(attentionFindingItems(overdue, tr, date).map((item) => item.text), [
            'Sikkerhetsrapport – kontrollfristen var «2026-10-07»',
            'ISO – «SOC 2» utløp «2026-10-01»',
            'DBA – midlertidig aksept utløp «2026-10-05»',
            'Forsikring – «Polise 2025» er erstattet av en ny utgave',
        ]);

        const decision = { key: 'decision_required', requirements: [{ id: 5, title: 'DBA', display_status: 'not_evaluated' }] };
        assert.equal(attentionFindingText(decision, tr), '1 obligatorisk krav krever beslutning:');
        assert.deepEqual(attentionFindingItems(decision, tr), [{ id: 5, text: 'DBA (Ikke vurdert)' }]);

        const notEvaluated = { key: 'requirement_not_evaluated', requirements: [{ id: 6, title: 'A', level: 'mandatory' }, { id: 7, title: 'B', level: 'important' }] };
        assert.equal(attentionFindingText(notEvaluated, tr), '2 obligatoriske eller viktige krav er ikke vurdert:');
        assert.deepEqual(attentionFindingItems(notEvaluated, tr).map((item) => item.text), ['A (Obligatorisk)', 'B (Viktig)']);

        assert.equal(attentionFindingText({ key: 'profile_incomplete', criticality: 'critical' }, tr), 'Leverandøren er Kritisk, og leverandørprofilen er ikke fylt ut.');
        // v1 findings name no requirements and keep their own wording.
        assert.deepEqual(attentionFindingItems({ key: 'missing_owner' }, tr), []);
        assert.equal(attentionFindingText({ key: 'missing_owner' }, tr), 'Leverandøren mangler intern ansvarlig.');
    });

    test('each new finding points to where it is followed up', () => {
        assert.equal(ATTENTION_TARGETS.decision_required.anchor, 'supplier-assurance-heading');
        assert.equal(ATTENTION_TARGETS.control_overdue.anchor, 'supplier-control-heading');
        assert.equal(ATTENTION_TARGETS.requirement_not_evaluated.anchor, 'supplier-control-heading');
        assert.equal(ATTENTION_TARGETS.profile_incomplete.anchor, 'supplier-profile-heading');
    });
});

describe('Neste kontroller', () => {
    const entry = (kind, overrides = {}) => ({ kind, date: '2026-11-01', overdue: false, requirement: { id: 1, title: 'DBA' }, document: { id: 2, title: 'SOC 2' }, ...overrides });

    test('nothing for no plan (an ended supplier) or an empty one; five, then all', () => {
        assert.equal(followUpView(null).visible, false);
        assert.equal(followUpView({ entries: [], on_change: [] }).visible, false);
        assert.equal(followUpView({ entries: [], on_change: [{ id: 1, title: 'Underleverandører' }] }).visible, true);

        const plan = { entries: Array.from({ length: 7 }, (_, index) => entry('control', { requirement: { id: index, title: `K${index}` } })), on_change: [], preview: 5 };
        assert.deepEqual([followUpView(plan).items.length, followUpView(plan).hasMore, followUpView(plan).count], [5, true, 7]);
        assert.equal(followUpView(plan, true).items.length, 7);
    });

    test('each entry says what falls due, for which requirement or document', () => {
        assert.equal(followUpEntryText(entry('control'), tr), 'Ny kontroll: DBA');
        assert.equal(followUpEntryText(entry('document_renewal'), tr), 'Forny «SOC 2» (brukt for DBA)');
        assert.equal(followUpEntryText(entry('acceptance'), tr), 'Midlertidig aksept utløper: DBA');
        assert.equal(followUpEntryText(entry('document', { requirement: null }), tr), 'Dokumentasjon utløper: «SOC 2»');
        assert.equal(followUpEntryText(entry('assessment', { requirement: null, document: null }), tr), 'Neste leverandørvurdering');
        assert.equal(followUpEntryDate(entry('document_renewal', { date: null, overdue: true }), tr, date), 'Nå');
        assert.equal(followUpEntryDate(entry('control'), tr, date), '«2026-11-01»');
    });

    test('a requirement row shows its next control only once controlled with a status that runs out', () => {
        const row = (status, followUp) => ({ evaluated: status !== null, current: status ? { status } : null, follow_up: followUp });

        assert.equal(nextControlText(row(null, { next_control_on: null }), tr), null);
        assert.equal(nextControlText(row('missing', { next_control_on: null }), tr), null);
        assert.equal(nextControlText(row('temporarily_accepted', { next_control_on: null, accepted_until: '2026-12-01' }), tr), null);
        assert.equal(nextControlText(row('documented', { next_control_on: null }), tr), 'Ingen fast kontrollfrist');
        assert.equal(nextControlText(row('documented', { next_control_on: '2027-04-08', control_overdue: false }), tr, date), 'Neste kontroll «2027-04-08»');
        assert.equal(nextControlText(row('partially_documented', { next_control_on: '2026-10-07', control_overdue: true }), tr, date), 'Kontrollfristen var «2026-10-07»');
    });
});
