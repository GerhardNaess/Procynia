import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as assuranceStatus from './assuranceStatus.js';
import {
    applicableText,
    decisionByline,
    openMandatory,
    snapshotSummary,
    stateNow,
    summaryGroups,
} from './assuranceStatus.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const tr = {
    assurance: {
        states: { mandatory_open: 'Obligatorisk krav åpent', follow_up_required: 'Krever oppfølging', in_order: 'I orden' },
        decisions: { approved: 'Godkjent', approved_with_follow_up: 'Godkjent med oppfølging', not_approved: 'Ikke godkjent for nye kjøp' },
    },
    control: {
        levels: { mandatory: 'Obligatorisk', important: 'Viktig' },
        display_statuses: { acceptance_expired: 'Aksept utløpt', missing: 'Mangler' },
    },
};

describe('Kravstatus, and the retired kontrollbeslutninger as history', () => {
    test('Kravstatus is the state the server computed — never a decision, never a share', () => {
        assert.deepEqual(stateNow({ state: 'mandatory_open' }, tr), { key: 'mandatory_open', label: 'Obligatorisk krav åpent', tone: 'rose' });
        assert.deepEqual(stateNow('follow_up_required', tr), { key: 'follow_up_required', label: 'Krever oppfølging', tone: 'amber' });
        assert.equal(stateNow({ state: 'in_order' }, tr).label, 'I orden');
        assert.equal(stateNow(null, tr), null);
        // The server no longer sends a decision signal, and a stray one changes nothing.
        assert.equal(stateNow({ state: 'mandatory_open', decision_required: true }, tr).label, 'Obligatorisk krav åpent');

        const block = source('./SupplierAssuranceStatus.jsx');
        assert.match(block, /data-testid="supplier-requirement-status"/);
        assert.doesNotMatch(block, /<progress|progressbar|\* 100|\/ state\.applicable_count/);
    });

    test('nothing registers a decision any more: no form, no endpoint, no button', () => {
        for (const retired of ['decisionFormData', 'decisionMissing', 'decisionOptions']) {
            assert.equal(assuranceStatus[retired], undefined, retired);
        }

        for (const file of ['./SupplierAssuranceStatus.jsx', './Show.jsx', './Index.jsx']) {
            const text = source(file);
            assert.doesNotMatch(text, /assurance-decisions|Registrer beslutning|Krever beslutning|decision_required|Ingen beslutning registrert/, file);
        }
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
