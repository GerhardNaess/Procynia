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
