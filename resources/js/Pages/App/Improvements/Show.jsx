import { useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import CustomerAppLayout from '../../../Layouts/CustomerAppLayout';
import PageHelpButton from '../../../Components/App/PageHelpButton';
import StatusBadge from '../../../Components/App/StatusBadge';
import WikiKnowledgeHandoffPanel from '../../../Components/App/WikiKnowledgeHandoffPanel';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { originLine } from '../../../Support/complianceNavigation';
import { refocusIfLost, useFocusFirstError } from '../../../Support/focusManagement';
import { rowErrorId, trackRowAction, useRowActionError } from '../../../Support/rowActionError';
import useInlinePanelFocus from '../Risk/useInlinePanelFocus';
import KpiContextPanel from '../Objectives/KpiContextPanel';
import ImprovementActions from './ImprovementActions';
import { ImprovementAttentionNote } from './ImprovementAttention';
import ImprovementCause from './ImprovementCause';
import ImprovementForm from './ImprovementForm';
import ImprovementHistory from './ImprovementHistory';
import { ImprovementCancelForm, ImprovementCloseForm, ImprovementReopenForm } from './ImprovementStatusForms';
import { casePanelFocusCandidates } from './improvementA11y';
import { improvementHelp } from './improvementHelp';
import { IMPROVEMENT_STATUS_TONES, IMPROVEMENT_TYPE_TONES, formatDay, formatLongDate } from './improvementStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * One avvik or forbedring. Every action is offered only when the server said this person may do it
 * to a case in this area; the controller refuses it otherwise. An open case can be edited, started,
 * closed or cancelled; one under arbeid edited, closed or cancelled; an ended one only reopened.
 * Status never moves through Rediger. Årsak og bakgrunn and Tiltak sit between what the case is and
 * how it is handled; a case with tiltak still to be done is refused closing by the server.
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
        actions = [],
        attention = null,
        action_owner_options: actionOwnerOptions = [],
        quality_context: qualityContext = null,
        quality_context_options: qualityContextOptions = [],
        audit_origin: auditOrigin = null,
        supplier_origin: supplierOrigin = null,
        review_origin: reviewOrigin = null,
        today = '',
        knowledge_handoff: knowledgeHandoff = null,
        flash = {},
        errors: pageErrors = {},
    } = usePage().props;

    const tr = translations?.improvements ?? {};
    const statusLabels = tr.statuses ?? {};
    const typeLabels = tr.types ?? {};
    // Which panel is open: 'edit', 'close', 'cancel', 'reopen' or none. One at a time.
    const [panel, setPanel] = useState(null);
    const [starting, setStarting] = useState(false);
    const ended = item.status === 'closed' || item.status === 'cancelled';
    // Every panel opens in place of the buttons that open it. Focus goes into the panel — the edit form
    // as a whole (named «Rediger», its first field is a choice), a lifecycle form at its text — and,
    // when it closes, back to the button that opened it or to what the case offers now (A11Y-014).
    const editPanelRef = useRef(null);
    const editFormRef = useRef(null);
    const statusPanelRef = useRef(null);
    const lastPanelRef = useRef(null);
    const focusFirstEditError = useFocusFirstError(editFormRef);
    const statusPanelOpen = panel === 'close' || panel === 'cancel' || panel === 'reopen';
    useInlinePanelFocus(panel === 'edit', { panelRef: editPanelRef, returnIds: casePanelFocusCandidates('edit'), initialFocus: 'panel' });
    useInlinePanelFocus(statusPanelOpen, { panelRef: statusPanelRef, returnIds: casePanelFocusCandidates(lastPanelRef.current) });
    // Slett sak can be refused by the server with a flash error; the refusal is shown at the button too.
    const rowError = useRowActionError(flash?.error);

    const openPanel = (name) => {
        lastPanelRef.current = name;
        setPanel(name);
    };

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
        form.patch(`/app/improvements/${item.id}`, { preserveScroll: true, onSuccess: () => setPanel(null), onError: () => focusFirstEditError() });
    };

    const start = () => {
        // aria-disabled while starting, so the guard lives here.
        if (starting) {
            return;
        }

        router.post(`/app/improvements/${item.id}/start`, {}, {
            preserveScroll: true,
            onStart: () => setStarting(true),
            onFinish: () => setStarting(false),
            // «Start behandling» goes once the case is under arbeid; focus to what it offers now.
            onSuccess: () => refocusIfLost(casePanelFocusCandidates('start')),
        });
    };

    const destroy = () => {
        if (! window.confirm(tr.delete_confirm ?? 'Slett saken? Dette kan ikke angres.')) {
            return;
        }

        router.delete(`/app/improvements/${item.id}`, trackRowAction('improvement-delete'));
    };

    // A refused start (the case moved on meanwhile) comes back as a validation error on `status`, which
    // nothing showed before: it is said and shown at the button (3.3.1, 4.1.3).
    const startError = permissions.can_start ? (pageErrors.status ?? null) : null;
    const deleteError = rowError('improvement-delete');

    const who = (name) => name ?? (tr.unknown_user ?? 'en tidligere bruker');

    return (
        <CustomerAppLayout title={item.title} showPageTitle={false} workView={panel === 'edit' ? (tr.edit ?? 'Rediger') : null}>
            <div className="space-y-6">
                <header className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge tone={IMPROVEMENT_TYPE_TONES[item.type] ?? 'slate'}>{typeLabels[item.type] ?? item.type}</StatusBadge>
                                <StatusBadge tone={IMPROVEMENT_STATUS_TONES[item.status] ?? 'slate'}>{statusLabels[item.status] ?? item.status}</StatusBadge>
                            </div>
                            <h1 id="improvement-heading" className="break-words text-3xl font-semibold tracking-tight text-slate-950">{item.title}</h1>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <PageHelpButton {...improvementHelp(tr, 'case')} />
                            {permissions.can_edit && panel === null && (
                                <button id="improvement-edit-button" type="button" onClick={() => openPanel('edit')} className={SECONDARY_ACTION}>{tr.edit ?? 'Rediger'}</button>
                            )}
                            {permissions.can_delete && (
                                <button
                                    type="button"
                                    onClick={destroy}
                                    aria-describedby={deleteError ? rowErrorId('improvement-delete') : undefined}
                                    className={DESTRUCTIVE_ACTION}
                                >
                                    {tr.delete ?? 'Slett sak'}
                                </button>
                            )}
                        </div>
                        {deleteError && (
                            <p id={rowErrorId('improvement-delete')} className="w-full text-right text-base text-rose-700">{deleteError}</p>
                        )}
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

                    {auditOrigin && (
                        <p className="break-words rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="improvement-audit-origin">
                            {tr.audit_origin ?? 'Fra revisjonsfunn i'}{' '}
                            <Link href={auditOrigin.audit_url} className="font-semibold text-violet-700 hover:text-violet-900">{auditOrigin.audit_title}</Link>
                            <span className="block text-slate-600">{(tr.audit_origin_finding ?? 'Funn: :title').replace(':title', auditOrigin.finding_title)}</span>
                            {auditOrigin.requirement_origin && (
                                <span className="block text-slate-600" data-testid="improvement-audit-requirement">
                                    {(tr.audit_origin_requirement ?? 'Krav: :requirement').replace(':requirement', originLine(auditOrigin.requirement_origin))}
                                </span>
                            )}
                        </p>
                    )}
                    {reviewOrigin && (
                        <p className="break-words rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="improvement-review-origin">
                            {tr.review_origin ?? 'Fra ledelsens gjennomgang'}{' '}
                            <Link href={reviewOrigin.review_url} className="font-semibold text-violet-700 hover:text-violet-900">{reviewOrigin.review_title}</Link>
                            <span className="block text-slate-600">{(tr.review_origin_decision ?? 'Besluttet tiltak: :text').replace(':text', reviewOrigin.decision)}</span>
                        </p>
                    )}
                    {supplierOrigin && supplierOrigin.map((origin) => (
                        <p key={origin.url} className="break-words rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-800" data-testid="improvement-supplier-origin">
                            {tr.supplier_origin ?? 'Gjelder leverandør'}{' '}
                            <Link href={origin.url} className="font-semibold text-violet-700 hover:text-violet-900">{origin.name}</Link>
                            {origin.from_supplier && (
                                <span className="block text-slate-600">
                                    {origin.due_diligence_assessed_on
                                        ? (tr.supplier_origin_due_diligence ?? 'Saken ble opprettet fra aktsomhetsvurderingen :date.').replace(':date', formatLongDate(origin.due_diligence_assessed_on, locale))
                                        : origin.evaluation
                                        ? (tr.supplier_origin_evaluation ?? 'Saken ble opprettet fra kontrollen av «:requirement» :date.')
                                            .replace(':requirement', origin.evaluation.requirement_title)
                                            .replace(':date', formatLongDate(origin.evaluation.evaluated_on, locale))
                                        : origin.assessed_on
                                        ? (tr.supplier_origin_assessment ?? 'Saken ble opprettet fra leverandørvurderingen :date.').replace(':date', formatLongDate(origin.assessed_on, locale))
                                        : (tr.supplier_origin_handoff ?? 'Saken ble opprettet fra leverandøren.')}
                                </span>
                            )}
                        </p>
                    ))}
                </header>

                <ImprovementAttentionNote attention={attention} tr={tr} />

                {panel === 'edit' ? (
                    <section ref={editPanelRef} className={CARD} aria-label={tr.edit ?? 'Rediger'}>
                        <ImprovementForm
                            form={form}
                            formRef={editFormRef}
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

                <ImprovementCause
                    caseId={item.id}
                    type={item.type}
                    text={item.cause_analysis}
                    canEdit={Boolean(permissions.can_edit_cause)}
                    tr={tr}
                />

                <ImprovementActions
                    caseId={item.id}
                    actions={actions}
                    canManage={Boolean(permissions.can_manage_actions)}
                    ownerOptions={actionOwnerOptions}
                    locale={locale}
                    tr={tr}
                />

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
                                <button
                                    id="improvement-start-button"
                                    type="button"
                                    onClick={start}
                                    aria-disabled={starting || undefined}
                                    aria-describedby={startError ? 'improvement-start-error' : undefined}
                                    className={PRIMARY_ACTION}
                                >
                                    {tr.start ?? 'Start behandling'}
                                </button>
                            )}
                            {permissions.can_close && (
                                <button id="improvement-close-button" type="button" onClick={() => openPanel('close')} className={SECONDARY_ACTION}>{tr.close ?? 'Lukk sak'}</button>
                            )}
                            {permissions.can_cancel && (
                                <button id="improvement-cancel-button" type="button" onClick={() => openPanel('cancel')} className={SECONDARY_ACTION}>{tr.cancel_case ?? 'Avbryt sak'}</button>
                            )}
                            {permissions.can_reopen && (
                                <button id="improvement-reopen-button" type="button" onClick={() => openPanel('reopen')} className={SECONDARY_ACTION}>{tr.reopen ?? 'Gjenåpne'}</button>
                            )}
                        </div>
                    )}
                    {startError && panel === null && (
                        <p id="improvement-start-error" role="alert" className="mt-2 text-base text-rose-700">{startError}</p>
                    )}
                </section>

                {statusPanelOpen && (
                    <div ref={statusPanelRef}>
                        {panel === 'close' && <ImprovementCloseForm caseId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                        {panel === 'cancel' && <ImprovementCancelForm caseId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                        {panel === 'reopen' && <ImprovementReopenForm caseId={item.id} onDone={() => setPanel(null)} tr={tr} />}
                    </div>
                )}

                <section className={CARD} aria-labelledby="improvement-context-facts-heading">
                    <h2 id="improvement-context-facts-heading" className="text-xl font-semibold text-slate-950">{tr.context_heading ?? 'Kontekst'}</h2>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt className={TERM}>{tr.reported_by ?? 'Registrert av'}</dt>
                            <dd className={VALUE}>
                                {item.registered_by_integration
                                    ? (tr.registered_by_integration ?? 'Integrasjonen :client').replace(':client', item.registered_by_integration)
                                    : who(item.reported_by_name)}
                            </dd>
                        </div>
                        <div>
                            <dt className={TERM}>{tr.reported_at ?? 'Registrert'}</dt>
                            <dd className={VALUE}>{formatLongDate(item.created_at, locale)}</dd>
                        </div>
                        {item.occurred_at && (
                            <div>
                                <dt className={TERM}>{tr.field_occurred_at ?? 'Hendelsesdato'}</dt>
                                {/* Long, like Registrert beside it. A plain day, read as local midnight. */}
                                <dd className={VALUE}>{formatLongDate(`${item.occurred_at}T00:00:00`, locale)}</dd>
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

                <WikiKnowledgeHandoffPanel handoff={knowledgeHandoff} idPrefix={`improvement-case-${item.id}`} />

                <ImprovementHistory entries={statusHistory} locale={locale} tr={tr} />
            </div>
        </CustomerAppLayout>
    );
}
