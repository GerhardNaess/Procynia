import { useForm } from '@inertiajs/react';
import { SECONDARY_ACTION, WARNING_ACTION, PRIMARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * Avslutt leverandør or Gjenåpne leverandør, each with its begrunnelse. The server requires the
 * reason too, writes the change to the history in the same step, and refuses it if the supplier
 * has moved on in the meantime.
 */
function StatusReasonForm({ id, action, heading, intro, submit, submitClass, onDone, tr }) {
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
                    <button type="submit" disabled={form.processing} className={submitClass}>
                        {form.processing ? (tr.saving ?? 'Lagrer...') : submit}
                    </button>
                </div>
            </form>
        </section>
    );
}

export function SupplierEndForm({ supplierId, onDone, tr }) {
    return (
        <StatusReasonForm
            id="supplier-end-reason"
            action={`/app/supplier-management/${supplierId}/end`}
            heading={tr.end_heading ?? 'Avslutt leverandør'}
            intro={tr.end_intro ?? 'Leverandøren og historikken blir stående, men kan ikke endres før den gjenåpnes.'}
            submit={tr.end_submit ?? 'Avslutt leverandør'}
            submitClass={WARNING_ACTION}
            onDone={onDone}
            tr={tr}
        />
    );
}

export function SupplierReopenForm({ supplierId, onDone, tr }) {
    return (
        <StatusReasonForm
            id="supplier-reopen-reason"
            action={`/app/supplier-management/${supplierId}/reopen`}
            heading={tr.reopen_heading ?? 'Gjenåpne leverandør'}
            intro={tr.reopen_intro ?? 'Leverandøren blir aktiv igjen og kan endres. Avslutningen blir stående i historikken.'}
            submit={tr.reopen_submit ?? 'Gjenåpne leverandør'}
            submitClass={PRIMARY_ACTION}
            onDone={onDone}
            tr={tr}
        />
    );
}
