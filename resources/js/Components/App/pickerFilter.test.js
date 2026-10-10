import test from 'node:test';
import assert from 'node:assert/strict';
import { filterPickerOptions } from './pickerFilter.js';

const frameworks = [
    { value: 'iso9001', label: 'ISO 9001 Kvalitetsledelse', description: 'Standard · Kvalitet' },
    { value: 'iso14001', label: 'ISO 14001 Miljøledelse', description: 'Standard · Miljø' },
    { value: 'nis2', label: 'NIS2 Cybersikkerhet og regulatoriske krav', description: 'Regulatorisk rammeverk · Cybersikkerhet' },
    { value: 'dora', label: 'DORA Digital operasjonell motstandsdyktighet', description: 'Regulatorisk rammeverk · Finans og IKT-risiko' },
];

test('an empty search shows every option', () => {
    assert.equal(filterPickerOptions(frameworks, '   ').length, 4);
});

test('the search matches the name, case-insensitively', () => {
    assert.deepEqual(filterPickerOptions(frameworks, 'kvalitets').map((option) => option.value), ['iso9001']);
    assert.deepEqual(filterPickerOptions(frameworks, 'MILJØ').map((option) => option.value), ['iso14001']);
});

test('the search matches the domain and the type in the description', () => {
    assert.deepEqual(filterPickerOptions(frameworks, 'regulatorisk').map((option) => option.value), ['nis2', 'dora']);
    assert.deepEqual(filterPickerOptions(frameworks, 'ikt').map((option) => option.value), ['dora']);
});

test('every word must match, in any order', () => {
    assert.deepEqual(filterPickerOptions(frameworks, 'krav nis2').map((option) => option.value), ['nis2']);
    assert.deepEqual(filterPickerOptions(frameworks, 'nis2 miljø'), []);
});

test('people are found by part of their name, accents ignored', () => {
    const people = [{ value: 1, label: 'Kari Nordmann' }, { value: 2, label: 'Åse Hågensen' }];
    assert.deepEqual(filterPickerOptions(people, 'nord').map((option) => option.value), [1]);
    assert.deepEqual(filterPickerOptions(people, 'hagen').map((option) => option.value), [2]);
});
