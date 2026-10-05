import assert from 'node:assert/strict';
import test from 'node:test';
import { existingValueFor, indicatorLabel, responsibleLabel } from './kpiStatus.js';

test('a KPI with its own owner names that owner', () => {
    assert.equal(responsibleLabel({ responsible_name: 'Kari', responsible_is_fallback: false }, {}), 'Kari');
});

test('a KPI without its own owner names the objective owner as the fallback', () => {
    assert.equal(
        responsibleLabel({ responsible_name: 'Ola', responsible_is_fallback: true }, { responsible_fallback: ':name (målets ansvarlig)' }),
        'Ola (målets ansvarlig)',
    );
});

test('nobody responsible is left to the page to flag', () => {
    assert.equal(responsibleLabel({ responsible_name: null, responsible_is_fallback: false }, {}), null);
});

test('a chosen period that already has a value makes the measurement a correction', () => {
    const form = {
        mode: 'period',
        period_options: [
            { key: '2026-09', label: 'September 2026', current_value_display: '98,7 %' },
            { key: '2026-08', label: 'August 2026', current_value_display: null },
        ],
    };

    assert.equal(existingValueFor(form, { period: '2026-09' }), '98,7 %');
    assert.equal(existingValueFor(form, { period: '2026-08' }), null);
    assert.equal(existingValueFor(form, { period: '2026-07' }), null);
    assert.equal(existingValueFor(null, { period: '2026-09' }), null);
});

test('a KPI without frequency looks the chosen date up instead', () => {
    const form = { mode: 'date', existing_by_date: { '2026-10-05': '12' } };

    assert.equal(existingValueFor(form, { measured_on: '2026-10-05' }), '12');
    assert.equal(existingValueFor(form, { measured_on: '2026-10-04' }), null);
});

test('the objective indicator is a count of KPIs on target, or nothing without active KPIs', () => {
    assert.equal(indicatorLabel({ on_target: 2, total: 3 }, {}), '2 av 3 KPI-er på mål');
    assert.equal(indicatorLabel({ on_target: 0, total: 1 }, { indicator: ':on of :total KPIs on target' }), '0 of 1 KPIs on target');
    assert.equal(indicatorLabel(null, {}), null);
});
