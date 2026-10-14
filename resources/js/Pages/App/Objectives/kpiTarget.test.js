import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    growthTarget,
    resolvedTarget,
    targetFields,
    targetRuleText,
    toleranceRuleText,
    unitProblem,
} from './kpiTarget.js';

// The server's KpiUnitRules::forClient(), as the form receives it.
const RULES = {
    percent: { decimals: 4, min: '0', max: '100' },
    percent_change: { decimals: 4, min: null, max: null },
    count: { decimals: 0, min: '0', max: null },
    number: { decimals: 4, min: null, max: null },
    currency: { decimals: 2, min: null, max: null },
    hours: { decimals: 2, min: '0', max: null },
    days: { decimals: 2, min: '0', max: null },
};

const spaces = (text) => text.replace(/ /g, ' ');

describe('targetFields', () => {
    test('each måltype shows only its own fields', () => {
        assert.deepEqual(targetFields('at_least'), ['target_min']);
        assert.deepEqual(targetFields('at_most'), ['target_max']);
        assert.deepEqual(targetFields('range'), ['target_min', 'target_max']);
        assert.deepEqual(targetFields('exact'), ['target_min']);
        assert.deepEqual(targetFields('growth'), ['baseline_value', 'change_percent']);
    });
});

describe('growthTarget, the same as KpiGrowth on the server', () => {
    test('30 % up from 1 000 000 is 1 300 000', () => {
        assert.equal(growthTarget('1000000', '30', 2), '1300000');
    });

    test('an increase rounds up and a decrease rounds down, to the unit', () => {
        assert.equal(growthTarget('7', '30', 0), '10');
        assert.equal(growthTarget('7', '-30', 0), '4');
        assert.equal(growthTarget('3', '33.33', 4), '3.9999');
        assert.equal(growthTarget('100.5', '12.5', 2), '113.07');
    });

    test('a 100 % decrease is zero, and nothing else is computed from what cannot make a target', () => {
        assert.equal(growthTarget('20', '-100', 0), '0');
        assert.equal(growthTarget('20', '-101', 0), null);
        assert.equal(growthTarget('0', '30', 2), null);
        assert.equal(growthTarget('100', '0', 2), null);
        assert.equal(growthTarget('', '30', 2), null);
    });

    test('16 digits stay exact', () => {
        assert.equal(growthTarget('1234567890123456', '10', 2), '1358024679135801.6');
    });
});

describe('unitProblem, the same as KpiUnitRules', () => {
    test('a share is between 0 and 100, a change is not limited', () => {
        assert.equal(unitProblem('100', 'percent', RULES), null);
        assert.equal(unitProblem('99.995', 'percent', RULES), null);
        assert.equal(unitProblem('100.01', 'percent', RULES), 'percent_share');
        assert.equal(unitProblem('-1', 'percent', RULES), 'percent_share');
        assert.equal(unitProblem('250', 'percent_change', RULES), null);
        assert.equal(unitProblem('-40', 'percent_change', RULES), null);
    });

    test('a count is a whole number that is never negative', () => {
        assert.equal(unitProblem('3', 'count', RULES), null);
        assert.equal(unitProblem('3.5', 'count', RULES), 'whole_number');
        assert.equal(unitProblem('3.0', 'count', RULES), null);
        assert.equal(unitProblem('-1', 'count', RULES), 'negative');
    });

    test('currency allows negative amounts with two decimals, durations are never negative', () => {
        assert.equal(unitProblem('-1250.75', 'currency', RULES), null);
        assert.equal(unitProblem('10.125', 'currency', RULES), 'too_many_decimals');
        assert.equal(unitProblem('-2', 'days', RULES), 'negative');
        assert.equal(unitProblem('-12.5', 'number', RULES), null);
    });

    test('a tolerance is only checked for its precision', () => {
        assert.equal(unitProblem('150', 'percent', RULES, true), null);
        assert.equal(unitProblem('0.5', 'count', RULES, true), 'whole_number');
    });
});

describe('resolvedTarget', () => {
    test('the four types map onto the bounds the server stores', () => {
        assert.deepEqual(resolvedTarget({ target_type: 'at_least', target_min: '100', target_max: '200' }), { type: 'at_least', min: '100', max: null });
        assert.deepEqual(resolvedTarget({ target_type: 'at_most', target_min: '1', target_max: '5' }), { type: 'at_most', min: null, max: '5' });
        assert.deepEqual(resolvedTarget({ target_type: 'range', target_min: '18', target_max: '22' }), { type: 'range', min: '18', max: '22' });
        assert.deepEqual(resolvedTarget({ target_type: 'exact', target_min: '100' }), { type: 'exact', min: '100', max: '100' });
    });

    test('a range needs from below to', () => {
        assert.equal(resolvedTarget({ target_type: 'range', target_min: '22', target_max: '18' }), null);
        assert.equal(resolvedTarget({ target_type: 'range', target_min: '18', target_max: '18' }), null);
    });

    test('growth becomes at least for an increase and at most for a decrease', () => {
        const data = { target_type: 'growth', unit: 'currency', baseline_value: '1000000' };

        assert.deepEqual(resolvedTarget({ ...data, change_percent: '30' }, RULES), { type: 'at_least', min: '1300000', max: null });
        assert.deepEqual(resolvedTarget({ ...data, change_percent: '-20' }, RULES), { type: 'at_most', min: null, max: '800000' });
    });
});

describe('targetRuleText', () => {
    const TR = {
        rule: {
            at_least: 'Målet er nådd når verdien er minst :value.',
            at_most: 'Målet er nådd når verdien er høyst :value.',
            range: 'Målet er nådd når verdien er mellom :min og :max.',
            exact: 'Målet er nådd når verdien er nøyaktig :value.',
        },
        growth_sentence: { increase: 'Det er en økning på :change fra startverdien :baseline (:date).' },
        unit_suffix: { days: { one: 'dag', other: 'dager' } },
        percentage_points: { one: 'prosentpoeng', other: 'prosentpoeng' },
        tolerance_rule: { below: 'Inntil :tolerance under målet gir «Trenger oppmerksomhet».', outside: 'Inntil :tolerance utenfor målet gir «Trenger oppmerksomhet».' },
    };

    test('states the rule with localized numbers and the unit', () => {
        assert.equal(
            spaces(targetRuleText({ target_type: 'at_least', unit: 'currency', currency_code: 'NOK', target_min: '1300000' }, TR, 'no')),
            'Målet er nådd når verdien er minst NOK 1 300 000.',
        );
        assert.equal(
            spaces(targetRuleText({ target_type: 'at_most', unit: 'days', target_max: '5' }, TR, 'no')),
            'Målet er nådd når verdien er høyst 5 dager.',
        );
        assert.equal(
            spaces(targetRuleText({ target_type: 'range', unit: 'number', target_min: '18', target_max: '22' }, TR, 'no')),
            'Målet er nådd når verdien er mellom 18 og 22.',
        );
        assert.equal(
            targetRuleText({ target_type: 'at_least', unit: 'currency', currency_code: 'NOK', target_min: '1300000' }, {}, 'en').replace(/ /g, ' '),
            'Målet er nådd når verdien er minst NOK 1,300,000.',
        );
    });

    test('a growth target says where it comes from', () => {
        const text = spaces(targetRuleText({
            target_type: 'growth', unit: 'currency', currency_code: 'NOK', baseline_value: '1000000', baseline_date: '2026-10-01', change_percent: '30',
        }, TR, 'no', RULES));

        assert.match(text, /^Målet er nådd når verdien er minst NOK 1 300 000\. Det er en økning på 30 % fra startverdien NOK 1 000 000 \(1\. oktober 2026\)\.$/);
    });

    test('nothing is stated while the målverdi is incomplete', () => {
        assert.equal(targetRuleText({ target_type: 'at_least', unit: 'number', target_min: '' }, TR), null);
        assert.equal(targetRuleText({ target_type: 'range', unit: 'number', target_min: '5', target_max: '' }, TR), null);
    });

    test('a percent tolerance reads in percentage points and always points away from the target', () => {
        assert.equal(
            spaces(toleranceRuleText({ target_type: 'at_least', unit: 'percent', target_min: '99.5', tolerance: '1' }, TR, 'no')),
            'Inntil 1 prosentpoeng under målet gir «Trenger oppmerksomhet».',
        );
        assert.equal(
            spaces(toleranceRuleText({ target_type: 'exact', unit: 'number', target_min: '100', tolerance: '5' }, TR, 'no')),
            'Inntil 5 utenfor målet gir «Trenger oppmerksomhet».',
        );
        assert.equal(toleranceRuleText({ target_type: 'at_least', unit: 'number', target_min: '1', tolerance: '0' }, TR), null);
    });
});
