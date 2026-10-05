import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * One lifecycle step that needs a text: Lukk (resultat), Avbryt (begrunnelse) or Gjenåpne
 * (begrunnelse). The server requires the text too, writes the change to the history in the same
 * step, and refuses it if the case has moved on in the meantime.
 */
function StatusTextForm({ id, action, field, heading, intro, label, submit, onDone, tr }) {
    const form = useForm({ [field]: '' });

    const send = (event) => {
        event.preventDefault();
        form.post(action, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD} aria-labelledby={`${id}-heading`}>
            <h2 id={`${id}-heading`} className="text-xl font-semibold text-slate-950">{heading}</h2>
            <p className="mt-1 text-base text-slate-600">{intro}</p>
            <form onSubmit={send} className="mt-4 space-y-4">
                <div>
                    <label htmlFor={id} className={LABEL}>{label}<RequiredMark /></label>
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
        </section>
    );
}

export function ImprovementCloseForm({ caseId, onDone, tr }) {
    return (
        <StatusTextForm
            id="improvement-close-note"
            action={`/app/improvements/${caseId}/close`}
            field="closing_note"
            heading={tr.close_heading ?? 'Lukk sak'}
            intro={tr.close_intro ?? 'Skriv kort hva som ble gjort, eller hvorfor saken kan lukkes. Teksten lagres på saken og i historikken.'}
            label={tr.close_note_label ?? 'Resultat / avsluttende kommentar'}
            submit={tr.close_submit ?? 'Lukk sak'}
            onDone={onDone}
            tr={tr}
        />
    );
}

export function ImprovementCancelForm({ caseId, onDone, tr }) {
    return (
        <StatusTextForm
            id="improvement-cancel-reason"
            action={`/app/improvements/${caseId}/cancel`}
            field="reason"
            heading={tr.cancel_heading ?? 'Avbryt sak'}
            intro={tr.cancel_intro ?? 'Bruk dette når saken ikke skal behandles videre, for eksempel et duplikat eller en feilregistrering. Begrunnelsen lagres i historikken.'}
            label={tr.cancel_reason_label ?? 'Begrunnelse'}
            submit={tr.cancel_submit ?? 'Avbryt sak'}
            onDone={onDone}
            tr={tr}
        />
    );
}

export function ImprovementReopenForm({ caseId, onDone, tr }) {
    return (
        <StatusTextForm
            id="improvement-reopen-reason"
            action={`/app/improvements/${caseId}/reopen`}
            field="reason"
            heading={tr.reopen_heading ?? 'Gjenåpne sak'}
            intro={tr.reopen_intro ?? 'Saken blir åpen igjen og kan redigeres. Forklar hvorfor – begrunnelsen lagres i historikken, og den tidligere avslutningen blir stående der.'}
            label={tr.reopen_reason_label ?? 'Begrunnelse'}
            submit={tr.reopen_submit ?? 'Gjenåpne sak'}
            onDone={onDone}
            tr={tr}
        />
    );
}
