import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import StatusBadge from '../../../Components/App/StatusBadge';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-sm font-semibold text-slate-700';

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('nb-NO') : '—');
// A deadline is a calendar day (Y-m-d); parsed as local time so it never shifts a day across time zones.
const formatDay = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString('nb-NO') : '—');

/**
 * Tiltak on the risk: what is to be done, by whom, by when. Open actions come first and carry the
 * weight; completed ones sit beneath them, quieter. Every action is offered only when the server
 * said this person may edit the risk — the controller refuses them otherwise. «Forfalt» is the
 * server's answer (is_overdue), not computed here.
 */
export default function RiskTreatmentPanel({ riskId, actions, ownerOptions, canManage, tr }) {
    const tt = tr.treatment ?? {};
    // null: nothing open; 'new': the create form; an id: that action's edit form.
    const [editing, setEditing] = useState(null);
    const [completing, setCompleting] = useState(null);

    const openActions = actions.filter((action) => action.status === 'open');
    const completedActions = actions.filter((action) => action.status !== 'open');

    const reopen = (action) => {
        router.post(`/app/risk/risks/${riskId}/actions/${action.id}/reopen`, {}, { preserveScroll: true });
    };

    const renderAction = (action) => {
        if (editing === action.id) {
            return (
                <li key={action.id} className="py-3">
                    <ActionForm
                        riskId={riskId}
                        action={action}
                        ownerOptions={ownerOptions}
                        onDone={() => setEditing(null)}
                        tt={tt}
                    />
                </li>
            );
        }

        const open = action.status === 'open';

        return (
            <li key={action.id} className={`space-y-2 py-4 ${open ? '' : 'text-slate-600'}`}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 space-y-1">
                        <p className={`text-base ${open ? 'font-semibold text-slate-950' : 'font-medium text-slate-700'}`}>{action.title}</p>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <StatusBadge tone={open ? 'violet' : 'emerald'}>
                                {open ? (tt.status_open ?? 'Åpen') : (tt.status_completed ?? 'Fullført')}
                            </StatusBadge>
                            {action.is_overdue && <StatusBadge tone="rose">{tt.overdue ?? 'Forfalt'}</StatusBadge>}
                            <span className={action.is_overdue ? 'font-semibold text-rose-700' : 'text-slate-600'}>
                                {(tt.due ?? 'Frist :date').replace(':date', formatDay(action.due_at))}
                            </span>
                            <span className="text-slate-600">
                                {action.owner_name
                                    ? (tt.owner ?? 'Ansvarlig: :name').replace(':name', action.owner_name)
                                    : (tt.no_owner ?? 'Ingen ansvarlig')}
                            </span>
                            {! open && action.completed_at && (
                                <span className="text-slate-600">
                                    {(tt.completed_on ?? 'Fullført :date').replace(':date', formatDate(action.completed_at))}
                                </span>
                            )}
                        </div>
                        {action.outcome_note && (
                            <p className="whitespace-pre-line text-sm text-slate-700">
                                <span className="font-semibold">{tt.outcome_note ?? 'Resultat'}:</span> {action.outcome_note}
                            </p>
                        )}
                    </div>
                    {canManage && completing !== action.id && (
                        <div className="flex flex-wrap gap-2">
                            <button type="button" onClick={() => { setEditing(action.id); setCompleting(null); }} className={SECONDARY_ACTION}>
                                {tt.edit ?? 'Rediger'}
                            </button>
                            {open ? (
                                <button type="button" onClick={() => { setCompleting(action.id); setEditing(null); }} className={SECONDARY_ACTION}>
                                    {tt.complete ?? 'Marker som fullført'}
                                </button>
                            ) : (
                                <button type="button" onClick={() => reopen(action)} className={SECONDARY_ACTION}>
                                    {tt.reopen ?? 'Gjenåpne'}
                                </button>
                            )}
                        </div>
                    )}
                </div>
                {completing === action.id && (
                    <CompleteForm riskId={riskId} action={action} onDone={() => setCompleting(null)} tt={tt} />
                )}
            </li>
        );
    };

    return (
        <section className={CARD}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">{tt.title ?? 'Tiltak'}</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        {tt.description ?? 'Konkrete tiltak for å håndtere risikoen, med ansvarlig og frist.'}
                    </p>
                </div>
                {canManage && editing !== 'new' && (
                    <button type="button" onClick={() => { setEditing('new'); setCompleting(null); }} className={SECONDARY_ACTION}>
                        {tt.create ?? 'Nytt tiltak'}
                    </button>
                )}
            </div>

            {editing === 'new' && (
                <div className="mt-4">
                    <ActionForm riskId={riskId} action={null} ownerOptions={ownerOptions} onDone={() => setEditing(null)} tt={tt} />
                </div>
            )}

            {actions.length === 0 && editing !== 'new' && (
                <p className="mt-4 text-base text-slate-600">{tt.empty ?? 'Ingen tiltak er registrert på denne risikoen.'}</p>
            )}

            {openActions.length > 0 && (
                <div className="mt-4">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{tt.open_heading ?? 'Åpne tiltak'}</h3>
                    <ul className="divide-y divide-slate-100">{openActions.map(renderAction)}</ul>
                </div>
            )}

            {completedActions.length > 0 && (
                <div className="mt-6 rounded-2xl bg-slate-50 px-4 py-2">
                    <h3 className="pt-2 text-sm font-semibold uppercase tracking-wide text-slate-500">{tt.completed_heading ?? 'Fullførte tiltak'}</h3>
                    <ul className="divide-y divide-slate-200">{completedActions.map(renderAction)}</ul>
                </div>
            )}
        </section>
    );
}

function ActionForm({ riskId, action, ownerOptions, onDone, tt }) {
    const form = useForm({
        title: action?.title ?? '',
        owner_user_id: action?.owner_user_id ? String(action.owner_user_id) : '',
        due_at: action?.due_at ?? '',
        outcome_note: action?.outcome_note ?? '',
    });
    const prefix = action ? `risk-action-${action.id}` : 'risk-action-new';

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } };

        if (action) {
            form.patch(`/app/risk/risks/${riskId}/actions/${action.id}`, options);
        } else {
            form.post(`/app/risk/risks/${riskId}/actions`, options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div>
                <label htmlFor={`${prefix}-title`} className={LABEL}>{tt.field_title ?? 'Hva skal gjøres'}</label>
                <input
                    id={`${prefix}-title`}
                    type="text"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.title && <p className="mt-1 text-sm text-rose-700">{form.errors.title}</p>}
            </div>
            <div className="grid gap-3 md:grid-cols-2">
                <div>
                    <label htmlFor={`${prefix}-owner`} className={LABEL}>{tt.field_owner ?? 'Ansvarlig'}</label>
                    <select
                        id={`${prefix}-owner`}
                        value={form.data.owner_user_id}
                        onChange={(event) => form.setData('owner_user_id', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    >
                        <option value="">{tt.choose_owner ?? 'Velg ansvarlig'}</option>
                        {ownerOptions.map((owner) => (
                            <option key={owner.id} value={owner.id}>{owner.name}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-sm text-slate-500">
                        {tt.field_owner_hint ?? 'Bare personer som kan se denne risikoen kan være ansvarlige.'}
                    </p>
                    {form.errors.owner_user_id && <p className="mt-1 text-sm text-rose-700">{form.errors.owner_user_id}</p>}
                </div>
                <div>
                    <label htmlFor={`${prefix}-due`} className={LABEL}>{tt.field_due_at ?? 'Frist'}</label>
                    <input
                        id={`${prefix}-due`}
                        type="date"
                        value={form.data.due_at}
                        onChange={(event) => form.setData('due_at', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.due_at && <p className="mt-1 text-sm text-rose-700">{form.errors.due_at}</p>}
                </div>
            </div>
            <div>
                <label htmlFor={`${prefix}-note`} className={LABEL}>{tt.field_outcome_note ?? 'Resultat / sluttnotat (valgfritt)'}</label>
                <textarea
                    id={`${prefix}-note`}
                    rows={2}
                    value={form.data.outcome_note}
                    onChange={(event) => form.setData('outcome_note', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.outcome_note && <p className="mt-1 text-sm text-rose-700">{form.errors.outcome_note}</p>}
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{tt.save ?? 'Lagre tiltak'}</button>
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

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/risk/risks/${riskId}/actions/${action.id}/complete`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div>
                <label htmlFor={id} className={LABEL}>{tt.field_outcome_note ?? 'Resultat / sluttnotat (valgfritt)'}</label>
                <textarea
                    id={id}
                    rows={2}
                    value={form.data.outcome_note}
                    onChange={(event) => form.setData('outcome_note', event.target.value)}
                    className={`mt-1 ${INPUT}`}
                />
                {form.errors.outcome_note && <p className="mt-1 text-sm text-rose-700">{form.errors.outcome_note}</p>}
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>{tt.complete_confirm ?? 'Fullfør tiltak'}</button>
                <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tt.cancel ?? 'Avbryt'}</button>
            </div>
        </form>
    );
}
