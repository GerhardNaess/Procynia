import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { truncateLabelToWidth } from './graphLabelLogic.js';

// Deterministic fake measurer (no canvas/DOM available under node --test) — treats every
// character as a fixed width, so results are exact and independent of any real font metrics.
const CHAR_WIDTH = 8;
const measure = (text) => text.length * CHAR_WIDTH;

describe('truncateLabelToWidth', () => {
    test('returns short labels unchanged', () => {
        assert.equal(truncateLabelToWidth(measure, 'ITIL', 200), 'ITIL');
    });

    test('returns a label that exactly fits unchanged', () => {
        assert.equal(truncateLabelToWidth(measure, 'ABCD', 32), 'ABCD'); // 4 chars * 8px = 32px
    });

    test('truncates a long label and appends an ellipsis', () => {
        const long = 'Styringsnivåer: strategisk, taktisk og operativt';
        const result = truncateLabelToWidth(measure, long, 100);

        assert.ok(result.endsWith('…'), `expected "${result}" to end with an ellipsis`);
        assert.ok(result.length < long.length);
    });

    test('never produces a result wider than the max width', () => {
        const long = 'x'.repeat(100);
        const result = truncateLabelToWidth(measure, long, 50);

        assert.ok(measure(result) <= 50, `expected "${result}" (${measure(result)}px) to fit within 50px`);
    });

    test('trims trailing whitespace before appending the ellipsis', () => {
        const result = truncateLabelToWidth(measure, 'Kunnskapsforvaltning i praksis', 65);

        assert.ok(!result.includes(' …'), `expected no space directly before the ellipsis in "${result}"`);
    });

    test('falls back to just the ellipsis when not even one character fits', () => {
        const result = truncateLabelToWidth(measure, 'Anything', 4); // narrower than one character
        assert.equal(result, '…');
    });
});
