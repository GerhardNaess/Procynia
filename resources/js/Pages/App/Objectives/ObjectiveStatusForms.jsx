import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * Lukk mål: the outcome is the decision, the comment is optional. The server writes the closing to
 * the history in the same step.
 */
export function ObjectiveCloseForm({ objectiveId, outcomes = [], statusLabels = {}, onDone, tr }) {
    const form = useForm({ status: '', note: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/objectives/${objectiveId}/close`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD}>
            <h2 className="text-lg font-semibold text-slate-950">{tr.close_heading ?? 'Lukk mål'}</h2>
            <p className="mt-1 text-base text-slate-600">{tr.close_intro ?? 'Velg hvordan målet avsluttes. Avgjørelsen lagres i historikken.'}</p>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <fieldset>
                    <legend className={LABEL}>{tr.close_outcome_label ?? 'Utfall'}<RequiredMark /></legend>
                    <div className="mt-2 flex flex-wrap gap-4">
                        {outcomes.map((outcome) => (
                            <label key={outcome} className="flex items-center gap-2 text-base text-slate-900">
                                <input
                                    type="radio"
                                    name="objective-close-outcome"
                                    value={outcome}
                                    checked={form.data.status === outcome}
                                    onChange={() => form.setData('status', outcome)}
                                    required
                                />
                                {statusLabels[outcome] ?? outcome}
                            </label>
                        ))}
                    </div>
                    {form.errors.status && <p className="mt-1 text-base text-rose-600">{form.errors.status}</p>}
                </fieldset>
                <div>
                    <label htmlFor="objective-close-note" className={LABEL}>{tr.close_note_label ?? 'Kommentar'}</label>
                    <p id="objective-close-note-hint" className="text-base text-slate-600">
                        {tr.close_note_hint ?? 'Valgfritt.'}
                    </p>
                    <textarea
                        id="objective-close-note"
                        rows={3}
                        aria-describedby="objective-close-note-hint"
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.note && <p className="mt-1 text-base text-rose-600">{form.errors.note}</p>}
                </div>
                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                        {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.close_submit ?? 'Lukk mål')}
                    </button>
                </div>
            </form>
        </section>
    );
}

/**
 * Gjenåpne: undoes a closing, so the reason is required. The earlier closing stays in the history.
 */
export function ObjectiveReopenForm({ objectiveId, onDone, tr }) {
    const form = useForm({ reason: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/objectives/${objectiveId}/reopen`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD}>
            <h2 className="text-lg font-semibold text-slate-950">{tr.reopen_heading ?? 'Gjenåpne mål'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.reopen_intro ?? 'Gjenåpning omgjør en tidligere avslutning. Forklar hvorfor – begrunnelsen lagres i historikken.'}
            </p>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <div>
                    <label htmlFor="objective-reopen-reason" className={LABEL}>{tr.reopen_reason_label ?? 'Begrunnelse'}<RequiredMark /></label>
                    <textarea
                        id="objective-reopen-reason"
                        rows={3}
                        required
                        aria-required="true"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.reason && <p className="mt-1 text-base text-rose-600">{form.errors.reason}</p>}
                </div>
                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                        {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.reopen_submit ?? 'Gjenåpne mål')}
                    </button>
                </div>
            </form>
        </section>
    );
}
