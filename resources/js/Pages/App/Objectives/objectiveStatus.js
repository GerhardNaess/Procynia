export const OBJECTIVE_STATUS_TONES = {
    active: 'blue',
    achieved: 'emerald',
    not_achieved: 'amber',
    cancelled: 'slate',
};

/**
 * A target date as the page shows it: the day itself, or «Løpende» for an objective without one.
 *
 * @param {string|null} value  Y-m-d
 * @param {string} runningLabel
 */
export function formatTargetDate(value, runningLabel) {
    if (! value) {
        return runningLabel;
    }

    const [year, month, day] = value.split('-');

    return `${day}.${month}.${year}`;
}

/**
 * A moment as a long date in the person's language: «5. oktober 2026» / «5 October 2026».
 *
 * @param {string|null} iso
 * @param {string} locale  The app locale shared by the backend ('no', 'en').
 */
export function formatLongDate(iso, locale = 'no') {
    if (! iso) {
        return '';
    }

    const tag = String(locale).toLowerCase().startsWith('en') ? 'en-GB' : 'nb-NO';

    return new Date(iso).toLocaleDateString(tag, { day: 'numeric', month: 'long', year: 'numeric' });
}
