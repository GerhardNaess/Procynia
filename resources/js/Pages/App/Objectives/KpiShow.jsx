import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import KpiForm, { initialKpiData } from './KpiForm';
import ObjectiveHistory from './ObjectiveHistory';
import { KpiReopenForm, KpiRetireForm } from './KpiStatusForms';
import { KPI_STATUS_TONES, responsibleLabel } from './kpiStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

function Detail({ label, children }) {
    return (
        <div>
            <dt className="text-sm font-semibold text-slate-600">{label}</dt>
            <dd className="mt-1 text-base text-slate-900">{children}</dd>
        </div>
    );
}

/**
 * One KPI, under its objective. Every action is offered only when the server said this person may
 * do it; the controller refuses it otherwise. An active KPI can be edited and retired; a retired one
 * can only be reopened (or deleted). There is no measurement here yet, and nothing pretends there is.
 */
export default function KpiShow() {
    const {
        translations = {},
        kpi,
        objective,
        status_history: statusHistory = [],
        permissions = {},
        form_options: formOptions = null,
        locale = 'no',
    } = usePage().props;

    const objectiveTr = translations?.objectives ?? {};
    const tr = { ...objectiveTr, ...(objectiveTr.kpi ?? {}) };
    const statusLabels = tr.statuses ?? {};
    const frequencyLabels = tr.frequencies ?? {};
    const baseUrl = `/app/objectives/${objective.id}/kpis/${kpi.id}`;
    // Which panel is open: 'edit', 'retire', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);

    const form = useForm(initialKpiData(kpi, formOptions ?? {}));

    const submit = (event) => {
        event.preventDefault();
        form.patch(baseUrl, { preserveScroll: true, onSuccess: () => setPanel(null) });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett KPI-en? Dette kan ikke angres.')) {
            return;
        }

        router.delete(baseUrl);
    };

    const describeChange = (entry, who) => (entry.to_status === 'active'
        ? (tr.history_reopened ?? 'Gjenåpnet av :name')
        : (tr.history_retired ?? 'Avsluttet av :name')).replace(':name', who);

    return (
        <CustomerAppLayout title={kpi.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href={objective.url} className="text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {tr.back ?? 'Til målet'}
                </Link>

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">{tr.col_title ?? 'KPI'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{kpi.title}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-semibold text-slate-600">{tr.status_label ?? 'Status'}:</span>
                            <StatusBadge tone={KPI_STATUS_TONES[kpi.status] ?? 'slate'}>{statusLabels[kpi.status] ?? kpi.status}</StatusBadge>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {permissions.can_edit && panel === null && (
                            <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>{tr.edit ?? 'Rediger'}</button>
                        )}
                        {permissions.can_retire && panel === null && (
                            <button type="button" onClick={() => setPanel('retire')} className={SECONDARY_ACTION}>{tr.retire ?? 'Avslutt KPI'}</button>
                        )}
                        {permissions.can_reopen && panel === null && (
                            <button type="button" onClick={() => setPanel('reopen')} className={SECONDARY_ACTION}>{tr.reopen ?? 'Gjenåpne'}</button>
                        )}
                        {permissions.can_delete && (
                            <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{tr.delete ?? 'Slett KPI'}</button>
                        )}
                    </div>
                </header>

                {panel === 'retire' && <KpiRetireForm baseUrl={baseUrl} onDone={() => setPanel(null)} tr={tr} />}
                {panel === 'reopen' && <KpiReopenForm baseUrl={baseUrl} onDone={() => setPanel(null)} tr={tr} />}

                {panel === 'edit' && formOptions ? (
                    <section className={CARD}>
                        <h2 className="mb-4 text-lg font-semibold text-slate-950">{tr.edit_heading ?? 'Rediger KPI'}</h2>
                        <KpiForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { setPanel(null); form.reset(); form.clearErrors(); }}
                            options={formOptions}
                            tr={tr}
                        />
                    </section>
                ) : (
                    <section className={CARD}>
                        <h2 className="text-lg font-semibold text-slate-950">{tr.what_heading ?? 'Hva måles'}</h2>
                        {kpi.description ? (
                            <p className="mt-2 whitespace-pre-line text-base leading-7 text-slate-800">{kpi.description}</p>
                        ) : (
                            <p className="mt-2 text-base text-slate-500">{tr.no_description ?? 'Ingen beskrivelse.'}</p>
                        )}

                        <h2 className="mt-6 text-lg font-semibold text-slate-950">{tr.details ?? 'Detaljer'}</h2>
                        <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="kpi-details">
                            <Detail label={tr.detail_target ?? 'Målverdi'}>
                                <span className="whitespace-nowrap font-semibold">{kpi.target_display}</span>
                            </Detail>
                            <Detail label={tr.detail_tolerance ?? 'Slingringsmonn'}>
                                {kpi.tolerance_display ?? (tr.tolerance_none ?? 'Ingen')}
                            </Detail>
                            <Detail label={tr.detail_unit ?? 'Enhet'}>{kpi.unit_display}</Detail>
                            <Detail label={tr.detail_frequency ?? 'Frekvens'}>
                                {kpi.frequency ? (frequencyLabels[kpi.frequency] ?? kpi.frequency) : (tr.no_frequency ?? 'Ingen fast frekvens')}
                            </Detail>
                            <Detail label={tr.detail_deadline ?? 'Innrapporteringsfrist'}>{kpi.deadline_display}</Detail>
                            <Detail label={tr.detail_responsible ?? 'Ansvarlig'}>
                                {responsibleLabel(kpi, tr) ?? <span className="text-amber-700">{tr.no_responsible ?? 'Mangler ansvarlig'}</span>}
                            </Detail>
                            <Detail label={tr.detail_objective ?? 'Tilhørende mål'}>
                                <Link href={objective.url} className="font-semibold text-violet-700 hover:text-violet-900">{objective.title}</Link>
                                {objective.area_name && <span className="block text-sm text-slate-500">{objective.area_name}</span>}
                            </Detail>
                        </dl>
                    </section>
                )}

                <ObjectiveHistory
                    entries={statusHistory}
                    locale={locale}
                    tr={tr}
                    describe={describeChange}
                    testId="kpi-history"
                />
            </div>
        </CustomerAppLayout>
    );
}
