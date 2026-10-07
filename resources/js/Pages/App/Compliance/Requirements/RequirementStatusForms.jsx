import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../../Support/actionStyles';
import RequiredMark from '../../Risk/RequiredMark';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * Sett som utgått or Gjenåpne, each with its begrunnelse. The server requires the reason too,
 * writes the change to the status history in the same step, and refuses it if the requirement has
 * moved on in the meantime.
 */
function StatusReasonForm({ id, action, heading, intro, submit, onDone, tr }) {
    const form = useForm({ reason: '' });

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
                    <label htmlFor={id} className={LABEL}>{tr.reason_label ?? 'Begrunnelse'}<RequiredMark /></label>
                    <textarea
                        id={id}
                        rows={3}
                        required
                        aria-required="true"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.reason && <p className="mt-1 text-base text-rose-700">{form.errors.reason}</p>}
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

export function RequirementRetireForm({ requirementId, onDone, tr }) {
    return (
        <StatusReasonForm
            id="compliance-retire-reason"
            action={`/app/compliance/requirements/${requirementId}/retire`}
            heading={tr.retire_heading ?? 'Sett kravet som utgått'}
            intro={tr.retire_intro ?? 'Bruk dette når kravet ikke lenger gjelder for virksomheten. Begrunnelsen lagres i statushistorikken.'}
            submit={tr.retire_submit ?? 'Sett som utgått'}
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
            onDone={onDone}
            tr={tr}
        />
    );
}
