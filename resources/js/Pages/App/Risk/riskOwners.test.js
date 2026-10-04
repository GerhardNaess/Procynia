import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ownersForArea } from './riskOwners.js';

const options = [
    { id: 1, name: 'Ada', area_ids: [10, 11] },
    { id: 2, name: 'Bo', area_ids: [11] },
];

test('only people who can read risks in the chosen area are offered as owner', () => {
    assert.deepEqual(ownersForArea(options, 10).map((o) => o.id), [1]);
    assert.deepEqual(ownersForArea(options, '11').map((o) => o.id), [1, 2]);
});

test('no area chosen offers nobody', () => {
    assert.deepEqual(ownersForArea(options, ''), []);
    assert.deepEqual(ownersForArea(options, null), []);
});
