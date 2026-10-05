export const KPI_STATUS_TONES = {
    active: 'blue',
    retired: 'slate',
};

/**
 * Who answers for a KPI, as a sentence fragment: its own owner, or the objective's owner marked as
 * such. The server decides which; this only words it.
 *
 * @param {{responsible_name: string|null, responsible_is_fallback: boolean}} kpi
 * @param {object} tr  translations.objectives.kpi
 */
export function responsibleLabel(kpi, tr) {
    if (! kpi.responsible_name) {
        return null;
    }

    return kpi.responsible_is_fallback
        ? (tr.responsible_fallback ?? ':name (målets ansvarlig)').replace(':name', kpi.responsible_name)
        : kpi.responsible_name;
}

/**
 * Badge tones for a KPI's result, from KpiTargetPolicy through the server, plus «Ikke målt».
 */
export const KPI_RESULT_TONES = {
    on_target: 'emerald',
    attention: 'amber',
    off_target: 'rose',
    not_measured: 'slate',
};

/**
 * The value already registered for what the measurement form has chosen — the period key, or the
 * date for a KPI without frequency — or null. When there is one, the new measurement is a
 * correction and needs a comment. The server decides the same again.
 *
 * @param {object|null} measurementForm  measurement_form from the server
 * @param {{period?: string, measured_on?: string}} data
 */
export function existingValueFor(measurementForm, data) {
    if (! measurementForm) {
        return null;
    }

    if (measurementForm.mode === 'date') {
        return measurementForm.existing_by_date?.[data.measured_on] ?? null;
    }

    return (measurementForm.period_options ?? []).find((option) => option.key === data.period)?.current_value_display ?? null;
}

/**
 * «2 av 3 KPI-er på mål», or null when the objective has no active KPI. A count, never a score.
 *
 * @param {{on_target: number, total: number}|null} indicator
 * @param {object} tr  translations.objectives.kpi
 */
export function indicatorLabel(indicator, tr) {
    if (! indicator) {
        return null;
    }

    return (tr.indicator ?? ':on av :total KPI-er på mål')
        .replace(':on', String(indicator.on_target))
        .replace(':total', String(indicator.total));
}
