import { test } from 'node:test';
import assert from 'node:assert/strict';
import { controlLabel, controlOptionLabel, filterControls } from './riskControlOptions.js';

const options = [
    { id: 1, code: 'K-01', title: 'Fire-øyne-kontroll', placements: ['Lønnskjøring › Godkjenn utbetaling'] },
    { id: 2, code: null, title: 'Tilgangsgjennomgang', placements: [] },
    { id: 3, code: 'K-07', title: 'Fire-øyne-kontroll', placements: ['Innkjøp › Attester faktura', 'Innkjøp › Betal'] },
];

test('labels a control with its code when it has one', () => {
    assert.equal(controlLabel(options[0]), 'K-01 · Fire-øyne-kontroll');
    assert.equal(controlLabel(options[1]), 'Tilgangsgjennomgang');
});

test('adds where the control sits, so look-alikes can be told apart', () => {
    assert.equal(controlOptionLabel(options[0]), 'K-01 · Fire-øyne-kontroll — Lønnskjøring › Godkjenn utbetaling');
    assert.equal(controlOptionLabel(options[2]), 'K-07 · Fire-øyne-kontroll — Innkjøp › Attester faktura; Innkjøp › Betal');
    assert.equal(controlOptionLabel(options[1]), 'Tilgangsgjennomgang');
});

test('an empty query keeps every control', () => {
    assert.deepEqual(filterControls(options, '   '), options);
});

test('matches every word against code, title and placement, ignoring case', () => {
    assert.deepEqual(filterControls(options, 'fire innkjøp').map((c) => c.id), [3]);
    assert.deepEqual(filterControls(options, 'k-01').map((c) => c.id), [1]);
    assert.deepEqual(filterControls(options, 'TILGANG').map((c) => c.id), [2]);
    assert.deepEqual(filterControls(options, 'finnes ikke'), []);
});

test('keeps the chosen control even when it no longer matches', () => {
    assert.deepEqual(filterControls(options, 'innkjøp', '2').map((c) => c.id), [2, 3]);
});
