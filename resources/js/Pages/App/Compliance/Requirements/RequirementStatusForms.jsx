import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { RequiredFieldsNote } from '../../../../Components/App/FormField';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import { INPUT, LABEL } from '../../../../Support/formStyles';
import RequiredMark from '../RequiredMark';
import FieldError from '../FieldError';
import { fieldStateProps } from '../complianceA11y';
import { useFocusOnRejectedSubmit } from '../useComplianceFocus';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';

/**
 * Sett som utgått or Gjenåpne, each with its begrunnelse. The server requires the reason too,
 * writes the change to the status history in the same step, and refuses it if the requirement has
 * moved on in the meantime.
 */
function StatusReasonForm({ id, action, heading, intro, submit, panelKey, onDone, tr }) {
    const form = useForm({ reason: '' });
    const formRef = useRef(null);
    useFocusOnRejectedSubmit(form, formRef);

    const send = (event) => {
        event.preventDefault();
        form.post(action, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD} aria-labelledby={`${id}-heading`} data-inline-panel={panelKey}>
            <h2 id={`${id}-heading`} className="text-xl font-semibold text-slate-950">{heading}</h2>
            <p className="mt-1 text-base text-slate-600">{intro}</p>
            <form ref={formRef} onSubmit={send} className="mt-4 space-y-4">
                <div>
                    <label htmlFor={id} className={LABEL}>{tr.reason_label ?? 'Begrunnelse'}<RequiredMark /></label>
                    <textarea
                        id={id}
                        rows={3}
                        required
                        aria-required="true"
                        {...fieldStateProps(id, form.errors.reason)}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    <FieldError field={id} message={form.errors.reason} />
                </div>
                <RequiredFieldsNote text={tr.required_note ?? 'Felt merket med * må fylles ut.'} className="text-base text-slate-600" />
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

export function RequirementRetireForm({ requirementId, onDone, tr }) {
    return (
        <StatusReasonForm
            id="compliance-retire-reason"
            action={`/app/compliance/requirements/${requirementId}/retire`}
            heading={tr.retire_heading ?? 'Sett kravet som utgått'}
            intro={tr.retire_intro ?? 'Bruk dette når kravet ikke lenger gjelder for virksomheten. Begrunnelsen lagres i statushistorikken.'}
            submit={tr.retire_submit ?? 'Sett som utgått'}
            panelKey="retire"
            onDone={onDone}
            tr={tr}
        />
    );
}

export function RequirementReopenForm({ requirementId, onDone, tr }) {
    return (
        <StatusReasonForm
            id="compliance-reopen-reason"
            action={`/app/compliance/requirements/${requirementId}/reopen`}
            heading={tr.reopen_heading ?? 'Gjenåpne kravet'}
            intro={tr.reopen_intro ?? 'Kravet blir aktivt igjen og kan redigeres. Begrunnelsen lagres i statushistorikken.'}
            submit={tr.reopen_submit ?? 'Gjenåpne krav'}
            panelKey="reopen"
            onDone={onDone}
            tr={tr}
        />
    );
}
