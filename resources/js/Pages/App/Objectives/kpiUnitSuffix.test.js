import assert from 'node:assert/strict';
import test from 'node:test';
import { kpiUnitSuffix } from './kpiUnitSuffix.js';

const NO = {
    unit_suffix: { hours: { one: 'time', other: 'timer' }, days: { one: 'dag', other: 'dager' } },
    input_suffix: { count: 'stk.', nok: 'kr' },
};

const EN = {
    unit_suffix: { hours: { one: 'hour', other: 'hours' }, days: { one: 'day', other: 'days' } },
    input_suffix: { count: 'pcs', nok: 'NOK' },
};

test('every unit the KPI model has gets its suffix', () => {
    assert.equal(kpiUnitSuffix({ unit: 'percent' }, NO, '35'), '%');
    assert.equal(kpiUnitSuffix({ unit: 'currency', currency_code: 'NOK' }, NO, '1300000'), 'kr');
    assert.equal(kpiUnitSuffix({ unit: 'currency', currency_code: 'EUR' }, NO, '25000'), 'EUR');
    assert.equal(kpiUnitSuffix({ unit: 'count', unit_label: '' }, NO, '250'), 'stk.');
    assert.equal(kpiUnitSuffix({ unit: 'hours' }, NO, '4,5'), 'timer');
    assert.equal(kpiUnitSuffix({ unit: 'days' }, NO, '7'), 'dager');
});

test('a unit without a suffix of its own shows nothing', () => {
    assert.equal(kpiUnitSuffix({ unit: 'number', unit_label: '' }, NO, '3,75'), null);
    assert.equal(kpiUnitSuffix({ unit: '' }, NO, '12'), null);
    assert.equal(kpiUnitSuffix(null, NO, '12'), null);
});

test('a count or number with its own label shows the label', () => {
    assert.equal(kpiUnitSuffix({ unit: 'count', unit_label: ' hendelser ' }, NO, '3'), 'hendelser');
    assert.equal(kpiUnitSuffix({ unit: 'number', unit_label: 'poeng' }, NO, '3,75'), 'poeng');
});

test('a currency KPI shows its code as it is typed, and nothing before there is one', () => {
    assert.equal(kpiUnitSuffix({ unit: 'currency', currency_code: '' }, NO, '100'), null);
    assert.equal(kpiUnitSuffix({ unit: 'currency', currency_code: 'nok' }, NO, '100'), 'kr');
    assert.equal(kpiUnitSuffix({ unit: 'currency', currency_code: 'SEK' }, NO, '100'), 'SEK');
});

test('hours and days are singular for exactly one, however it is written', () => {
    for (const one of ['1', '1,0', '1.00', ' 1 ', '+1', '01']) {
        assert.equal(kpiUnitSuffix({ unit: 'hours' }, NO, one), 'time', `«${one}»`);
    }

    for (const other of ['', '0', '1,5', '2', '10', '-1', '11']) {
        assert.equal(kpiUnitSuffix({ unit: 'days' }, NO, other), 'dager', `«${other}»`);
    }
});

test('switching unit in the form changes the suffix at once, the value staying as typed', () => {
    const data = { unit: 'currency', unit_label: '', currency_code: 'NOK', target_min: '1 300 000' };
    assert.equal(kpiUnitSuffix(data, NO, data.target_min), 'kr');

    // What KpiForm's changeUnit does: the label and currency go, the numbers stay.
    const switched = { ...data, unit: 'percent', currency_code: '' };
    assert.equal(kpiUnitSuffix(switched, NO, switched.target_min), '%');
    assert.equal(switched.target_min, '1 300 000');

    const counted = { ...switched, unit: 'count' };
    assert.equal(kpiUnitSuffix(counted, NO, counted.target_min), 'stk.');
    assert.equal(kpiUnitSuffix({ ...counted, unit_label: 'saker' }, NO, counted.target_min), 'saker');
});

test('editing starts from the stored KPI and shows its unit', () => {
    // The KPI detail the server sends to the edit form and the measurement form.
    const kpi = { unit: 'currency', unit_label: null, currency_code: 'NOK', target_min: '1300000' };

    assert.equal(kpiUnitSuffix(kpi, NO, kpi.target_min), 'kr');
    assert.equal(kpiUnitSuffix({ unit: 'count', unit_label: null }, NO, '3'), 'stk.');
});

test('the suffix reads in the user\'s language', () => {
    assert.equal(kpiUnitSuffix({ unit: 'currency', currency_code: 'NOK' }, EN, '100'), 'NOK');
    assert.equal(kpiUnitSuffix({ unit: 'count' }, EN, '3'), 'pcs');
    assert.equal(kpiUnitSuffix({ unit: 'hours' }, EN, '1'), 'hour');
    assert.equal(kpiUnitSuffix({ unit: 'hours' }, EN, '2'), 'hours');
});

test('without translations the Norwegian words are the fallback', () => {
    assert.equal(kpiUnitSuffix({ unit: 'count' }, {}, '3'), 'stk.');
    assert.equal(kpiUnitSuffix({ unit: 'currency', currency_code: 'NOK' }, {}, '3'), 'kr');
    assert.equal(kpiUnitSuffix({ unit: 'days' }, {}, '1'), 'dag');
});
