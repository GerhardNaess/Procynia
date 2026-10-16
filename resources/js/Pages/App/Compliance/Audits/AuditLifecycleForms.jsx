import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../../Support/actionStyles';
import { ERROR, HINT, INPUT, LABEL } from '../../../../Support/formStyles';
import RequiredMark from '../RequiredMark';
import FieldError from '../FieldError';
import { fieldStateProps } from '../complianceA11y';
import { useFocusOnRejectedSubmit } from '../useComplianceFocus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * One lifecycle step as a small form: Start, Avbryt and Gjenåpne. The server decides whether the
 * step is allowed now, requires the reason where it must (Avbryt, Gjenåpne), and writes the change
 * to the status history in the same step.
 */
function ReasonForm({ id, action, heading, intro, submit, required, onDone, ta, tone = PRIMARY_ACTION }) {
    const form = useForm({ reason: '' });
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);

    const send = (event) => {
        event.preventDefault();
        form.post(action, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD} aria-labelledby={`${id}-heading`} data-testid={id}>
            <h2 id={`${id}-heading`} className="text-xl font-semibold text-slate-950">{heading}</h2>
            <p className="mt-1 text-base text-slate-600">{intro}</p>
            <form ref={formRef} onSubmit={send} className="mt-4 space-y-4">
                <div>
                    <label htmlFor={`${id}-reason`} className={LABEL}>
                        {required ? (ta.reason_label ?? 'Begrunnelse') : (ta.reason_optional ?? 'Begrunnelse (valgfritt)')}
                        {required && <RequiredMark />}
                    </label>
                    <textarea
                        id={`${id}-reason`}
                        rows={3}
                        required={required}
                        aria-required={required ? 'true' : undefined}
                        {...fieldStateProps(`${id}-reason`, form.errors.reason)}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError field={`${id}-reason`} message={form.errors.reason} />
                    {form.errors.status && <p className={ERROR} data-form-error-summary="">{form.errors.status}</p>}
                </div>
                {required && <RequiredFieldsNote text={ta.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />}
                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
                    <button type="submit" disabled={form.processing} className={tone}>
                        {form.processing ? (ta.saving ?? 'Lagrer...') : submit}
                    </button>
                </div>
            </form>
        </section>
    );
}

export function AuditStartForm({ auditId, onDone, ta }) {
    return (
        <ReasonForm
            id="compliance-audit-start"
            action={`/app/compliance/audits/${auditId}/start`}
            heading={ta.start_heading ?? 'Start revisjon'}
            intro={ta.start_intro ?? 'Revisjonen settes til Under arbeid. Typen kan ikke endres etterpå.'}
            submit={ta.start_submit ?? 'Start revisjon'}
            required={false}
            onDone={onDone}
            ta={ta}
        />
    );
}

export function AuditCancelForm({ auditId, onDone, ta }) {
    return (
        <ReasonForm
            id="compliance-audit-cancel"
            action={`/app/compliance/audits/${auditId}/cancel`}
            heading={ta.cancel_heading ?? 'Avbryt revisjon'}
            intro={ta.cancel_intro ?? 'Bruk dette når revisjonen ikke skal gjennomføres likevel. Revisjonen slettes ikke.'}
            submit={ta.cancel_submit ?? 'Avbryt revisjon'}
            required
            tone={WARNING_ACTION}
            onDone={onDone}
            ta={ta}
        />
    );
}

export function AuditReopenForm({ auditId, onDone, ta }) {
    return (
        <ReasonForm
            id="compliance-audit-reopen"
            action={`/app/compliance/audits/${auditId}/reopen`}
            heading={ta.reopen_heading ?? 'Gjenåpne revisjon'}
            intro={ta.reopen_intro ?? 'Revisjonen settes tilbake til Under arbeid og kan endres igjen.'}
            submit={ta.reopen_submit ?? 'Gjenåpne revisjon'}
            required
            onDone={onDone}
            ta={ta}
        />
    );
}

/**
 * Fullfør revisjon: the conclusion, prefilled with what was saved underway, and the step itself. The
 * server refuses it without a conclusion, writes both in one step, and creates nothing else — an
 * audit can be completed without findings.
 */
export function AuditCompleteForm({ audit, onDone, ta }) {
    const form = useForm({ conclusion: audit.conclusion ?? '' });
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/audits/${audit.id}/complete`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-audit-complete-heading" data-testid="compliance-audit-complete">
            <h2 id="compliance-audit-complete-heading" className="text-xl font-semibold text-slate-950">{ta.complete_heading ?? 'Fullfør revisjon'}</h2>
            <p className="mt-1 text-base text-slate-600">{ta.complete_intro ?? 'Revisjonen avsluttes med konklusjonen under og blir låst. En revisjon kan fullføres uten funn.'}</p>
            <form ref={formRef} onSubmit={send} className="mt-4 space-y-4">
                <div>
                    <label htmlFor="compliance-audit-complete-conclusion" className={LABEL}>{ta.field_conclusion ?? 'Konklusjon'}<RequiredMark /></label>
                    <p id="compliance-audit-complete-conclusion-hint" className={HINT}>{ta.field_conclusion_hint ?? 'Revisjonens samlede vurdering.'}</p>
                    <textarea
                        id="compliance-audit-complete-conclusion"
                        rows={5}
                        aria-required="true"
                        {...fieldStateProps('compliance-audit-complete-conclusion', form.errors.conclusion, ['compliance-audit-complete-conclusion-hint'])}
                        value={form.data.conclusion}
                        onChange={(event) => form.setData('conclusion', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError field="compliance-audit-complete-conclusion" message={form.errors.conclusion} />
                    {form.errors.status && <p className={ERROR} data-form-error-summary="">{form.errors.status}</p>}
                </div>
                <RequiredFieldsNote text={ta.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{ta.cancel ?? 'Avbryt'}</button>
                    <button type="submit" disabled={form.processing} className={PRIMARY_ACTION}>
                        {form.processing ? (ta.saving ?? 'Lagrer...') : (ta.complete_submit ?? 'Fullfør revisjon')}
                    </button>
                </div>
            </form>
        </section>
    );
}
