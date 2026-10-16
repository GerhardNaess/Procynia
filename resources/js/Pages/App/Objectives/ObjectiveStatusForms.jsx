import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import FormField, { FieldsetField, RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, LABEL, TEXTAREA } from '../../../Support/formStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Lukk mål: the outcome is the decision, the comment is optional. The server writes the closing to
 * the history in the same step. The outcomes are one named group with its error tied to it
 * (A11Y-120); a refused save puts focus on the first field that is wrong (A11Y-013).
 */
export function ObjectiveCloseForm({ objectiveId, outcomes = [], statusLabels = {}, onDone, tr }) {
    const form = useForm({ status: '', note: '' });
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const note = fieldA11y({ id: 'objective-close-note', hasHint: true, hasError: Boolean(form.errors.note) });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/objectives/${objectiveId}/close`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    return (
        <section className={CARD} aria-labelledby="objective-close-heading">
            <h2 id="objective-close-heading" className="text-lg font-semibold text-slate-950">{tr.close_heading ?? 'Lukk mål'}</h2>
            <p className="mt-1 text-base text-slate-600">{tr.close_intro ?? 'Velg hvordan målet avsluttes. Avgjørelsen lagres i historikken.'}</p>
            <form ref={formRef} onSubmit={submit} className="mt-4 space-y-4">
                <FieldsetField
                    id="objective-close-outcome"
                    legend={(
                        <>
                            {tr.close_outcome_label ?? 'Utfall'}
                            <span aria-hidden="true" className="ml-0.5 text-rose-700">*</span>
                        </>
                    )}
                    error={form.errors.status}
                >
                    {(optionProps) => (
                        <div className="mt-2 flex flex-wrap gap-4">
                            {outcomes.map((outcome) => (
                                <label key={outcome} className="flex items-center gap-2 text-base text-slate-900">
                                    <input
                                        {...optionProps}
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
                    )}
                </FieldsetField>
                <div>
                    <label htmlFor="objective-close-note" className={LABEL}>{tr.close_note_label ?? 'Kommentar'}</label>
                    <p id={note.hintId} className="text-base text-slate-600">
                        {tr.close_note_hint ?? 'Valgfritt.'}
                    </p>
                    <textarea
                        {...note.controlProps}
                        rows={3}
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        className={`mt-1 ${TEXTAREA}`}
                    />
                    {form.errors.note && <p id={note.errorId} className={ERROR}>{form.errors.note}</p>}
                </div>
                <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
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
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/objectives/${objectiveId}/reopen`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    return (
        <section className={CARD} aria-labelledby="objective-reopen-heading">
            <h2 id="objective-reopen-heading" className="text-lg font-semibold text-slate-950">{tr.reopen_heading ?? 'Gjenåpne mål'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.reopen_intro ?? 'Gjenåpning omgjør en tidligere avslutning. Forklar hvorfor – begrunnelsen lagres i historikken.'}
            </p>
            <form ref={formRef} onSubmit={submit} className="mt-4 space-y-4">
                <FormField id="objective-reopen-reason" label={tr.reopen_reason_label ?? 'Begrunnelse'} error={form.errors.reason} required>
                    {(field) => (
                        <textarea
                            {...field}
                            rows={3}
                            required
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            className={TEXTAREA}
                        />
                    )}
                </FormField>
                <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
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
