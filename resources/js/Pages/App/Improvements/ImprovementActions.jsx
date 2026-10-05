import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { DESTRUCTIVE_ACTION, PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';
import ImprovementActionForm from './ImprovementActionForm';
import { ACTION_STATUS_TONES, VERIFICATION_RESULT_TONES, cancellationReason, describeActionHistoryEntry, formatDay, formatLongDate } from './improvementStatus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * Tiltak on the case page: what is to be done, by whom, by when, how far it has come and — once
 * done — what was actually done. A light list of cards, not a task board.
 *
 * Every button is offered only when the server said so for this tiltak (improvement.edit in the
 * case's area, the case still active, the tiltak in the right state; improvement.close for Verifiser
 * effekt); the server refuses it otherwise. One panel is open at a time, inside the card it belongs
 * to.
 */
export default function ImprovementActions({ caseId, actions = [], canManage, ownerOptions = [], locale, tr }) {
    const t = tr.actions ?? {};
    const { errors = {} } = usePage().props;
    // { id: action id or 'new', kind: 'create' | 'edit' | 'complete' | 'cancel' | 'reopen' | 'verify' }
    const [panel, setPanel] = useState(null);
    const [busy, setBusy] = useState(null);
    const close = () => setPanel(null);
    const base = `/app/improvements/${caseId}/actions`;

    const start = (action) => {
        router.post(`${base}/${action.id}/start`, {}, {
            preserveScroll: true,
            onStart: () => setBusy(action.id),
            onFinish: () => setBusy(null),
        });
    };

    const destroy = (action) => {
        if (! window.confirm(t.delete_confirm ?? 'Slett tiltaket? Bruk sletting bare for tiltak som er lagt inn ved en feil.')) {
            return;
        }

        router.delete(`${base}/${action.id}`, { preserveScroll: true });
    };

    return (
        <section className={CARD} aria-labelledby="improvement-actions-heading" data-testid="improvement-actions">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="improvement-actions-heading" className="text-xl font-semibold text-slate-950">{t.heading ?? 'Tiltak'}</h2>
                    <p className="mt-1 text-base text-slate-600">{t.intro ?? 'Konkrete aktiviteter som skal gjøres, med ansvarlig og frist.'}</p>
                </div>
                {canManage && panel?.kind !== 'create' && (
                    <button type="button" onClick={() => setPanel({ id: 'new', kind: 'create' })} className={PRIMARY_ACTION}>
                        {t.create ?? 'Nytt tiltak'}
                    </button>
                )}
            </div>

            {errors.action && <p className="mt-3 text-base text-rose-700" role="alert">{errors.action}</p>}

            {panel?.kind === 'create' && (
                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <NewAction base={base} ownerOptions={ownerOptions} onDone={close} tr={tr} />
                </div>
            )}

            {actions.length === 0 ? (
                <p className="mt-4 text-base text-slate-600">{t.empty ?? 'Ingen tiltak er lagt inn ennå.'}</p>
            ) : (
                <ul className="mt-4 space-y-4">
                    {actions.map((action) => (
                        <ActionCard
                            key={action.id}
                            action={action}
                            base={base}
                            panel={panel?.id === action.id ? panel.kind : null}
                            openPanel={(kind) => setPanel({ id: action.id, kind })}
                            closePanel={close}
                            onStart={() => start(action)}
                            onDelete={() => destroy(action)}
                            starting={busy === action.id}
                            ownerOptions={ownerOptions}
                            locale={locale}
                            tr={tr}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function NewAction({ base, ownerOptions, onDone, tr }) {
    const form = useForm({ title: '', description: '', owner_user_id: '', due_date: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(base, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <ImprovementActionForm
            form={form}
            onSubmit={submit}
            onCancel={onDone}
            heading={tr.actions?.create_heading ?? 'Nytt tiltak'}
            ownerOptions={ownerOptions}
            tr={tr}
        />
    );
}

function EditAction({ action, base, ownerOptions, onDone, tr }) {
    const form = useForm({
        title: action.title ?? '',
        description: action.description ?? '',
        owner_user_id: action.owner_user_id ? String(action.owner_user_id) : '',
        due_date: action.due_date ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`${base}/${action.id}`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <ImprovementActionForm
            form={form}
            onSubmit={submit}
            onCancel={onDone}
            heading={tr.actions?.edit_heading ?? 'Rediger tiltak'}
            ownerOptions={ownerOptions}
            tr={tr}
        />
    );
}

/**
 * Fullfør («Hva ble gjort?»), Avbryt (begrunnelse) or Gjenåpne (begrunnelse). The server requires the
 * text too and writes it to the tiltak's history in the same step.
 */
function ActionTextForm({ id, action, field, heading, intro, label, submit, onDone, tr }) {
    const form = useForm({ [field]: '' });

    const send = (event) => {
        event.preventDefault();
        form.post(action, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="space-y-4" aria-label={heading}>
            <h3 className="text-lg font-semibold text-slate-950">{heading}</h3>
            <p className="text-base text-slate-600">{intro}</p>
            <div>
                <label htmlFor={id} className="block text-base font-semibold text-slate-700">{label}<RequiredMark /></label>
                <textarea
                    id={id}
                    rows={3}
                    required
                    aria-required="true"
                    value={form.data[field]}
                    onChange={(event) => form.setData(field, event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors[field] && <p className="mt-1 text-base text-rose-700">{form.errors[field]}</p>}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : submit}
                </button>
            </div>
        </form>
    );
}

/**
 * Verifiser effekt: Effekt bekreftet or Ikke effektivt, and a comment either way. A new judgement is
 * a new entry; the earlier ones stay.
 */
function VerifyForm({ action, onDone, tr }) {
    const t = tr.actions ?? {};
    const results = t.verification_results ?? {};
    const form = useForm({ result: '', note: '' });

    const send = (event) => {
        event.preventDefault();
        form.post(action, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={send} className="space-y-4" aria-label={t.verify ?? 'Verifiser effekt'}>
            <h3 className="text-lg font-semibold text-slate-950">{t.verify ?? 'Verifiser effekt'}</h3>
            <p className="text-base text-slate-600">{t.verify_intro ?? 'Vurder om tiltaket faktisk ga ønsket resultat. En ny vurdering sletter ikke tidligere vurderinger.'}</p>
            <fieldset>
                <legend className="text-base font-semibold text-slate-700">{t.verification_result_label ?? 'Resultat'}<RequiredMark /></legend>
                <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                    {['effective', 'not_effective'].map((value) => (
                        <label key={value} className="inline-flex min-h-10 items-center gap-2 text-base text-slate-900">
                            <input
                                type="radio"
                                name="verification-result"
                                id={`improvement-action-verify-${value}`}
                                value={value}
                                required
                                checked={form.data.result === value}
                                onChange={() => form.setData('result', value)}
                                className="h-5 w-5"
                            />
                            {results[value] ?? (value === 'effective' ? 'Effekt bekreftet' : 'Ikke effektivt')}
                        </label>
                    ))}
                </div>
                {form.errors.result && <p className="mt-1 text-base text-rose-700">{form.errors.result}</p>}
            </fieldset>
            <div>
                <label htmlFor="improvement-action-verify-note" className="block text-base font-semibold text-slate-700">{t.verification_note_label ?? 'Kommentar'}<RequiredMark /></label>
                <p id="improvement-action-verify-note-hint" className="text-base text-slate-600">{t.verification_note_hint ?? 'Hva bygger vurderingen på?'}</p>
                <textarea
                    id="improvement-action-verify-note"
                    rows={3}
                    required
                    aria-required="true"
                    aria-describedby="improvement-action-verify-note-hint"
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.note && <p className="mt-1 text-base text-rose-700">{form.errors.note}</p>}
            </div>
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                    {form.processing ? (tr.saving ?? 'Lagrer...') : (t.verify ?? 'Verifiser effekt')}
                </button>
            </div>
        </form>
    );
}

/** One judgement: result, comment, when and by whom. */
function VerificationEntry({ entry, locale, tr, testId }) {
    const t = tr.actions ?? {};
    const results = t.verification_results ?? {};

    return (
        <div data-testid={testId}>
            <StatusBadge tone={VERIFICATION_RESULT_TONES[entry.result] ?? 'slate'}>{results[entry.result] ?? entry.result}</StatusBadge>
            <p className="mt-2 whitespace-pre-line break-words text-base text-slate-900">{entry.note}</p>
            <p className="mt-1 text-base text-slate-600">
                {(t.verification_summary ?? 'Vurdert :date av :name.')
                    .replace(':date', formatLongDate(entry.verified_at, locale))
                    .replace(':name', entry.verified_by_name ?? (tr.unknown_user ?? 'en tidligere bruker'))}
            </p>
            {entry.earlier_completion && (
                <p className="mt-1 text-base text-slate-600">{t.verification_earlier_completion ?? 'Gjaldt en tidligere fullføring.'}</p>
            )}
        </div>
    );
}

/**
 * Effektverifisering on the card: for a completed tiltak the current judgement of its current
 * completion, or «Venter på effektverifisering»; below, every earlier judgement, kept as history.
 */
function ActionVerification({ action, locale, tr }) {
    const t = tr.actions ?? {};
    const verification = action.verification ?? {};
    const earlier = verification.earlier ?? [];
    const completed = action.status === 'completed';

    if (! completed && earlier.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 space-y-2 rounded-2xl border border-slate-200 p-4" data-testid="improvement-action-verification">
            <p className="text-base font-semibold text-slate-700">{t.verification_heading ?? 'Effektverifisering'}</p>
            {completed && verification.current && (
                <>
                    <VerificationEntry entry={verification.current} locale={locale} tr={tr} testId="improvement-action-verification-current" />
                    {verification.current.result === 'not_effective' && (
                        <p className="font-semibold text-base text-rose-800">
                            {t.verification_not_effective_hint ?? 'Effekten er ikke bekreftet. Gjenåpne tiltaket dersom det må arbeides videre med.'}
                        </p>
                    )}
                </>
            )}
            {verification.awaiting && (
                <div data-testid="improvement-action-verification-awaiting">
                    <p className="text-base font-semibold text-amber-800">{t.verification_awaiting ?? 'Venter på effektverifisering'}</p>
                    <p className="text-base text-slate-600">{t.verification_awaiting_hint ?? 'Tiltaket er fullført, men ingen har vurdert om det virket.'}</p>
                </div>
            )}
            {earlier.length > 0 && (
                <details>
                    <summary className="inline-flex min-h-10 cursor-pointer items-center text-base font-semibold text-violet-700 hover:text-violet-900">
                        {(t.verification_earlier_show ?? 'Tidligere vurderinger (:count)').replace(':count', String(earlier.length))}
                    </summary>
                    <ol className="mt-2 divide-y divide-slate-100" data-testid="improvement-action-verification-history">
                        {earlier.map((entry) => (
                            <li key={entry.id} className="py-3">
                                <VerificationEntry entry={entry} locale={locale} tr={tr} />
                            </li>
                        ))}
                    </ol>
                </details>
            )}
        </div>
    );
}

function ActionCard({ action, base, panel, openPanel, closePanel, onStart, onDelete, starting, ownerOptions, locale, tr }) {
    const t = tr.actions ?? {};
    const statusLabels = t.statuses ?? {};
    const can = action.permissions ?? {};
    const who = (name) => name ?? (tr.unknown_user ?? 'en tidligere bruker');
    const reason = cancellationReason(action);
    const anyAction = can.can_start || can.can_complete || can.can_verify || can.can_cancel || can.can_reopen || can.can_edit || can.can_delete;
    const url = `${base}/${action.id}`;

    return (
        <li id={`improvement-action-${action.id}`} className="scroll-mt-6 rounded-2xl border border-slate-200 p-4" data-testid="improvement-action" aria-labelledby={`improvement-action-${action.id}-title`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h3 id={`improvement-action-${action.id}-title`} className="min-w-0 break-words text-lg font-semibold text-slate-950">{action.title}</h3>
                <StatusBadge tone={ACTION_STATUS_TONES[action.status] ?? 'slate'}>{statusLabels[action.status] ?? action.status}</StatusBadge>
            </div>

            {action.description && (
                <p className="mt-2 whitespace-pre-line break-words text-base text-slate-800">{action.description}</p>
            )}

            <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt className={TERM}>{t.field_owner ?? 'Ansvarlig'}</dt>
                    <dd className={VALUE} data-testid="improvement-action-owner">
                        {action.owner_name ?? <span className="font-semibold text-amber-800">{t.no_owner ?? 'Mangler ansvarlig'}</span>}
                    </dd>
                </div>
                <div>
                    <dt className={TERM}>{t.field_due_date ?? 'Frist'}</dt>
                    <dd className={VALUE} data-testid="improvement-action-due-date">
                        {formatDay(action.due_date)}
                        {action.is_overdue && <span className="ml-2 font-semibold text-amber-800">{t.overdue ?? 'Frist passert'}</span>}
                    </dd>
                    {action.is_after_case_due_date && (
                        <p className="mt-1 text-base text-slate-600">{t.after_case_due_date ?? 'Fristen er etter sakens frist.'}</p>
                    )}
                </div>
            </dl>

            {action.status === 'completed' && action.completion_note && (
                <div className="mt-3 space-y-1 rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
                    <p className="text-base font-semibold text-slate-700">{t.completion_label ?? 'Hva ble gjort?'}</p>
                    <p className="whitespace-pre-line break-words text-base text-slate-900" data-testid="improvement-action-completion">{action.completion_note}</p>
                    <p className="text-base text-slate-600">
                        {(t.completed_summary ?? 'Fullført :date av :name.')
                            .replace(':date', formatLongDate(action.completed_at, locale))
                            .replace(':name', who(action.completed_by_name))}
                    </p>
                </div>
            )}

            <ActionVerification action={action} locale={locale} tr={tr} />

            {reason && (
                <div className="mt-3 space-y-1 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-base font-semibold text-slate-700">{t.cancelled_reason ?? 'Begrunnelse'}</p>
                    <p className="whitespace-pre-line break-words text-base text-slate-900">{reason}</p>
                </div>
            )}

            {panel === null && anyAction && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {can.can_start && (
                        <button type="button" onClick={onStart} disabled={starting} className={PRIMARY_ACTION}>{t.start ?? 'Start tiltak'}</button>
                    )}
                    {can.can_complete && (
                        <button type="button" onClick={() => openPanel('complete')} className={can.can_start ? SECONDARY_ACTION : PRIMARY_ACTION}>{t.complete ?? 'Fullfør tiltak'}</button>
                    )}
                    {can.can_verify && (
                        <button type="button" onClick={() => openPanel('verify')} className={action.verification?.awaiting ? PRIMARY_ACTION : SECONDARY_ACTION}>{t.verify ?? 'Verifiser effekt'}</button>
                    )}
                    {can.can_edit && (
                        <button type="button" onClick={() => openPanel('edit')} className={SECONDARY_ACTION}>{t.edit ?? 'Rediger'}</button>
                    )}
                    {can.can_cancel && (
                        <button type="button" onClick={() => openPanel('cancel')} className={SECONDARY_ACTION}>{t.cancel_action ?? 'Avbryt tiltak'}</button>
                    )}
                    {can.can_reopen && (
                        <button type="button" onClick={() => openPanel('reopen')} className={SECONDARY_ACTION}>{t.reopen ?? 'Gjenåpne tiltak'}</button>
                    )}
                    {can.can_delete && (
                        <button type="button" onClick={onDelete} className={DESTRUCTIVE_ACTION}>{t.delete ?? 'Slett'}</button>
                    )}
                </div>
            )}

            {panel !== null && (
                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    {panel === 'verify' && <VerifyForm action={`${url}/verify`} onDone={closePanel} tr={tr} />}
                    {panel === 'edit' && <EditAction action={action} base={base} ownerOptions={ownerOptions} onDone={closePanel} tr={tr} />}
                    {panel === 'complete' && (
                        <ActionTextForm
                            id="improvement-action-completion-note"
                            action={`${url}/complete`}
                            field="completion_note"
                            heading={t.complete ?? 'Fullfør tiltak'}
                            intro={t.complete_intro ?? 'Skriv kort hva som ble gjort. Teksten vises på tiltaket og i historikken.'}
                            label={t.completion_label ?? 'Hva ble gjort?'}
                            submit={t.complete ?? 'Fullfør tiltak'}
                            onDone={closePanel}
                            tr={tr}
                        />
                    )}
                    {panel === 'cancel' && (
                        <ActionTextForm
                            id="improvement-action-cancel-reason"
                            action={`${url}/cancel`}
                            field="reason"
                            heading={t.cancel_action ?? 'Avbryt tiltak'}
                            intro={t.cancel_intro ?? 'Bruk dette når tiltaket ikke skal gjennomføres. Begrunnelsen lagres i historikken.'}
                            label={t.cancel_reason_label ?? 'Begrunnelse'}
                            submit={t.cancel_action ?? 'Avbryt tiltak'}
                            onDone={closePanel}
                            tr={tr}
                        />
                    )}
                    {panel === 'reopen' && (
                        <ActionTextForm
                            id="improvement-action-reopen-reason"
                            action={`${url}/reopen`}
                            field="reason"
                            heading={t.reopen ?? 'Gjenåpne tiltak'}
                            intro={t.reopen_intro ?? 'Tiltaket blir planlagt igjen og kan redigeres. Forklar hvorfor – den tidligere fullføringen eller avbrytelsen blir stående i historikken.'}
                            label={t.reopen_reason_label ?? 'Begrunnelse'}
                            submit={t.reopen ?? 'Gjenåpne tiltak'}
                            onDone={closePanel}
                            tr={tr}
                        />
                    )}
                </div>
            )}

            {(action.history ?? []).length > 0 && (
                <details className="mt-4">
                    <summary className="inline-flex min-h-10 cursor-pointer items-center text-base font-semibold text-violet-700 hover:text-violet-900">
                        {(t.history_show ?? 'Vis historikk (:count)').replace(':count', String(action.history.length))}
                    </summary>
                    <ol className="mt-2 divide-y divide-slate-100" data-testid="improvement-action-history" aria-label={t.history_heading ?? 'Historikk'}>
                        {action.history.map((entry) => (
                            <li key={entry.id} className="py-3">
                                <p className="text-base text-slate-600">{formatLongDate(entry.changed_at, locale)}</p>
                                <p className="mt-0.5 text-base font-semibold text-slate-900">{describeActionHistoryEntry(entry, tr)}</p>
                                {entry.note && <p className="mt-1 whitespace-pre-line break-words text-base text-slate-700">{entry.note}</p>}
                            </li>
                        ))}
                    </ol>
                </details>
            )}
        </li>
    );
}
