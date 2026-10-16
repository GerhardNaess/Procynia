import { useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import ScrollRegion from '../../../Components/App/ScrollRegion';
import { SECONDARY_ACTION } from '../../../Support/actionStyles';
import { usePanelFocus } from '../../../Support/focusManagement';
import KpiForm, { initialKpiData } from './KpiForm';
import { KPI_RESULT_TONES, KPI_STATUS_TONES, indicatorLabel, responsibleLabel } from './kpiStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * KPI-er on the objective page: what is measured, against which target, the latest value and where
 * it stands against today's target, how often and by whom. Above the list, «2 av 3 KPI-er på mål»
 * — a count of active KPIs, never a score. Ny KPI is offered only when the server says this person
 * may edit the objective and it is active.
 */
export default function ObjectiveKpis({ objective, kpis = [], indicator = null, formOptions = null, canCreate = false, tr, results = {} }) {
    const [creating, setCreating] = useState(false);
    const statusLabels = tr.statuses ?? {};
    const indicatorText = indicatorLabel(indicator, tr);
    const frequencyLabels = tr.frequencies ?? {};
    const form = useForm(initialKpiData(null, formOptions ?? {}));
    // Ny KPI opens in place: focus to its first field, back to the button on Avbryt (A11Y-014).
    const panelRef = useRef(null);
    const createButtonRef = useRef(null);
    usePanelFocus(creating, { panelRef, returnFocusRef: createButtonRef });

    const submit = (event, focusFirstError) => {
        event.preventDefault();
        form.post(`/app/objectives/${objective.id}/kpis`, { preserveScroll: true, onError: focusFirstError });
    };

    const cancel = () => {
        setCreating(false);
        form.reset();
        form.clearErrors();
    };

    return (
        <section className={CARD} aria-labelledby="objective-kpis-heading">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="objective-kpis-heading" className="text-lg font-semibold text-slate-950">{tr.section_heading ?? 'KPI-er'}</h2>
                    <p className="mt-1 text-base text-slate-600">{tr.section_intro ?? 'Hvordan fremdriften mot målet måles.'}</p>
                    {indicatorText && (
                        <p className="mt-2 text-base font-semibold text-slate-900" data-testid="objective-kpi-indicator">{indicatorText}</p>
                    )}
                </div>
                {canCreate && ! creating && (
                    <button type="button" ref={createButtonRef} onClick={() => setCreating(true)} className={SECONDARY_ACTION}>
                        {tr.create ?? 'Ny KPI'}
                    </button>
                )}
            </div>

            {objective.status !== 'active' && (
                <p className="mt-3 text-base text-slate-600">{tr.objective_closed_note ?? 'KPI-er legges til og endres mens målet er aktivt. Gjenåpne målet for å endre dem.'}</p>
            )}

            {creating && formOptions && (
                <div ref={panelRef} className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4" role="group" aria-labelledby="objective-kpi-create-heading">
                    <h3 id="objective-kpi-create-heading" className="mb-3 text-base font-semibold text-slate-900">{tr.create_heading ?? 'Ny KPI'}</h3>
                    <KpiForm form={form} onSubmit={submit} onCancel={cancel} options={formOptions} tr={tr} />
                </div>
            )}

            {kpis.length === 0 ? (
                ! creating && <EmptyStateBox className="mt-4" title={tr.empty ?? 'Ingen KPI-er er registrert for dette målet ennå.'} />
            ) : (
                <ScrollRegion label={tr.section_heading ?? 'KPI-er'} className="mt-4">
                    <table className="w-full text-base" data-testid="objective-kpis">
                        <caption className="sr-only">{tr.section_heading ?? 'KPI-er'}</caption>
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-base font-semibold text-slate-600">
                                <th scope="col" className="pb-3 pr-4">{tr.col_title ?? 'KPI'}</th>
                                <th scope="col" className="px-4 pb-3">{tr.col_target ?? 'Målverdi'}</th>
                                <th scope="col" className="px-4 pb-3">{tr.col_latest ?? 'Siste verdi'}</th>
                                <th scope="col" className="px-4 pb-3">{tr.col_result ?? 'Dagens status'}</th>
                                <th scope="col" className="px-4 pb-3">{tr.col_frequency ?? 'Målefrekvens'}</th>
                                <th scope="col" className="pb-3 pl-4">{tr.col_responsible ?? 'Ansvarlig'}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {kpis.map((kpi) => (
                                <tr key={kpi.id}>
                                    <th scope="row" className="py-3 pr-4 text-left font-normal">
                                        <Link href={kpi.url} className="font-semibold text-violet-700 hover:text-violet-900">{kpi.title}</Link>
                                        {kpi.status !== 'active' && (
                                            <StatusBadge tone={KPI_STATUS_TONES[kpi.status] ?? 'slate'} className="ml-2">{statusLabels[kpi.status] ?? kpi.status}</StatusBadge>
                                        )}
                                    </th>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-900">{kpi.target_display}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-900">
                                        {kpi.latest_value_display ?? '—'}
                                        {kpi.latest_period_label && <span className="block text-base text-slate-600">{kpi.latest_period_label}</span>}
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge tone={KPI_RESULT_TONES[kpi.result] ?? 'slate'}>{results[kpi.result] ?? kpi.result}</StatusBadge>
                                    </td>
                                    <td className="px-4 py-3 text-slate-700">
                                        {kpi.frequency ? (frequencyLabels[kpi.frequency] ?? kpi.frequency) : (tr.no_frequency ?? 'Ingen fast målefrekvens')}
                                    </td>
                                    <td className="py-3 pl-4 text-slate-700">
                                        {responsibleLabel(kpi, tr) ?? <span className="text-amber-700">{tr.no_responsible ?? 'Mangler ansvarlig'}</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </ScrollRegion>
            )}
        </section>
    );
}
