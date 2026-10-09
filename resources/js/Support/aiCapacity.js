/**
 * Presentation logic for the shared AI capacity (AI units) on the subscription page.
 *
 * The backend (CustomerAiCapacityService) owns every number and the status; this module only
 * decides how they read. It never sees tokens, models or money — the payload does not carry them.
 */

export const CAPACITY_STATUS = {
    NORMAL: 'normal',
    WARNING: 'warning',
    EXHAUSTED: 'exhausted',
    NOT_CONFIGURED: 'not_configured',
};

const TONES = {
    [CAPACITY_STATUS.NORMAL]: 'green',
    [CAPACITY_STATUS.WARNING]: 'amber',
    [CAPACITY_STATUS.EXHAUSTED]: 'rose',
};

const BAR_CLASSES = {
    [CAPACITY_STATUS.NORMAL]: 'bg-emerald-500',
    [CAPACITY_STATUS.WARNING]: 'bg-amber-500',
    [CAPACITY_STATUS.EXHAUSTED]: 'bg-rose-500',
};

const STATUS_FALLBACKS = {
    [CAPACITY_STATUS.NORMAL]: 'God kapasitet',
    [CAPACITY_STATUS.WARNING]: 'Nærmer seg grensen',
    [CAPACITY_STATUS.EXHAUSTED]: 'Brukt opp',
};

export function interpolate(template, replacements = {}) {
    if (typeof template !== 'string') {
        return '';
    }

    return Object.entries(replacements).reduce(
        (text, [key, value]) => text.split(`:${key}`).join(String(value)),
        template,
    );
}

export function isConfigured(capacity) {
    return Boolean(capacity?.is_configured);
}

export function statusKey(capacity) {
    if (!isConfigured(capacity)) {
        return CAPACITY_STATUS.NOT_CONFIGURED;
    }

    return Object.values(CAPACITY_STATUS).includes(capacity?.status) ? capacity.status : CAPACITY_STATUS.NORMAL;
}

export function statusLabel(capacity, texts = {}) {
    const key = statusKey(capacity);

    return texts.statuses?.[key] ?? STATUS_FALLBACKS[key] ?? '';
}

export function statusTone(capacity) {
    return TONES[statusKey(capacity)] ?? 'slate';
}

export function barClass(capacity) {
    return BAR_CLASSES[statusKey(capacity)] ?? 'bg-slate-400';
}

/** 0–100, for the bar width and its accessible value. */
export function percentage(capacity) {
    const value = Number(capacity?.percentage_used);

    return Number.isFinite(value) ? Math.min(100, Math.max(0, Math.round(value))) : 0;
}

/** Whole units with the locale's grouping: 3200 → "3 200" in Norwegian. */
export function formatUnits(value, locale = 'nb-NO') {
    const number = Number(value);

    return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(Number.isFinite(number) ? number : 0);
}

export function headline(capacity, texts = {}, locale = 'nb-NO') {
    return interpolate(texts.headline ?? ':used av :included AI-enheter brukt', {
        used: formatUnits(capacity?.used, locale),
        included: formatUnits(capacity?.included, locale),
    });
}

export function progressLabel(capacity, texts = {}) {
    return interpolate(texts.progress_label ?? ':percent % av AI-kapasiteten er brukt', { percent: percentage(capacity) });
}

function parseDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);

    // Noon UTC, formatted in UTC: the calendar date can never slide a day in any viewer timezone.
    return new Date(Date.UTC(year, month - 1, day, 12));
}

/** "1.–31. oktober 2026" — the period's first and last day, as the locale writes a range. */
export function periodLabel(capacity, locale = 'nb-NO') {
    const start = parseDate(capacity?.period_start);
    const end = parseDate(capacity?.period_end);

    if (!start || !end) {
        return '';
    }

    const format = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });

    return typeof format.formatRange === 'function'
        ? format.formatRange(start, end)
        : `${format.format(start)} – ${format.format(end)}`;
}

export function nextPeriodLabel(capacity, locale = 'nb-NO') {
    const date = parseDate(capacity?.next_period_start);

    return date
        ? new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(date)
        : '';
}

export function reservationNotice(capacity, texts = {}, locale = 'nb-NO') {
    if (!capacity?.shows_reservation) {
        return null;
    }

    return interpolate(texts.reserved_notice ?? ':reserved enheter er midlertidig reservert av AI-arbeid som pågår.', {
        reserved: formatUnits(capacity.reserved, locale),
    });
}

export function exhaustedNotice(capacity, texts = {}, locale = 'nb-NO') {
    if (statusKey(capacity) !== CAPACITY_STATUS.EXHAUSTED) {
        return null;
    }

    return interpolate(texts.exhausted_notice ?? 'AI-kapasiteten for denne perioden er brukt opp. Ny kapasitet blir tilgjengelig :date.', {
        date: nextPeriodLabel(capacity, locale),
    });
}

/**
 * Whether the customer may choose its level: only while the level is what sizes the capacity (not
 * under a Procynia-set override) and there is more than one level to choose between.
 */
export function canChangeLevel(capacity, levels = []) {
    return isConfigured(capacity) && Boolean(capacity?.level_changeable) && Array.isArray(levels) && levels.length > 1;
}

/** "3 600 AI-enheter" — what one level would include for this customer, units only. */
export function levelUnitsLabel(level, texts = {}, locale = 'nb-NO') {
    return interpolate(texts.level_units ?? ':units AI-enheter', { units: formatUnits(level?.included, locale) });
}

/** The short customer-facing text for a level ("Standard", "Mer kapasitet"), or null. */
export function levelDescription(level, texts = {}) {
    return texts.level_descriptions?.[level?.key] ?? null;
}
