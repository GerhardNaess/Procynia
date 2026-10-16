import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, INPUT, LABEL } from '../../../Support/formStyles';
import RequiredMark from '../Risk/RequiredMark';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * One lifecycle step that needs a text: Lukk (resultat), Avbryt (begrunnelse) or Gjenåpne
 * (begrunnelse). The server requires the text too, writes the change to the history in the same
 * step, and refuses it if the case has moved on in the meantime.
 *
 * A refusal — the text missing, or tiltak still open when closing — comes back on the field: it is
 * tied to the textarea, marks it invalid and takes focus there (3.3.1, A11Y-013).
 */
function StatusTextForm({ id, action, field, heading, intro, label, submit, onDone, tr }) {
    const form = useForm({ [field]: '' });
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const { errorId, controlProps } = fieldA11y({ id, hasError: Boolean(form.errors[field]), required: true });

    const send = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.post(action, { preserveScroll: true, onSuccess: onDone, onError: () => focusFirstError() });
    };

    return (
        <section className={CARD} aria-labelledby={`${id}-heading`}>
            <h2 id={`${id}-heading`} className="text-xl font-semibold text-slate-950">{heading}</h2>
            <p className="mt-1 text-base text-slate-600">{intro}</p>
            <form ref={formRef} onSubmit={send} className="mt-4 space-y-4">
                <div>
                    <label htmlFor={id} className={LABEL}>{label}<RequiredMark /></label>
                    <textarea
                        {...controlProps}
                        rows={3}
                        required
                        value={form.data[field]}
                        onChange={(event) => form.setData(field, event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors[field] && <p id={errorId} className={ERROR}>{form.errors[field]}</p>}
                </div>
                <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                    <button type="submit" aria-disabled={form.processing || undefined} className={PRIMARY_ACTION}>
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
