import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import EmptyStateBox from '../../../Components/App/EmptyStateBox';
import { SECONDARY_ACTION } from '../../../Support/actionStyles';
import KpiForm, { initialKpiData } from './KpiForm';
import { KPI_STATUS_TONES, responsibleLabel } from './kpiStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * KPI-er on the objective page: what is measured, against which target, how often and by whom.
 * No measured value, target status or next period — there are no measurements yet, and the list
 * shows nothing it cannot back. Ny KPI is offered only when the server says this person may edit
 * the objective and it is active.
 */
export default function ObjectiveKpis({ objective, kpis = [], formOptions = null, canCreate = false, tr }) {
    const [creating, setCreating] = useState(false);
    const statusLabels = tr.statuses ?? {};
    const frequencyLabels = tr.frequencies ?? {};
    const form = useForm(initialKpiData(null, formOptions ?? {}));

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/objectives/${objective.id}/kpis`, { preserveScroll: true });
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
                </div>
                {canCreate && ! creating && (
                    <button type="button" onClick={() => setCreating(true)} className={SECONDARY_ACTION}>
                        {tr.create ?? 'Ny KPI'}
                    </button>
                )}
            </div>

            {objective.status !== 'active' && (
                <p className="mt-3 text-sm text-slate-600">{tr.objective_closed_note ?? 'KPI-er legges til og endres mens målet er aktivt. Gjenåpne målet for å endre dem.'}</p>
            )}

            {creating && formOptions && (
                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <h3 className="mb-3 text-base font-semibold text-slate-900">{tr.create_heading ?? 'Ny KPI'}</h3>
                    <KpiForm form={form} onSubmit={submit} onCancel={cancel} options={formOptions} tr={tr} />
                </div>
            )}

            {kpis.length === 0 ? (
                ! creating && <EmptyStateBox className="mt-4" title={tr.empty ?? 'Ingen KPI-er er registrert for dette målet ennå.'} />
            ) : (
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-base" data-testid="objective-kpis">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-sm font-semibold text-slate-600">
                                <th className="pb-3 pr-4">{tr.col_title ?? 'KPI'}</th>
                                <th className="px-4 pb-3">{tr.col_target ?? 'Målverdi'}</th>
                                <th className="px-4 pb-3">{tr.col_unit ?? 'Enhet'}</th>
                                <th className="px-4 pb-3">{tr.col_frequency ?? 'Frekvens'}</th>
                                <th className="px-4 pb-3">{tr.col_responsible ?? 'Ansvarlig'}</th>
                                <th className="pb-3 pl-4">{tr.col_status ?? 'Status'}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {kpis.map((kpi) => (
                                <tr key={kpi.id}>
                                    <td className="py-3 pr-4">
                                        <Link href={kpi.url} className="font-semibold text-violet-700 hover:text-violet-900">{kpi.title}</Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-900">{kpi.target_display}</td>
                                    <td className="px-4 py-3 text-slate-700">{kpi.unit_display}</td>
                                    <td className="px-4 py-3 text-slate-700">
                                        {kpi.frequency ? (frequencyLabels[kpi.frequency] ?? kpi.frequency) : (tr.no_frequency ?? 'Ingen fast frekvens')}
                                    </td>
                                    <td className="px-4 py-3 text-slate-700">
                                        {responsibleLabel(kpi, tr) ?? <span className="text-amber-700">{tr.no_responsible ?? 'Mangler ansvarlig'}</span>}
                                    </td>
                                    <td className="py-3 pl-4">
                                        <StatusBadge tone={KPI_STATUS_TONES[kpi.status] ?? 'slate'}>{statusLabels[kpi.status] ?? kpi.status}</StatusBadge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
