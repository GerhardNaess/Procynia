import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import FormField, { RequiredFieldsNote } from '../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { fieldA11y } from '../../../Support/formFieldA11y';
import { ERROR, LABEL, TEXTAREA } from '../../../Support/formStyles';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Avslutt KPI: the decision to stop measuring it. The comment is optional; the server writes the
 * retirement to the history in the same step. The hint sits above the field and is tied to it; a
 * refused save puts focus on the field (A11Y-013).
 */
export function KpiRetireForm({ baseUrl, onDone, tr }) {
    const form = useForm({ note: '' });
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);
    const note = fieldA11y({ id: 'kpi-retire-note', hasHint: true, hasError: Boolean(form.errors.note) });

    const submit = (event) => {
        event.preventDefault();
        form.post(`${baseUrl}/retire`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    return (
        <section className={CARD} aria-labelledby="kpi-retire-heading">
            <h2 id="kpi-retire-heading" className="text-lg font-semibold text-slate-950">{tr.retire_heading ?? 'Avslutt KPI'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.retire_intro ?? 'En avsluttet KPI skal ikke lenger måles. Avgjørelsen lagres i historikken, og KPI-en kan gjenåpnes senere.'}
            </p>
            <form ref={formRef} onSubmit={submit} className="mt-4 space-y-4">
                <div>
                    <label htmlFor="kpi-retire-note" className={LABEL}>{tr.retire_note_label ?? 'Kommentar'}</label>
                    <p id={note.hintId} className="text-base text-slate-600">{tr.retire_note_hint ?? 'Valgfritt.'}</p>
                    <textarea
                        {...note.controlProps}
                        rows={3}
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        className={`mt-1 ${TEXTAREA}`}
                    />
                    {form.errors.note && <p id={note.errorId} className={ERROR}>{form.errors.note}</p>}
                </div>
                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                        {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.retire_submit ?? 'Avslutt KPI')}
                    </button>
                </div>
            </form>
        </section>
    );
}

/**
 * Gjenåpne: undoes a retirement, so the reason is required. The retirement stays in the history.
 */
export function KpiReopenForm({ baseUrl, onDone, tr }) {
    const form = useForm({ reason: '' });
    const formRef = useRef(null);
    const focusFirstError = useFocusFirstError(formRef);

    const submit = (event) => {
        event.preventDefault();
        form.post(`${baseUrl}/reopen`, { preserveScroll: true, onSuccess: onDone, onError: focusFirstError });
    };

    return (
        <section className={CARD} aria-labelledby="kpi-reopen-heading">
            <h2 id="kpi-reopen-heading" className="text-lg font-semibold text-slate-950">{tr.reopen_heading ?? 'Gjenåpne KPI'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.reopen_intro ?? 'Gjenåpning omgjør en tidligere avslutning. Forklar hvorfor – begrunnelsen lagres i historikken.'}
            </p>
            <form ref={formRef} onSubmit={submit} className="mt-4 space-y-4">
                <FormField id="kpi-reopen-reason" label={tr.reopen_reason_label ?? 'Begrunnelse'} error={form.errors.reason} required>
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
                        {form.processing ? (tr.saving ?? 'Lagrer...') : (tr.reopen_submit ?? 'Gjenåpne KPI')}
                    </button>
                </div>
            </form>
        </section>
    );
}
