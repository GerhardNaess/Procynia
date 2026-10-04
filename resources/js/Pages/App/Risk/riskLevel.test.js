import { test } from 'node:test';
import assert from 'node:assert/strict';
import { previewLevel } from './riskLevel.js';

const criteria = {
    likelihood: [1, 2, 3, 4, 5],
    consequence: [1, 2, 3, 4, 5],
    bands: [
        { level: 'low', min: 1, max: 4 },
        { level: 'moderate', min: 5, max: 9 },
        { level: 'high', min: 10, max: 16 },
        { level: 'very_high', min: 17, max: 25 },
    ],
};

test('the preview follows the bands the server sent', () => {
    assert.deepEqual(previewLevel(criteria, 2, 2), { score: 4, level: 'low' });
    assert.deepEqual(previewLevel(criteria, '1', '5'), { score: 5, level: 'moderate' });
    assert.deepEqual(previewLevel(criteria, 4, 4), { score: 16, level: 'high' });
    assert.deepEqual(previewLevel(criteria, 5, 4), { score: 20, level: 'very_high' });
});

test('an incomplete or out-of-scale choice has no preview', () => {
    assert.equal(previewLevel(criteria, '', 3), null);
    assert.equal(previewLevel(criteria, 6, 3), null);
    assert.equal(previewLevel(null, 3, 3), null);
});
