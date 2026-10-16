import { useEffect, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../Components/App/FormField';
import StatusBadge from '../../../Components/App/StatusBadge';
import RequiredMark from './RequiredMark';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { refocusIfLost, useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, HINT, INPUT, LABEL } from '../../../Support/formStyles';
import { riskActionFocusCandidates } from './riskA11y';
import useInlinePanelFocus from './useInlinePanelFocus';
import { formatRiskDate, formatRiskDay } from './riskDates';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';


// A deadline is a calendar day (Y-m-d); parsed as local time so it never shifts a day across time zones.


/**
 * Tiltak on the risk: what is to be done, by whom, by when. Open actions come first and carry the
 * weight; completed ones sit beneath them, quieter. Every action is offered only when the server
 * said this person may edit the risk — the controller refuses them otherwise. «Forfalt» is the
 * server's answer (is_overdue), not computed here.
 */
export default function RiskTreatmentPanel({ riskId, actions, ownerOptions, canManage, tr }) {
    const { locale } = usePage().props;
    const tt = tr.treatment ?? {};
    // null: nothing open; 'new': the create form; an id: that action's edit form.
    const [editing, setEditing] = useState(null);
    const [completing, setCompleting] = useState(null);
    // Each form opens in place of the buttons that opened it. Nytt tiltak: focus into the form and
    // back to the button. A tiltak's own forms (Rediger, Marker som fullført) and Gjenåpne: back to
    // the button pressed, or — when the tiltak now offers something else — to the one it offers
    // (riskActionFocusCandidates). Only ever if focus was lost (A11Y-014).
    const newPanelRef = useRef(null);
    useInlinePanelFocus(editing === 'new', { panelRef: newPanelRef, returnIds: ['risk-action-create', 'risk-treatment-heading'] });

    const openActions = actions.filter((action) => action.status === 'open');
    const completedActions = actions.filter((action) => action.status !== 'open');

    const reopen = (action) => {
        router.post(`/app/risk/risks/${riskId}/actions/${action.id}/reopen`, {}, {
            preserveScroll: true,
            // The tiltak moves to Åpne tiltak and «Gjenåpne» becomes «Marker som fullført».
            onSuccess: () => refocusIfLost(riskActionFocusCandidates(action.id, 'complete')),
        });
    };

    const closeEdit = (action) => {
        setEditing(null);
        refocusIfLost(riskActionFocusCandidates(action.id, 'edit'));
    };

    const closeComplete = (action) => {
        setCompleting(null);
        refocusIfLost(riskActionFocusCandidates(action.id, 'complete'));
    };

    const renderAction = (action) => {
        if (editing === action.id) {
            return (
                <li key={action.id} className="py-3">
                    <ActionForm
                        riskId={riskId}
                        action={action}
                        ownerOptions={ownerOptions}
                        onDone={() => closeEdit(action)}
                        focusOnMount
                        tt={tt}
                        tr={tr}
                    />
                </li>
            );
        }

        const open = action.status === 'open';

        return (
            <li key={action.id} className={`space-y-2 py-4 ${open ? '' : 'text-slate-600'}`}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 space-y-1">
                        <p id={`risk-action-${action.id}-name`} className={`text-base ${open ? 'font-semibold text-slate-950' : 'font-medium text-slate-700'}`}>{action.title}</p>
                        <div className="flex flex-wrap items-center gap-2 text-base">
                            <StatusBadge tone={open ? 'violet' : 'emerald'}>
                                {open ? (tt.status_open ?? 'Åpen') : (tt.status_completed ?? 'Fullført')}
                            </StatusBadge>
                            {action.is_overdue && <StatusBadge tone="rose">{tt.overdue ?? 'Forfalt'}</StatusBadge>}
                            <span className={action.is_overdue ? 'font-semibold text-rose-700' : 'text-slate-600'}>
                                {(tt.due ?? 'Frist :date').replace(':date', formatRiskDay(action.due_at, locale))}
                            </span>
                            <span className="text-slate-600">
                                {action.owner_name
                                    ? (tt.owner ?? 'Ansvarlig: :name').replace(':name', action.owner_name)
                                    : (tt.no_owner ?? 'Ingen ansvarlig')}
                            </span>
                            {! open && action.completed_at && (
                                <span className="text-slate-600">
                                    {(tt.completed_on ?? 'Fullført :date').replace(':date', formatRiskDate(action.completed_at, locale))}
                                </span>
                            )}
                        </div>
                        {action.outcome_note && (
                            <p className="whitespace-pre-line text-base text-slate-700">
                                <span className="font-semibold">
                                    {open ? (tt.previous_outcome_note ?? 'Tidligere resultat') : (tt.outcome_note ?? 'Resultat')}:
                                </span> {action.outcome_note}
                            </p>
                        )}
                    </div>
                    {/* The same three words on every tiltak: each button is described by its tiltak (A11Y-109). */}
                    {canManage && completing !== action.id && (
                        <div className="flex flex-wrap gap-2">
                            <button id={`risk-action-${action.id}-edit`} type="button" aria-describedby={`risk-action-${action.id}-name`} onClick={() => { setEditing(action.id); setCompleting(null); }} className={SECONDARY_ACTION}>
                                {tt.edit ?? 'Rediger'}
                            </button>
                            {open ? (
                                <button id={`risk-action-${action.id}-complete`} type="button" aria-describedby={`risk-action-${action.id}-name`} onClick={() => { setCompleting(action.id); setEditing(null); }} className={SECONDARY_ACTION}>
                                    {tt.complete ?? 'Marker som fullført'}
                                </button>
                            ) : (
                                <button id={`risk-action-${action.id}-reopen`} type="button" aria-describedby={`risk-action-${action.id}-name`} onClick={() => reopen(action)} className={SECONDARY_ACTION}>
                                    {tt.reopen ?? 'Gjenåpne'}
                                </button>
                            )}
                        </div>
                    )}
                </div>
                {completing === action.id && (
                    <CompleteForm riskId={riskId} action={action} onDone={() => closeComplete(action)} tt={tt} />
                )}
            </li>
        );
    };

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="risk-treatment-heading" className="text-lg font-semibold text-slate-950">{tt.title ?? 'Tiltak'}</h2>
                    <p className="mt-1 text-base text-slate-600">
                        {tt.description ?? 'Konkrete tiltak for å håndtere risikoen, med ansvarlig og frist.'}
                    </p>
                </div>
                {canManage && editing !== 'new' && (
                    <button id="risk-action-create" type="button" onClick={() => { setEditing('new'); setCompleting(null); }} className={SECONDARY_ACTION}>
                        {tt.create ?? 'Nytt tiltak'}
                    </button>
                )}
            </div>

            {editing === 'new' && (
                <div ref={newPanelRef} className="mt-4">
                    <ActionForm riskId={riskId} action={null} ownerOptions={ownerOptions} onDone={() => setEditing(null)} tt={tt} tr={tr} />
                </div>
            )}

            {actions.length === 0 && editing !== 'new' && (
                <p className="mt-4 text-base text-slate-600">{tt.empty ?? 'Ingen tiltak er registrert på denne risikoen.'}</p>
            )}

            {openActions.length > 0 && (
                <div className="mt-4">
                    <h3 className="text-base font-semibold uppercase tracking-wide text-slate-500">{tt.open_heading ?? 'Åpne tiltak'}</h3>
                    <ul className="divide-y divide-slate-100">{openActions.map(renderAction)}</ul>
                </div>
            )}

            {completedActions.length > 0 && (
                <div className="mt-6 rounded-2xl bg-slate-50 px-4 py-2">
                    <h3 className="pt-2 text-base font-semibold uppercase tracking-wide text-slate-600">{tt.completed_heading ?? 'Fullførte tiltak'}</h3>
                    <ul className="divide-y divide-slate-200">{completedActions.map(renderAction)}</ul>
                </div>
            )}
        </section>
    );
}

/**
 * Create or edit an action. The result belongs to completing it: a new or open action's form has no
 * such field and does not send one, so a result kept from before a reopen stays as it was. Only a
 * completed action's result can be corrected here.
 */
function ActionForm({ riskId, action, ownerOptions, onDone, focusOnMount = false, tt, tr }) {
    const withOutcome = action !== null && action.status !== 'open';
    const form = useForm({
        title: action?.title ?? '',
        owner_user_id: action?.owner_user_id ? String(action.owner_user_id) : '',
        due_at: action?.due_at ?? '',
        ...(withOutcome ? { outcome_note: action.outcome_note ?? '' } : {}),
    });
    const prefix = action ? `risk-action-${action.id}` : 'risk-action-new';
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    useFocusOnOpen(formRef, focusOnMount);

    const submit = (event) => {
        event.preventDefault();

        // «Lagre tiltak» stays focusable while saving (aria-disabled), so the guard lives here.
        if (form.processing) {
            return;
        }

        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); }, onError: () => focusFirstError() };

        if (action) {
            form.patch(`/app/risk/risks/${riskId}/actions/${action.id}`, options);
        } else {
            form.post(`/app/risk/risks/${riskId}/actions`, options);
        }
    };

    const title = fieldA11y({ id: `${prefix}-title`, hasError: Boolean(form.errors.title), required: true });
    const owner = fieldA11y({ id: `${prefix}-owner`, hasHint: true, hasError: Boolean(form.errors.owner_user_id), required: true });
    const due = fieldA11y({ id: `${prefix}-due`, hasError: Boolean(form.errors.due_at), required: true });
    const note = fieldA11y({ id: `${prefix}-note`, hasError: Boolean(form.errors.outcome_note) });

    return (
        <form ref={formRef} onSubmit={submit} className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div>
                <label htmlFor={`${prefix}-title`} className={LABEL}>{tt.field_title ?? 'Hva skal gjøres'}<RequiredMark /></label>
                <input
                    {...title.controlProps}
                    type="text"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p id={title.errorId} className={ERROR}>{form.errors.title}</p>}
            </div>
            <div className="grid gap-3 md:grid-cols-2">
                <div>
                    <label htmlFor={`${prefix}-owner`} className={LABEL}>{tt.field_owner ?? 'Ansvarlig'}<RequiredMark /></label>
                    <select
                        {...owner.controlProps}
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tt.choose_owner ?? 'Velg ansvarlig'}</option>
                        {ownerOptions.map((option) => (
                            <option key={option.id} value={option.id}>{option.name}</option>
                        ))}
                    </select>
                    <p id={owner.hintId} className={HINT}>
                        {tt.field_owner_hint ?? 'Bare personer som kan se denne risikoen kan være ansvarlige.'}
                    </p>
                    {form.errors.owner_user_id && <p id={owner.errorId} className={ERROR}>{form.errors.owner_user_id}</p>}
                </div>
                <div>
                    <label htmlFor={`${prefix}-due`} className={LABEL}>{tt.field_due_at ?? 'Frist'}<RequiredMark /></label>
                    <input
                        {...due.controlProps}
                        type="date"
                        value={form.data.due_at}
                        onChange={(event) => form.setData('due_at', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.due_at && <p id={due.errorId} className={ERROR}>{form.errors.due_at}</p>}
                </div>
            </div>
            {withOutcome && (
                <div>
                    <label htmlFor={`${prefix}-note`} className={LABEL}>{tt.field_outcome_note ?? 'Resultat (valgfritt)'}</label>
                    <textarea
                        {...note.controlProps}
                        rows={2}
                        value={form.data.outcome_note}
                        onChange={(event) => form.setData('outcome_note', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.outcome_note && <p id={note.errorId} className={ERROR}>{form.errors.outcome_note}</p>}
                </div>
            )}
            <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
            <div className="flex flex-wrap gap-2">
                <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>{tt.save ?? 'Lagre tiltak'}</button>
                <button type="button" onClick={() => { form.reset(); form.clearErrors(); onDone(); }} className={SECONDARY_ACTION}>
                    {tt.cancel ?? 'Avbryt'}
                </button>
            </div>
        </form>
    );
}

function CompleteForm({ riskId, action, onDone, tt }) {
    const form = useForm({ outcome_note: action.outcome_note ?? '' });
    const id = `risk-action-${action.id}-complete-note`;
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    useFocusOnOpen(formRef, true);

    const submit = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.post(`/app/risk/risks/${riskId}/actions/${action.id}/complete`, { preserveScroll: true, onSuccess: onDone, onError: () => focusFirstError() });
    };

    const field = fieldA11y({ id, hasHint: true, hasError: Boolean(form.errors.outcome_note) });

    return (
        <form ref={formRef} onSubmit={submit} className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div>
                <label htmlFor={id} className={LABEL}>{tt.field_outcome_note ?? 'Resultat (valgfritt)'}</label>
                <p id={field.hintId} className="text-base text-slate-600">{tt.field_outcome_note_hint ?? 'Hva ble gjort, og hva ble resultatet?'}</p>
                <textarea
                    {...field.controlProps}
                    rows={3}
                    value={form.data.outcome_note}
                    onChange={(event) => form.setData('outcome_note', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.outcome_note && <p id={field.errorId} className={ERROR}>{form.errors.outcome_note}</p>}
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>{tt.complete_confirm ?? 'Fullfør tiltak'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tt.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}

/**
 * A tiltak's own form replaces its row's buttons, so it is mounted by the click that opens it: focus
 * goes to its first field then (A11Y-014). Never on a page load — these forms start closed.
 */
function useFocusOnOpen(formRef, enabled) {
    useEffect(() => {
        if (enabled) {
            formRef.current?.querySelector('input, select, textarea')?.focus();
        }
        // Mount only: the form is new each time it opens.
    }, []);
}
