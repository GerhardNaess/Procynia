import assert from 'node:assert/strict';
import test from 'node:test';
import { editableKpiNumber, editFromFormatted, editablePosition, formatKpiNumber, kpiNumberProblemMessage, parseKpiNumber } from './kpiNumber.js';

const NBSP = '\u00A0';
const no = (text) => text.replaceAll(' ', NBSP);

const NO_TR = {
    number_input: {
        ambiguous: '«:value» kan bety både :thousands og :decimal. Skriv :thousands_plain eller :decimal_plain.',
        invalid: 'Skriv et tall, for eksempel 1 300 000 eller 12,5.',
    },
};

const EN_TR = {
    number_input: {
        ambiguous: '«:value» could mean :thousands or :decimal. Enter :thousands_plain or :decimal_plain.',
        invalid: 'Enter a number, for example 1,300,000 or 12.5.',
    },
};

test('Norwegian groups thousands with a space and writes a decimal comma', () => {
    assert.equal(formatKpiNumber('1000000', 'no'), no('1 000 000'));
    assert.equal(formatKpiNumber('1300000', 'no'), no('1 300 000'));
    assert.equal(formatKpiNumber('12500.5', 'no'), no('12 500,5'));
    assert.equal(formatKpiNumber('99.5', 'no'), '99,5');
    assert.equal(formatKpiNumber('0', 'no'), '0');
    assert.equal(formatKpiNumber('-2500.25', 'no'), no('-2 500,25'));
    assert.equal(formatKpiNumber('999', 'no'), '999');
});

test('English groups thousands with a comma and writes a decimal point', () => {
    assert.equal(formatKpiNumber('1000000', 'en'), '1,000,000');
    assert.equal(formatKpiNumber('1300000', 'en'), '1,300,000');
    assert.equal(formatKpiNumber('12500.5', 'en'), '12,500.5');
    assert.equal(formatKpiNumber('99.5', 'en'), '99.5');
    assert.equal(formatKpiNumber('0', 'en'), '0');
});

test('the same stored value reads in each language', () => {
    for (const [stored, norwegian, english] of [
        ['1300000', '1 300 000', '1,300,000'],
        ['12500.5', '12 500,5', '12,500.5'],
        ['0.0005', '0,0005', '0.0005'],
    ]) {
        assert.equal(formatKpiNumber(stored, 'no'), no(norwegian));
        assert.equal(formatKpiNumber(stored, 'en'), english);
        assert.equal(parseKpiNumber(formatKpiNumber(stored, 'no'), 'no').value, stored);
        assert.equal(parseKpiNumber(editableKpiNumber(stored, 'en'), 'en').value, stored);
    }
});

test('trailing zeros go, the decimals that matter stay', () => {
    assert.equal(formatKpiNumber('12.5000', 'no'), '12,5');
    assert.equal(formatKpiNumber('100.0000', 'no'), '100');
    assert.equal(formatKpiNumber('3.1416', 'no'), '3,1416');
    assert.equal(formatKpiNumber('0.0001', 'en'), '0.0001');
});

test('large numbers keep every digit — never a JavaScript number', () => {
    const largest = '9999999999999999.9999';
    assert.equal(formatKpiNumber(largest, 'no'), no('9 999 999 999 999 999,9999'));
    assert.equal(formatKpiNumber(largest, 'en'), '9,999,999,999,999,999.9999');
    assert.equal(parseKpiNumber(formatKpiNumber(largest, 'no'), 'no').value, largest);
    assert.equal(parseKpiNumber('1234567890123456,7891', 'no').value, '1234567890123456.7891');
});

test('while editing the value has no thousands separators and the locale decimal', () => {
    assert.equal(editableKpiNumber('1300000', 'no'), '1300000');
    assert.equal(editableKpiNumber('12500.5', 'no'), '12500,5');
    assert.equal(editableKpiNumber('12500.5', 'en'), '12500.5');
    assert.equal(editableKpiNumber('', 'no'), '');
});

test('stored values the server sends read back exactly, untouched', () => {
    // KpiPresenter::plain() strips trailing zeros; the form must send back what it got.
    for (const stored of ['0', '1', '35', '99.5', '1300000', '12500.5', '-4.25', '0.0001', '9999999999999999.9999']) {
        for (const locale of ['no', 'en']) {
            assert.equal(parseKpiNumber(formatKpiNumber(stored, locale), locale).value, stored, `${stored} ${locale} shown`);
            assert.equal(parseKpiNumber(editableKpiNumber(stored, locale), locale).value, stored, `${stored} ${locale} edited`);
        }
    }
});

test('Norwegian input reads to the plain value', () => {
    assert.deepEqual(parseKpiNumber('1 300 000', 'no'), { value: '1300000', problem: null });
    assert.deepEqual(parseKpiNumber(no('1 300 000'), 'no'), { value: '1300000', problem: null });
    assert.deepEqual(parseKpiNumber('12500,5', 'no'), { value: '12500.5', problem: null });
    assert.deepEqual(parseKpiNumber('99,50', 'no'), { value: '99.5', problem: null });
    assert.deepEqual(parseKpiNumber('0', 'no'), { value: '0', problem: null });
    assert.deepEqual(parseKpiNumber('0,0', 'no'), { value: '0', problem: null });
    assert.deepEqual(parseKpiNumber('-0', 'no'), { value: '0', problem: null });
    assert.deepEqual(parseKpiNumber('\u22123,5', 'no'), { value: '-3.5', problem: null });
    assert.deepEqual(parseKpiNumber('+7', 'no'), { value: '7', problem: null });
    assert.deepEqual(parseKpiNumber(',5', 'no'), { value: '0.5', problem: null });
    assert.deepEqual(parseKpiNumber('12,', 'no'), { value: '12', problem: null });
    assert.deepEqual(parseKpiNumber('007', 'no'), { value: '7', problem: null });
    assert.deepEqual(parseKpiNumber('', 'no'), { value: '', problem: null });
    assert.deepEqual(parseKpiNumber('   ', 'no'), { value: '', problem: null });
});

test('each language reads its own thousands and decimal separators', () => {
    const cases = [
        ['en', '25,000', '25000'],
        ['en', '1,300,000', '1300000'],
        ['en', '12.5', '12.5'],
        ['en', '1,300.50', '1300.5'],
        ['en', '1,500', '1500'],
        ['en', '999,999,999,999,999.9999', '999999999999999.9999'],
        ['no', '25 000', '25000'],
        ['no', '1 300 000', '1300000'],
        ['no', '12,5', '12.5'],
        ['no', '1 300,50', '1300.5'],
        ['no', no('1 300,50'), '1300.5'],
        ['no', '1\u202F300', '1300'],
        ['no', '1,500', '1.5'],
    ];

    for (const [locale, typed, plain] of cases) {
        assert.deepEqual(parseKpiNumber(typed, locale), { value: plain, problem: null }, `${locale} «${typed}»`);
    }
});

test('a space is a thousands separator in English too, a pasted Norwegian number', () => {
    assert.equal(parseKpiNumber('1 300 000', 'en').value, '1300000');
    assert.equal(parseKpiNumber('1 300.5', 'en').value, '1300.5');
});

test('a Norwegian point reads only when it can mean nothing else', () => {
    assert.equal(parseKpiNumber('12.5', 'no').value, '12.5');
    assert.equal(parseKpiNumber('0.500', 'no').value, '0.5');
    assert.equal(parseKpiNumber('1.500.000', 'no').value, '1500000');
    assert.equal(parseKpiNumber('1.300,50', 'no').value, '1300.5');
});

test('thousands in the wrong places are refused, never read as another number', () => {
    const malformed = [
        ['en', '1,30,000'],
        ['en', '1.300,50'],
        ['en', '12,5'],
        ['en', '1,5000'],
        ['en', '0,500'],
        ['en', ',500'],
        ['en', '1,300 000'],
        ['en', '1,300.5.0'],
        ['no', '1 30 000'],
        ['no', '1 3000'],
        ['no', '0 500'],
        ['no', '1  300'],
        ['no', '1 300.000'],
        ['no', '1.30,5'],
        ['no', '1,30 0'],
        ['no', '12,5,0'],
    ];

    for (const [locale, typed] of malformed) {
        const result = parseKpiNumber(typed, locale);
        assert.equal(result.problem?.code, 'invalid', `${locale} «${typed}»`);
        assert.equal(result.value, typed, `${locale} «${typed}» kept as typed`);
    }
});

test('a Norwegian «1.500» could mean two things and is refused, never guessed', () => {
    const norwegian = parseKpiNumber('1.500', 'no');
    assert.equal(norwegian.value, '1.500');
    assert.deepEqual(norwegian.problem, { code: 'ambiguous', thousands: '1500', decimal: '1.5' });

    assert.equal(parseKpiNumber('-12.500', 'no').problem.code, 'ambiguous');
    assert.equal(parseKpiNumber('-12.500', 'no').problem.thousands, '-12500');
    assert.equal(parseKpiNumber('999.999', 'no').problem.code, 'ambiguous');
    assert.equal(parseKpiNumber('1.500 kr', 'no', { suffix: 'kr' }).problem.code, 'ambiguous');

    // English puts its thousands first: the same text is 1500, no question asked.
    assert.deepEqual(parseKpiNumber('1,500', 'en'), { value: '1500', problem: null });
    assert.deepEqual(parseKpiNumber('1.500', 'en'), { value: '1.5', problem: null });
});

test('the ambiguity message says both readings and how to write each', () => {
    const norwegian = parseKpiNumber('1.500', 'no');
    assert.equal(
        kpiNumberProblemMessage(norwegian.problem, '1.500', NO_TR, 'no'),
        `«1.500» kan bety både 1${NBSP}500 og 1,5. Skriv 1500 eller 1,5.`,
    );
});

test('text that is not a number is refused with an example', () => {
    for (const typed of ['abc', '12a', '1,2,3', '1.2.3,4,5', '1..5', ',', '--3', '1 300 000 NOK', '- 5', '1e6']) {
        const result = parseKpiNumber(typed, 'no');
        assert.equal(result.problem?.code, 'invalid', `«${typed}»`);
        assert.equal(result.value, typed);
    }

    assert.equal(kpiNumberProblemMessage({ code: 'invalid' }, 'abc', NO_TR, 'no'), 'Skriv et tall, for eksempel 1 300 000 eller 12,5.');
    assert.equal(kpiNumberProblemMessage({ code: 'invalid' }, 'abc', {}, 'no'), 'Skriv et tall, for eksempel 1 300 000 eller 12,5.');
    assert.equal(kpiNumberProblemMessage({ code: 'invalid' }, 'abc', EN_TR, 'en'), 'Enter a number, for example 1,300,000 or 12.5.');
});

test('a pasted value with the field\'s own unit reads as the number', () => {
    assert.equal(parseKpiNumber('1 300 000 kr', 'no', { suffix: 'kr' }).value, '1300000');
    assert.equal(parseKpiNumber(no('99,5 %'), 'no', { suffix: '%' }).value, '99.5');
    assert.equal(parseKpiNumber('250 stk.', 'no', { suffix: 'stk.' }).value, '250');
    assert.equal(parseKpiNumber('1,250,000 EUR', 'en', { suffix: 'EUR' }).value, '1250000');
    // Only the field's own unit: another is not a number.
    assert.equal(parseKpiNumber('1 300 000 EUR', 'no', { suffix: 'kr' }).problem?.code, 'invalid');
    assert.equal(parseKpiNumber('kr', 'no', { suffix: 'kr' }).problem?.code, 'invalid');
});

test('decimals beyond what is stored are not rounded here — the server refuses them', () => {
    assert.equal(parseKpiNumber('1,23456', 'no').value, '1.23456');
    assert.equal(formatKpiNumber('1.23456', 'no'), '1,23456');
});

test('a cursor in the formatted value lands on the same digit in the editable one', () => {
    const shown = formatKpiNumber('1300000.5', 'no'); // «1 300 000,5»
    assert.equal(editablePosition(shown, 0, 'no'), 0);
    assert.equal(editablePosition(shown, 5, 'no'), 4); // «1 300|» → «1300|»
    assert.equal(editablePosition(shown, shown.length, 'no'), '1300000,5'.length);
    assert.equal(editablePosition('1,300,000.5', 6, 'en'), 4); // «1,300,|» → «1300|»
    assert.equal(editablePosition('', 0, 'no'), 0);
});

test('an edit to the formatted text loses only the formatting\'s separators, never what was typed', () => {
    const shown = formatKpiNumber('1300000', 'no'); // «1 300 000»

    // «5» typed after «1 300»: the cursor stays after the «5».
    assert.deepEqual(editFromFormatted(shown, no('1 3005 000'), 6, 'no'), { text: '13005000', caret: 5 });
    // A digit deleted before the second space.
    assert.deepEqual(editFromFormatted(shown, no('1 30 000'), 4, 'no'), { text: '130000', caret: 3 });
    // Everything replaced by a paste: the paste stays exactly as it came, its spaces to be checked.
    assert.deepEqual(editFromFormatted(shown, '2 500 000', 9, 'no'), { text: '2 500 000', caret: 9 });
    assert.deepEqual(editFromFormatted(shown, '1 30 000', 8, 'no'), { text: '1 30 000', caret: 8 });
    // English, a digit typed at the end.
    assert.deepEqual(editFromFormatted('1,300,000', '1,300,0007', 10, 'en'), { text: '13000007', caret: 8 });
    // A separator deleted alone changes nothing.
    assert.deepEqual(editFromFormatted(shown, no('1 300000'), 5, 'no'), { text: '1300000', caret: 4 });
    // Into an empty field.
    assert.deepEqual(editFromFormatted('', '7', 1, 'no'), { text: '7', caret: 1 });
});
