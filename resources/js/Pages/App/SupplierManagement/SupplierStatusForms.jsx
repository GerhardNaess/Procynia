import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { SECONDARY_ACTION, WARNING_ACTION, PRIMARY_ACTION } from '../../../Support/actionStyles';
import { useFocusFirstError } from '../../../Support/focusManagement';
import { TEXTAREA } from '../../../Support/formStyles';
import SupplierField, { BUSY, RequiredNote } from './SupplierField';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * Avslutt leverandør or Gjenåpne leverandør, each with its begrunnelse. The server requires the
 * reason too, writes the change to the history in the same step, and refuses it if the supplier
 * has moved on in the meantime.
 *
 * The form sits under the «Status» heading (h3), so its own heading is an h4. The begrunnelse is
 * tied to its error, and a refused save puts focus on it (A11Y-013).
 */
function StatusReasonForm({ id, action, heading, intro, submit, submitClass, onDone, tr }) {
    const form = useForm({ reason: '' });
    const formRef = useRef(null);
    const focusError = useFocusFirstError(formRef);

    const send = (event) => {
        event.preventDefault();

        if (form.processing) {
            return;
        }

        form.post(action, { preserveScroll: true, onSuccess: onDone, onError: focusError });
    };

    return (
        <section className={CARD} aria-labelledby={`${id}-heading`}>
            <h4 id={`${id}-heading`} className="text-xl font-semibold text-slate-950">{heading}</h4>
            <p className="mt-1 text-base text-slate-600">{intro}</p>
            <form ref={formRef} onSubmit={send} className="mt-4 space-y-4">
                <SupplierField id={id} label={tr.reason_label ?? 'Begrunnelse'} labelClassName={LABEL} error={form.errors.reason} required>
                    {(field) => (
                        <textarea
                            {...field}
                            rows={3}
                            required
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            className={`mt-1 ${TEXTAREA}`}
                        />
                    )}
                </SupplierField>
                <RequiredNote tr={tr} />
                <div className="flex flex-wrap justify-end gap-3">
                    <button type="button" onClick={onDone} className={SECONDARY_ACTION}>{tr.cancel ?? 'Avbryt'}</button>
                    <button type="submit" aria-disabled={form.processing || undefined} className={`${submitClass} ${BUSY}`}>
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
