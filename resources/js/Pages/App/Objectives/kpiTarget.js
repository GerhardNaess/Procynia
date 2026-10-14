import { formatKpiNumber, isEnglishLocale } from './kpiNumber.js';
import { kpiUnitSuffix } from './kpiUnitSuffix.js';
import { formatLongDate } from './objectiveStatus.js';

/**
 * The KPI form's målverdi, as the person chooses it: a måltype and the fields that belong to it.
 * The same rules as the server — KpiTarget, KpiGrowth and KpiUnitRules — so the form can show the
 * rule and say what is wrong before anything is sent. The server decides again on save.
 *
 *  - at_least: target_min («Minst») · at_most: target_max («Høyst»)
 *  - range: target_min and target_max («Fra», «Til») · exact: target_min, stored as both bounds
 *  - growth: startverdi, its date and the change in per cent; the målverdi is computed
 *
 * Numbers are plain decimal strings («1300000», «12.5»), computed on BigInt digits and never
 * through a JavaScript number, as everywhere else in the KPI form.
 */

export const TARGET_TYPES = ['at_least', 'at_most', 'range', 'exact', 'growth'];

const PERCENT_UNITS = ['percent', 'percent_change'];
const NBSP = ' ';

/** The number fields a måltype uses, in form order. */
export function targetFields(type) {
    switch (type) {
        case 'at_least':
        case 'exact':
            return ['target_min'];
        case 'at_most':
            return ['target_max'];
        case 'range':
            return ['target_min', 'target_max'];
        case 'growth':
            return ['baseline_value', 'change_percent'];
        default:
            return [];
    }
}

/** A plain decimal as BigInt digits and a scale: «-12.5» → { digits: -125n, scale: 1 }. */
function decimal(plain) {
    const text = String(plain ?? '').trim();

    if (! /^-?\d+(\.\d+)?$/.test(text)) {
        return null;
    }

    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const digits = BigInt(whole + fraction) * (text.startsWith('-') ? -1n : 1n);

    return { digits, scale: fraction.length };
}

function scaleOf(plain) {
    const [, fraction = ''] = String(plain).replace('-', '').split('.');

    return fraction.replace(/0+$/, '').length;
}

function toPlain(digits, scale) {
    const negative = digits < 0n;
    const text = (negative ? -digits : digits).toString().padStart(scale + 1, '0');
    const whole = scale > 0 ? text.slice(0, -scale) : text;
    const fraction = scale > 0 ? text.slice(-scale).replace(/0+$/, '') : '';
    const plain = whole + (fraction !== '' ? `.${fraction}` : '');

    return (negative && /[1-9]/.test(plain) ? '-' : '') + plain;
}

function compare(a, b) {
    const x = decimal(a);
    const y = decimal(b);
    const scale = Math.max(x.scale, y.scale);
    const left = x.digits * 10n ** BigInt(scale - x.scale);
    const right = y.digits * 10n ** BigInt(scale - y.scale);

    return left === right ? 0 : (left < right ? -1 : 1);
}

/**
 * The målverdi a change from a startverdi leads to, as KpiGrowth computes it: rounded to the unit's
 * decimals, an increase upwards and a decrease downwards. Null while the inputs do not make one.
 */
export function growthTarget(baseline, changePercent, decimals) {
    const base = decimal(baseline);
    const change = decimal(changePercent);

    if (! base || ! change || base.digits <= 0n || change.digits === 0n) {
        return null;
    }

    // baseline × (100 + change) / 100, exact at scale base + change + 2.
    const hundred = 100n * 10n ** BigInt(change.scale);

    if (hundred + change.digits < 0n) {
        return null;
    }

    const product = base.digits * (hundred + change.digits);
    const exactScale = base.scale + change.scale + 2;

    if (decimals >= exactScale) {
        return toPlain(product * 10n ** BigInt(decimals - exactScale), decimals);
    }

    // The product is never negative here, so BigInt division is a floor: right for a decrease,
    // one up for an increase that does not land exactly.
    const drop = 10n ** BigInt(exactScale - decimals);
    let rounded = product / drop;

    if (product % drop !== 0n && change.digits > 0n) {
        rounded += 1n;
    }

    return toPlain(rounded, decimals);
}

/** Which bound a growth målverdi becomes: an increase is «Minst», a decrease «Høyst». */
export function growthBound(changePercent) {
    return String(changePercent ?? '').trim().startsWith('-') ? 'at_most' : 'at_least';
}

/**
 * What is wrong with a number in this unit, as KpiUnitRules says it — 'whole_number',
 * 'too_many_decimals', 'percent_share', 'negative' — or null. A tolerance (amountOnly) is only
 * checked for its precision.
 */
export function unitProblem(plain, unit, rules = {}, amountOnly = false) {
    const value = decimal(plain);
    const rule = rules[unit];

    if (! value || ! rule) {
        return null;
    }

    if (scaleOf(plain) > rule.decimals) {
        return rule.decimals === 0 ? 'whole_number' : 'too_many_decimals';
    }

    if (amountOnly) {
        return null;
    }

    if (unit === 'percent' && (value.digits < 0n || compare(plain, '100') > 0)) {
        return 'percent_share';
    }

    if (rule.min !== null && rule.min !== undefined && compare(plain, rule.min) < 0) {
        return 'negative';
    }

    return null;
}

/** The unit problem as the person reads it, from translations.objectives.kpi.validation.unit_rules. */
export function unitProblemMessage(code, unit, rules, tr = {}) {
    if (! code) {
        return null;
    }

    const messages = tr.validation?.unit_rules ?? {};
    const fallback = {
        whole_number: 'Skriv et helt tall.',
        too_many_decimals: 'Skriv høyst :count desimaler for denne enheten.',
        negative: 'Verdien kan ikke være negativ for denne enheten.',
        percent_share: 'En prosentandel er mellom 0 og 100.',
    };

    return (messages[code] ?? fallback[code]).replace(':count', String(rules?.[unit]?.decimals ?? 4));
}

/**
 * One amount in the KPI's unit, as the server writes it (KpiTargetFormatter::amount): «NOK 1 300 000»,
 * «99,5 %», «5 dager», «3 hendelser».
 */
export function formatKpiAmount(plain, definition, tr = {}, locale = 'no') {
    const number = formatKpiNumber(plain, locale);
    const unit = definition?.unit ?? '';

    if (PERCENT_UNITS.includes(unit)) {
        return isEnglishLocale(locale) ? `${number}%` : `${number}${NBSP}%`;
    }

    if (unit === 'currency') {
        const code = String(definition?.currency_code ?? '').trim().toUpperCase();

        return code !== '' ? `${code}${NBSP}${number}` : number;
    }

    if (unit === 'count') {
        const label = String(definition?.unit_label ?? '').trim();

        return label !== '' ? `${number}${NBSP}${label}` : number;
    }

    const suffix = kpiUnitSuffix(definition, tr, plain);

    return suffix ? `${number}${NBSP}${suffix}` : number;
}

/** A tolerance amount: for the percent units in percentage points, never «%» of the målverdi. */
export function formatToleranceAmount(plain, definition, tr = {}, locale = 'no') {
    if (PERCENT_UNITS.includes(definition?.unit)) {
        const points = tr.percentage_points ?? {};
        const one = compare(plain, '1') === 0;

        return `${formatKpiNumber(plain, locale)}${NBSP}${(one ? points.one : points.other) ?? 'prosentpoeng'}`;
    }

    return formatKpiAmount(plain, definition, tr, locale);
}

/**
 * The bounds the form's data stands for, the way the server will store them, or null while
 * something is missing: { type, min, max } with type one of the four KpiTarget types.
 */
export function resolvedTarget(data, rules = {}) {
    const filled = (value) => decimal(value) !== null;

    switch (data.target_type) {
        case 'at_least':
            return filled(data.target_min) ? { type: 'at_least', min: data.target_min, max: null } : null;
        case 'at_most':
            return filled(data.target_max) ? { type: 'at_most', min: null, max: data.target_max } : null;
        case 'exact':
            return filled(data.target_min) ? { type: 'exact', min: data.target_min, max: data.target_min } : null;
        case 'range':
            return filled(data.target_min) && filled(data.target_max) && compare(data.target_min, data.target_max) < 0
                ? { type: 'range', min: data.target_min, max: data.target_max }
                : null;
        case 'growth': {
            const target = growthTarget(data.baseline_value, data.change_percent, rules[data.unit]?.decimals ?? 4);

            if (target === null) {
                return null;
            }

            return growthBound(data.change_percent) === 'at_least'
                ? { type: 'at_least', min: target, max: null }
                : { type: 'at_most', min: null, max: target };
        }
        default:
            return null;
    }
}

/**
 * The rule as the KPI page will state it — «Målet er nådd når verdien er minst NOK 1 300 000.» —
 * with the growth sentence for a målverdi set from a startverdi. Null while the målverdi is not
 * complete.
 */
export function targetRuleText(data, tr = {}, locale = 'no', rules = {}) {
    const target = resolvedTarget(data, rules);

    if (! target || ! data.unit) {
        return null;
    }

    const amount = (value) => formatKpiAmount(value, data, tr, locale);
    const rule = tr.rule ?? {};
    const fallback = {
        at_least: 'Målet er nådd når verdien er minst :value.',
        at_most: 'Målet er nådd når verdien er høyst :value.',
        range: 'Målet er nådd når verdien er mellom :min og :max.',
        exact: 'Målet er nådd når verdien er nøyaktig :value.',
    };
    const sentence = (rule[target.type] ?? fallback[target.type])
        .replace(':value', amount(target.min ?? target.max))
        .replace(':min', amount(target.min ?? ''))
        .replace(':max', amount(target.max ?? ''));

    if (data.target_type !== 'growth') {
        return sentence;
    }

    const decrease = growthBound(data.change_percent) === 'at_most';
    const change = String(data.change_percent).replace('-', '');
    const growth = (tr.growth_sentence?.[decrease ? 'decrease' : 'increase']
        ?? (decrease ? 'Det er en reduksjon på :change fra startverdien :baseline (:date).' : 'Det er en økning på :change fra startverdien :baseline (:date).'))
        .replace(':change', formatKpiAmount(change, { unit: 'percent' }, tr, locale))
        .replace(':baseline', amount(data.baseline_value))
        .replace(':date', data.baseline_date ? formatLongDate(`${data.baseline_date}T12:00:00`, locale) : '');

    return `${sentence} ${growth}`;
}

/** What the tolerance means for the chosen måltype, or null without one. */
export function toleranceRuleText(data, tr = {}, locale = 'no', rules = {}) {
    const tolerance = decimal(data.tolerance);
    const target = resolvedTarget(data, rules);

    if (! tolerance || tolerance.digits <= 0n || ! target || ! data.unit) {
        return null;
    }

    const side = target.type === 'at_least' ? 'below' : (target.type === 'at_most' ? 'above' : 'outside');
    const fallback = {
        below: 'Inntil :tolerance under målet gir «Trenger oppmerksomhet».',
        above: 'Inntil :tolerance over målet gir «Trenger oppmerksomhet».',
        outside: 'Inntil :tolerance utenfor målet gir «Trenger oppmerksomhet».',
    };

    return (tr.tolerance_rule?.[side] ?? fallback[side]).replace(':tolerance', formatToleranceAmount(data.tolerance, data, tr, locale));
}
