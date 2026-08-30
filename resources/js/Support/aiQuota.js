/**
 * Presentation logic for the commercial AI-case quota.
 *
 * The backend owns every number here; this module only decides how they read. It is kept separate
 * from the components so the wording and status rules can be tested without a DOM, and so the
 * billing page and the AI workspace can never drift apart in how they describe the same state.
 */

export const QUOTA_STATUS = {
    NORMAL: 'normal',
    WARNING: 'warning',
    CRITICAL: 'critical',
    EXHAUSTED: 'exhausted',
    SUSPENDED: 'suspended',
};

const TONES = {
    [QUOTA_STATUS.NORMAL]: 'green',
    [QUOTA_STATUS.WARNING]: 'amber',
    [QUOTA_STATUS.CRITICAL]: 'amber',
    [QUOTA_STATUS.EXHAUSTED]: 'rose',
    [QUOTA_STATUS.SUSPENDED]: 'rose',
};

const BAR_CLASSES = {
    [QUOTA_STATUS.NORMAL]: 'bg-emerald-500',
    [QUOTA_STATUS.WARNING]: 'bg-amber-500',
    [QUOTA_STATUS.CRITICAL]: 'bg-amber-600',
    [QUOTA_STATUS.EXHAUSTED]: 'bg-rose-500',
    [QUOTA_STATUS.SUSPENDED]: 'bg-slate-400',
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

export function isUnlimited(quota) {
    return Boolean(quota?.is_unlimited);
}

export function isNotIncluded(quota) {
    return quota?.quota_type === 'none';
}

export function isSuspended(quota) {
    return Boolean(quota?.is_suspended);
}

export function statusKey(quota) {
    const status = quota?.status;
    return Object.values(QUOTA_STATUS).includes(status) ? status : QUOTA_STATUS.NORMAL;
}

export function statusTone(quota) {
    return TONES[statusKey(quota)] ?? 'slate';
}

export function barClass(quota) {
    return BAR_CLASSES[statusKey(quota)] ?? 'bg-slate-400';
}

/**
 * An unlimited plan has no meaningful bar: rendering a full one would say the opposite of the
 * truth, and an empty one would imply a limit exists. Such a plan gets no bar at all.
 */
export function showsProgressBar(quota) {
    return !isUnlimited(quota) && !isNotIncluded(quota) && Number.isFinite(Number(quota?.percentage_used));
}

export function percentage(quota) {
    const value = Number(quota?.percentage_used);
    if (!Number.isFinite(value)) {
        return 0;
    }
    return Math.max(0, Math.min(100, Math.round(value)));
}

export function allowance(quota) {
    const value = Number(quota?.allowance);
    return Number.isFinite(value) ? value : 0;
}

/** What the customer has spent, including cases whose first AI run is still in flight. */
export function consumed(quota) {
    const used = Number(quota?.used);
    const reserved = Number(quota?.reserved);
    return (Number.isFinite(used) ? used : 0) + (Number.isFinite(reserved) ? reserved : 0);
}

export function remaining(quota) {
    const value = Number(quota?.remaining);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
}

export function statusLabel(quota, texts = {}) {
    return texts?.statuses?.[statusKey(quota)] ?? '';
}

export function statusDescription(quota, texts = {}) {
    return texts?.status_descriptions?.[statusKey(quota)] ?? '';
}

/** The headline for the billing card and the one-line workspace indicator. */
export function headline(quota, texts = {}) {
    if (isNotIncluded(quota)) {
        return texts.not_included ?? '';
    }

    if (isUnlimited(quota)) {
        return texts.unlimited_description ?? texts.unlimited ?? '';
    }

    return interpolate(texts.used_of, {
        used: consumed(quota),
        allowance: allowance(quota),
    });
}

export function compactLabel(quota, texts = {}) {
    if (isNotIncluded(quota)) {
        return texts.not_included ?? '';
    }

    if (isUnlimited(quota)) {
        return texts.compact_unlimited ?? '';
    }

    return interpolate(texts.compact, {
        used: consumed(quota),
        allowance: allowance(quota),
    });
}

export function remainingLabel(quota, texts = {}) {
    if (isUnlimited(quota) || isNotIncluded(quota)) {
        return '';
    }

    return interpolate(texts.remaining, { remaining: remaining(quota) });
}

/**
 * The bar is decorative; this is what a screen reader and a colour-blind reader actually get.
 */
export function progressAccessibleLabel(quota, texts = {}) {
    return interpolate(texts.progress_label, { percent: percentage(quota) });
}

export function periodLabel(quota, texts = {}, locale = 'nb-NO') {
    if (!quota?.period_start || !quota?.period_end) {
        return '';
    }

    return interpolate(texts.period_range, {
        start: formatDate(quota.period_start, locale),
        end: formatDate(quota.period_end, locale),
    });
}

export function formatDate(value, locale = 'nb-NO') {
    if (typeof value !== 'string' || value === '') {
        return '';
    }

    const parsed = new Date(`${value}T00:00:00Z`);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    try {
        return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(parsed);
    } catch {
        return value;
    }
}

/** The day after the period ends — when finite capacity becomes available again. */
export function nextResetLabel(quota, locale = 'nb-NO') {
    if (!quota?.period_end || isUnlimited(quota) || isNotIncluded(quota)) {
        return '';
    }

    const parsed = new Date(`${quota.period_end}T00:00:00Z`);
    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    parsed.setUTCDate(parsed.getUTCDate() + 1);
    return formatDate(parsed.toISOString().slice(0, 10), locale);
}
