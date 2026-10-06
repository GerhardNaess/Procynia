import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION, WARNING_ACTION } from '../../../../Support/actionStyles';
import RequiredMark from '../../Risk/RequiredMark';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';
const HINT = 'mt-1 text-base text-slate-600';
const ERROR = 'mt-1 text-base text-rose-700';

/**
 * One lifecycle step as a small form: Start, Avbryt and Gjenåpne. The server decides whether the
 * step is allowed now, requires the reason where it must (Avbryt, Gjenåpne), and writes the change
 * to the status history in the same step.
 */
function ReasonForm({ id, action, heading, intro, submit, required, onDone, ta, tone = PRIMARY_ACTION }) {
    const form = useForm({ reason: '' });

    const send = (event) => {
        event.preventDefault();
        form.post(action, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD} aria-labelledby={`${id}-heading`} data-testid={id}>
            <h2 id={`${id}-heading`} className="text-xl font-semibold text-slate-950">{heading}</h2>
            <p className="mt-1 text-base text-slate-600">{intro}</p>
            <form onSubmit={send} className="mt-4 space-y-4">
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
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.reason && <p className={ERROR}>{form.errors.reason}</p>}
                    {form.errors.status && <p className={ERROR}>{form.errors.status}</p>}
                </div>
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

    const send = (event) => {
        event.preventDefault();
        form.post(`/app/compliance/audits/${audit.id}/complete`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD} aria-labelledby="compliance-audit-complete-heading" data-testid="compliance-audit-complete">
            <h2 id="compliance-audit-complete-heading" className="text-xl font-semibold text-slate-950">{ta.complete_heading ?? 'Fullfør revisjon'}</h2>
            <p className="mt-1 text-base text-slate-600">{ta.complete_intro ?? 'Revisjonen avsluttes med konklusjonen under og blir låst. En revisjon kan fullføres uten funn.'}</p>
            <form onSubmit={send} className="mt-4 space-y-4">
                <div>
                    <label htmlFor="compliance-audit-complete-conclusion" className={LABEL}>{ta.field_conclusion ?? 'Konklusjon'}<RequiredMark /></label>
                    <p id="compliance-audit-complete-conclusion-hint" className={HINT}>{ta.field_conclusion_hint ?? 'Revisjonens samlede vurdering.'}</p>
                    <textarea
                        id="compliance-audit-complete-conclusion"
                        rows={5}
                        aria-required="true"
                        aria-describedby="compliance-audit-complete-conclusion-hint"
                        value={form.data.conclusion}
                        onChange={(event) => form.setData('conclusion', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.conclusion && <p className={ERROR}>{form.errors.conclusion}</p>}
                    {form.errors.status && <p className={ERROR}>{form.errors.status}</p>}
                </div>
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
