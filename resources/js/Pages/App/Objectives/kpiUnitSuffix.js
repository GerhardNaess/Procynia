/**
 * The unit a KPI's number field shows inside it, to the right of the value: «%», «kr», «stk.»,
 * «timer». Presentation only — the suffix is never part of the value the person edits or the form
 * sends, so the stored numbers are exactly what was typed.
 *
 * Follows the units the KPI model has (percent, count, number, currency, hours, days):
 *  - percent:  «%»
 *  - currency: «kr» for NOK, otherwise the currency code («EUR»)
 *  - count:    the KPI's own unit label («hendelser»), else «stk.»
 *  - number:   the KPI's own unit label, else nothing
 *  - hours, days: «time»/«timer», «dag»/«dager» — the singular only for exactly 1
 *
 * @param {{unit?: string, unit_label?: string|null, currency_code?: string|null}} definition
 *        the unit as the form holds it, or the KPI from the server
 * @param {object} tr     translations.objectives.kpi (unit_suffix, input_suffix)
 * @param {string} value  the field's current text, for «1 time» against «2 timer»
 * @returns {string|null} null when the unit has no suffix
 */
export function kpiUnitSuffix(definition, tr = {}, value = '') {
    const unit = definition?.unit ?? '';
    const label = String(definition?.unit_label ?? '').trim();
    const inputSuffix = tr.input_suffix ?? {};

    switch (unit) {
        case 'percent':
            return '%';
        case 'currency': {
            const code = String(definition?.currency_code ?? '').trim().toUpperCase();

            if (code === '') {
                return null;
            }

            return code === 'NOK' ? (inputSuffix.nok ?? 'kr') : code;
        }
        case 'count':
            return label !== '' ? label : (inputSuffix.count ?? 'stk.');
        case 'number':
            return label !== '' ? label : null;
        case 'hours':
        case 'days': {
            const forms = tr.unit_suffix?.[unit] ?? {};
            const fallback = unit === 'hours' ? { one: 'time', other: 'timer' } : { one: 'dag', other: 'dager' };

            return isExactlyOne(value) ? (forms.one ?? fallback.one) : (forms.other ?? fallback.other);
        }
        default:
            return null;
    }
}

/** Whether the typed text reads as exactly 1 («1», «1,0», «1.00»), the way the server reads it. */
function isExactlyOne(value) {
    const plain = String(value ?? '').replace(/[\s ]/g, '').replace(',', '.');

    return /^\+?0*1(\.0*)?$/.test(plain);
}
