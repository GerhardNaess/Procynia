import { Fragment, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION, WARNING_COLOURS } from '../../../Support/actionStyles';
import KpiContextPanel from './KpiContextPanel';
import KpiForm, { initialKpiData } from './KpiForm';
import { KpiMeasurementForm, KpiWithdrawForm } from './KpiMeasurementForms';
import ObjectiveHistory from './ObjectiveHistory';
import { KpiReopenForm, KpiRetireForm } from './KpiStatusForms';
import { KPI_RESULT_TONES, KPI_STATUS_TONES, responsibleLabel } from './kpiStatus';
import { formatLongDate } from './objectiveStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
// A row action in a dense table: the warning colours, at the size of a badge.
const ROW_WARNING_ACTION = `inline-flex items-center whitespace-nowrap rounded-full px-3 py-1.5 text-base font-semibold transition ${WARNING_COLOURS}`;

function Detail({ label, children }) {
    return (
        <div>
            <dt className="text-base font-semibold text-slate-600">{label}</dt>
            <dd className="mt-1 text-base text-slate-900">{children}</dd>
        </div>
    );
}

/**
 * When the next measurement is due, as one line: the oldest missing period (calmly, in amber),
 * the period now within its grace days, or the running one. The server computes all of it.
 */
function NextMeasurement({ kpi, tm }) {
    const schedule = kpi.schedule ?? {};

    if (! kpi.frequency) {
        return tm.no_schedule ?? 'Ingen fast målefrekvens – registreres ved behov';
    }

    if (schedule.measurement_missing) {
        return (
            <span className="text-amber-800" data-testid="kpi-measurement-missing">
                <span className="font-semibold">{(tm.missing ?? 'Måling mangler for :period').replace(':period', schedule.missing_label)}</span>
                {schedule.missing_count_display && <span className="block text-base">{schedule.missing_count_display}</span>}
            </span>
        );
    }

    if (schedule.pending_label) {
        return (tm.pending ?? ':period, frist :date').replace(':period', schedule.pending_label).replace(':date', schedule.pending_deadline);
    }

    if (schedule.upcoming_label) {
        return (tm.upcoming ?? ':period, kan registreres fra :date').replace(':period', schedule.upcoming_label).replace(':date', schedule.upcoming_from);
    }

    return tm.not_measured_now ?? 'Tar ikke imot målinger nå';
}

/**
 * Målehistorikk: every measurement, newest period first, each judged against the target that
 * applied when it was registered. Superseded and withdrawn rows stay — they are the audit trail.
 */
function MeasurementHistory({ measurements, baseUrl, locale, tm }) {
    const [withdrawing, setWithdrawing] = useState(null);
    const results = tm.results ?? {};

    return (
        <section className={CARD} aria-labelledby="kpi-measurements-heading">
            <h2 id="kpi-measurements-heading" className="text-lg font-semibold text-slate-950">{tm.history_heading ?? 'Målehistorikk'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tm.history_intro ?? 'Hver måling vurderes mot målverdien som gjaldt da den ble registrert. Korrigeringer og tilbaketrekkinger beholdes.'}
            </p>

            {measurements.length === 0 ? (
                <p className="mt-4 text-base text-slate-500">{tm.history_empty ?? 'Ingen målinger er registrert ennå.'}</p>
            ) : (
                // relative: the header's screen-reader label is positioned, and would otherwise
                // escape this scroll box and widen the whole page on a phone.
                <div className="relative mt-4 overflow-x-auto">
                    <table className="w-full text-base" data-testid="kpi-measurements">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-base font-semibold text-slate-600">
                                <th className="pb-3 pr-3">{tm.col_period ?? 'Periode'}</th>
                                <th className="px-3 pb-3">{tm.col_value ?? 'Verdi'}</th>
                                <th className="px-3 pb-3">{tm.col_result ?? 'Status da'}</th>
                                <th className="px-3 pb-3">{tm.col_target ?? 'Målverdi da'}</th>
                                <th className="px-3 pb-3">{tm.col_recorded ?? 'Registrert'}</th>
                                <th className="px-3 pb-3">{tm.col_comment ?? 'Kommentar'}</th>
                                <th className="pb-3 pl-3"><span className="sr-only">{tm.withdraw ?? 'Trekk tilbake'}</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {measurements.map((row) => {
                                const counts = row.state === 'current';

                                return (
                                    <Fragment key={row.id}>
                                        <tr data-state={row.state} className={counts ? '' : 'text-slate-500'}>
                                            <td className="whitespace-nowrap py-3 pr-3 font-semibold">{row.period_label}</td>
                                            <td className="whitespace-nowrap px-3 py-3 align-top sm:align-middle">
                                                <span className={row.state === 'withdrawn' ? 'line-through' : (counts ? 'font-semibold text-slate-900' : '')}>
                                                    {row.value_display}
                                                </span>
                                                {row.state === 'superseded' && (
                                                    <StatusBadge tone="slate" className="mt-1 !flex w-fit">{tm.state_superseded ?? 'Erstattet'}</StatusBadge>
                                                )}
                                                {row.state === 'withdrawn' && (
                                                    <StatusBadge tone="rose" className="mt-1 !flex w-fit">{tm.state_withdrawn ?? 'Tilbaketrukket'}</StatusBadge>
                                                )}
                                            </td>
                                            <td className="px-3 py-3">
                                                <StatusBadge tone={counts ? (KPI_RESULT_TONES[row.result] ?? 'slate') : 'slate'}>
                                                    {results[row.result] ?? row.result}
                                                </StatusBadge>
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3">{row.target_display}</td>
                                            <td className="px-3 py-3 text-base">
                                                {(tm.recorded_by ?? ':date av :name')
                                                    .replace(':date', formatLongDate(row.recorded_at, locale))
                                                    .replace(':name', row.recorded_by_name ?? (tm.unknown_user ?? 'en tidligere bruker'))}
                                            </td>
                                            <td className="min-w-[10rem] px-3 py-3 text-base">
                                                {row.comment && <p className="whitespace-pre-line">{row.comment}</p>}
                                                {row.state === 'withdrawn' && (
                                                    <p className="mt-1 text-rose-700">
                                                        {(tm.withdrawn_by ?? 'Trukket tilbake :date av :name: :reason')
                                                            .replace(':date', formatLongDate(row.withdrawn_at, locale))
                                                            .replace(':name', row.withdrawn_by_name ?? (tm.unknown_user ?? 'en tidligere bruker'))
                                                            .replace(':reason', row.withdrawal_reason)}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="py-3 pl-3 text-right">
                                                {row.can_withdraw && withdrawing !== row.id && (
                                                    <button type="button" onClick={() => setWithdrawing(row.id)} className={ROW_WARNING_ACTION}>
                                                        {tm.withdraw ?? 'Trekk tilbake'}
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                        {withdrawing === row.id && (
                                            <tr>
                                                <td colSpan={7} className="pb-4">
                                                    <KpiWithdrawForm baseUrl={baseUrl} measurement={row} onDone={() => setWithdrawing(null)} tr={tm} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

/**
 * One KPI, under its objective. On top: the latest result judged against today's målverdi, and
 * when the next measurement is due. Below: what is measured, the measurement history and the
 * KPI's own status history.
 *
 * Every action is offered only when the server said this person may do it. Registrer måling takes
 * objective.measure and an active KPI under an active objective; Rediger, Avslutt and Gjenåpne take
 * objective.edit. An active KPI can be edited and retired; a retired one can only be reopened.
 */
export default function KpiShow() {
    const {
        translations = {},
        kpi,
        objective,
        measurements = [],
        measurement_form: measurementForm = null,
        status_history: statusHistory = [],
        permissions = {},
        form_options: formOptions = null,
        quality_context: qualityContext = null,
        quality_context_options: qualityContextOptions = [],
        locale = 'no',
    } = usePage().props;

    const objectiveTr = translations?.objectives ?? {};
    const tr = { ...objectiveTr, ...(objectiveTr.kpi ?? {}) };
    const tm = { ...tr, ...(objectiveTr.measurement ?? {}) };
    const statusLabels = tr.statuses ?? {};
    const frequencyLabels = tr.frequencies ?? {};
    const results = tm.results ?? {};
    const baseUrl = `/app/objectives/${objective.id}/kpis/${kpi.id}`;
    // Which panel is open: 'measure', 'edit', 'retire', 'reopen' or none. One at a time.
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
                        <p className="text-base font-semibold uppercase tracking-wide text-slate-500">{tr.col_title ?? 'KPI'}</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{kpi.title}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-base font-semibold text-slate-600">{tr.status_label ?? 'Status'}:</span>
                            <StatusBadge tone={KPI_STATUS_TONES[kpi.status] ?? 'slate'}>{statusLabels[kpi.status] ?? kpi.status}</StatusBadge>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {permissions.can_measure && measurementForm && panel === null && (
                            <button type="button" onClick={() => setPanel('measure')} className={PRIMARY_ACTION}>{tm.register ?? 'Registrer måling'}</button>
                        )}
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

                <section className={CARD} aria-labelledby="kpi-result-heading">
                    <h2 id="kpi-result-heading" className="sr-only">{tm.latest_heading ?? 'Siste resultat'}</h2>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="kpi-result">
                        <Detail label={tm.latest_value ?? 'Siste verdi'}>
                            {kpi.latest_value_display ? (
                                <>
                                    <span className="whitespace-nowrap text-2xl font-semibold" data-testid="kpi-latest-value">{kpi.latest_value_display}</span>
                                    <span className="block text-base text-slate-500">{kpi.latest_period_label}</span>
                                </>
                            ) : (
                                <span className="text-slate-500">{tm.not_measured_yet ?? 'Ingen gjeldende måling ennå.'}</span>
                            )}
                        </Detail>
                        <Detail label={tm.result_now ?? 'Dagens status'}>
                            <span data-testid="kpi-current-result">
                                <StatusBadge tone={KPI_RESULT_TONES[kpi.result] ?? 'slate'}>{results[kpi.result] ?? kpi.result}</StatusBadge>
                            </span>
                        </Detail>
                        <Detail label={tr.detail_target ?? 'Målverdi'}>
                            <span className="whitespace-nowrap font-semibold">{kpi.target_display}</span>
                        </Detail>
                        <Detail label={tr.detail_frequency ?? 'Målefrekvens'}>
                            {kpi.frequency ? (frequencyLabels[kpi.frequency] ?? kpi.frequency) : (tr.no_frequency ?? 'Ingen fast målefrekvens')}
                        </Detail>
                        <Detail label={tm.next_period ?? 'Neste måling'}>
                            <NextMeasurement kpi={kpi} tm={tm} />
                        </Detail>
                        <Detail label={tr.detail_responsible ?? 'Ansvarlig'}>
                            {responsibleLabel(kpi, tr) ?? <span className="text-amber-700">{tr.no_responsible ?? 'Mangler ansvarlig'}</span>}
                        </Detail>
                    </dl>
                </section>

                {panel === 'measure' && measurementForm && (
                    <KpiMeasurementForm baseUrl={baseUrl} kpi={kpi} options={measurementForm} onDone={() => setPanel(null)} tr={tm} />
                )}
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
                            unitLocked={Boolean(kpi.unit_locked)}
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
                            <Detail label={tr.detail_tolerance ?? 'Slingringsmonn'}>
                                {kpi.tolerance_display ?? (tr.tolerance_none ?? 'Ingen')}
                            </Detail>
                            <Detail label={tr.detail_unit ?? 'Enhet'}>{kpi.unit_display}</Detail>
                            <Detail label={tr.detail_deadline ?? 'Innrapporteringsfrist'}>{kpi.deadline_display}</Detail>
                            <Detail label={tr.detail_objective ?? 'Tilhørende mål'}>
                                <Link href={objective.url} className="font-semibold text-violet-700 hover:text-violet-900">{objective.title}</Link>
                                {objective.area_name && <span className="block text-base text-slate-500">{objective.area_name}</span>}
                            </Detail>
                        </dl>
                    </section>
                )}

                {/* null, not empty: the person cannot read Kvalitet, so nothing is said about context. */}
                {qualityContext !== null && (
                    <KpiContextPanel
                        baseUrl={baseUrl}
                        context={qualityContext}
                        options={qualityContextOptions}
                        canLink={Boolean(permissions.can_link_context)}
                        tr={tr}
                    />
                )}

                <MeasurementHistory measurements={measurements} baseUrl={baseUrl} locale={locale} tm={tm} />

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
