/**
 * The numbers in a KPI's fields — Minst, Høyst, Slingringsmonn and a measurement's Verdi — in the
 * reader's notation, and back.
 *
 * The form always holds the plain value the server stores and receives: digits, an optional
 * leading minus and a point before the decimals («1300000», «12500.5»). What the person sees is
 * that value written their way: «1 300 000» and «12 500,5» in Norwegian, «1,300,000» and
 * «12,500.5» in English — the same notation KpiTargetFormatter uses on the server. Everything is
 * done on the digits as text, never through a JavaScript number, so a 16-digit value or its four
 * decimals are never rounded.
 *
 * Reading what was typed starts from the locale's own separators, and checks them: thousands
 * come in groups of three after a first group of one to three digits, and anything else is refused
 * rather than read as some other number.
 *  - English: «25,000», «1,300,000», «12.5», «1,300.50». A comma is always a thousands separator,
 *    never a decimal comma, so «1,500» is 1500 — and «1,30,000» or «1.300,50» are refused.
 *  - Norwegian: «25 000», «1 300 000», «12,5», «1 300,50». A space is always a thousands separator.
 *    A point is read as English writes it only when it can mean nothing else: «12.5» is 12,5 and
 *    «1.500.000» is 1 500 000, but «1.500» could be either and is refused with both readings, so
 *    the person says which they meant instead of the field guessing.
 * A space is accepted as a thousands separator in English too (a pasted Norwegian number), but
 * never mixed with another one in the same number.
 */

const NBSP = '\u00A0';
// Every kind of space a number may carry: typed, pasted or our own non-breaking one.
const SPACES = /[\s\u00A0\u202F]/g;
const PLAIN = /^-?\d+(\.\d+)?$/;

/** Whether the locale writes numbers the English way. Everything else reads Norwegian. */
export function isEnglishLocale(locale) {
    return String(locale ?? '').toLowerCase().startsWith('en');
}

function separators(locale) {
    return isEnglishLocale(locale)
        ? { decimal: '.', group: ',' }
        : { decimal: ',', group: NBSP };
}

/**
 * A plain value as the field shows it when it is not being edited: «1 300 000», «12 500,5».
 * Anything that is not a plain value is returned as it is.
 */
export function formatKpiNumber(value, locale) {
    return write(value, locale, true);
}

/**
 * A plain value as the field shows it while it is being edited: without thousands separators, so
 * the cursor never jumps as groups appear, and with the locale's decimal separator: «12500,5».
 */
export function editableKpiNumber(value, locale) {
    return write(value, locale, false);
}

function write(value, locale, grouped) {
    const plain = String(value ?? '');

    if (! PLAIN.test(plain)) {
        return plain;
    }

    const { decimal, group } = separators(locale);
    const negative = plain.startsWith('-');
    const [whole, fraction = ''] = plain.replace('-', '').split('.');
    const trimmedFraction = fraction.replace(/0+$/, '');
    const digits = grouped ? whole.replace(/\B(?=(\d{3})+$)/g, group) : whole;

    return (negative ? '-' : '') + digits + (trimmedFraction !== '' ? decimal + trimmedFraction : '');
}

/**
 * Where a cursor position in the formatted text lands in the editable text: the same digit, the
 * thousands separators before it taken away. «1 300| 000» → «1300|000».
 */
export function editablePosition(formatted, position, locale) {
    return withoutGroups(String(formatted ?? '').slice(0, position ?? undefined), locale).length;
}

/**
 * An edit made to the formatted text before the field switched to the editable one — a right-click
 * paste, say — as editable text: the thousands separators the formatting put there are taken out,
 * and what was typed or pasted stays exactly as it came, to be read and checked like anything else.
 * «1 300 000» with «5» typed after «1 300» → «13005000», the cursor after the «5».
 *
 * @returns {{text: string, caret: number}}
 */
export function editFromFormatted(formatted, edited, caret, locale) {
    const before = String(formatted ?? '');
    const after = String(edited ?? '');
    const shortest = Math.min(before.length, after.length);

    let head = 0;
    while (head < shortest && before[head] === after[head]) {
        head += 1;
    }

    let tail = 0;
    while (tail < shortest - head && before[before.length - 1 - tail] === after[after.length - 1 - tail]) {
        tail += 1;
    }

    const kept = after.slice(0, head);
    const typed = after.slice(head, after.length - tail);
    const rest = after.slice(after.length - tail);
    const text = withoutGroups(kept, locale) + typed + withoutGroups(rest, locale);

    const at = Math.max(0, Math.min(caret ?? after.length, after.length));
    let position;

    if (at <= head) {
        position = withoutGroups(kept.slice(0, at), locale).length;
    } else if (at <= head + typed.length) {
        position = withoutGroups(kept, locale).length + (at - head);
    } else {
        position = withoutGroups(kept, locale).length + typed.length + withoutGroups(rest.slice(0, at - head - typed.length), locale).length;
    }

    return { text, caret: position };
}

/** The text without the thousands separators the locale's formatting writes. */
function withoutGroups(text, locale) {
    return text.split(separators(locale).group).join('');
}

/**
 * What the person typed, read as a plain value.
 *
 * @param {string} text     the field's text
 * @param {string} locale   the reader's locale («no», «en»)
 * @param {{suffix?: string|null}} options  the field's unit, dropped when pasted along at the end
 * @returns {{value: string, problem: null|{code: 'invalid'}|{code: 'ambiguous', thousands: string, decimal: string}}}
 *          value is '' for an empty field, the plain value when it reads, the text as typed when not
 */
export function parseKpiNumber(text, locale, { suffix = null } = {}) {
    const typed = String(text ?? '');
    // Every kind of space becomes a plain one; inside the number it is a separator to be checked.
    let body = typed.replace(SPACES, ' ').trim();

    const unit = String(suffix ?? '').replace(SPACES, ' ').trim();
    if (unit !== '' && body.length > unit.length && body.toLowerCase().endsWith(unit.toLowerCase())) {
        body = body.slice(0, -unit.length).trim();
    }

    if (body === '') {
        return { value: '', problem: null };
    }

    const invalid = { value: typed, problem: { code: 'invalid' } };
    const match = body.match(/^([+\-\u2212]?)([\d., ]+)$/);

    if (! match) {
        return invalid;
    }

    const sign = match[1] === '' || match[1] === '+' ? '' : '-';
    const english = isEnglishLocale(locale);
    const parts = match[2].split(english ? '.' : ',');

    if (parts.length > 2) {
        return invalid;
    }

    let [whole, fraction = null] = parts;

    if (fraction !== null && ! /^\d*$/.test(fraction)) {
        return invalid;
    }

    // The thousands separators the locale reads: its own, and a space.
    const used = (english ? [',', ' '] : [' ', '.']).filter((separator) => whole.includes(separator));

    if (used.length > 1) {
        return invalid;
    }

    if (used.length === 1) {
        const [separator] = used;
        const groups = whole.split(separator);
        const canGroup = /^[1-9]\d{0,2}$/.test(groups[0]) && groups.slice(1).every((group) => /^\d{3}$/.test(group));
        // Only a Norwegian point can also be a decimal point: «12.5» as English writes it.
        const canDecimal = separator === '.' && fraction === null && groups.length === 2 && /^\d*$/.test(groups[0]) && /^\d+$/.test(groups[1]);

        if (canGroup && canDecimal) {
            return {
                value: typed,
                problem: {
                    code: 'ambiguous',
                    thousands: plainOf(sign, groups.join(''), null),
                    decimal: plainOf(sign, groups[0], groups[1]),
                },
            };
        }

        if (canGroup) {
            whole = groups.join('');
        } else if (canDecimal) {
            [whole, fraction] = groups;
        } else {
            return invalid;
        }
    }

    if (! /^\d*$/.test(whole) || (whole === '' && ! fraction)) {
        return invalid;
    }

    return { value: plainOf(sign, whole, fraction), problem: null };
}

/** Digits put together as a plain value: no leading zeros, no trailing decimal zeros, no «-0». */
function plainOf(sign, whole, fraction) {
    const digits = whole.replace(/^0+(?=\d)/, '') || '0';
    const decimals = String(fraction ?? '').replace(/0+$/, '');
    const plain = digits + (decimals !== '' ? `.${decimals}` : '');

    return /^0(\.0*)?$/.test(plain) ? plain : sign + plain;
}

/**
 * The message for a value that does not read, in the person's language and notation:
 * ««1.500» kan bety både 1 500 og 1,5. Skriv 1500 eller 1,5.»
 *
 * @param {object} problem  from parseKpiNumber
 * @param {string} typed    the text as typed
 * @param {object} tr       translations.objectives.kpi (number_input)
 */
export function kpiNumberProblemMessage(problem, typed, tr = {}, locale = 'no') {
    const messages = tr.number_input ?? {};

    if (problem?.code === 'ambiguous') {
        return (messages.ambiguous ?? '«:value» kan bety både :thousands og :decimal. Skriv :thousands_plain eller :decimal_plain.')
            .replace(':value', String(typed).trim())
            .replace(':thousands_plain', editableKpiNumber(problem.thousands, locale))
            .replace(':decimal_plain', editableKpiNumber(problem.decimal, locale))
            .replace(':thousands', formatKpiNumber(problem.thousands, locale))
            .replace(':decimal', formatKpiNumber(problem.decimal, locale));
    }

    return messages.invalid ?? 'Skriv et tall, for eksempel 1 300 000 eller 12,5.';
}
