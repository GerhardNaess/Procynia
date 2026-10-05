import { useForm } from '@inertiajs/react';
import { PRIMARY_ACTION, SECONDARY_ACTION } from '../../../Support/actionStyles';
import RequiredMark from '../Risk/RequiredMark';

const CARD = 'rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm';
const INPUT = 'min-h-10 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-base text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none';
const LABEL = 'block text-base font-semibold text-slate-700';

/**
 * Avslutt KPI: the decision to stop measuring it. The comment is optional; the server writes the
 * retirement to the history in the same step.
 */
export function KpiRetireForm({ baseUrl, onDone, tr }) {
    const form = useForm({ note: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`${baseUrl}/retire`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD}>
            <h2 className="text-lg font-semibold text-slate-950">{tr.retire_heading ?? 'Avslutt KPI'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.retire_intro ?? 'En avsluttet KPI skal ikke lenger måles. Avgjørelsen lagres i historikken, og KPI-en kan gjenåpnes senere.'}
            </p>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <div>
                    <label htmlFor="kpi-retire-note" className={LABEL}>{tr.retire_note_label ?? 'Kommentar'}</label>
                    <p id="kpi-retire-note-hint" className="text-base text-slate-600">{tr.retire_note_hint ?? 'Valgfritt.'}</p>
                    <textarea
                        id="kpi-retire-note"
                        rows={3}
                        aria-describedby="kpi-retire-note-hint"
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.note && <p className="mt-1 text-base text-rose-600">{form.errors.note}</p>}
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

    const submit = (event) => {
        event.preventDefault();
        form.post(`${baseUrl}/reopen`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <section className={CARD}>
            <h2 className="text-lg font-semibold text-slate-950">{tr.reopen_heading ?? 'Gjenåpne KPI'}</h2>
            <p className="mt-1 text-base text-slate-600">
                {tr.reopen_intro ?? 'Gjenåpning omgjør en tidligere avslutning. Forklar hvorfor – begrunnelsen lagres i historikken.'}
            </p>
            <form onSubmit={submit} className="mt-4 space-y-4">
                <div>
                    <label htmlFor="kpi-reopen-reason" className={LABEL}>{tr.reopen_reason_label ?? 'Begrunnelse'}<RequiredMark /></label>
                    <textarea
                        id="kpi-reopen-reason"
                        rows={3}
                        required
                        aria-required="true"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        className={`mt-1 ${INPUT}`}
                    />
                    {form.errors.reason && <p className="mt-1 text-base text-rose-600">{form.errors.reason}</p>}
                </div>
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
