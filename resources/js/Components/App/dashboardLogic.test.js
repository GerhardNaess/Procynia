import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    FOLLOW_UP_KEYS,
    followUpSignals,
    hasAnyOutcome,
    managementRows,
    visibleOutcomes,
    winRateMetric,
} from './dashboardLogic.js';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, 'DashboardCockpit.jsx'), 'utf8');

/**
 * The dashboard answers three questions in order: what needs following up, where the cases are,
 * and what came of them. Everything else is secondary or gone.
 *
 * These cover the decisions — which signals appear, what counts as measurable, what counts as an
 * outcome — plus source-level guards for the information architecture itself, since the project
 * has no JSX renderer.
 */

const ATTENTION = [
    { key: 'deadline-soon', title: 'Frister innen 5 dager', subtitle: 'nær frist', count: 2, severity: 'danger', items: [] },
    { key: 'missing-bid-manager', title: 'Saker uten bid-manager', subtitle: 'mangler ansvar', count: 9, severity: 'warning', items: [] },
    { key: 'missing-commercial-owner', title: 'Saker uten kommersiell eier', subtitle: '...', count: 4, severity: 'warning', items: [] },
    { key: 'go-no-go-pending', title: 'Go / No-Go uten beslutning', subtitle: 'venter', count: 2, severity: 'warning', items: [] },
    { key: 'inactive-seven-days', title: 'Uten aktivitet siste 7 dager', subtitle: 'stille', count: 0, severity: 'neutral', items: [] },
];

const DESCRIPTIONS = {
    'go-no-go-pending': 'Antall saker som venter på beslutning om de skal tas videre.',
    'missing-bid-manager': 'Antall aktive saker uten ansvarlig Bid Manager.',
    'deadline-soon': 'Antall saker med en operativ frist i dag eller de neste 5 dagene.',
    'inactive-seven-days': 'Antall saker uten registrert kommentar eller innsending de siste 7 dagene.',
};

const LABELS = {
    'go-no-go-pending': 'Go / No-Go',
    'missing-bid-manager': 'Mangler Bid Manager',
    'deadline-soon': 'Nær frist',
    'inactive-seven-days': 'Ingen aktivitet > 7 dager',
};

describe('follow-up signals', () => {
    test('exactly the four action signals appear, in priority order', () => {
        const signals = followUpSignals(ATTENTION, LABELS);

        assert.deepEqual(signals.map((s) => s.key), FOLLOW_UP_KEYS);
        assert.equal(signals.length, 4);
    });

    test('commercial-owner coverage is not a follow-up signal — it belongs to management', () => {
        const keys = followUpSignals(ATTENTION, LABELS).map((s) => s.key);

        assert.ok(!keys.includes('missing-commercial-owner'));
    });

    test('each signal carries its count, a short label and a description', () => {
        const signals = followUpSignals(ATTENTION, LABELS, DESCRIPTIONS);
        const byKey = Object.fromEntries(signals.map((s) => [s.key, s]));

        assert.equal(byKey['missing-bid-manager'].count, 9);
        assert.equal(byKey['missing-bid-manager'].label, 'Mangler Bid Manager');
        assert.equal(byKey['missing-bid-manager'].description, 'Antall aktive saker uten ansvarlig Bid Manager.');
    });

    test('it falls back to the backend subtitle when no translated description exists', () => {
        const signals = followUpSignals(ATTENTION, LABELS, {});

        assert.equal(signals.find((s) => s.key === 'missing-bid-manager').description, 'mangler ansvar');
    });

    test('a signal at zero is still shown — it is an answer, not an absence', () => {
        const signals = followUpSignals(ATTENTION, LABELS);

        assert.equal(signals.find((s) => s.key === 'inactive-seven-days').count, 0);
    });

    test('a signal missing from the payload is dropped, never invented', () => {
        const signals = followUpSignals([ATTENTION[0]], LABELS);

        assert.deepEqual(signals.map((s) => s.key), ['deadline-soon']);
    });

    test('it falls back to the backend title when no short label is supplied', () => {
        const signals = followUpSignals(ATTENTION, {});

        assert.equal(signals[0].label, 'Go / No-Go uten beslutning');
    });
});

describe('management rows', () => {
    const bidQuality = {
        items: [
            { key: 'bid_manager_coverage', numerator: 3, denominator: 12 },
            { key: 'opportunity_owner_coverage', numerator: 12, denominator: 12 },
            { key: 'win_rate_90d', numerator: 2, denominator: 3, value: 66.7 },
        ],
    };
    const labels = { opportunity_owner: 'Med kommersiell eier', bid_manager: 'Med Bid Manager', activity_14_days: 'Aktivitet siste 14 dager' };

    test('coverage reads as a ratio, not a percentage', () => {
        const rows = managementRows(bidQuality, { activity: { activity_count_14_days: 12 } }, labels, 'Ingen måling');

        assert.deepEqual(rows.map((r) => r.value), ['12 / 12', '3 / 12', '12']);
    });

    test('coverage with nothing to cover is unmeasured, not zero per cent', () => {
        const empty = { items: [{ key: 'bid_manager_coverage', numerator: 0, denominator: 0 }] };
        const rows = managementRows(empty, {}, labels, 'Ingen måling');

        assert.equal(rows.find((r) => r.key === 'bid_manager').value, 'Ingen måling');
    });

    test('activity is an event count, so it is shown as a plain number', () => {
        const rows = managementRows(bidQuality, { activity: { activity_count_14_days: 0 } }, labels, 'Ingen måling');

        assert.equal(rows.find((r) => r.key === 'activity_14_days').value, '0');
    });
});

describe('outcomes', () => {
    const outcomes = [
        { key: 'won', label: 'Vunnet', count: 2 },
        { key: 'lost', label: 'Tapt', count: 1 },
        { key: 'no_go', label: 'No-Go', count: 1 },
        { key: 'withdrawn', label: 'Trukket', count: 0 },
        { key: 'archived', label: 'Arkiv', count: 0 },
    ];

    test('the four real results are always shown, including those at zero', () => {
        assert.deepEqual(
            visibleOutcomes(outcomes).map((o) => o.key),
            ['won', 'lost', 'no_go', 'withdrawn'],
        );
    });

    test('"Arkiv" is a legacy status, not a result — it appears only when cases hold it', () => {
        const withArchived = outcomes.map((o) => (o.key === 'archived' ? { ...o, count: 3 } : o));

        assert.ok(visibleOutcomes(withArchived).some((o) => o.key === 'archived'));
    });

    test('it knows when nothing has been closed at all', () => {
        assert.equal(hasAnyOutcome(outcomes), true);
        assert.equal(hasAnyOutcome(outcomes.map((o) => ({ ...o, count: 0 }))), false);
        assert.equal(hasAnyOutcome([]), false);
    });
});

describe('win rate', () => {
    test('it is shown when there is a basis', () => {
        const metric = winRateMetric({ items: [{ key: 'win_rate_90d', numerator: 2, denominator: 3, value: 66.7 }] });

        assert.equal(metric.value, 66.7);
    });

    test('no closed cases means no win rate row, rather than a tile reading "—"', () => {
        assert.equal(winRateMetric({ items: [{ key: 'win_rate_90d', numerator: 0, denominator: 0, value: null }] }), null);
        assert.equal(winRateMetric({ items: [] }), null);
        assert.equal(winRateMetric({}), null);
    });
});

describe('the information architecture', () => {
    test('the three questions are the three headings, in order', () => {
        const order = ['follow_up_title', 'pipeline_title', 'results_title']
            .map((key) => source.indexOf(`redesignText.${key}`));

        assert.ok(order.every((index) => index > -1), 'all three sections must render');
        assert.ok(order[0] < order[1] && order[1] < order[2], 'follow-up, then pipeline, then results');
    });

    test('the removed KPI cards are gone', () => {
        for (const removed of [
            'sectionsText.portfolio',
            'sectionsText.bid_quality?.title',
            'sectionsText.responsibility_activity',
            'metricsText.portfolio_total_title',
            'metricsText.portfolio_active_title',
            'metricsText.portfolio_outcome_title',
            'metricsText.responsibility_saved_watch_lists',
            'metricsText.responsibility_contributor_cases',
            'metricsText.responsibility_last_activity',
            'metricsText.responsibility_inactive_7_days',
            'metricsText.pipeline_average_age_label',
        ]) {
            assert.ok(!source.includes(removed), `${removed} must no longer be rendered`);
        }
    });

    test('no stage claims an average age the payload does not carry', () => {
        assert.ok(!source.includes('average_age_hours'));
    });

    test('the follow-up signals stay keyboard-reachable and expose their state', () => {
        const button = source.slice(source.indexOf('function FollowUpSignal'));

        assert.match(button, /type="button"/);
        assert.match(button, /aria-expanded=\{isOpen\}/);
    });
});
