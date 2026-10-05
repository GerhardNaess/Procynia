import assert from 'node:assert/strict';
import test from 'node:test';
import { responsibleLabel } from './kpiStatus.js';

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
