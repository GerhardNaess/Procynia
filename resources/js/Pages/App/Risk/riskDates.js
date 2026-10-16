/**
 * Dates in Risiko in the person's language (WCAG 3.1.2, A11Y-172). They were formatted with a fixed
 * 'nb-NO', so an English page said «4. oktober 2026» and «4.10.2026, 14:05:00» — Norwegian text in
 * an English document. The locale is the app locale the backend shares ('no', 'en'); anything that is
 * not English stays Norwegian, as before.
 */

/** The BCP 47 tag for the app locale. Pure. */
export function dateTag(locale) {
    return String(locale ?? '').toLowerCase().startsWith('en') ? 'en-GB' : 'nb-NO';
}

/** A moment as a date: «4.10.2026» / «04/10/2026». */
export function formatRiskDate(value, locale) {
    return value ? new Date(value).toLocaleDateString(dateTag(locale)) : '—';
}

/** A calendar day ("2026-10-04") as a date, read at local midnight so no time zone shifts it. */
export function formatRiskDay(value, locale) {
    return value ? new Date(`${value}T00:00:00`).toLocaleDateString(dateTag(locale)) : '—';
}

/** A moment with its time: «4.10.2026, 14:05:00» / «04/10/2026, 14:05:00». */
export function formatRiskMoment(value, locale) {
    return value ? new Date(value).toLocaleString(dateTag(locale)) : '—';
}

/** A calendar day as a long date: «4. oktober 2026» / «4 October 2026». Null for no day. */
export function formatRiskLongDay(day, locale) {
    if (! day) {
        return null;
    }

    const [year, month, date] = day.split('-').map(Number);

    return new Date(year, month - 1, date).toLocaleDateString(dateTag(locale), { day: 'numeric', month: 'long', year: 'numeric' });
}
