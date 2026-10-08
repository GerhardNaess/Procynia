import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    applicableText,
    decisionByline,
    decisionFormData,
    decisionMissing,
    decisionOptions,
    openMandatory,
    snapshotSummary,
    stateNow,
    summaryGroups,
} from './assuranceStatus.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const tr = {
    assurance: {
        decision_required: 'Krever beslutning',
        states: { mandatory_open: 'Obligatorisk krav åpent', follow_up_required: 'Krever oppfølging', in_order: 'I orden' },
        decisions: { approved: 'Godkjent', approved_with_follow_up: 'Godkjent med oppfølging', not_approved: 'Ikke godkjent for nye kjøp' },
        form: { not_allowed: { approved: 'Kan ikke velges nå.' } },
    },
    control: {
        levels: { mandatory: 'Obligatorisk', important: 'Viktig' },
        display_statuses: { acceptance_expired: 'Aksept utløpt', missing: 'Mangler' },
    },
};

describe('Kontrollstatus (supplier-assurance-v2-plan §9.5)', () => {
    test('Tilstand nå says Krever beslutning when the server says so, and otherwise the state — never the decision', () => {
        assert.deepEqual(stateNow({ state: 'mandatory_open', decision_required: true }, tr), { key: 'decision_required', label: 'Krever beslutning', tone: 'rose' });
        // Ikke godkjent for nye kjøp is in force: the open requirement is still said, but no decision is asked for.
        assert.equal(stateNow({ state: 'mandatory_open', decision_required: false }, tr).label, 'Obligatorisk krav åpent');
        assert.equal(stateNow({ state: 'in_order', decision_required: false }, tr).label, 'I orden');
        assert.equal(stateNow(null, tr), null);

        // Two labelled lines, the decision and the state, each with its own badge.
        const block = source('./SupplierAssuranceStatus.jsx');
        assert.match(block, /data-testid="assurance-decision"/);
        assert.match(block, /data-testid="assurance-state"/);
        assert.match(block, /a\.decision \?\? 'Beslutning'/);
        assert.match(block, /a\.state_now \?\? 'Tilstand nå'/);
        assert.doesNotMatch(block, /<progress|progressbar|\* 100|\/ state\.applicable_count/);
    });

    test('the summary counts in the plan order, overdue together, zero groups left out — never a share', () => {
        const groups = summaryGroups({ documented: 14, partially_documented: 0, missing: 2, not_evaluated: 1, temporarily_accepted: 0, renewal_due: 1, acceptance_expired: 0 }, {});
        assert.deepEqual(groups.map((group) => group.text), ['14 dokumentert', '2 mangler', '1 ikke vurdert', '1 forfalt']);
        assert.deepEqual(summaryGroups({ renewal_due: 1, acceptance_expired: 2 }, {}).map((group) => [group.key, group.count]), [['overdue', 3]]);
        assert.equal(applicableText(18, {}), '18 krav gjelder');
        assert.equal(applicableText(1, {}), '1 krav gjelder');
        for (const group of groups) {
            assert.doesNotMatch(group.text, /\/|%/);
        }
    });

    test('an open mandatory requirement is named, with why it is open', () => {
        assert.equal(openMandatory([], tr), null);
        assert.deepEqual(openMandatory([{ id: 3, title: 'Databehandleravtale', display_status: 'acceptance_expired' }], tr), {
            heading: '1 obligatorisk krav er ikke dokumentert eller akseptert:',
            items: [{ id: 3, text: 'Databehandleravtale – Aksept utløpt' }],
        });
        assert.match(openMandatory([{ id: 1, title: 'A' }, { id: 2, title: 'B' }], tr).heading, /^2 obligatoriske krav/);
    });

    test('the decision form offers only what the state allows, and asks for what the decision needs', () => {
        const options = decisionOptions(['approved', 'approved_with_follow_up', 'not_approved'], ['approved_with_follow_up', 'not_approved'], tr);
        assert.deepEqual(options.map((option) => [option.value, option.allowed, option.reason]), [
            ['approved', false, 'Kan ikke velges nå.'],
            ['approved_with_follow_up', true, null],
            ['not_approved', true, null],
        ]);

        const data = decisionFormData('2026-10-08');
        assert.deepEqual(data, { decision: '', rationale: '', follow_up_note: '', decided_on: '2026-10-08' });
        assert.deepEqual(decisionMissing(data, ['not_approved']), ['decision', 'rationale']);
        // A decision the state does not allow cannot be sent.
        assert.deepEqual(decisionMissing({ ...data, decision: 'approved', rationale: 'Alt er dokumentert.' }, ['not_approved']), ['decision']);
        // Godkjent med oppfølging says what is followed up.
        assert.deepEqual(decisionMissing({ ...data, decision: 'approved_with_follow_up', rationale: 'Akseptert.' }, ['approved_with_follow_up']), ['follow_up_note']);
        assert.deepEqual(decisionMissing({ ...data, decision: 'not_approved', rationale: '  ' }, ['not_approved']), ['rationale']);
        assert.deepEqual(decisionMissing({ ...data, decision: 'not_approved', rationale: 'Ikke dokumentert.' }, ['not_approved']), []);
    });

    test('the history reads each decision with the state it was taken on, as it was then', () => {
        assert.equal(decisionByline({ decided_on: '2026-10-08', decided_by_name: 'Kari Hansen' }, tr, 'no'), 'Besluttet 8. oktober 2026 av Kari Hansen');
        assert.deepEqual(snapshotSummary({
            state: 'follow_up_required',
            applicable_count: 2,
            counts: { documented: 1, temporarily_accepted: 1 },
            unmet: [{ id: 3, title: 'Databehandleravtale', level: 'mandatory', display_status: 'missing' }],
        }, tr), {
            state: 'Krever oppfølging',
            applicable: '2 krav gjelder',
            groups: [{ key: 'documented', count: 1, text: '1 dokumentert' }, { key: 'temporarily_accepted', count: 1, text: '1 midlertidig akseptert' }],
            unmet: [{ id: 3, text: 'Databehandleravtale – Obligatorisk – Mangler' }],
        });
        // Newest first as the server sends it, and never raw JSON.
        const block = source('./SupplierAssuranceStatus.jsx');
        assert.match(block, /data-testid="decision-history-entry"/);
        assert.doesNotMatch(block, /JSON\.stringify/);
    });
});
