import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import WikiKnowledgeHandoffPanel from '../../../Components/App/WikiKnowledgeHandoffPanel';
import { DESTRUCTIVE_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { ObjectiveAttentionNote } from './ObjectiveAttention';
import ObjectiveForm from './ObjectiveForm';
import { objectiveHelp } from './objectiveHelp';
import ObjectiveHistory from './ObjectiveHistory';
import ObjectiveKpis from './ObjectiveKpis';
import { ObjectiveCloseForm, ObjectiveReopenForm } from './ObjectiveStatusForms';
import { OBJECTIVE_STATUS_TONES, formatLongDate, formatTargetDate } from './objectiveStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * One objective. Every action is offered only when the server said this person may do it to an
 * objective in this area; the controller refuses it otherwise. An active objective can be edited
 * and closed; a closed one can only be reopened (or deleted). Status never moves through Rediger.
 */
export default function ObjectiveShow() {
    const {
        translations = {},
        objective,
        status_history: statusHistory = [],
        closing_outcomes: closingOutcomes = [],
        locale = 'no',
        permissions = {},
        area_options: areaOptions = [],
        owner_options: ownerOptions = [],
        kpis = [],
        kpi_form_options: kpiFormOptions = null,
        kpi_indicator: kpiIndicator = null,
        affected_processes: affectedProcesses = null,
        attention = null,
        knowledge_handoff: knowledgeHandoff = null,
    } = usePage().props;

    const tr = translations?.objectives ?? {};
    // KPI strings, with the objective's shared ones (Lagre, Avbryt) underneath.
    const tk = { ...tr, ...(tr.kpi ?? {}) };
    const statusLabels = tr.statuses ?? {};
    // Which panel is open: 'edit', 'close', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);
    const editing = panel === 'edit';

    const form = useForm({
        title: objective.title ?? '',
        description: objective.description ?? '',
        business_area_id: String(objective.business_area_id ?? ''),
        owner_user_id: objective.owner_user_id ? String(objective.owner_user_id) : '',
        target_date: objective.target_date ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/app/objectives/${objective.id}`, {
            preserveScroll: true,
            onSuccess: () => setPanel(null),
        });
    };

    const destroy = () => {
        const message = kpis.length > 0
            ? (tr.delete_confirm_with_kpis ?? 'Slett målet og KPI-ene som hører til? Dette kan ikke angres.')
            : (tr.delete_confirm ?? 'Slett målet? Dette kan ikke angres.');

        if (! window.confirm(message)) {
            return;
        }

        router.delete(`/app/objectives/${objective.id}`);
    };

    return (
        <CustomerAppLayout title={objective.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/objectives" className="text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {tr.back ?? 'Til målene'}
                </Link>

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{objective.title}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-base font-semibold text-slate-600">{tr.status_label ?? 'Status'}:</span>
                            <StatusBadge tone={OBJECTIVE_STATUS_TONES[objective.status] ?? 'slate'}>
                                {statusLabels[objective.status] ?? objective.status}
                            </StatusBadge>
                        </div>
                        {objective.status !== 'active' && objective.closed_at && (
                            <p className="text-base text-slate-600">
                                {(tr.closed_summary ?? 'Lukket :date av :name.')
                                    .replace(':date', formatLongDate(objective.closed_at, locale))
                                    .replace(':name', objective.closed_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'))}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <PageHelpButton {...objectiveHelp(tr, 'objective')} />
                        {permissions.can_edit && panel === null && (
                            <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>
                                {tr.edit ?? 'Rediger'}
                            </button>
                        )}
                        {permissions.can_close && panel === null && (
                            <button type="button" onClick={() => setPanel('close')} className={SECONDARY_ACTION}>
                                {tr.close ?? 'Lukk mål'}
                            </button>
                        )}
                        {permissions.can_reopen && panel === null && (
                            <button type="button" onClick={() => setPanel('reopen')} className={SECONDARY_ACTION}>
                                {tr.reopen ?? 'Gjenåpne'}
                            </button>
                        )}
                        {permissions.can_delete && (
                            <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>
                                {tr.delete ?? 'Slett mål'}
                            </button>
                        )}
                    </div>
                </header>

                <ObjectiveAttentionNote attention={attention} tr={tr} />

                {panel === 'close' && (
                    <ObjectiveCloseForm
                        objectiveId={objective.id}
                        outcomes={closingOutcomes}
                        statusLabels={statusLabels}
                        onDone={() => setPanel(null)}
                        tr={tr}
                    />
                )}

                {panel === 'reopen' && (
                    <ObjectiveReopenForm objectiveId={objective.id} onDone={() => setPanel(null)} tr={tr} />
                )}

                {editing ? (
                    <section className={CARD}>
                        <ObjectiveForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { setPanel(null); form.reset(); form.clearErrors(); }}
                            areaOptions={areaOptions}
                            ownerOptions={ownerOptions}
                            tr={tr}
                        />
                    </section>
                ) : (
                    <section className={CARD}>
                        {objective.description ? (
                            <p className="whitespace-pre-line text-base leading-7 text-slate-800">{objective.description}</p>
                        ) : (
                            <p className="text-base text-slate-500">{tr.no_description ?? 'Ingen beskrivelse.'}</p>
                        )}

                        <h2 className="mt-6 text-lg font-semibold text-slate-950">{tr.details ?? 'Detaljer'}</h2>
                        <dl className="mt-4 grid gap-4 sm:grid-cols-4">
                            <div>
                                <dt className="text-base font-semibold text-slate-600">{tr.field_owner ?? 'Ansvarlig'}</dt>
                                <dd className="mt-1 text-base text-slate-900">
                                    {objective.owner_name ?? <span className="text-amber-700">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-base font-semibold text-slate-600">{tr.field_area ?? 'Fagområde'}</dt>
                                <dd className="mt-1 text-base text-slate-900">{objective.area_name}</dd>
                            </div>
                            <div>
                                <dt className="text-base font-semibold text-slate-600">{tr.field_target_date ?? 'Måldato'}</dt>
                                <dd className="mt-1 text-base text-slate-900">{formatTargetDate(objective.target_date, tr.running ?? 'Løpende')}</dd>
                            </div>
                            <div>
                                <dt className="text-base font-semibold text-slate-600">{tr.updated ?? 'Sist endret'}</dt>
                                <dd className="mt-1 text-base text-slate-900">
                                    {objective.updated_at ? formatLongDate(objective.updated_at, locale) : '—'}
                                </dd>
                            </div>
                        </dl>

                        {/* Derived live from the active KPIs' links; null without Kvalitet read, so nothing shows. */}
                        {affectedProcesses !== null && affectedProcesses.length > 0 && (
                            <div className="mt-6" data-testid="objective-affected-processes">
                                <h2 className="text-base font-semibold text-slate-600">{tk.context?.affected_heading ?? 'Berørte prosesser'}</h2>
                                <ul className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                    {affectedProcesses.map((process) => (
                                        <li key={process.id}>
                                            <a href={process.url} className="text-base text-violet-700 hover:text-violet-900">
                                                {process.code ? `${process.code} · ${process.title}` : process.title}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}

                <ObjectiveKpis
                    objective={objective}
                    kpis={kpis}
                    indicator={kpiIndicator}
                    results={tr.measurement?.results ?? {}}
                    formOptions={kpiFormOptions}
                    canCreate={Boolean(permissions.can_create_kpi)}
                    tr={tk}
                />

                <WikiKnowledgeHandoffPanel handoff={knowledgeHandoff} idPrefix={`objective-${objective.id}`} />

                <ObjectiveHistory entries={statusHistory} statusLabels={statusLabels} locale={locale} tr={tr} />
            </div>
        </CustomerAppLayout>
    );
}
