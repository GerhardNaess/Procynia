import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import KpiContextPanel from '../Objectives/KpiContextPanel';
import ImprovementForm from './ImprovementForm';
import ImprovementHistory from './ImprovementHistory';
import { ImprovementCancelForm, ImprovementCloseForm, ImprovementReopenForm } from './ImprovementStatusForms';
import { improvementHelp } from './improvementHelp';
import { IMPROVEMENT_STATUS_TONES, IMPROVEMENT_TYPE_TONES, formatDay, formatLongDate } from './improvementStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * One avvik or forbedring. Every action is offered only when the server said this person may do it
 * to a case in this area; the controller refuses it otherwise. An open case can be edited, started,
 * closed or cancelled; one under arbeid edited, closed or cancelled; an ended one only reopened.
 * Status never moves through Rediger.
 */
export default function ImprovementShow() {
    const {
        translations = {},
        case: item,
        status_history: statusHistory = [],
        locale = 'no',
        permissions = {},
        types = [],
        area_options: areaOptions = [],
        owner_options: ownerOptions = [],
        quality_context: qualityContext = null,
        quality_context_options: qualityContextOptions = [],
        today = '',
    } = usePage().props;

    const tr = translations?.improvements ?? {};
    const statusLabels = tr.statuses ?? {};
    const typeLabels = tr.types ?? {};
    // Which panel is open: 'edit', 'close', 'cancel', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);
    const [starting, setStarting] = useState(false);
    const ended = item.status === 'closed' || item.status === 'cancelled';

    const form = useForm({
        type: item.type ?? '',
        title: item.title ?? '',
        description: item.description ?? '',
        business_area_id: String(item.business_area_id ?? ''),
        owner_user_id: item.owner_user_id ? String(item.owner_user_id) : '',
        occurred_at: item.occurred_at ?? '',
        due_date: item.due_date ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/app/improvements/${item.id}`, { preserveScroll: true, onSuccess: () => setPanel(null) });
    };

    const start = () => {
        router.post(`/app/improvements/${item.id}/start`, {}, {
            preserveScroll: true,
            onStart: () => setStarting(true),
            onFinish: () => setStarting(false),
        });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett saken? Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/improvements/${item.id}`);
    };

    const who = (name) => name ?? (tr.unknown_user ?? 'en tidligere bruker');

    return (
        <CustomerAppLayout title={item.title} showPageTitle={false}>
            <div className="space-y-6">
                <Link href="/app/improvements" className="inline-block text-base font-semibold text-violet-700 hover:text-violet-900">
                    ← {tr.back ?? 'Til avvik og forbedringer'}
                </Link>

                <header className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge tone={IMPROVEMENT_TYPE_TONES[item.type] ?? 'slate'}>{typeLabels[item.type] ?? item.type}</StatusBadge>
                                <StatusBadge tone={IMPROVEMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                            </div>
                            <h1 className="break-words text-3xl font-semibold tracking-tight text-slate-950">{item.title}</h1>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <PageHelpButton {...improvementHelp(tr, 'case')} />
                            {permissions.can_edit && panel === null && (
                                <button type="button" onClick={() => setPanel('edit')} className={SECONDARY_ACTION}>{tr.edit ?? 'Rediger'}</button>
                            )}
                            {permissions.can_delete && (
                                <button type="button" onClick={destroy} className={DESTRUCTIVE_ACTION}>{tr.delete ?? 'Slett sak'}</button>
                            )}
                        </div>
                    </div>

                    <dl className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt className={TERM}>{tr.field_owner ?? 'Ansvarlig'}</dt>
                            <dd className={VALUE}>{item.owner_name ?? <span className="text-amber-800">{tr.no_owner ?? 'Mangler ansvarlig'}</span>}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.field_area ?? 'Fagområde'}</dt>
                            <dd className={VALUE}>{item.area_name}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.field_due_date ?? 'Frist'}</dt>
                            <dd className={VALUE}>
                                {formatDay(item.due_date, tr.no_due_date ?? 'Ingen frist')}
                                {item.is_overdue && <span className="ml-2 font-semibold text-amber-800">{tr.overdue ?? 'Frist passert'}</span>}
                            </dd>
                        </div>
                    </dl>
                </header>

                {panel === 'edit' ? (
                    <section className={CARD} aria-label={tr.edit ?? 'Rediger'}>
                        <ImprovementForm
                            form={form}
                            onSubmit={submit}
                            onCancel={() => { setPanel(null); form.reset(); form.clearErrors(); }}
                            types={types}
                            areaOptions={areaOptions}
                            ownerOptions={ownerOptions}
                            today={today}
                            tr={tr}
                        />
                    </section>
                ) : (
                    <section className={CARD} aria-labelledby="improvement-description-heading">
                        <h2 id="improvement-description-heading" className="text-xl font-semibold text-slate-950">
                            {item.type === 'improvement'
                                ? (tr.description_heading_improvement ?? 'Hva er forbedringen?')
                                : (tr.description_heading_deviation ?? 'Hva er avviket?')}
                        </h2>
                        <p className="mt-3 whitespace-pre-line break-words text-base leading-7 text-slate-800">{item.description}</p>
                    </section>
                )}

                <section className={CARD} aria-labelledby="improvement-handling-heading" data-testid="improvement-handling">
                    <h2 id="improvement-handling-heading" className="text-xl font-semibold text-slate-950">{tr.handling_heading ?? 'Behandling'}</h2>
                    <p className="mt-3 text-base text-slate-800">{tr.handling?.[item.status] ?? ''}</p>

                    {ended && item.closed_at && (
                        <div className="mt-4 space-y-1 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-base font-semibold text-slate-700">
                                {item.status === 'closed' ? (tr.result_heading ?? 'Resultat') : (tr.cancel_reason_heading ?? 'Begrunnelse')}
                            </p>
                            <p className="whitespace-pre-line break-words text-base text-slate-900" data-testid="improvement-closing-note">{item.closing_note}</p>
                            <p className="text-base text-slate-600">
                                {(tr.ended_summary?.[item.status] ?? 'Lukket :date av :name.')
                                    .replace(':date', formatLongDate(item.closed_at, locale))
                                    .replace(':name', who(item.closed_by_name))}
                            </p>
                        </div>
                    )}

                    {panel === null && (permissions.can_start || permissions.can_close || permissions.can_cancel || permissions.can_reopen) && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {permissions.can_start && (
                                <button type="button" onClick={start} disabled={starting} className={PRIMARY_ACTION}>{tr.start ?? 'Start behandling'}</button>
                            )}
                            {permissions.can_close && (
                                <button type="button" onClick={() => setPanel('close')} className={SECONDARY_ACTION}>{tr.close ?? 'Lukk sak'}</button>
                            )}
                            {permissions.can_cancel && (
                                <button type="button" onClick={() => setPanel('cancel')} className={SECONDARY_ACTION}>{tr.cancel_case ?? 'Avbryt sak'}</button>
                            )}
                            {permissions.can_reopen && (
                                <button type="button" onClick={() => setPanel('reopen')} className={SECONDARY_ACTION}>{tr.reopen ?? 'Gjenåpne'}</button>
                            )}
                        </div>
                    )}
                </section>

                {panel === 'close' && <ImprovementCloseForm caseId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                {panel === 'cancel' && <ImprovementCancelForm caseId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                {panel === 'reopen' && <ImprovementReopenForm caseId={item.id} onDone={() => setPanel(null)} tr={tr} />}

                <section className={CARD} aria-labelledby="improvement-context-facts-heading">
                    <h2 id="improvement-context-facts-heading" className="text-xl font-semibold text-slate-950">{tr.context_heading ?? 'Kontekst'}</h2>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt className={TERM}>{tr.reported_by ?? 'Registrert av'}</dt>
                            <dd className={VALUE}>{who(item.reported_by_name)}</dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.reported_at ?? 'Registrert'}</dt>
                            <dd className={VALUE}>{formatLongDate(item.created_at, locale)}</dd>
                        </div>
                        {item.occurred_at && (
                            <div>
                                <dt className={TERM}>{tr.field_occurred_at ?? 'Hendelsesdato'}</dt>
                                <dd className={VALUE}>{formatDay(item.occurred_at)}</dd>
                            </div>
                        )}
                    </dl>
                </section>

                {/* null, not empty: the person cannot read Kvalitet, so nothing is said about context. */}
                {qualityContext !== null && (
                    <KpiContextPanel
                        baseUrl={`/app/improvements/${item.id}`}
                        context={qualityContext}
                        options={qualityContextOptions}
                        canLink={Boolean(permissions.can_link_context)}
                        tr={tr}
                        idPrefix="improvement-context"
                    />
                )}

                <ImprovementHistory entries={statusHistory} locale={locale} tr={tr} />
            </div>
        </CustomerAppLayout>
    );
}
